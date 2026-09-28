<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassroomPlacement extends Model
{
    protected $table = 'classroom_placements';

    protected $fillable = [
        'student_registration_id',
        'student_id',
        'classroom_id',
        'academic_year_id',
        'assigned_by',
        'assigned_at',
        'unassigned_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function registration()
    {
        return $this->belongsTo(StudentRegistration::class, 'student_registration_id');
    }

    /**
     * Siswa yang menempati kelas ini. Bisa null bila penempatan dibuat
     * sebelum entitas siswa terbentuk.
     */
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

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
