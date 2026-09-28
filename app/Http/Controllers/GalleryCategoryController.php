<?php

namespace App\Http\Controllers;

use App\Models\GalleryCategory;
use Illuminate\Http\Request;

class GalleryCategoryController extends Controller
{
    public function index()
    {
        $categories = GalleryCategory::ordered()
            ->withCount('galleries')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function show($id)
    {
        $category = GalleryCategory::withCount('galleries')->find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori galeri tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori maksimal 100 karakter.',
        ]);

        $name = trim($validated['name']);

        if (GalleryCategory::where('name', $name)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori dengan nama tersebut sudah ada.',
            ], 422);
        }

        $category = GalleryCategory::create([
            'name' => $name,
            'slug' => GalleryCategory::makeUniqueSlug($name),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori galeri berhasil ditambahkan',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $category = GalleryCategory::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori galeri tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
        ]);

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));

            $duplicate = GalleryCategory::where('name', $name)
                ->where('id', '!=', $category->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori dengan nama tersebut sudah ada.',
                ], 422);
            }

            if ($name !== $category->name) {
                $category->name = $name;
                $category->slug = GalleryCategory::makeUniqueSlug($name, $category->id);
            }
        }

        if ($request->has('sort_order')) {
            $category->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $category->is_active = $request->boolean('is_active');
        }

        $category->save();

        return response()->json([
            'success' => true,
            'message' => 'Kategori galeri berhasil diperbarui',
            'data' => $category,
        ]);
    }

    public function destroy($id)
    {
        $category = GalleryCategory::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori galeri tidak ditemukan',
            ], 404);
        }

        $albumCount = $category->galleries()->count();

        if ($albumCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Kategori ini masih dipakai oleh {$albumCount} album. "
                    .'Pindahkan atau hapus albumnya terlebih dahulu.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori galeri berhasil dihapus',
        ]);
    }

    /**
     * Simpan urutan kategori sekaligus.
     * Urutan mengikuti susunan id yang dikirim (1, 2, 3, ...).
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:gallery_categories,id',
        ], [
            'ids.required' => 'Daftar urutan kategori tidak boleh kosong.',
            'ids.*.exists' => 'Ada kategori yang tidak ditemukan.',
        ]);

        // Kategori yang dikirim tampil lebih dulu sesuai urutan kiriman,
        // sisanya diletakkan setelahnya agar penomoran tetap rapat 1..n.
        $remainingIds = GalleryCategory::whereNotIn('id', $validated['ids'])
            ->ordered()
            ->pluck('id')
            ->all();

        $orderedIds = array_merge($validated['ids'], $remainingIds);

        foreach ($orderedIds as $index => $id) {
            GalleryCategory::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Urutan kategori berhasil disimpan',
            'data' => GalleryCategory::ordered()->withCount('galleries')->get(),
        ]);
    }
}
