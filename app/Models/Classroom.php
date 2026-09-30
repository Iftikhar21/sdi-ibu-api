<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    public const NAME_IKHWAN = 'Ikhwan';

    public const NAME_AKHWAT = 'Akhwat';

    public const NAMES = [self::NAME_IKHWAN, self::NAME_AKHWAT];

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

    /** Urutkan per tingkat lalu nama kelompok kelas. */
    public function scopeOrdered($query)
    {
        return $query
            ->orderBy('grade_level')
            ->orderByRaw("CASE name WHEN 'Ikhwan' THEN 1 WHEN 'Akhwat' THEN 2 WHEN 'A' THEN 1 WHEN 'B' THEN 2 ELSE 3 END")
            ->orderBy('name');
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

    /** Normalisasi input baru sekaligus alias lama A/B. */
    public static function normalizeName(?string $name): ?string
    {
        return match (strtolower(trim((string) $name))) {
            'a', 'ikhwan' => self::NAME_IKHWAN,
            'b', 'akhwat' => self::NAME_AKHWAT,
            default => null,
        };
    }

    /** Nama tampil kelas, mis. "1 Ikhwan". */
    public function getDisplayNameAttribute(): string
    {
        // Format lama dipertahankan sebagai fallback bila migrasi belum dijalankan.
        if (in_array($this->name, ['A', 'B'], true)) {
            return $this->grade_level.$this->name;
        }

        return $this->grade_level.' '.$this->name;
    }
}
