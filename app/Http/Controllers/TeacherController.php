<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Teacher::ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Data guru tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $teacher]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'required|in:L,P',
            'last_education' => 'nullable|string|max:150',
            'position' => 'nullable|string|max:150',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]*$/'],
            'address' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'gender.required' => 'Jenis kelamin wajib dipilih.',
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
            'phone.regex' => 'No. telepon hanya boleh berisi angka, spasi, dan simbol + - ( ).',
        ]);

        $upload = $request->hasFile('photo')
            ? ImageUploader::store($request->file('photo'), 'profile/teachers')
            : ['path' => null, 'thumb' => null];

        $teacher = Teacher::create([
            'name' => $validated['name'],
            'gender' => $validated['gender'],
            'last_education' => $validated['last_education'] ?? null,
            'position' => $validated['position'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'photo' => $upload['path'],
            'thumb' => $upload['thumb'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data guru berhasil ditambahkan',
            'data' => $teacher,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Data guru tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'gender' => 'sometimes|required|in:L,P',
            'last_education' => 'nullable|string|max:150',
            'position' => 'nullable|string|max:150',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]*$/'],
            'address' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
            'phone.regex' => 'No. telepon hanya boleh berisi angka, spasi, dan simbol + - ( ).',
        ]);

        foreach (['name', 'gender', 'last_education', 'position', 'phone', 'address'] as $field) {
            if ($request->has($field)) {
                $teacher->{$field} = $request->input($field);
            }
        }

        if ($request->has('sort_order')) {
            $teacher->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $teacher->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('photo')) {
            ImageUploader::delete($teacher->photo, $teacher->thumb);

            $upload = ImageUploader::store($request->file('photo'), 'profile/teachers');
            $teacher->photo = $upload['path'];
            $teacher->thumb = $upload['thumb'];
        }

        $teacher->save();

        return response()->json([
            'success' => true,
            'message' => 'Data guru berhasil diperbarui',
            'data' => $teacher,
        ]);
    }

    public function destroy($id)
    {
        $teacher = Teacher::find($id);

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Data guru tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($teacher->photo, $teacher->thumb);
        $teacher->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data guru berhasil dihapus',
        ]);
    }
}
