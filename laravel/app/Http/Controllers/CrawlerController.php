<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrawlRequest;
use App\Jobs\ProcessCrawlTask;
use App\Models\CrawlTask;
use App\Models\Product;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CrawlerController extends Controller
{
    public function submitCrawlTask(CrawlRequest $request)
    {
        $taskId = Str::uuid()->toString();
        $startUrl = $request->input('start_url');
        $maxPages = $request->input('max_pages', 10);

        // 緩存任務到 Redis，後台批量插入
        Redis::lpush('pending_tasks', json_encode([
            'task_id' => $taskId,
            'start_url' => $startUrl,
            'status' => 'pending',
        ]));

        // 提交佇列任務
        ProcessCrawlTask::dispatch($taskId, $startUrl, $maxPages);

        return response()->json(['message' => '爬蟲任務已提交', 'task_id' => $taskId]);
    }

    public function getCrawlStatus($taskId)
    {
        $cacheKey = "task_status:{$taskId}";
        $cachedStatus = Redis::get($cacheKey);

        if ($cachedStatus) {
            return response()->json(json_decode($cachedStatus));
        }

        try {
            $task = CrawlTask::where('task_id', $taskId)->firstOrFail();
            Redis::setex($cacheKey, 60, json_encode($task->toArray()));
            return response()->json($task);
        } catch (\Exception $e) {
            Log::error("Error querying task status: {$e->getMessage()}");
            return response()->json(['error' => '任務不存在'], 404);
        }
    }

    public function getProducts(Request $request)
    {
        $skip = $request->query('skip', 0);
        $limit = $request->query('limit', 100);
        $cacheKey = "products:{$skip}:{$limit}";
        $cachedProducts = Redis::get($cacheKey);

        if ($cachedProducts) {
            return response()->json(json_decode($cachedProducts));
        }

        try {
            $products = Product::select(['id', 'title', 'price', 'product_url', 'crawl_time'])
                ->skip($skip)
                ->take($limit)
                ->get();
            Redis::setex($cacheKey, 60, json_encode($products));
            return response()->json($products);
        } catch (\Exception $e) {
            Log::error("Error querying products: {$e->getMessage()}");
            return response()->json(['error' => '查詢商品失敗'], 500);
        }
    }

    public function getProduct($productId)
    {
        $cacheKey = "product:{$productId}";
        $cachedProduct = Redis::get($cacheKey);

        if ($cachedProduct) {
            return response()->json(json_decode($cachedProduct));
        }

        try {
            $product = Product::findOrFail($productId);
            Redis::setex($cacheKey, 60, json_encode($product));
            return response()->json($product);
        } catch (\Exception $e) {
            Log::error("Error querying product: {$e->getMessage()}");
            return response()->json(['error' => '商品不存在'], 404);
        }
    }
}