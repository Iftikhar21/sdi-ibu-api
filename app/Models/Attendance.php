<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    public const STATUS_PRESENT = 'hadir';

    public const STATUS_PERMISSION = 'izin';

    public const STATUS_SICK = 'sakit';

    public const STATUS_ABSENT = 'alpa';

    /** Status kehadiran yang tersedia beserta labelnya. */
    public const STATUSES = [
        self::STATUS_PRESENT => 'Hadir',
        self::STATUS_PERMISSION => 'Izin',
        self::STATUS_SICK => 'Sakit',
        self::STATUS_ABSENT => 'Alpa',
    ];

    protected $table = 'attendances';

    protected $fillable = [
        'student_id',
        'classroom_id',
        'academic_year_id',
        'date',
        'status',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
