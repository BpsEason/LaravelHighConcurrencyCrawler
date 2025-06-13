<?php

namespace App\Console\Commands;

use App\Models\CrawlTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class BatchInsertTasks extends Command
{
    protected $signature = 'crawler:batch-insert-tasks';
    protected $description = 'Batch insert pending tasks from Redis to MySQL';

    public function handle()
    {
        while (true) {
            $tasks = [];
            while (Redis::llen('pending_tasks') > 0 && count($tasks) < 1000) {
                $taskJson = Redis::rpop('pending_tasks');
                if ($taskJson) {
                    $tasks[] = json_decode($taskJson, true);
                }
            }

            if ($tasks) {
                try {
                    CrawlTask::upsert(
                        array_map(fn($t) => [
                            'task_id' => $t['task_id'],
                            'start_url' => $t['start_url'],
                            'status' => $t['status'],
                        ], $tasks),
                        ['task_id'],
                        ['start_url', 'status']
                    );
                    Log::info("Inserted " . count($tasks) . " tasks into MySQL");
                } catch (\Throwable $e) {
                    Log::error("Error batch inserting tasks: {$e->getMessage()}");
                }
            }

            sleep(1);
        }
    }
}