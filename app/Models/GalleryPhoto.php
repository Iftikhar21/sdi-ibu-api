<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GalleryPhoto extends Model
{
    protected $table = 'gallery_photos';

    protected $fillable = [
        'gallery_id',
        'image',
        'thumb',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }
}
