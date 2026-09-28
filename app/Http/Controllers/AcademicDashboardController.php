<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Grade;
use App\Models\Student;
use App\Support\SemesterPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Dashboard akademik: ringkasan siswa, kelas, nilai, dan absensi per tahun ajaran.
 *
 * Hanya membaca data yang sudah ada (kelas, penempatan, nilai, absensi) —
 * tidak menyimpan atau mengubah apa pun.
 */
class AcademicDashboardController extends Controller
{
    private function summarizeAttendance(Collection $statuses): array
    {
        $hadir = $statuses->filter(fn ($status) => $status === Attendance::STATUS_PRESENT)->count();
        $izin = $statuses->filter(fn ($status) => $status === Attendance::STATUS_PERMISSION)->count();
        $sakit = $statuses->filter(fn ($status) => $status === Attendance::STATUS_SICK)->count();
        $alpa = $statuses->filter(fn ($status) => $status === Attendance::STATUS_ABSENT)->count();
        $total = $statuses->count();

        return [
            'hadir' => $hadir,
            'izin' => $izin,
            'sakit' => $sakit,
            'alpa' => $alpa,
            'total' => $total,
            'rate' => $total > 0 ? round(($hadir / $total) * 100, 1) : null,
        ];
    }

    public function index(Request $request)
    {
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
            'semester' => $semester,
            'semesters' => Grade::SEMESTERS,
            'period' => ['from' => null, 'to' => null],
            'classrooms' => [],
            'classroom' => null,
            'students' => [
                'active_in_year' => 0,
                'active_total' => 0,
                'inactive' => 0,
                'graduated' => 0,
            ],
            'unplaced_students' => 0,
            'classes' => [
                'total' => 0,
                'capacity' => 0,
                'filled' => 0,
                'available' => 0,
                'students' => 0,
                'rows' => [],
            ],
            'grades' => [
                'filled' => 0,
                'average' => null,
                'students_without_score' => 0,
                'by_subject' => [],
                'by_class' => [],
            ],
            'attendance' => [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
                'rate' => null,
                'by_class' => [],
            ],
        ];

        // Ringkasan status siswa (global)
        $statusCounts = Student::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $payload['students']['active_total'] = (int) ($statusCounts[Student::STATUS_ACTIVE] ?? 0);
        $payload['students']['inactive'] = (int) ($statusCounts[Student::STATUS_INACTIVE] ?? 0);
        $payload['students']['graduated'] = (int) ($statusCounts[Student::STATUS_GRADUATED] ?? 0);

        if (! $academicYear) {
            return response()->json(['success' => true, 'data' => $payload]);
        }

        $period = SemesterPeriod::for($academicYear, $semester);
        $payload['period'] = $period;

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->withCount('activePlacements')
            ->ordered()
            ->get();

        $payload['classrooms'] = $classrooms->map(fn (Classroom $item) => [
            'id' => $item->id,
            'display_name' => $item->display_name,
            'grade_level' => $item->grade_level,
        ])->values();

        $classroom = $classrooms->firstWhere('id', (int) $request->get('classroom_id'));
        $payload['classroom'] = $classroom ? [
            'id' => $classroom->id,
            'display_name' => $classroom->display_name,
            'grade_level' => $classroom->grade_level,
        ] : null;

        $classroomIds = $classroom ? [$classroom->id] : $classrooms->pluck('id')->all();

        // Penempatan aktif pada tahun ajaran ini (dibatasi kelas bila difilter)
        $placements = ClassroomPlacement::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->whereIn('classroom_id', $classroomIds)
            ->get(['id', 'classroom_id', 'student_id']);

        $payload['students']['active_in_year'] = $placements
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique()
            ->count();

        // Rekap kelas: kapasitas, terisi (kuota), dan jumlah siswa
        $classRows = $classrooms
            ->when($classroom, fn (Collection $items) => $items->where('id', $classroom->id))
            ->map(function (Classroom $item) use ($placements) {
                $milik = $placements->where('classroom_id', $item->id);

                return [
                    'id' => $item->id,
                    'display_name' => $item->display_name,
                    'grade_level' => $item->grade_level,
                    'quota' => $item->quota,
                    'filled' => $milik->count(),
                    'available' => max(0, $item->quota - $milik->count()),
                    'students' => $milik->whereNotNull('student_id')->pluck('student_id')->unique()->count(),
                ];
            })
            ->values();

        $payload['classes'] = [
            'total' => $classRows->count(),
            'capacity' => (int) $classRows->sum('quota'),
            'filled' => (int) $classRows->sum('filled'),
            'available' => (int) $classRows->sum('available'),
            'students' => (int) $classRows->sum('students'),
            'rows' => $classRows->all(),
        ];

        // Siswa aktif yang pendaftarannya pada tahun ini tetapi belum punya kelas
        $payload['unplaced_students'] = Student::where('status', Student::STATUS_ACTIVE)
            ->whereHas('registration', function ($query) use ($academicYear) {
                $query->where('academic_year_id', $academicYear->id);
            })
            ->whereDoesntHave('activePlacement', function ($query) use ($academicYear) {
                $query->where('academic_year_id', $academicYear->id);
            })
            ->count();

        $studentIds = $placements->whereNotNull('student_id')->pluck('student_id')->unique();

        // Rekap nilai pada tahun ajaran + semester terpilih
        $grades = Grade::with('subject')
            ->where('academic_year_id', $academicYear->id)
            ->where('semester', $semester)
            ->whereIn('student_id', $studentIds)
            ->get();

        $payload['grades']['filled'] = $grades->count();
        $payload['grades']['average'] = $grades->count() > 0
            ? round((float) $grades->avg('score'), 2)
            : null;

        // Siswa yang sama sekali belum punya nilai pada semester ini
        $punyaNilai = $grades->pluck('student_id')->unique();
        $payload['grades']['students_without_score'] = $studentIds->diff($punyaNilai)->count();

        $payload['grades']['by_subject'] = $grades
            ->groupBy('subject_id')
            ->map(function (Collection $items) {
                $subject = $items->first()->subject;

                return [
                    'subject_id' => $subject?->id,
                    'code' => $subject?->code,
                    'name' => $subject?->name,
                    'filled' => $items->count(),
                    'average' => round((float) $items->avg('score'), 2),
                    'highest' => (float) $items->max('score'),
                    'lowest' => (float) $items->min('score'),
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();

        $payload['grades']['by_class'] = $classRows->map(function (array $row) use ($grades, $placements) {
            $ids = $placements->where('classroom_id', $row['id'])->pluck('student_id')->filter()->unique();
            $milik = $grades->whereIn('student_id', $ids);

            return [
                'classroom_id' => $row['id'],
                'display_name' => $row['display_name'],
                'filled' => $milik->count(),
                'average' => $milik->count() > 0 ? round((float) $milik->avg('score'), 2) : null,
            ];
        })->values()->all();

        // Rekap absensi pada periode semester terpilih
        $attendances = Attendance::where('academic_year_id', $academicYear->id)
            ->whereIn('classroom_id', $classroomIds)
            ->when($period['from'], fn ($query) => $query->whereDate('date', '>=', $period['from']))
            ->when($period['to'], fn ($query) => $query->whereDate('date', '<=', $period['to']))
            ->get(['id', 'classroom_id', 'status']);

        $payload['attendance'] = array_merge(
            $this->summarizeAttendance($attendances->pluck('status')),
            ['by_class' => $classRows->map(function (array $row) use ($attendances) {
                $milik = $attendances->where('classroom_id', $row['id']);

                return array_merge(
                    ['classroom_id' => $row['id'], 'display_name' => $row['display_name']],
                    $this->summarizeAttendance($milik->pluck('status'))
                );
            })->values()->all()]
        );

        return response()->json(['success' => true, 'data' => $payload]);
    }
}
