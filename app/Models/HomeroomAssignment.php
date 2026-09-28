<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeroomAssignment extends Model
{
    protected $table = 'homeroom_assignments';

    protected $fillable = [
        'academic_year_id',
        'classroom_id',
        'teacher_id',
    ];

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
}
