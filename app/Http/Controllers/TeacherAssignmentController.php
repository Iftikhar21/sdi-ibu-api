<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\HomeroomAssignment;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan guru: wali kelas dan guru pengampu mata pelajaran.
 *
 * Guru diambil dari Master Guru & Tenaga Kependidikan dan mata pelajaran dari
 * Master Mata Pelajaran — tidak ada data guru/mapel baru di sini.
 * Setiap penugasan terikat tahun ajaran sehingga riwayat tahun sebelumnya aman.
 */
class TeacherAssignmentController extends Controller
{
    /**
     * Data penugasan pada satu kelas + tahun ajaran.
     */
    public function index(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $academicYearId = (int) ($request->get('academic_year_id')
            ?: $academicYears->firstWhere('is_active', true)?->id
            ?: $academicYears->first()?->id);

        $academicYear = $academicYears->firstWhere('id', $academicYearId);

        $payload = [
            'academic_years' => $academicYears,
            'academic_year' => $academicYear,
            'classrooms' => [],
            'classroom' => null,
            'teachers' => [],
            'homeroom' => null,
            'subjects' => [],
        ];

        if (! $academicYear) {
            return response()->json(['success' => true, 'data' => $payload]);
        }

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->withCount('activePlacements')
            ->ordered()
            ->get();

        $payload['classrooms'] = $classrooms->map(fn (Classroom $item) => [
            'id' => $item->id,
            'display_name' => $item->display_name,
            'grade_level' => $item->grade_level,
            'filled' => $item->filled_count,
        ])->values();

        // Guru aktif untuk dropdown, diurutkan sesuai Master Guru
        $teachers = Teacher::where('is_active', true)->ordered()->get(['id', 'name', 'position']);

        $payload['teachers'] = $teachers->map(fn (Teacher $teacher) => [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'position' => $teacher->position,
        ])->values();

        $classroom = $classrooms->firstWhere('id', (int) $request->get('classroom_id'));

        if (! $classroom) {
            return response()->json(['success' => true, 'data' => $payload]);
        }

        $payload['classroom'] = [
            'id' => $classroom->id,
            'display_name' => $classroom->display_name,
            'grade_level' => $classroom->grade_level,
        ];

        $homeroom = HomeroomAssignment::with('teacher')
            ->where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->first();

        $payload['homeroom'] = $homeroom ? [
            'id' => $homeroom->id,
            'teacher_id' => $homeroom->teacher_id,
            'teacher' => $homeroom->teacher?->name,
        ] : null;

        // Daftar mata pelajaran yang berlaku untuk tingkat kelas ini,
        // lengkap dengan guru pengampu yang sudah ditugaskan (bila ada)
        $subjects = Subject::active()
            ->forGrade((int) $classroom->grade_level)
            ->ordered()
            ->get();

        $assignments = TeachingAssignment::with('teacher')
            ->where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->get()
            ->keyBy('subject_id');

        $payload['subjects'] = $subjects->map(function (Subject $subject) use ($assignments) {
            $assignment = $assignments->get($subject->id);

            return [
                'subject_id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'teacher_id' => $assignment?->teacher_id,
                'teacher' => $assignment?->teacher?->name,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $payload]);
    }

    /**
     * Simpan / kosongkan wali kelas untuk satu kelas pada satu tahun ajaran.
     */
    public function storeHomeroom(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $classroom = Classroom::findOrFail($validated['classroom_id']);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'success' => false,
                'message' => "Kelas {$classroom->display_name} bukan milik tahun ajaran {$academicYear->name}.",
            ], 422);
        }

        $teacherId = $validated['teacher_id'] ?? null;

        if ($teacherId) {
            $teacher = Teacher::find($teacherId);

            if (! $teacher->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => "Guru {$teacher->name} sedang tidak aktif.",
                ], 422);
            }

            // Satu guru tidak boleh menjadi wali kelas di dua kelas pada tahun yang sama
            $bentrok = HomeroomAssignment::with('classroom')
                ->where('academic_year_id', $academicYear->id)
                ->where('teacher_id', $teacherId)
                ->where('classroom_id', '!=', $classroom->id)
                ->first();

            if ($bentrok) {
                return response()->json([
                    'success' => false,
                    'message' => "Guru {$teacher->name} sudah menjadi wali kelas "
                        ."{$bentrok->classroom?->display_name} pada tahun ajaran {$academicYear->name}.",
                ], 422);
            }
        }

        $homeroom = DB::transaction(function () use ($academicYear, $classroom, $teacherId) {
            $existing = HomeroomAssignment::where('academic_year_id', $academicYear->id)
                ->where('classroom_id', $classroom->id)
                ->first();

            // Dikosongkan admin -> hapus penugasan wali kelas
            if (! $teacherId) {
                $existing?->delete();

                return null;
            }

            return HomeroomAssignment::updateOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'classroom_id' => $classroom->id,
                ],
                ['teacher_id' => $teacherId]
            );
        });

        return response()->json([
            'success' => true,
            'message' => $homeroom
                ? 'Wali kelas berhasil disimpan.'
                : 'Wali kelas berhasil dikosongkan.',
            'data' => $homeroom?->load('teacher'),
        ]);
    }

    /**
     * Simpan guru pengampu untuk beberapa mata pelajaran sekaligus.
     *
     * Dipakai juga untuk mengosongkan pengampu (teacher_id null).
     */
    public function storeTeaching(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'assignments' => 'present|array',
            'assignments.*.subject_id' => 'required|integer|exists:subjects,id',
            'assignments.*.teacher_id' => 'nullable|integer|exists:teachers,id',
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $classroom = Classroom::findOrFail($validated['classroom_id']);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'success' => false,
                'message' => "Kelas {$classroom->display_name} bukan milik tahun ajaran {$academicYear->name}.",
            ], 422);
        }

        $subjects = Subject::whereIn(
            'id',
            collect($validated['assignments'])->pluck('subject_id')->unique()
        )->get()->keyBy('id');

        $teachers = Teacher::whereIn(
            'id',
            collect($validated['assignments'])->pluck('teacher_id')->filter()->unique()
        )->get()->keyBy('id');

        $errors = [];
        $prepared = [];
        $seen = [];

        foreach ($validated['assignments'] as $index => $item) {
            $baris = $index + 1;
            $subject = $subjects->get($item['subject_id']);

            if (isset($seen[$item['subject_id']])) {
                $errors[] = "Baris {$baris}: mata pelajaran tercantum lebih dari sekali.";

                continue;
            }

            $seen[$item['subject_id']] = true;

            if (! $subject) {
                $errors[] = "Baris {$baris}: mata pelajaran tidak ditemukan.";

                continue;
            }

            if (! $subject->is_active) {
                $errors[] = "Baris {$baris}: mata pelajaran {$subject->name} sedang tidak aktif.";

                continue;
            }

            $berlaku = $subject->grade_level === null
                || (int) $subject->grade_level === (int) $classroom->grade_level;

            if (! $berlaku) {
                $errors[] = "Baris {$baris}: mata pelajaran {$subject->name} tidak berlaku untuk "
                    ."tingkat {$classroom->grade_level}.";

                continue;
            }

            $teacherId = $item['teacher_id'] ?? null;

            if ($teacherId) {
                $teacher = $teachers->get($teacherId);

                if (! $teacher || ! $teacher->is_active) {
                    $errors[] = "Baris {$baris}: guru tidak ditemukan atau sedang tidak aktif.";

                    continue;
                }
            }

            $prepared[] = [
                'subject_id' => $subject->id,
                'teacher_id' => $teacherId,
            ];
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' baris yang perlu diperbaiki. '
                    .'Tidak ada penugasan yang disimpan.',
                'errors' => ['assignments' => $errors],
            ], 422);
        }

        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($prepared, $academicYear, $classroom, &$saved, &$cleared) {
            foreach ($prepared as $item) {
                $existing = TeachingAssignment::where('academic_year_id', $academicYear->id)
                    ->where('classroom_id', $classroom->id)
                    ->where('subject_id', $item['subject_id'])
                    ->first();

                if (! $item['teacher_id']) {
                    if ($existing) {
                        $existing->delete();
                        $cleared++;
                    }

                    continue;
                }

                TeachingAssignment::updateOrCreate(
                    [
                        'academic_year_id' => $academicYear->id,
                        'classroom_id' => $classroom->id,
                        'subject_id' => $item['subject_id'],
                    ],
                    ['teacher_id' => $item['teacher_id']]
                );

                $saved++;
            }
        });

        $message = "Guru pengampu tersimpan: {$saved}.";

        if ($cleared > 0) {
            $message .= " Penugasan yang dikosongkan dihapus: {$cleared}.";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['saved' => $saved, 'cleared' => $cleared],
        ]);
    }
}
