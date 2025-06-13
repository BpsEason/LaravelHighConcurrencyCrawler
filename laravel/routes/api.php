<?php

use App\Http\Controllers\CrawlerController;
use Illuminate\Support\Facades\Route;

Route::post('/crawl', [CrawlerController::class, 'submitCrawlTask']);
Route::get('/crawl_status/{taskId}', [CrawlerController::class, 'getCrawlStatus']);
Route::get('/products', [CrawlerController::class, 'getProducts']);
Route::get('/products/{productId}', [CrawlerController::class, 'getProduct']);