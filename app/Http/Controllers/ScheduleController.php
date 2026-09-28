<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\HomeroomAssignment;
use App\Models\Schedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Support\TeacherScope;
use Illuminate\Http\Request;

/**
 * Jadwal pelajaran per kelas dan tahun ajaran.
 *
 * Guru diambil dari Master Guru & Tenaga Kependidikan, mata pelajaran dari
 * Master Mata Pelajaran. Bentrok jam guru maupun kelas divalidasi di backend.
 */
class ScheduleController extends Controller
{
    /**
     * Cari jadwal yang jamnya bertumpuk dengan data yang akan disimpan.
     */
    private function findConflict(array $data, ?int $ignoreId = null): ?Schedule
    {
        return Schedule::with(['teacher', 'subject', 'classroom'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('day', $data['day'])
            // Rentang jam bertumpuk: mulai < selesai milik data lain
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->where(function ($query) use ($data) {
                $query->where('teacher_id', $data['teacher_id'])
                    ->orWhere('classroom_id', $data['classroom_id']);
            })
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();
    }

    /**
     * Validasi bersama untuk simpan dan ubah.
     */
    private function validateSchedule(Request $request, ?int $ignoreId = null)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'teacher_id' => 'required|integer|exists:teachers,id',
            'day' => 'required|in:senin,selasa,rabu,kamis,jumat,sabtu',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ], [
            'day.required' => 'Hari wajib dipilih.',
            'day.in' => 'Hari tidak dikenal.',
            'start_time.date_format' => 'Jam mulai harus format HH:MM.',
            'end_time.date_format' => 'Jam selesai harus format HH:MM.',
            'end_time.after' => 'Jam selesai harus lebih akhir dari jam mulai.',
        ]);

        $academicYear = AcademicYear::find($validated['academic_year_id']);
        $classroom = Classroom::find($validated['classroom_id']);
        $subject = Subject::find($validated['subject_id']);
        $teacher = Teacher::find($validated['teacher_id']);

        if ((int) $classroom->academic_year_id !== (int) $academicYear->id) {
            return ['error' => "Kelas {$classroom->display_name} bukan milik tahun ajaran "
                ."{$academicYear->name}."];
        }

        if (! $subject->is_active) {
            return ['error' => "Mata pelajaran {$subject->name} sedang tidak aktif."];
        }

        if ($subject->grade_level !== null
            && (int) $subject->grade_level !== (int) $classroom->grade_level) {
            return ['error' => "Mata pelajaran {$subject->name} tidak berlaku untuk tingkat "
                ."{$classroom->grade_level}."];
        }

        if (! $teacher->is_active) {
            return ['error' => "Guru {$teacher->name} sedang tidak aktif."];
        }

        // Jam selesai harus lebih akhir (dibandingkan sebagai jam, bukan string)
        if (strtotime((string) $validated['end_time']) <= strtotime((string) $validated['start_time'])) {
            return ['error' => 'Jam selesai harus lebih akhir dari jam mulai.'];
        }

        $conflict = $this->findConflict($validated, $ignoreId);

        if ($conflict) {
            $jam = Schedule::jam($conflict->start_time).'–'.Schedule::jam($conflict->end_time);
            $hari = Schedule::DAYS[$conflict->day] ?? $conflict->day;

            if ((int) $conflict->teacher_id === (int) $validated['teacher_id']) {
                return ['error' => "Guru {$teacher->name} sudah mengajar di kelas "
                    ."{$conflict->classroom?->display_name} pada {$hari} jam {$jam}."];
            }

            return ['error' => "Kelas {$classroom->display_name} sudah punya jadwal "
                ."{$conflict->subject?->name} pada {$hari} jam {$jam}."];
        }

        return ['data' => $validated];
    }

    /**
     * Daftar jadwal + data pendukung untuk filter.
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
            'teachers' => [],
            'subjects' => [],
            'homeroom_teacher' => null,
            'subject_teachers' => [],
            'days' => Schedule::DAYS,
            'schedules' => [],
        ];

        if (! $academicYear) {
            return response()->json(['success' => true, 'data' => $empty]);
        }

        $classrooms = Classroom::where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->ordered()
            ->get();

        // Guru hanya melihat jadwal kelas yang dia walikan atau ampu
        $teacher = TeacherScope::teacherFor($request->user());

        if ($teacher) {
            $allowed = array_unique(array_merge(
                TeacherScope::homeroomClassroomIds($teacher, $academicYear->id),
                TeacherScope::teachingClassroomIds($teacher, $academicYear->id)
            ));

            $classrooms = $classrooms->whereIn('id', $allowed)->values();
        }

        $empty['classrooms'] = $classrooms->map(fn (Classroom $item) => [
            'id' => $item->id,
            'display_name' => $item->display_name,
            'grade_level' => $item->grade_level,
        ])->values();

        $classroom = $classrooms->firstWhere('id', (int) $request->get('classroom_id'));

        if (! $classroom) {
            return response()->json(['success' => true, 'data' => $empty]);
        }

        $day = $request->get('day');

        // Guru pengampu per mata pelajaran dari penugasan (STEP 16), dipakai
        // untuk mengisi otomatis pilihan guru saat menambah jadwal.
        $subjectTeachers = TeachingAssignment::where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->pluck('teacher_id', 'subject_id');

        $homeroomTeacher = HomeroomAssignment::with('teacher')
            ->where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->first()?->teacher?->name;

        $schedules = Schedule::with(['subject', 'teacher', 'classroom'])
            ->where('academic_year_id', $academicYear->id)
            ->where('classroom_id', $classroom->id)
            ->when($day && $day !== 'all', fn ($query) => $query->where('day', $day))
            ->get()
            ->sortBy(fn (Schedule $schedule) => array_search($schedule->day, array_keys(Schedule::DAYS), true)
                .'-'.$schedule->start_time)
            ->values();

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
                'teachers' => Teacher::where('is_active', true)->ordered()->get(['id', 'name', 'position']),
                'subjects' => Subject::active()
                    ->forGrade((int) $classroom->grade_level)
                    ->ordered()
                    ->get(['id', 'code', 'name', 'grade_level']),
                'homeroom_teacher' => $homeroomTeacher,
                'subject_teachers' => $subjectTeachers,
                'days' => Schedule::DAYS,
                'schedules' => $schedules->map(fn (Schedule $schedule) => [
                    'id' => $schedule->id,
                    'day' => $schedule->day,
                    'day_label' => $schedule->day_label,
                    'start_time' => $schedule->start_label,
                    'end_time' => $schedule->end_label,
                    'subject_id' => $schedule->subject_id,
                    'subject' => $schedule->subject?->name,
                    'subject_code' => $schedule->subject?->code,
                    'teacher_id' => $schedule->teacher_id,
                    'teacher' => $schedule->teacher?->name,
                    'classroom_id' => $schedule->classroom_id,
                    'classroom' => $schedule->classroom?->display_name,
                ]),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $result = $this->validateSchedule($request);

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        $schedule = Schedule::create($result['data']);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil ditambahkan',
            'data' => $schedule->load(['subject', 'teacher', 'classroom']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $schedule = Schedule::find($id);

        if (! $schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan',
            ], 404);
        }

        $result = $this->validateSchedule($request, $schedule->id);

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        $schedule->update($result['data']);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil diperbarui',
            'data' => $schedule->load(['subject', 'teacher', 'classroom']),
        ]);
    }

    public function destroy($id)
    {
        $schedule = Schedule::find($id);

        if (! $schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan',
            ], 404);
        }

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil dihapus',
        ]);
    }
}
