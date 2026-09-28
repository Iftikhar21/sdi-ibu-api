<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Grade;
use App\Models\HomeroomAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Support\SemesterPeriod;
use App\Support\TeacherScope;
use Illuminate\Http\Request;

/**
 * Rapor sederhana: nilai per mata pelajaran + rekap absensi siswa.
 *
 * Rapor tidak menyimpan data baru — semuanya dirangkum dari nilai (STEP 10)
 * dan absensi (STEP 11) yang sudah ada, difilter per tahun ajaran + semester.
 */
class ReportCardController extends Controller
{
    // Siswa aktif yang terdaftar di sebuah kelas pada tahun ajaran tertentu.
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

    // Rekap absensi siswa pada rentang tanggal tertentu.
    private function attendanceRecap(Student $student, AcademicYear $academicYear, array $period): array
    {
        $records = Attendance::where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->when($period['from'], fn ($query) => $query->whereDate('date', '>=', $period['from']))
            ->when($period['to'], fn ($query) => $query->whereDate('date', '<=', $period['to']))
            ->pluck('status');

        return [
            'hadir' => $records->filter(fn ($status) => $status === Attendance::STATUS_PRESENT)->count(),
            'izin' => $records->filter(fn ($status) => $status === Attendance::STATUS_PERMISSION)->count(),
            'sakit' => $records->filter(fn ($status) => $status === Attendance::STATUS_SICK)->count(),
            'alpa' => $records->filter(fn ($status) => $status === Attendance::STATUS_ABSENT)->count(),
            'total' => $records->count(),
        ];
    }

    public function index(Request $request)
    {
        // start_date & end_date dibutuhkan untuk menentukan rentang semester
        $academicYears = AcademicYear::ordered()
            ->get(['id', 'name', 'is_active', 'start_date', 'end_date']);

        $academicYearId = (int) ($request->get('academic_year_id')
            ?: $academicYears->firstWhere('is_active', true)?->id
            ?: $academicYears->first()?->id);

        $academicYear = $academicYears->firstWhere('id', $academicYearId);

        $semester = (int) ($request->get('semester') ?: 1);

        if (! array_key_exists($semester, Grade::SEMESTERS)) {
            $semester = 1;
        }

        $payload = [
            'academic_years' => $academicYears,
            'academic_year' => $academicYear,
            'classrooms' => [],
            'classroom' => null,
            'semester' => $semester,
            'semesters' => Grade::SEMESTERS,
            'period' => ['from' => null, 'to' => null],
            'students' => [],
            'student' => null,
            'subjects' => [],
            'attendance' => null,
            'average' => null,
            'homeroom_teacher' => null,
        ];

        if (! $academicYear) {
            return response()->json(['success' => true, 'data' => $payload]);
        }

        $period = SemesterPeriod::for($academicYear, $semester);
        $payload['period'] = $period;

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->ordered()
            ->get();

        // Rapor dilihat oleh wali kelas: guru hanya bisa membuka kelasnya sendiri
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher) {
            $allowed = TeacherScope::homeroomClassroomIds($teacher, $academicYear->id);

            $classrooms = $classrooms->whereIn('id', $allowed)->values();
        }

        $payload['classrooms'] = $classrooms->map(fn (Classroom $item) => [
            'id' => $item->id,
            'display_name' => $item->display_name,
            'grade_level' => $item->grade_level,
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

        $students = $this->studentsOf($classroom, $academicYear);

        $nilai = Grade::where('academic_year_id', $academicYear->id)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->get();

        // Daftar siswa + rata-rata sederhana (bukan peringkat)
        $payload['students'] = $students->map(function (Student $student) use ($nilai) {
            $milik = $nilai->where('student_id', $student->id);

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                'filled' => $milik->count(),
                'average' => $milik->count() > 0
                    ? round((float) $milik->avg('score'), 2)
                    : null,
            ];
        })->values();

        $studentId = (int) $request->get('student_id');
        $student = $students->firstWhere('id', $studentId);

        if (! $student) {
            return response()->json(['success' => true, 'data' => $payload]);
        }

        // Mata pelajaran yang berlaku untuk tingkat kelas ini, lengkap dengan nilainya
        $subjects = Subject::active()
            ->forGrade((int) $classroom->grade_level)
            ->ordered()
            ->get();

        $nilaiSiswa = $nilai->where('student_id', $student->id)->keyBy('subject_id');

        $payload['student'] = [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'nis' => $student->nis,
            'classroom' => $classroom->display_name,
        ];

        $payload['subjects'] = $subjects->map(function (Subject $subject) use ($nilaiSiswa) {
            $grade = $nilaiSiswa->get($subject->id);

            return [
                'subject_id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'score' => $grade?->score,
            ];
        })->values();

        $terisi = $payload['subjects']->whereNotNull('score');

        $payload['average'] = $terisi->count() > 0
            ? round((float) $terisi->avg('score'), 2)
            : null;

        $payload['attendance'] = $this->attendanceRecap($student, $academicYear, $period);

        // Wali kelas diambil dari penugasan guru (STEP 16) bila sudah diatur
        $payload['homeroom_teacher'] = HomeroomAssignment::with('teacher')
            ->where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->first()?->teacher?->name;

        return response()->json(['success' => true, 'data' => $payload]);
    }
}
