<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Subject::query();

        if ($request->filled('search')) {
            $search = $request->get('search');

            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('grade_level') && $request->get('grade_level') !== 'all') {
            $query->where('grade_level', (int) $request->get('grade_level'));
        }

        if ($request->filled('is_active') && $request->get('is_active') !== 'all') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $subject = Subject::find($id);

        if (! $subject) {
            return response()->json([
                'success' => false,
                'message' => 'Mata pelajaran tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $subject]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:subjects,code',
            'name' => 'required|string|max:255',
            'grade_level' => 'nullable|integer|between:1,6',
            'is_active' => 'nullable|boolean',
        ], [
            'code.required' => 'Kode mata pelajaran wajib diisi.',
            'code.unique' => 'Kode mata pelajaran sudah dipakai.',
            'name.required' => 'Nama mata pelajaran wajib diisi.',
            'grade_level.between' => 'Tingkat harus antara 1 sampai 6.',
        ]);

        $subject = Subject::create([
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'grade_level' => $validated['grade_level'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mata pelajaran berhasil ditambahkan',
            'data' => $subject,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $subject = Subject::find($id);

        if (! $subject) {
            return response()->json([
                'success' => false,
                'message' => 'Mata pelajaran tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('subjects', 'code')->ignore($subject->id),
            ],
            'name' => 'sometimes|required|string|max:255',
            'grade_level' => 'nullable|integer|between:1,6',
            'is_active' => 'nullable|boolean',
        ], [
            'code.unique' => 'Kode mata pelajaran sudah dipakai.',
            'name.required' => 'Nama mata pelajaran wajib diisi.',
            'grade_level.between' => 'Tingkat harus antara 1 sampai 6.',
        ]);

        if (array_key_exists('code', $validated) && $validated['code']) {
            $subject->code = strtoupper(trim($validated['code']));
        }

        if (array_key_exists('name', $validated) && $validated['name']) {
            $subject->name = trim($validated['name']);
        }

        if ($request->has('grade_level')) {
            $subject->grade_level = $request->input('grade_level') ?: null;
        }

        if ($request->has('is_active')) {
            $subject->is_active = $request->boolean('is_active');
        }

        $subject->save();

        return response()->json([
            'success' => true,
            'message' => 'Mata pelajaran berhasil diperbarui',
            'data' => $subject,
        ]);
    }

    public function destroy($id)
    {
        $subject = Subject::withCount('grades')->find($id);

        if (! $subject) {
            return response()->json([
                'success' => false,
                'message' => 'Mata pelajaran tidak ditemukan',
            ], 404);
        }

        if ($subject->grades_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Mata pelajaran ini sudah dipakai pada {$subject->grades_count} nilai siswa. "
                    .'Nonaktifkan saja agar tidak muncul di input nilai.',
            ], 422);
        }

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Mata pelajaran berhasil dihapus',
        ]);
    }
}
