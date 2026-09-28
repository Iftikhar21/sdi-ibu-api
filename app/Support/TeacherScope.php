<?php

namespace App\Support;

use App\Models\HomeroomAssignment;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;

/**
 * Pembatas akses untuk role guru.
 *
 * Guru hanya boleh menyentuh kelas/mapel yang ditugaskan kepadanya:
 * - absensi  : kelas yang dia walikan
 * - nilai    : mata pelajaran yang dia ampu
 * - rapor    : kelas yang dia walikan
 * - jadwal   : kelas yang dia walikan atau ampu (hanya melihat)
 *
 * Admin tidak dibatasi (helper ini mengembalikan null untuk admin).
 */
class TeacherScope
{
    public const ROLE = 'guru';

    /** Profil guru dari user yang sedang login (null untuk admin). */
    public static function teacherFor(?User $user): ?Teacher
    {
        if (! $user || ! $user->role || $user->role->role_name !== self::ROLE) {
            return null;
        }

        return Teacher::where('user_id', $user->id)->first();
    }

    /** ID kelas yang dia walikan pada tahun ajaran tertentu. */
    public static function homeroomClassroomIds(?Teacher $teacher, int $academicYearId): array
    {
        if (! $teacher) {
            return [];
        }

        return HomeroomAssignment::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $academicYearId)
            ->pluck('classroom_id')
            ->all();
    }

    /** ID kelas tempat guru mengampu mata pelajaran pada tahun ajaran tertentu. */
    public static function teachingClassroomIds(?Teacher $teacher, int $academicYearId): array
    {
        if (! $teacher) {
            return [];
        }

        return TeachingAssignment::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $academicYearId)
            ->pluck('classroom_id')
            ->unique()
            ->values()
            ->all();
    }

    /** ID mata pelajaran yang diampu guru pada satu kelas & tahun ajaran. */
    public static function subjectIdsFor(?Teacher $teacher, int $classroomId, int $academicYearId): array
    {
        if (! $teacher) {
            return [];
        }

        return TeachingAssignment::where('teacher_id', $teacher->id)
            ->where('classroom_id', $classroomId)
            ->where('academic_year_id', $academicYearId)
            ->pluck('subject_id')
            ->all();
    }
}
