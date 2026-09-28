<?php

namespace App\Http\Controllers;

use App\Models\AboutSchool;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class AboutSchoolController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => AboutSchool::ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $about = AboutSchool::find($id);

        if (! $about) {
            return response()->json([
                'success' => false,
                'message' => 'Data tentang sekolah tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $about]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        $upload = $request->hasFile('image')
            ? ImageUploader::store($request->file('image'), 'profile/about')
            : ['path' => null, 'thumb' => null];

        $about = AboutSchool::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'image' => $upload['path'],
            'thumb' => $upload['thumb'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data tentang sekolah berhasil ditambahkan',
            'data' => $about,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $about = AboutSchool::find($id);

        if (! $about) {
            return response()->json([
                'success' => false,
                'message' => 'Data tentang sekolah tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        if ($request->has('title')) {
            $about->title = $request->input('title');
        }

        if ($request->has('description')) {
            $about->description = $request->input('description');
        }

        if ($request->has('sort_order')) {
            $about->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $about->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($about->image, $about->thumb);

            $upload = ImageUploader::store($request->file('image'), 'profile/about');
            $about->image = $upload['path'];
            $about->thumb = $upload['thumb'];
        }

        $about->save();

        return response()->json([
            'success' => true,
            'message' => 'Data tentang sekolah berhasil diperbarui',
            'data' => $about,
        ]);
    }

    public function destroy($id)
    {
        $about = AboutSchool::find($id);

        if (! $about) {
            return response()->json([
                'success' => false,
                'message' => 'Data tentang sekolah tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($about->image, $about->thumb);
        $about->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data tentang sekolah berhasil dihapus',
        ]);
    }
}
