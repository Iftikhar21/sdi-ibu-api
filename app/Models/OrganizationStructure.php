<?php

namespace App\Models;

use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;

class OrganizationStructure extends Model
{
    protected $table = 'organization_structures';

    protected $fillable = [
        'name',
        'position',
        'photo',
        'thumb',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['photo_url'];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return ImageUploader::url($this->photo, $this->thumb);
    }
}
