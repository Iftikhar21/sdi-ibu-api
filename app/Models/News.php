<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'thumbnail',
    ];

    public function photos()
    {
        return $this->hasMany(NewsPhoto::class);
    }
}
