<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Akun login untuk guru (role "guru").
 *
 * Password awal dibuat sistem dan ditampilkan sekali ke admin untuk
 * diteruskan ke guru; guru wajib menggantinya saat login pertama.
 */
class TeacherAccountController extends Controller
{
    private function makePassword(): string
    {
        return Str::password(10, symbols: false);
    }

    /** Buat akun login untuk seorang guru. */
    public function store(Request $request, $id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Data guru tidak ditemukan',
            ], 404);
        }

        if ($teacher->user_id) {
            return response()->json([
                'success' => false,
                'message' => "Guru {$teacher->name} sudah punya akun login. "
                    .'Gunakan Reset Password bila lupa.',
            ], 422);
        }

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
        ], [
            'email.required' => 'Email guru wajib diisi untuk membuat akun.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
        ]);

        $password = $this->makePassword();

        $user = DB::transaction(function () use ($teacher, $validated, $password) {
            $role = Role::firstOrCreate(['role_name' => TeacherScope::ROLE]);

            $user = User::create([
                'name' => $teacher->name,
                'email' => $validated['email'],
                'password' => $password,
                'role_id' => $role->id,
                'must_change_password' => true,
            ]);

            $teacher->update([
                'email' => $validated['email'],
                'user_id' => $user->id,
            ]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => "Akun untuk {$teacher->name} berhasil dibuat. "
                .'Sampaikan password awal ini ke guru — ia wajib menggantinya saat login pertama.',
            'data' => [
                'email' => $user->email,
                'password' => $password,
                'must_change_password' => true,
            ],
        ], 201);
    }

    /** Buat ulang password akun guru. */
    public function resetPassword($id)
    {
        $teacher = Teacher::with('user')->find($id);

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Data guru tidak ditemukan',
            ], 404);
        }

        if (! $teacher->user) {
            return response()->json([
                'success' => false,
                'message' => "Guru {$teacher->name} belum punya akun login.",
            ], 422);
        }

        $password = $this->makePassword();

        $teacher->user->update([
            'password' => $password,
            'must_change_password' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Password {$teacher->name} berhasil dibuat ulang. "
                .'Guru wajib menggantinya saat login.',
            'data' => [
                'email' => $teacher->user->email,
                'password' => $password,
                'must_change_password' => true,
            ],
        ]);
    }

    /**
     * Profil guru yang sedang login beserta penugasannya.
     */
    public function me(Request $request)
    {
        $teacher = TeacherScope::teacherFor($request->user());

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak terhubung ke data guru.',
            ], 403);
        }

        $guru = $teacher->load(['homeroomAssignments.academicYear', 'homeroomAssignments.classroom']);

        $homerooms = $guru->homeroomAssignments->map(fn ($assignment) => [
            'academic_year_id' => $assignment->academic_year_id,
            'academic_year' => $assignment->academicYear?->name,
            'classroom_id' => $assignment->classroom_id,
            'classroom' => $assignment->classroom?->display_name,
        ])->values();

        $teachings = TeachingAssignment::with(['academicYear', 'classroom', 'subject'])
            ->where('teacher_id', $teacher->id)
            ->get()
            ->map(fn ($assignment) => [
                'academic_year_id' => $assignment->academic_year_id,
                'academic_year' => $assignment->academicYear?->name,
                'classroom_id' => $assignment->classroom_id,
                'classroom' => $assignment->classroom?->display_name,
                'subject_id' => $assignment->subject_id,
                'subject' => $assignment->subject?->name,
                'subject_code' => $assignment->subject?->code,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'position' => $teacher->position,
                    'photo_url' => $teacher->photo_url,
                ],
                'homerooms' => $homerooms,
                'teachings' => $teachings,
            ],
        ]);
    }
}
