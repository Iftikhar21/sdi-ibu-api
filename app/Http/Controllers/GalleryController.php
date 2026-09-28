<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    /**
     * Lengkapi album dengan URL foto, foto sampul, dan jumlah foto.
     */
    private function formatGallery(Gallery $gallery): Gallery
    {
        $gallery->photos->each(function ($photo) {
            $photo->image_url = asset('storage/'.$photo->image);
            $photo->thumb_url = $photo->thumb
                ? asset('storage/'.$photo->thumb)
                : $photo->image_url;
        });

        $firstPhoto = $gallery->photos->first();

        $gallery->cover_url = $firstPhoto
            ? ($firstPhoto->thumb
                ? asset('storage/'.$firstPhoto->thumb)
                : asset('storage/'.$firstPhoto->image))
            : null;
        $gallery->photos_count = $gallery->photos->count();

        return $gallery;
    }

    /**
     * Buat versi kecil dari foto supaya beranda tidak memuat berkas besar.
     * Mengembalikan null bila foto sudah cukup kecil atau gagal diproses.
     */
    private function createThumbnail(string $originalPath): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk('public');
        $fullPath = $disk->path($originalPath);

        if (! is_file($fullPath)) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($fullPath));

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $maxWidth = 1200;

        if ($width <= $maxWidth) {
            imagedestroy($source);

            return null;
        }

        $newWidth = $maxWidth;
        $newHeight = (int) round($height * ($maxWidth / $width));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, 82);
        $contents = ob_get_clean();
        imagedestroy($canvas);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $thumbPath = 'gallery/thumbs/'.pathinfo($originalPath, PATHINFO_FILENAME).'.jpg';
        $disk->put($thumbPath, $contents);

        return $thumbPath;
    }

    /**
     * Simpan file foto album.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $photos
     */
    private function storePhotos(Gallery $gallery, array $photos, int $startOrder = 0): void
    {
        foreach (array_values($photos) as $index => $photo) {
            $path = $photo->store('gallery/photos', 'public');

            $gallery->photos()->create([
                'image' => $path,
                'thumb' => $this->createThumbnail($path),
                'sort_order' => $startOrder + $index,
            ]);
        }
    }

    public function index()
    {
        $galleries = Gallery::with(['photos', 'category'])->ordered()->get();

        $galleries->each(fn ($gallery) => $this->formatGallery($gallery));

        return response()->json([
            'success' => true,
            'data' => $galleries,
        ]);
    }

    public function show($id)
    {
        $gallery = Gallery::with(['photos', 'category'])->find($id);

        if (! $gallery) {
            return response()->json([
                'success' => false,
                'message' => 'Galeri tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGallery($gallery),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'gallery_category_id' => 'required|integer|exists:gallery_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'photos' => 'required|array|min:1',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'gallery_category_id.required' => 'Kategori galeri wajib dipilih.',
            'gallery_category_id.exists' => 'Kategori galeri tidak ditemukan.',
            'photos.required' => 'Minimal satu foto harus diunggah.',
            'photos.*.max' => 'Ukuran setiap foto maksimal 5MB.',
            'photos.*.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
            'photos.*.image' => 'Berkas yang diunggah harus berupa gambar.',
        ]);

        $gallery = Gallery::create([
            'gallery_category_id' => $validated['gallery_category_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->storePhotos($gallery, $request->file('photos'));

        $gallery->load(['photos', 'category']);

        return response()->json([
            'success' => true,
            'message' => 'Album galeri berhasil ditambahkan',
            'data' => $this->formatGallery($gallery),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $gallery = Gallery::with(['photos', 'category'])->find($id);

        if (! $gallery) {
            return response()->json([
                'success' => false,
                'message' => 'Galeri tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'gallery_category_id' => 'sometimes|required|integer|exists:gallery_categories,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'deleted_photo_ids' => 'nullable|array',
            'deleted_photo_ids.*' => 'integer',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'gallery_category_id.required' => 'Kategori galeri wajib dipilih.',
            'gallery_category_id.exists' => 'Kategori galeri tidak ditemukan.',
            'photos.*.max' => 'Ukuran setiap foto maksimal 5MB.',
            'photos.*.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        if ($request->has('gallery_category_id')) {
            $gallery->gallery_category_id = $request->input('gallery_category_id');
        }

        if ($request->has('title')) {
            $gallery->title = $request->input('title');
        }

        if ($request->has('description')) {
            $gallery->description = $request->input('description');
        }

        if ($request->has('sort_order')) {
            $gallery->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $gallery->is_active = $request->boolean('is_active');
        }

        $gallery->save();

        // Hapus foto yang ditandai admin
        $deletedIds = $request->input('deleted_photo_ids', []);

        if (! empty($deletedIds)) {
            $photos = GalleryPhoto::where('gallery_id', $gallery->id)
                ->whereIn('id', $deletedIds)
                ->get();

            foreach ($photos as $photo) {
                Storage::disk('public')->delete($photo->image);

                if ($photo->thumb) {
                    Storage::disk('public')->delete($photo->thumb);
                }

                $photo->delete();
            }
        }

        // Tambah foto baru
        if ($request->hasFile('photos')) {
            $lastOrder = (int) $gallery->photos()->max('sort_order');

            $this->storePhotos($gallery, $request->file('photos'), $lastOrder + 1);
        }

        $gallery->load(['photos', 'category']);

        if ($gallery->photos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Album galeri harus memiliki minimal satu foto.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Album galeri berhasil diperbarui',
            'data' => $this->formatGallery($gallery),
        ]);
    }

    public function destroy($id)
    {
        $gallery = Gallery::with('photos')->find($id);

        if (! $gallery) {
            return response()->json([
                'success' => false,
                'message' => 'Galeri tidak ditemukan',
            ], 404);
        }

        foreach ($gallery->photos as $photo) {
            Storage::disk('public')->delete($photo->image);

            if ($photo->thumb) {
                Storage::disk('public')->delete($photo->thumb);
            }
        }

        $gallery->delete();

        return response()->json([
            'success' => true,
            'message' => 'Album galeri berhasil dihapus',
        ]);
    }
}
