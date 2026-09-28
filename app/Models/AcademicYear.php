<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $table = 'academic_years';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Urutkan dari tahun ajaran terbaru.
     */
    public function scopeOrdered($query)
    {
        return $query->orderByDesc('start_date')->orderByDesc('id');
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class);
    }
}
