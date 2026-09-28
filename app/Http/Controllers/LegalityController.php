<?php

namespace App\Http\Controllers;

use App\Models\Legality;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class LegalityController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Legality::ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $legality = Legality::find($id);

        if (! $legality) {
            return response()->json([
                'success' => false,
                'message' => 'Data legalitas tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $legality]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'description.required' => 'Deskripsi legalitas wajib diisi.',
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        $upload = $request->hasFile('image')
            ? ImageUploader::store($request->file('image'), 'profile/legalities')
            : ['path' => null, 'thumb' => null];

        $legality = Legality::create([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'],
            'image' => $upload['path'],
            'thumb' => $upload['thumb'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data legalitas berhasil ditambahkan',
            'data' => $legality,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $legality = Legality::find($id);

        if (! $legality) {
            return response()->json([
                'success' => false,
                'message' => 'Data legalitas tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'sometimes|required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'description.required' => 'Deskripsi legalitas wajib diisi.',
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        if ($request->has('title')) {
            $legality->title = $request->input('title');
        }

        if ($request->has('description')) {
            $legality->description = $request->input('description');
        }

        if ($request->has('sort_order')) {
            $legality->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $legality->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($legality->image, $legality->thumb);

            $upload = ImageUploader::store($request->file('image'), 'profile/legalities');
            $legality->image = $upload['path'];
            $legality->thumb = $upload['thumb'];
        }

        $legality->save();

        return response()->json([
            'success' => true,
            'message' => 'Data legalitas berhasil diperbarui',
            'data' => $legality,
        ]);
    }

    public function destroy($id)
    {
        $legality = Legality::find($id);

        if (! $legality) {
            return response()->json([
                'success' => false,
                'message' => 'Data legalitas tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($legality->image, $legality->thumb);
        $legality->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data legalitas berhasil dihapus',
        ]);
    }
}
