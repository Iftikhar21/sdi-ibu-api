<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gallery extends Model
{
    protected $table = 'galleries';

    protected $fillable = [
        'gallery_category_id',
        'title',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'gallery_category_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Urutkan berdasarkan urutan tampil lalu waktu dibuat.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('created_at', 'asc');
    }

    /**
     * Foto-foto di dalam album galeri ini.
     */
    public function photos()
    {
        return $this->hasMany(GalleryPhoto::class)->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function category()
    {
        return $this->belongsTo(GalleryCategory::class, 'gallery_category_id');
    }
}
