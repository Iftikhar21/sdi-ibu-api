<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Graduation extends Model
{
    protected $table = 'graduations';

    protected $fillable = [
        'student_id',
        'graduation_year_id',
        'graduation_date',
        'processed_by',
        'notes',
    ];

    protected $casts = [
        'graduation_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Tahun kelulusan (Master Tahun Ajaran).
     */
    public function graduationYear()
    {
        return $this->belongsTo(AcademicYear::class, 'graduation_year_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
