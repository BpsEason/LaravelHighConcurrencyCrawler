<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrawlTask extends Model
{
    protected $fillable = ['task_id', 'start_url', 'status', 'total_urls_processed', 'error_message'];
    protected $dates = ['start_time', 'end_time'];
}