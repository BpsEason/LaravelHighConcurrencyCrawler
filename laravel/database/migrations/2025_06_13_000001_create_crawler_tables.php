<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->string('image_url', 255)->nullable();
            $table->string('product_url', 255)->unique();
            $table->timestamp('crawl_time')->useCurrent();
            $table->index('product_url', 'idx_product_url');
            $table->index('crawl_time', 'idx_crawl_time');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });

        Schema::create('crawl_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_id', 64)->unique();
            $table->string('start_url', 255);
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('total_urls_processed')->default(0);
            $table->timestamp('start_time')->useCurrent();
            $table->timestamp('end_time')->nullable();
            $table->text('error_message')->nullable();
            $table->index('status', 'idx_task_status');
            $table->index('task_id', 'idx_task_id');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('crawl_tasks');
    }
};