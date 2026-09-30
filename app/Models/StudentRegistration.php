<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRegistration extends Model
{
    protected $fillable = [
        'registration_number',
        'user_id',
        'academic_year_id',

        'full_name',
        'nickname',
        'gender',
        'birth_place',
        'birth_date',
        'previous_school',

        'father_name',
        'mother_name',
        'address',
        'phone',
        'contact_email',

        'photo',
        'birth_certificate',
        'family_card',
        'payment_proof',
        'transfer_proof',

        'status',
        'notes',
    ];

    protected $appends = ['classroom_id', 'classroom_label'];

    /**
     * Nomor pendaftaran unik, mis. REG-2026-0001.
     *
     * Memakai id baris sebagai urutan supaya nomornya pasti unik tanpa perlu
     * menghitung data yang sudah ada (aman walau ada pendaftaran bersamaan).
     */
    public static function makeRegistrationNumber(int $id, ?AcademicYear $academicYear): string
    {
        $year = $academicYear?->start_date
            ? substr((string) $academicYear->start_date, 0, 4)
            : now()->format('Y');

        return 'REG-'.$year.'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Siswa yang terbentuk dari pendaftaran ini (bila sudah Diterima).
     */
    public function student()
    {
        return $this->hasOne(Student::class, 'registration_id');
    }

    /**
     * Seluruh riwayat penempatan kelas (aktif maupun sebelumnya).
     */
    public function placements()
    {
        return $this->hasMany(ClassroomPlacement::class)->latest('assigned_at');
    }

    public function activePlacement()
    {
        return $this->hasOne(ClassroomPlacement::class)
            ->where('is_active', true)
            ->latest('assigned_at');
    }

    /**
     * Kelas yang sedang ditempati (null bila belum ditempatkan).
     */
    public function getClassroomIdAttribute(): ?int
    {
        return $this->activePlacement?->classroom_id;
    }

    /**
     * Nama kelas yang tampil, mis. "1 Ikhwan".
     */
    public function getClassroomLabelAttribute(): ?string
    {
        return $this->activePlacement?->classroom?->display_name;
    }
}
