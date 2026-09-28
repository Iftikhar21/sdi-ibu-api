<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationValue extends Model
{
    protected $table = 'education_values';

    protected $fillable = [
        'title',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function items()
    {
        return $this->hasMany(EducationValueItem::class)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }
}
