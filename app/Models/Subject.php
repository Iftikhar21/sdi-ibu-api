<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $table = 'subjects';

    protected $fillable = [
        'code',
        'name',
        'grade_level',
        'is_active',
    ];

    protected $casts = [
        'grade_level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('code');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Mata pelajaran yang berlaku untuk satu tingkat tertentu
     * (termasuk yang berlaku untuk semua tingkat).
     */
    public function scopeForGrade($query, int $gradeLevel)
    {
        return $query->where(function ($query) use ($gradeLevel) {
            $query->whereNull('grade_level')->orWhere('grade_level', $gradeLevel);
        });
    }

    public function getGradeLabelAttribute(): string
    {
        return $this->grade_level ? "Tingkat {$this->grade_level}" : 'Semua Tingkat';
    }
}
