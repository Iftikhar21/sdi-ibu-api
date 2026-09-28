<?php

namespace App\Models;

use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;

class Legality extends Model
{
    protected $table = 'legalities';

    protected $fillable = [
        'title',
        'description',
        'image',
        'thumb',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function getImageUrlAttribute(): ?string
    {
        return ImageUploader::url($this->image, $this->thumb);
    }
}
