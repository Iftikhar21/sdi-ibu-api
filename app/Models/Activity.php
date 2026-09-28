<?php

namespace App\Models;

use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';

    /**
     * Jenis konten yang tersedia beserta labelnya.
     */
    public const TYPES = [
        'prestasi' => 'Prestasi',
        'agenda' => 'Agenda Sekolah',
    ];

    protected $fillable = [
        'type',
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

    protected $appends = ['image_url', 'type_label'];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');
    }

    public function getImageUrlAttribute(): ?string
    {
        return ImageUploader::url($this->image, $this->thumb);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
