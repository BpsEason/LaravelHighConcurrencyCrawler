<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['title', 'price', 'description', 'image_url', 'product_url'];
    protected $dates = ['crawl_time'];
}