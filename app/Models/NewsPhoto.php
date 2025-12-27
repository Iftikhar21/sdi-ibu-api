<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsPhoto extends Model
{
    protected $fillable = [
        'news_id', 
        'path'
    ];

    public function news()
    {
        return $this->belongsTo(News::class);
    }
}
