<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business rule kenaikan kelas.
 *
 * Kenaikan kelas tidak mengubah penempatan lama: satu baris penempatan baru
 * dibuat pada tahun ajaran tujuan sehingga riwayat kelas siswa tetap utuh
 * (mis. 2026/2027 → 1A dan 2027/2028 → 2A).
 */
class StudentPromotion
{
    /** Tingkat tertinggi di jenjang SD; setelah ini jalurnya Kelulusan. */
    private const TINGKAT_AKHIR = 6;

    /**
     * Daftar siswa yang bisa dinaikkan beserta usulan kelas tujuannya.
     *
     * @return array<string, mixed>
     */
    public function candidates(?int $fromYearId, ?int $toYearId): array
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $fromYear = $academicYears->firstWhere('id', (int) $fromYearId);
        $toYear = $academicYears->firstWhere('id', (int) $toYearId);

        $payload = [
            'academic_years' => $academicYears,
            'from_academic_year' => $fromYear,
            'to_academic_year' => $toYear,
            'classrooms' => [],
            'from_classrooms' => [],
            'rows' => [],
            'total' => 0,
        ];

        if (! $fromYear || ! $toYear || $fromYear->id === $toYear->id) {
            return $payload;
        }

        // Kelas tujuan yang bisa dipilih: kelas aktif pada tahun ajaran tujuan
        $targetClassrooms = Classroom::where('academic_year_id', $toYear->id)
            ->where('is_active', true)
            ->withCount('activePlacements')
            ->ordered()
            ->get();

        // Siswa yang sudah punya kelas pada tahun ajaran tujuan tidak perlu diproses lagi
        $sudahDitempatkan = ClassroomPlacement::where('academic_year_id', $toYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique();

        // Kandidat: punya kelas aktif pada tahun ajaran asal dan belum di tingkat akhir.
        // Siswa tingkat 6 tidak ikut karena jalurnya adalah Kelulusan.
        $placements = ClassroomPlacement::query()
            ->with(['classroom', 'student'])
            ->where('academic_year_id', $fromYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->whereHas('student', fn ($query) => $query->where('status', Student::STATUS_ACTIVE))
            ->whereHas('classroom', fn ($query) => $query->where('grade_level', '<', self::TINGKAT_AKHIR))
            ->get()
            ->filter(fn (ClassroomPlacement $placement) => ! $sudahDitempatkan->contains($placement->student_id));

        $rows = $placements
            ->map(function (ClassroomPlacement $placement) use ($targetClassrooms) {
                $student = $placement->student;
                $sourceClassroom = $placement->classroom;

                // Usulan: tingkat + 1 dengan nama kelas yang sama (1A -> 2A)
                $suggested = $this->findTargetClassroom(
                    $targetClassrooms,
                    (int) $sourceClassroom->grade_level + 1,
                    $sourceClassroom->name
                );

                return [
                    'student_id' => $student->id,
                    'full_name' => $student->full_name,
                    'nis' => $student->nis,
                    'status' => $student->status,
                    'status_label' => $student->status_label,
                    'from_classroom_id' => $sourceClassroom->id,
                    'from_classroom_label' => $sourceClassroom->display_name,
                    'from_grade_level' => $sourceClassroom->grade_level,
                    'suggested_classroom_id' => $suggested?->id,
                    'suggested_classroom_label' => $suggested?->display_name,
                ];
            })
            ->sortBy([['from_classroom_label', 'asc'], ['full_name', 'asc']])
            ->values();

        return [
            'academic_years' => $academicYears,
            'from_academic_year' => $fromYear,
            'to_academic_year' => $toYear,
            'classrooms' => $targetClassrooms->map(fn (Classroom $classroom) => [
                'id' => $classroom->id,
                'display_name' => $classroom->display_name,
                'grade_level' => $classroom->grade_level,
                'quota' => $classroom->quota,
                'filled' => $classroom->filled_count,
                'available' => $classroom->available_count,
            ])->values(),
            'from_classrooms' => $rows
                ->map(fn (array $row) => [
                    'id' => $row['from_classroom_id'],
                    'display_name' => $row['from_classroom_label'],
                    'grade_level' => $row['from_grade_level'],
                ])
                ->unique('id')
                ->sortBy('display_name')
                ->values(),
            'rows' => $rows,
            'total' => $rows->count(),
        ];
    }

    /**
     * Proses kenaikan kelas.
     *
     * Semua baris divalidasi lebih dulu, lalu seluruh proses berjalan dalam
     * satu transaksi (all-or-nothing): bila satu baris gagal, tidak ada data
     * yang tersimpan.
     *
     * @param  array<int, array{student_id: int, classroom_id: int}>  $items
     * @return int jumlah siswa yang dinaikkan
     *
     * @throws ValidationException
     */
    public function promote(int $fromYearId, int $toYearId, array $items, ?int $userId): int
    {
        $fromYear = AcademicYear::findOrFail($fromYearId);
        $toYear = AcademicYear::findOrFail($toYearId);

        $classrooms = Classroom::whereIn('id', collect($items)->pluck('classroom_id')->unique())
            ->get()
            ->keyBy('id');

        $errors = [];
        $prepared = [];

        foreach ($items as $index => $item) {
            $baris = $index + 1;
            $student = Student::find($item['student_id']);
            $classroom = $classrooms->get($item['classroom_id']);

            if (! $student) {
                $errors[] = "Baris {$baris}: siswa tidak ditemukan.";

                continue;
            }

            if ($student->status === Student::STATUS_GRADUATED) {
                $errors[] = "Baris {$baris}: {$student->full_name} sudah dinyatakan lulus.";

                continue;
            }

            if ($student->status !== Student::STATUS_ACTIVE) {
                $errors[] = "Baris {$baris}: {$student->full_name} tidak berstatus aktif, "
                    .'jadi tidak bisa dinaikkan.';

                continue;
            }

            // Siswa harus benar-benar punya kelas aktif pada tahun ajaran asal
            $asal = ClassroomPlacement::with('classroom')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $fromYear->id)
                ->where('is_active', true)
                ->first();

            if (! $asal || ! $asal->classroom) {
                $errors[] = "Baris {$baris}: {$student->full_name} tidak punya kelas aktif pada tahun "
                    ."ajaran {$fromYear->name}.";

                continue;
            }

            if ((int) $asal->classroom->grade_level >= self::TINGKAT_AKHIR) {
                $errors[] = "Baris {$baris}: {$student->full_name} berada di tingkat "
                    .self::TINGKAT_AKHIR.', jalurnya diproses lewat menu Kelulusan.';

                continue;
            }

            if (! $classroom || (int) $classroom->academic_year_id !== (int) $toYear->id) {
                $errors[] = "Baris {$baris}: kelas tujuan {$student->full_name} bukan milik tahun ajaran "
                    ."{$toYear->name}.";

                continue;
            }

            if (! $classroom->is_active) {
                $errors[] = "Baris {$baris}: kelas {$classroom->display_name} sedang tidak aktif.";

                continue;
            }

            // Tingkat tujuan harus tepat satu tingkat di atas kelas asal
            $tingkatTujuan = (int) $asal->classroom->grade_level + 1;

            if ((int) $classroom->grade_level !== $tingkatTujuan) {
                $errors[] = "Baris {$baris}: kelas tujuan {$classroom->display_name} tidak sesuai. "
                    ."Siswa dari {$asal->classroom->display_name} harus naik ke tingkat {$tingkatTujuan}.";

                continue;
            }

            if ($this->hasActivePlacementIn($student, (int) $toYear->id)) {
                $errors[] = "Baris {$baris}: {$student->full_name} sudah punya kelas pada tahun ajaran "
                    ."{$toYear->name}.";

                continue;
            }

            $prepared[] = [
                'row' => $baris,
                'student' => $student,
                'classroom' => $classroom,
                'from_label' => $asal->classroom->display_name,
            ];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['promotions' => $errors]);
        }

        return DB::transaction(function () use ($prepared, $fromYear, $toYear, $userId) {
            // Kunci baris kelas tujuan agar pengecekan kuota memakai data terbaru
            $locked = Classroom::whereIn('id', collect($prepared)->pluck('classroom.id')->unique())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $errors = [];

            foreach ($prepared as $item) {
                $classroom = $locked->get($item['classroom']->id);

                if (! $classroom) {
                    $errors[] = 'Ada kelas tujuan yang sudah tidak tersedia.';

                    continue;
                }

                // Bila data berubah saat proses berjalan, jangan lanjutkan
                if ($this->hasActivePlacementIn($item['student'], (int) $toYear->id)) {
                    $errors[] = "{$item['student']->full_name} sudah punya kelas pada tahun ajaran "
                        ."{$toYear->name}.";
                }
            }

            // Kuota dihitung ulang di dalam transaksi memakai jumlah penempatan terbaru
            foreach (collect($prepared)->groupBy(fn (array $item) => $item['classroom']->id) as $classroomId => $group) {
                $classroom = $locked->get($classroomId);

                if (! $classroom) {
                    continue;
                }

                $terisi = ClassroomPlacement::where('classroom_id', $classroom->id)
                    ->where('is_active', true)
                    ->count();

                if ($terisi + $group->count() > $classroom->quota) {
                    $errors[] = "Kuota kelas {$classroom->display_name} tidak cukup "
                        ."(kuota {$classroom->quota}, terisi {$terisi}, diminta {$group->count()}).";
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages(['promotions' => array_values(array_unique($errors))]);
            }

            foreach ($prepared as $item) {
                $item['student']->placeIntoClassroom(
                    $item['classroom'],
                    $userId,
                    "Naik dari {$item['from_label']} ({$fromYear->name})"
                );
            }

            return count($prepared);
        });
    }

    private function hasActivePlacementIn(Student $student, int $academicYearId): bool
    {
        return ClassroomPlacement::where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @param  Collection<int, Classroom>  $classrooms
     */
    private function findTargetClassroom(Collection $classrooms, int $gradeLevel, string $name): ?Classroom
    {
        return $classrooms->first(
            fn (Classroom $classroom) => (int) $classroom->grade_level === $gradeLevel
                && $classroom->name === $name
        );
    }
}
