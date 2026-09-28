<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    /**
     * Query dasar daftar siswa beserta filter yang aktif.
     */
    private function filteredQuery(Request $request)
    {
        $search = $request->get('search');
        $status = $request->get('status');
        $admissionYearId = $request->get('admission_year_id');

        $query = Student::with(['admissionYear', 'activePlacement.classroom']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($admissionYearId && $admissionYearId !== 'all') {
            $query->where('admission_year_id', $admissionYearId);
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nis', 'like', "%{$search}%");
            });
        }

        return $query->ordered();
    }

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->filteredQuery($request)->get(),
        ]);
    }

    public function show($id)
    {
        $student = Student::with([
            'admissionYear',
            'registration.academicYear',
            'classHistories.classroom.academicYear',
        ])->find($id);

        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $student,
        ]);
    }

    public function update(Request $request, $id)
    {
        $student = Student::find($id);

        if (! $student) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'nis' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('students', 'nis')->ignore($student->id),
            ],
            'full_name' => 'sometimes|required|string|max:255',
            'gender' => 'nullable|in:L,P',
            'birth_place' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'address' => 'nullable|string',
            'admission_year_id' => 'nullable|integer|exists:academic_years,id',
            'status' => ['sometimes', 'required', Rule::in(array_keys(Student::STATUS_LABELS))],
        ], [
            'nis.unique' => 'NIS sudah dipakai siswa lain.',
            'nis.max' => 'NIS maksimal 30 karakter.',
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'admission_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
            'status.in' => 'Status siswa tidak dikenal.',
        ]);

        $student->fill($validated);

        // NIS kosong disimpan sebagai null, bukan string kosong
        if (array_key_exists('nis', $validated) && ! $validated['nis']) {
            $student->nis = null;
        }

        $student->save();

        return response()->json([
            'success' => true,
            'message' => 'Data siswa berhasil diperbarui',
            'data' => $student->load(['admissionYear', 'activePlacement.classroom']),
        ]);
    }
}
