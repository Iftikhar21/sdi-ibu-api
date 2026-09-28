<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    /** Semester yang dipakai sekolah beserta labelnya. */
    public const SEMESTERS = [
        1 => 'Ganjil',
        2 => 'Genap',
    ];

    protected $table = 'grades';

    protected $fillable = [
        'student_id',
        'subject_id',
        'academic_year_id',
        'semester',
        'score',
        'recorded_by',
    ];

    protected $casts = [
        'semester' => 'integer',
        'score' => 'float',
    ];

    public function getSemesterLabelAttribute(): string
    {
        return 'Semester '.$this->semester.' ('.(self::SEMESTERS[$this->semester] ?? '-').')';
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
