<?php

namespace App\Models;

use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;

class Principal extends Model
{
    protected $table = 'principals';

    protected $fillable = [
        'name',
        'position',
        'employee_number',
        'photo',
        'thumb',
        'greeting',
        'education_history',
        'started_at',
        'ended_at',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'education_history' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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
