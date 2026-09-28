<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Student;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Absensi siswa per kelas dan per tanggal.
 *
 * Daftar siswa selalu diambil dari penempatan kelas aktif pada tahun ajaran
 * yang dipilih, bukan dari data pendaftaran.
 */
class AttendanceController extends Controller
{
    /** Siswa aktif yang terdaftar di sebuah kelas pada tahun ajaran tertentu. */
    private function studentsOf(Classroom $classroom, AcademicYear $academicYear)
    {
        return ClassroomPlacement::with('student')
            ->where('classroom_id', $classroom->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->get()
            ->map(fn (ClassroomPlacement $placement) => $placement->student)
            ->filter()
            ->sortBy('full_name')
            ->values();
    }

    /** Ringkasan jumlah per status. */
    private function summarize(iterable $statuses): array
    {
        $total = [
            Attendance::STATUS_PRESENT => 0,
            Attendance::STATUS_PERMISSION => 0,
            Attendance::STATUS_SICK => 0,
            Attendance::STATUS_ABSENT => 0,
        ];

        foreach ($statuses as $status) {
            if (array_key_exists($status, $total)) {
                $total[$status]++;
            }
        }

        return $total;
    }

    /**
     * Data absensi satu kelas pada satu tanggal.
     */
    public function index(Request $request)
    {
        $academicYears = AcademicYear::ordered()->get(['id', 'name', 'is_active']);

        $academicYearId = (int) ($request->get('academic_year_id')
            ?: $academicYears->firstWhere('is_active', true)?->id
            ?: $academicYears->first()?->id);

        $academicYear = $academicYears->firstWhere('id', $academicYearId);

        $empty = [
            'academic_years' => $academicYears,
            'academic_year' => $academicYear,
            'classrooms' => [],
            'classroom' => null,
            'date' => $request->get('date') ?: now()->toDateString(),
            'statuses' => Attendance::STATUSES,
            'students' => [],
            'attendances' => [],
            'summary' => $this->summarize([]),
        ];

        if (! $academicYear) {
            return response()->json(['success' => true, 'data' => $empty]);
        }

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->withCount('activePlacements')
            ->ordered()
            ->get();

        // Absensi adalah tugas wali kelas: guru hanya melihat kelas yang dia walikan
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher) {
            $allowed = TeacherScope::homeroomClassroomIds($teacher, $academicYear->id);

            $classrooms = $classrooms->whereIn('id', $allowed)->values();
        }

        $classroom = $classrooms->firstWhere('id', (int) $request->get('classroom_id'));
        $date = $request->get('date') ?: now()->toDateString();

        $empty['classrooms'] = $classrooms->map(fn (Classroom $item) => [
            'id' => $item->id,
            'display_name' => $item->display_name,
            'grade_level' => $item->grade_level,
            'filled' => $item->filled_count,
        ])->values();
        $empty['date'] = $date;

        if (! $classroom) {
            return response()->json(['success' => true, 'data' => $empty]);
        }

        $students = $this->studentsOf($classroom, $academicYear);

        $attendances = Attendance::where('classroom_id', $classroom->id)
            ->where('academic_year_id', $academicYear->id)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'success' => true,
            'data' => [
                'academic_years' => $academicYears,
                'academic_year' => $academicYear,
                'classrooms' => $empty['classrooms'],
                'classroom' => [
                    'id' => $classroom->id,
                    'display_name' => $classroom->display_name,
                    'grade_level' => $classroom->grade_level,
                ],
                'date' => $date,
                'statuses' => Attendance::STATUSES,
                'students' => $students->map(fn (Student $student) => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'nis' => $student->nis,
                    'status' => $attendances->get($student->id)?->status,
                    'notes' => $attendances->get($student->id)?->notes,
                ])->values(),
                'attendances' => $attendances->values()->map(fn (Attendance $item) => [
                    'student_id' => $item->student_id,
                    'status' => $item->status,
                    'status_label' => $item->status_label,
                    'notes' => $item->notes,
                ]),
                'summary' => $this->summarize($attendances->pluck('status')),
            ],
        ]);
    }

    /**
     * Simpan absensi satu kelas untuk satu tanggal (bulk, all-or-nothing).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'date' => 'required|date',
            'records' => 'present|array',
            'records.*.student_id' => 'required|integer|distinct|exists:students,id',
            'records.*.status' => 'required|in:hadir,izin,sakit,alpa',
            'records.*.notes' => 'nullable|string|max:500',
        ], [
            'date.required' => 'Tanggal absensi wajib dipilih.',
            'records.*.status.in' => 'Status kehadiran tidak dikenal.',
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $classroom = Classroom::findOrFail($validated['classroom_id']);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'success' => false,
                'message' => "Kelas {$classroom->display_name} bukan milik tahun ajaran {$academicYear->name}.",
            ], 422);
        }

        // Guru hanya boleh mengisi absensi kelas yang dia walikan
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher
            && ! in_array(
                $classroom->id,
                TeacherScope::homeroomClassroomIds($teacher, $academicYear->id),
                true
            )) {
            return response()->json([
                'success' => false,
                'message' => "Anda bukan wali kelas {$classroom->display_name} pada tahun ajaran "
                    ."{$academicYear->name}, jadi tidak bisa mengisi absensinya.",
            ], 403);
        }

        // Tanggal harus berada di dalam rentang tahun ajaran
        if ($academicYear->start_date && $academicYear->end_date) {
            if ($validated['date'] < $academicYear->start_date
                || $validated['date'] > $academicYear->end_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tanggal absensi harus berada di dalam rentang tahun ajaran '
                        .$academicYear->name.'.',
                ], 422);
            }
        }

        $studentIds = ClassroomPlacement::where('classroom_id', $classroom->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique();

        $errors = [];
        $prepared = [];

        foreach ($validated['records'] as $index => $item) {
            $baris = $index + 1;
            $student = Student::with('graduation.graduationYear')->find($item['student_id']);

            if (! $student) {
                $errors[] = "Baris {$baris}: siswa tidak ditemukan.";

                continue;
            }

            if (! $studentIds->contains($student->id)) {
                $errors[] = "Baris {$baris}: {$student->full_name} tidak terdaftar di kelas "
                    ."{$classroom->display_name} pada tahun ajaran {$academicYear->name}.";

                continue;
            }

            $graduationYear = $student->graduation?->graduationYear;

            if ($graduationYear
                && (int) $graduationYear->id !== (int) $academicYear->id
                && $graduationYear->start_date
                && $academicYear->start_date
                && $graduationYear->start_date < $academicYear->start_date) {
                $errors[] = "Baris {$baris}: {$student->full_name} sudah lulus pada tahun ajaran "
                    ."{$graduationYear->name}.";

                continue;
            }

            $prepared[] = [
                'student_id' => $student->id,
                'status' => $item['status'],
                'notes' => $item['notes'] ?? null,
            ];
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' baris yang perlu diperbaiki. '
                    .'Tidak ada absensi yang disimpan.',
                'errors' => ['records' => $errors],
            ], 422);
        }

        $recordedBy = $request->user()?->id;

        DB::transaction(function () use ($prepared, $classroom, $academicYear, $validated, $recordedBy) {
            foreach ($prepared as $item) {
                // Cari memakai whereDate agar cocok dengan nilai tanggal di
                // database (MySQL menyimpan sebagai date, SQLite sebagai datetime)
                $attendance = Attendance::where('student_id', $item['student_id'])
                    ->where('academic_year_id', $academicYear->id)
                    ->whereDate('date', $validated['date'])
                    ->first();

                if ($attendance) {
                    $attendance->update([
                        'classroom_id' => $classroom->id,
                        'status' => $item['status'],
                        'notes' => $item['notes'],
                        'recorded_by' => $recordedBy,
                    ]);

                    continue;
                }

                Attendance::create([
                    'student_id' => $item['student_id'],
                    'classroom_id' => $classroom->id,
                    'academic_year_id' => $academicYear->id,
                    'date' => $validated['date'],
                    'status' => $item['status'],
                    'notes' => $item['notes'],
                    'recorded_by' => $recordedBy,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Absensi tersimpan: '.count($prepared).' siswa pada '
                .$validated['date'].'.',
            'data' => [
                'saved' => count($prepared),
                'summary' => $this->summarize(array_column($prepared, 'status')),
            ],
        ]);
    }

    /**
     * Rekap kehadiran per siswa pada periode tertentu.
     */
    public function recap(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ], [
            'to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
        ]);

        $academicYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $classroom = Classroom::findOrFail($validated['classroom_id']);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return response()->json([
                'success' => false,
                'message' => "Kelas {$classroom->display_name} bukan milik tahun ajaran {$academicYear->name}.",
            ], 422);
        }

        // Rekap juga dibatasi untuk kelas yang dia walikan
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher
            && ! in_array(
                $classroom->id,
                TeacherScope::homeroomClassroomIds($teacher, $academicYear->id),
                true
            )) {
            return response()->json([
                'success' => false,
                'message' => "Anda bukan wali kelas {$classroom->display_name} pada tahun ajaran "
                    ."{$academicYear->name}.",
            ], 403);
        }

        $from = $validated['from'] ?? $academicYear->start_date;
        $to = $validated['to'] ?? $academicYear->end_date;

        $students = $this->studentsOf($classroom, $academicYear);

        $query = Attendance::where('academic_year_id', $academicYear->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('date', '<=', $to));

        $records = $query->get();

        $perStudent = $students->map(function (Student $student) use ($records) {
            $milik = $records->where('student_id', $student->id);

            $summary = $this->summarize($milik->pluck('status'));

            return [
                'student_id' => $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                'hadir' => $summary[Attendance::STATUS_PRESENT],
                'izin' => $summary[Attendance::STATUS_PERMISSION],
                'sakit' => $summary[Attendance::STATUS_SICK],
                'alpa' => $summary[Attendance::STATUS_ABSENT],
                'total' => $milik->count(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year' => $academicYear,
                'classroom' => [
                    'id' => $classroom->id,
                    'display_name' => $classroom->display_name,
                ],
                'from' => $from,
                'to' => $to,
                'summary' => $this->summarize($records->pluck('status')),
                'students' => $perStudent,
            ],
        ]);
    }
}
