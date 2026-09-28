<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    protected $table = 'classrooms';

    protected $fillable = [
        'academic_year_id',
        'grade_level',
        'name',
        'quota',
        'is_active',
    ];

    protected $casts = [
        'grade_level' => 'integer',
        'quota' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['display_name', 'filled_count', 'available_count'];

    /**
     * Urutkan per tingkat lalu nama kelas (1A, 1B, 2A, ...).
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('grade_level')->orderBy('name');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function placements()
    {
        return $this->hasMany(ClassroomPlacement::class);
    }

    public function activePlacements()
    {
        return $this->hasMany(ClassroomPlacement::class)->where('is_active', true);
    }

    /**
     * Jumlah siswa yang benar-benar sudah ditempatkan (bukan jumlah pendaftar).
     */
    public function getFilledCountAttribute(): int
    {
        return (int) ($this->active_placements_count ?? $this->activePlacements()->count());
    }

    public function getAvailableCountAttribute(): int
    {
        return max(0, $this->quota - $this->filled_count);
    }

    /**
     * Nama tampil kelas, mis. "1A".
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->grade_level.$this->name;
    }
}
