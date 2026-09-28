<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Akademik dasar: daftar siswa per kelas + mata pelajaran + input nilai.
 *
 * Nilai selalu disimpan sebagai relasi Student + Subject + Academic Year,
 * tidak pernah hanya berdasarkan nama siswa atau nama kelas.
 */
class GradeController extends Controller
{
    public function index(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $academicYearId = (int) ($request->get('academic_year_id')
            ?: $academicYears->firstWhere('is_active', true)?->id
            ?: $academicYears->first()?->id);

        $academicYear = $academicYears->firstWhere('id', $academicYearId);

        if (! $academicYear) {
            return response()->json([
                'success' => true,
                'data' => [
                    'academic_years' => $academicYears,
                    'academic_year' => null,
                    'classrooms' => [],
                    'classroom' => null,
                    'students' => [],
                    'subjects' => [],
                    'grades' => [],
                ],
            ]);
        }

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->withCount('activePlacements')
            ->ordered()
            ->get();

        // Guru hanya melihat kelas tempat ia mengampu mata pelajaran
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher) {
            $allowed = TeacherScope::teachingClassroomIds($teacher, $academicYear->id);

            $classrooms = $classrooms->whereIn('id', $allowed)->values();
        }

        $classroomId = (int) $request->get('classroom_id');
        $classroom = $classrooms->firstWhere('id', $classroomId);

        $semester = (int) ($request->get('semester') ?: 1);

        if (! array_key_exists($semester, Grade::SEMESTERS)) {
            $semester = 1;
        }

        if (! $classroom) {
            return response()->json([
                'success' => true,
                'data' => [
                    'academic_years' => $academicYears,
                    'academic_year' => $academicYear,
                    'classrooms' => $classrooms->map(fn (Classroom $item) => [
                        'id' => $item->id,
                        'display_name' => $item->display_name,
                        'grade_level' => $item->grade_level,
                        'filled' => $item->filled_count,
                    ])->values(),
                    'classroom' => null,
                    'semester' => $semester,
                    'semesters' => Grade::SEMESTERS,
                    'students' => [],
                    'subjects' => [],
                    'grades' => [],
                ],
            ]);
        }

        // Siswa diambil dari penempatan kelas pada tahun ajaran terpilih
        $students = ClassroomPlacement::with('student')
            ->where('classroom_id', $classroom->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->get()
            ->map(fn (ClassroomPlacement $placement) => $placement->student)
            ->filter()
            ->sortBy('full_name')
            ->values();

        // Mata pelajaran yang berlaku untuk tingkat kelas ini
        $subjects = Subject::active()
            ->forGrade((int) $classroom->grade_level)
            ->ordered()
            ->get();

        // Guru hanya melihat mata pelajaran yang diampunya di kelas ini
        if ($teacher) {
            $diampu = TeacherScope::subjectIdsFor($teacher, $classroom->id, $academicYear->id);

            $subjects = $subjects->whereIn('id', $diampu)->values();
        }

        $grades = Grade::where('academic_year_id', $academicYear->id)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->get()
            ->map(fn (Grade $grade) => [
                'student_id' => $grade->student_id,
                'subject_id' => $grade->subject_id,
                'score' => $grade->score,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'academic_years' => $academicYears,
                'academic_year' => $academicYear,
                'classrooms' => $classrooms->map(fn (Classroom $item) => [
                    'id' => $item->id,
                    'display_name' => $item->display_name,
                    'grade_level' => $item->grade_level,
                    'filled' => $item->filled_count,
                ])->values(),
                'classroom' => [
                    'id' => $classroom->id,
                    'display_name' => $classroom->display_name,
                    'grade_level' => $classroom->grade_level,
                ],
                'semester' => $semester,
                'semesters' => Grade::SEMESTERS,
                'students' => $students->map(fn (Student $student) => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'nis' => $student->nis,
                    'status' => $student->status,
                    'status_label' => $student->status_label,
                ])->values(),
                'subjects' => $subjects->map(fn (Subject $subject) => [
                    'id' => $subject->id,
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'grade_level' => $subject->grade_level,
                    'grade_label' => $subject->grade_label,
                ])->values(),
                'grades' => $grades,
            ],
        ]);
    }

    /**
     * Simpan nilai (bulk) untuk satu kelas pada satu tahun ajaran.
     *
     * Nilai kosong (null) menghapus nilai yang sudah ada. Seluruh proses
     * divalidasi dulu, lalu dijalankan dalam satu transaksi (all-or-nothing).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'semester' => 'nullable|integer|in:1,2',
            'scores' => 'present|array',
            'scores.*.student_id' => 'required|integer|exists:students,id',
            'scores.*.subject_id' => 'required|integer|exists:subjects,id',
            'scores.*.score' => 'nullable|numeric|min:0|max:100',
        ], [
            'scores.*.score.min' => 'Nilai tidak boleh kurang dari 0.',
            'scores.*.score.max' => 'Nilai tidak boleh lebih dari 100.',
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $classroom = Classroom::findOrFail($validated['classroom_id']);
        $semester = (int) ($validated['semester'] ?? 1);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'success' => false,
                'message' => "Kelas {$classroom->display_name} bukan milik tahun ajaran {$academicYear->name}.",
            ], 422);
        }

        // Siswa yang benar-benar terdaftar di kelas tersebut
        $studentIds = ClassroomPlacement::where('classroom_id', $classroom->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique();

        $subjects = Subject::whereIn('id', collect($validated['scores'])->pluck('subject_id')->unique())
            ->get()
            ->keyBy('id');

        $errors = [];
        $seen = [];
        $prepared = [];

        // Guru hanya boleh mengisi nilai mata pelajaran yang diampunya
        $teacher = TeacherScope::teacherFor($request->user());
        $subjectDiampu = $teacher
            ? TeacherScope::subjectIdsFor($teacher, $classroom->id, $academicYear->id)
            : null;

        foreach ($validated['scores'] as $index => $item) {
            $baris = $index + 1;
            $student = Student::with('graduation.graduationYear')->find($item['student_id']);
            $subject = $subjects->get($item['subject_id']);

            if (! $student) {
                $errors[] = "Baris {$baris}: siswa tidak ditemukan.";

                continue;
            }

            if (! $studentIds->contains($student->id)) {
                $errors[] = "Baris {$baris}: {$student->full_name} tidak terdaftar di kelas "
                    ."{$classroom->display_name} pada tahun ajaran {$academicYear->name}.";

                continue;
            }

            // Siswa yang sudah lulus tidak bisa dinilai pada tahun ajaran setelah kelulusannya
            $graduationYear = $student->graduation?->graduationYear;

            if ($graduationYear
                && (int) $graduationYear->id !== (int) $academicYear->id
                && $graduationYear->start_date
                && $academicYear->start_date
                && $graduationYear->start_date < $academicYear->start_date) {
                $errors[] = "Baris {$baris}: {$student->full_name} sudah lulus pada tahun ajaran "
                    ."{$graduationYear->name}, jadi tidak bisa dinilai lagi.";

                continue;
            }

            if (! $subject) {
                $errors[] = "Baris {$baris}: mata pelajaran tidak ditemukan.";

                continue;
            }

            if ($subjectDiampu !== null && ! in_array($subject->id, $subjectDiampu, true)) {
                $errors[] = "Baris {$baris}: Anda tidak mengampu mata pelajaran {$subject->name} "
                    ."di kelas {$classroom->display_name}.";

                continue;
            }

            $berlaku = $subject->grade_level === null
                || (int) $subject->grade_level === (int) $classroom->grade_level;

            if (! $berlaku) {
                $errors[] = "Baris {$baris}: mata pelajaran {$subject->name} tidak berlaku untuk "
                    ."tingkat {$classroom->grade_level}.";

                continue;
            }

            $key = $student->id.'|'.$subject->id;

            if (isset($seen[$key])) {
                $errors[] = "Baris {$baris}: nilai {$student->full_name} untuk {$subject->name} "
                    .'tercantum lebih dari sekali.';

                continue;
            }

            $seen[$key] = true;

            $prepared[] = [
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'score' => $item['score'],
            ];
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' baris yang perlu diperbaiki. '
                    .'Tidak ada nilai yang disimpan.',
                'errors' => ['scores' => $errors],
            ], 422);
        }

        $recordedBy = $request->user()?->id;
        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($prepared, $academicYear, $semester, $recordedBy, &$saved, &$cleared) {
            foreach ($prepared as $item) {
                $existing = Grade::where('student_id', $item['student_id'])
                    ->where('subject_id', $item['subject_id'])
                    ->where('academic_year_id', $academicYear->id)
                    ->where('semester', $semester)
                    ->first();

                // Nilai dikosongkan admin -> hapus nilainya
                if ($item['score'] === null || $item['score'] === '') {
                    if ($existing) {
                        $existing->delete();
                        $cleared++;
                    }

                    continue;
                }

                Grade::updateOrCreate(
                    [
                        'student_id' => $item['student_id'],
                        'subject_id' => $item['subject_id'],
                        'academic_year_id' => $academicYear->id,
                        'semester' => $semester,
                    ],
                    [
                        'score' => $item['score'],
                        'recorded_by' => $recordedBy,
                    ]
                );

                $saved++;
            }
        });

        $message = "Nilai tersimpan: {$saved}.";

        if ($cleared > 0) {
            $message .= " Nilai yang dikosongkan dihapus: {$cleared}.";
        }

        return response()->json([
            'success' => true,
            'message' => $message.' (Semester '.$semester.' '
                .(Grade::SEMESTERS[$semester] ?? '').')',
            'data' => [
                'saved' => $saved,
                'cleared' => $cleared,
                'semester' => $semester,
            ],
        ]);
    }
}
