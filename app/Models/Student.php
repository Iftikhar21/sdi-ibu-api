<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class Student extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_GRADUATED = 'graduated';

    /** Label status untuk ditampilkan di admin. */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Tidak Aktif',
        self::STATUS_GRADUATED => 'Lulus',
    ];

    protected $table = 'students';

    protected $fillable = [
        'registration_id',
        'nis',
        'full_name',
        'gender',
        'birth_place',
        'birth_date',
        'address',
        'admission_year_id',
        'status',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'admission_year_id' => 'integer',
    ];

    protected $appends = ['status_label', 'classroom_label'];

    public function registration()
    {
        return $this->belongsTo(StudentRegistration::class, 'registration_id');
    }

    /**
     * Tahun masuk siswa (Master Tahun Ajaran).
     */
    public function admissionYear()
    {
        return $this->belongsTo(AcademicYear::class, 'admission_year_id');
    }

    /**
     * Riwayat kelas siswa, termasuk yang sudah tidak aktif.
     *
     * Memakai tabel classroom_placements yang sudah menyimpan riwayat
     * (is_active, assigned_at, unassigned_at) sehingga tidak ada data ganda.
     */
    public function classHistories()
    {
        return $this->hasMany(ClassroomPlacement::class)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }

    /**
     * Penempatan kelas yang sedang aktif.
     */
    public function activePlacement()
    {
        return $this->hasOne(ClassroomPlacement::class)
            ->where('is_active', true)
            ->latest('assigned_at');
    }

    /**
     * Data kelulusan siswa (bila sudah lulus).
     */
    public function graduation()
    {
        return $this->hasOne(Graduation::class);
    }

    /**
     * Kelas pada tahun ajaran tertentu, dipakai untuk menampilkan kelas
     * terakhir saat siswa lulus. Bila tidak ada di tahun itu, dipakai
     * penempatan terakhir yang tercatat.
     */
    public function lastClassroomInYear(?int $academicYearId): ?ClassroomPlacement
    {
        $histories = $this->relationLoaded('classHistories')
            ? $this->classHistories
            : $this->classHistories()->with('classroom')->get();

        if ($academicYearId) {
            $placement = $histories->firstWhere('academic_year_id', $academicYearId);

            if ($placement) {
                return $placement;
            }
        }

        return $histories->sortByDesc('assigned_at')->first();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('full_name');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Nama kelas yang sedang ditempati, mis. "1 Ikhwan".
     */
    public function getClassroomLabelAttribute(): ?string
    {
        return $this->activePlacement?->classroom?->display_name;
    }

    /**
     * Tempatkan siswa ke sebuah kelas pada tahun ajaran kelas tersebut.
     *
     * Penempatan aktif lain pada tahun ajaran yang sama dinonaktifkan
     * (bukan dihapus) sehingga riwayat kelasnya tetap terlacak, dan hasil
     * akhirnya hanya ada satu kelas aktif per tahun ajaran.
     */
    public function placeIntoClassroom(
        Classroom $classroom,
        ?int $assignedBy = null,
        ?string $notes = null
    ): ClassroomPlacement {
        return DB::transaction(function () use ($classroom, $assignedBy, $notes) {
            ClassroomPlacement::where('student_id', $this->id)
                ->where('academic_year_id', $classroom->academic_year_id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'unassigned_at' => now(),
                ]);

            // updateOrCreate dipakai agar pindah kembali ke kelas yang pernah
            // ditempati pada tahun ajaran yang sama tidak gagal karena
            // constraint unik (pendaftaran + kelas + tahun ajaran).
            return ClassroomPlacement::updateOrCreate(
                [
                    'student_registration_id' => $this->registration_id,
                    'classroom_id' => $classroom->id,
                    'academic_year_id' => $classroom->academic_year_id,
                ],
                [
                    'student_id' => $this->id,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => now(),
                    'unassigned_at' => null,
                    'is_active' => true,
                    'notes' => $notes,
                ]
            );
        });
    }

    /**
     * Bentuk siswa dari pendaftaran yang sudah Diterima.
     *
     * Idempotent: kalau siswa untuk pendaftaran itu sudah ada, data yang
     * ada dikembalikan tanpa membuat baris baru. Penempatan kelas yang
     * terlanjur dibuat sebelum siswa ada ikut ditautkan ke siswa ini.
     */
    public static function ensureForRegistration(StudentRegistration $registration): self
    {
        $existing = static::where('registration_id', $registration->id)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($registration) {
                $student = static::create([
                    'registration_id' => $registration->id,
                    'full_name' => $registration->full_name,
                    'gender' => $registration->gender,
                    'birth_place' => $registration->birth_place,
                    'birth_date' => $registration->birth_date,
                    'address' => $registration->address,
                    'admission_year_id' => $registration->academic_year_id
                        ?? AcademicYear::where('is_active', true)->value('id'),
                    'status' => self::STATUS_ACTIVE,
                ]);

                // Tautkan penempatan kelas yang sudah ada (mis. dari Step 2)
                ClassroomPlacement::where('student_registration_id', $registration->id)
                    ->whereNull('student_id')
                    ->update(['student_id' => $student->id]);

                return $student;
            });
        } catch (QueryException $exception) {
            // Dua proses berjalan bersamaan: ambil siswa yang sudah tersimpan
            $student = static::where('registration_id', $registration->id)->first();

            if ($student) {
                return $student;
            }

            throw $exception;
        }
    }
}
