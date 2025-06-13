<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrawlRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'start_url' => ['required', 'url'],
            'max_pages' => ['integer', 'min:1', 'max:1000'],
        ];
    }
}