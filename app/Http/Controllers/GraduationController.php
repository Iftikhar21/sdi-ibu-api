<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ClassroomPlacement;
use App\Models\Graduation;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kelulusan siswa kelas 6.
 *
 * Data lulusan tidak diinput ulang: siswa yang sudah ada diangkat statusnya
 * menjadi GRADUATED, dan identitasnya tetap tersimpan di tabel students.
 */
class GraduationController extends Controller
{
    /**
     * Tahun ajaran yang dipakai sebagai default halaman kelulusan.
     */
    private function defaultAcademicYearId(): ?int
    {
        return AcademicYear::where('is_active', true)->value('id')
            ?? AcademicYear::ordered()->value('id');
    }

    /**
     * Siswa aktif kelas 6 pada tahun ajaran tertentu yang belum lulus.
     *
     * @return \Illuminate\Support\Collection<int, Student>
     */
    private function candidates(int $academicYearId)
    {
        return Student::query()
            ->with(['classHistories.classroom'])
            ->where('status', Student::STATUS_ACTIVE)
            ->whereDoesntHave('graduation')
            ->whereHas('classHistories', function ($query) use ($academicYearId) {
                $query->where('academic_year_id', $academicYearId)
                    ->where('is_active', true)
                    ->whereHas('classroom', function ($classroomQuery) {
                        $classroomQuery->where('grade_level', 6);
                    });
            })
            ->ordered()
            ->get();
    }

    /**
     * Kelas tingkat 6 pada tahun ajaran tersebut untuk siswa ini.
     */
    private function gradeSixPlacement(Student $student, int $academicYearId): ?ClassroomPlacement
    {
        return ClassroomPlacement::with('classroom')
            ->where('student_registration_id', $student->registration_id)
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->whereHas('classroom', fn ($query) => $query->where('grade_level', 6))
            ->first();
    }

    /**
     * Daftar calon lulusan, dikelompokkan per kelas.
     */
    public function index(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $academicYearId = (int) ($request->get('academic_year_id') ?: $this->defaultAcademicYearId());
        $academicYear = $academicYears->firstWhere('id', $academicYearId);

        if (! $academicYear) {
            return response()->json([
                'success' => true,
                'data' => [
                    'academic_years' => $academicYears,
                    'academic_year' => null,
                    'total' => 0,
                    'groups' => [],
                ],
            ]);
        }

        $groups = $this->candidates($academicYearId)
            ->map(function (Student $student) use ($academicYearId) {
                $placement = $student->classHistories->firstWhere('academic_year_id', $academicYearId);

                return [
                    'student' => $student,
                    'classroom' => $placement?->classroom,
                ];
            })
            ->groupBy(fn ($item) => $item['classroom']?->id ?? 0)
            ->map(function ($items) {
                $classroom = $items->first()['classroom'];

                return [
                    'classroom_id' => $classroom?->id,
                    'classroom_label' => $classroom?->display_name
                        ?? ($classroom ? $classroom->grade_level.$classroom->name : 'Tanpa Kelas'),
                    'students' => $items
                        ->map(fn ($item) => [
                            'id' => $item['student']->id,
                            'full_name' => $item['student']->full_name,
                            'nis' => $item['student']->nis,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('classroom_label')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'academic_years' => $academicYears,
                'academic_year' => $academicYear,
                'total' => $groups->sum(fn ($group) => count($group['students'])),
                'groups' => $groups,
            ],
        ]);
    }

    /**
     * Proses kelulusan massal untuk siswa yang dipilih.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'integer|distinct|exists:students,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'graduation_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ], [
            'student_ids.required' => 'Pilih minimal satu siswa.',
            'student_ids.*.exists' => 'Ada siswa yang tidak ditemukan.',
            'academic_year_id.required' => 'Tahun kelulusan wajib dipilih.',
            'academic_year_id.exists' => 'Tahun kelulusan tidak ditemukan.',
        ]);

        $academicYearId = (int) $validated['academic_year_id'];
        $academicYear = AcademicYear::find($academicYearId);
        $errors = [];
        $students = [];

        foreach ($validated['student_ids'] as $studentId) {
            $student = Student::find($studentId);

            if (! $student) {
                $errors[] = "Siswa #{$studentId} tidak ditemukan.";

                continue;
            }

            if ($student->status === Student::STATUS_GRADUATED || $student->graduation()->exists()) {
                $errors[] = "{$student->full_name} sudah dinyatakan lulus sebelumnya.";

                continue;
            }

            if ($student->status !== Student::STATUS_ACTIVE) {
                $errors[] = "{$student->full_name} tidak berstatus aktif.";

                continue;
            }

            if (! $this->gradeSixPlacement($student, $academicYearId)) {
                $errors[] = "{$student->full_name} tidak tercatat di tingkat 6 pada tahun ajaran "
                    .($academicYear->name ?? '-').'.';

                continue;
            }

            $students[] = $student;
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' siswa yang belum bisa diproses. '
                    .'Tidak ada status yang diubah.',
                'errors' => $errors,
            ], 422);
        }

        $processedBy = $request->user()?->id;

        DB::transaction(function () use ($students, $academicYearId, $validated, $processedBy) {
            foreach ($students as $student) {
                Graduation::create([
                    'student_id' => $student->id,
                    'graduation_year_id' => $academicYearId,
                    'graduation_date' => $validated['graduation_date'] ?? null,
                    'processed_by' => $processedBy,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $student->update(['status' => Student::STATUS_GRADUATED]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($students).' siswa berhasil ditetapkan sebagai lulusan '
                .($academicYear->name ?? '').'.',
            'data' => [
                'graduated' => count($students),
            ],
        ], 201);
    }

    /**
     * Daftar lulusan (dengan filter tahun kelulusan dan pencarian).
     */
    public function graduates(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);
        $academicYearId = $request->get('academic_year_id');
        $search = $request->get('search');

        $query = Graduation::query()
            ->with(['graduationYear', 'student.admissionYear', 'student.classHistories.classroom'])
            ->join('students', 'students.id', '=', 'graduations.student_id')
            ->select('graduations.*')
            ->orderBy('students.full_name');

        if ($academicYearId && $academicYearId !== 'all') {
            $query->where('graduations.graduation_year_id', $academicYearId);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('students.full_name', 'like', "%{$search}%")
                    ->orWhere('students.nis', 'like', "%{$search}%");
            });
        }

        $graduations = $query->get()->map(function (Graduation $graduation) {
            $student = $graduation->student;
            $placement = $student?->lastClassroomInYear($graduation->graduation_year_id);

            return [
                'id' => $graduation->id,
                'student_id' => $student?->id,
                'full_name' => $student?->full_name,
                'nis' => $student?->nis,
                'gender' => $student?->gender,
                'admission_year' => $student?->admissionYear?->name,
                'last_class' => $placement?->classroom?->display_name,
                'graduation_year_id' => $graduation->graduation_year_id,
                'graduation_year' => $graduation->graduationYear?->name,
                'graduation_date' => $graduation->graduation_date?->toDateString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'academic_years' => $academicYears,
                'total' => $graduations->count(),
                'graduates' => $graduations,
            ],
        ]);
    }

    /**
     * Batalkan kelulusan: siswa kembali aktif dan bisa diproses ulang.
     *
     * Data siswa (identitas, riwayat kelas, pendaftaran) tidak dihapus.
     */
    public function cancel(Request $request, $studentId)
    {
        $student = Student::with('graduation')->find($studentId);

        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan',
            ], 404);
        }

        if (! $student->graduation) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa ini belum memiliki data kelulusan.',
            ], 422);
        }

        DB::transaction(function () use ($student) {
            $student->graduation->delete();
            $student->update(['status' => Student::STATUS_ACTIVE]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Kelulusan '.$student->full_name.' dibatalkan. Siswa kembali berstatus aktif.',
            'data' => [
                'student_id' => $student->id,
                'status' => Student::STATUS_ACTIVE,
            ],
        ]);
    }
}
