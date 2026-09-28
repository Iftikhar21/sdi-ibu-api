<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    /** Hari yang dipakai sekolah, berurutan. */
    public const DAYS = [
        'senin' => 'Senin',
        'selasa' => 'Selasa',
        'rabu' => 'Rabu',
        'kamis' => 'Kamis',
        'jumat' => 'Jumat',
        'sabtu' => 'Sabtu',
    ];

    protected $table = 'schedules';

    protected $fillable = [
        'academic_year_id',
        'classroom_id',
        'subject_id',
        'teacher_id',
        'day',
        'start_time',
        'end_time',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** Jam dalam format H:i supaya enak ditampilkan. */
    public static function jam(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    public function getDayLabelAttribute(): string
    {
        return self::DAYS[$this->day] ?? $this->day;
    }

    public function getStartLabelAttribute(): ?string
    {
        return self::jam($this->start_time);
    }

    public function getEndLabelAttribute(): ?string
    {
        return self::jam($this->end_time);
    }
}
