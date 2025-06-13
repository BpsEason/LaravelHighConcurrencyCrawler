<?php

namespace App\Jobs;

use App\Models\CrawlTask;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Spatie\Async\Pool;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Arr;

class ProcessCrawlTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $taskId;
    protected $startUrl;
    protected $maxPages;

    public function __construct($taskId, $startUrl, $maxPages)
    {
        $this->taskId = $taskId;
        $this->startUrl = $startUrl;
        $this->maxPages = $maxPages;
    }

    public function handle()
    {
        try {
            CrawlTask::where('task_id', $this->taskId)->update([
                'status' => 'running',
                'start_time' => now(),
            ]);

            Redis::hset("task_progress:{$this->taskId}", 'processed_urls', 0);
            Redis::hset("task_progress:{$this->taskId}", 'total_urls', 1);

            $productBuffer = [];
            $urlsToCrawl = [[$this->startUrl, 1]];
            $client = new Client(['timeout' => 10]);
            $pool = Pool::create()->concurrency(100);
            $rules = $this->loadParseRules();

            while ($urlsToCrawl && (int) Redis::hget("task_progress:{$this->taskId}", 'processed_urls') < $this->maxPages) {
                $batch = array_splice($urlsToCrawl, 0, 100);
                foreach ($batch as [$url, $depth]) {
                    $pool->add(function () use ($url, $depth, $client, $rules) {
                        return $this->crawlUrl($url, $depth, $client, $rules);
                    })->then(function ($result) use (&$productBuffer, &$urlsToCrawl) {
                        if ($result) {
                            [$products, $newUrls] = $result;
                            $productBuffer = array_merge($productBuffer, $products);
                            $urlsToCrawl = array_merge($urlsToCrawl, array_map(fn($u) => [$u, $depth + 1], $newUrls));
                        }
                    })->catch(function (\Throwable $e) {
                        Log::error("Crawl error: {$e->getMessage()}");
                    });
                }

                $pool->wait();

                if (count($productBuffer) >= 1000) {
                    $this->saveProducts($productBuffer);
                    $productBuffer = [];
                }

                usleep(random_int(500000, 2000000)); // 0.5-2 秒延遲
            }

            if ($productBuffer) {
                $this->saveProducts($productBuffer);
            }

            CrawlTask::where('task_id', $this->taskId)->update([
                'status' => 'completed',
                'end_time' => now(),
            ]);

            // Prometheus 計數器（假設已整合）
            // \Prometheus\Counter::inc('task_success_total');
        } catch (\Throwable $e) {
            Log::error("Task {$this->taskId} failed: {$e->getMessage()}");
            CrawlTask::where('task_id', $this->taskId)->update([
                'status' => 'failed',
                'end_time' => now(),
                'error_message' => $e->getMessage(),
            ]);
            // \Prometheus\Counter::inc('task_failure_total');
            throw $e;
        } finally {
            Redis::del("task_progress:{$this->taskId}");
            Redis::del("retry_count:{$this->taskId}");
        }
    }

    protected function crawlUrl($url, $depth, $client, $rules)
    {
        $maxRetries = 3;
        $retryCount = (int) Redis::hget("retry_count:{$this->taskId}", $url) ?: 0;

        if ($retryCount >= $maxRetries || Redis::sismember('crawled_urls', $url)) {
            // \Prometheus\Counter::inc('crawl_failure_total');
            return null;
        }

        try {
            $proxy = $this->getProxy();
            $response = $client->get($url, ['proxy' => $proxy]);
            $html = (string) $response->getBody();
            $crawler = new Crawler($html);

            $domain = parse_url($url, PHP_URL_HOST);
            $siteRules = Arr::get($rules, "sites.{$domain}", []);

            $productData = [
                'title' => $crawler->filter($siteRules['title_selector'] ?? 'h1.product-title')->text('N/A'),
                'price' => (float) str_replace(['$', ','], '', $crawler->filter($siteRules['price_selector'] ?? 'span.product-price')->text('0.0')),
                'description' => $crawler->filter($siteRules['description_selector'] ?? 'div.product-description')->text(null),
                'image_url' => $crawler->filter($siteRules['image_selector'] ?? 'img.product-image')->attr('src'),
                'product_url' => $url,
            ];

            $products = $productData['title'] !== 'N/A' && $productData['price'] > 0 ? [$productData] : [];

            Redis::sadd('crawled_urls', $url);
            Redis::expire('crawled_urls', 86400);
            Redis::hincrby("task_progress:{$this->taskId}", 'processed_urls', 1);
            // \Prometheus\Counter::inc('crawl_success_total');

            $newUrls = [];
            if ($depth < 3) {
                $baseDomain = parse_url($url, PHP_URL_HOST);
                $crawler->filter('a')->each(function (Crawler $node) use ($url, $baseDomain, &$newUrls) {
                    $href = $node->attr('href');
                    $nextUrl = urljoin($url, $href);
                    $nextDomain = parse_url($nextUrl, PHP_URL_HOST);
                    if ($nextDomain === $baseDomain && !Redis::sismember('crawled_urls', $nextUrl)) {
                        $newUrls[] = $nextUrl;
                    }
                });
            }

            return [$products, $newUrls];
        } catch (\Throwable $e) {
            Redis::hincrby("retry_count:{$this->taskId}", $url, 1);
            Log::error("Crawl {$url} failed: {$e->getMessage()}");
            // \Prometheus\Counter::inc('crawl_failure_total');
            return null;
        }
    }

    protected function saveProducts($products)
    {
        try {
            foreach (array_chunk($products, 1000) as $chunk) {
                Product::upsert(
                    $chunk,
                    ['product_url'],
                    ['title', 'price', 'description', 'image_url', 'crawl_time']
                );
            }
            Log::info("Saved/updated " . count($products) . " products");
        } catch (\Throwable $e) {
            Log::error("Error saving products: {$e->getMessage()}");
        }
    }

    protected function loadParseRules()
    {
        $path = config_path('crawler_rules.yaml');
        if (!file_exists($path)) {
            Log::warning('crawler_rules.yaml not found, using default selectors');
            return ['sites' => []];
        }
        return yaml_parse_file($path);
    }

    protected function getProxy()
    {
        $proxies = config('crawler.proxies', [null]);
        return $proxies[array_rand($proxies)];
    }
}