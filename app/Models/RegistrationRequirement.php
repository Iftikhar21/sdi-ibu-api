<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationRequirement extends Model
{
    protected $fillable = [
        'content',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
