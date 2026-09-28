<?php

namespace App\Http\Controllers;

use App\Models\OrganizationStructure;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class OrganizationStructureController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => OrganizationStructure::ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $item = OrganizationStructure::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Data struktur organisasi tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'required|string|max:150',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama wajib diisi.',
            'position.required' => 'Jabatan wajib diisi.',
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        $upload = $request->hasFile('photo')
            ? ImageUploader::store($request->file('photo'), 'profile/organization')
            : ['path' => null, 'thumb' => null];

        $item = OrganizationStructure::create([
            'name' => $validated['name'],
            'position' => $validated['position'],
            'photo' => $upload['path'],
            'thumb' => $upload['thumb'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data struktur organisasi berhasil ditambahkan',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $item = OrganizationStructure::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Data struktur organisasi tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'position' => 'sometimes|required|string|max:150',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama wajib diisi.',
            'position.required' => 'Jabatan wajib diisi.',
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        foreach (['name', 'position'] as $field) {
            if ($request->has($field)) {
                $item->{$field} = $request->input($field);
            }
        }

        if ($request->has('sort_order')) {
            $item->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $item->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('photo')) {
            ImageUploader::delete($item->photo, $item->thumb);

            $upload = ImageUploader::store($request->file('photo'), 'profile/organization');
            $item->photo = $upload['path'];
            $item->thumb = $upload['thumb'];
        }

        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Data struktur organisasi berhasil diperbarui',
            'data' => $item,
        ]);
    }

    public function destroy($id)
    {
        $item = OrganizationStructure::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Data struktur organisasi tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($item->photo, $item->thumb);
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data struktur organisasi berhasil dihapus',
        ]);
    }
}
