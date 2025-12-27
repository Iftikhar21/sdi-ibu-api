<?php

namespace App\Http\Controllers;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class NewsController extends Controller
{
    /**
     * GET /api/news
     */
    public function index()
    {
        $news = News::with('photos')->latest()->get();

        if ($news->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'News belum ditambahkan'
            ], 404);
        }

        $news = $news->map(function ($item) {

            // thumbnail url
            $item->thumbnail_url = $item->thumbnail
                ? asset('storage/' . $item->thumbnail)
                : asset('images/no-image.png');

            // photos url
            if ($item->photos) {
                $item->photos->map(function ($photo) {
                    $photo->photo_url = asset('storage/' . $photo->path);
                    return $photo;
                });
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $news
        ]);
    }

    /**
     * GET /api/news/{id}
     */
    public function show($id)
    {
        $news = News::with('photos')->find($id);

        if (! $news) {
            return response()->json([
                'success' => false,
                'message' => 'News not found'
            ], 404);
        }

        // thumbnail url
        $news->thumbnail_url = $news->thumbnail
            ? asset('storage/' . $news->thumbnail)
            : asset('images/no-image.png');

        // photos url
        if ($news->photos) {
            $news->photos->map(function ($photo) {
                $photo->photo_url = asset('storage/' . $photo->path);
                return $photo;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $news
        ]);
    }


    /**
     * POST /api/news
     */
    public function store(Request $request)
    {

        $validated = $request->validate([
            'title'     => 'required|string|max:255',
            'content'   => 'required|string',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'photos'    => 'nullable|array',
            'photos.*'  => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        /** upload thumbnail */
        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')
                ->store('news/thumbnails', 'public');
        }

        $news = News::create([
            'title'     => $validated['title'],
            'slug'      => Str::slug($validated['title']),
            'content'   => $validated['content'],
            'thumbnail' => $thumbnailPath,
        ]);

        /** upload multiple photos */
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('news/photos', 'public');

                $news->photos()->create([
                    'path' => $path
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'News created successfully',
            'data' => $news->load('photos')
        ], 201);
    }

    /**
     * PUT /api/news/{id}
     */
    public function update(Request $request, $id)
    {
        $news = News::with('photos')->findOrFail($id);

        /* ================= TEXT ================= */
        if ($request->filled('title')) {
            $news->title = $request->title;
            $news->slug  = Str::slug($request->title);
        }

        if ($request->filled('content')) {
            $news->content = $request->content;
        }

        /* ================= THUMBNAIL ================= */
        if ($request->input('remove_thumbnail') == '1') {
            if ($news->thumbnail && Storage::disk('public')->exists($news->thumbnail)) {
                Storage::disk('public')->delete($news->thumbnail);
            }
            $news->thumbnail = null;
        }

        if ($request->hasFile('thumbnail')) {
            if ($news->thumbnail && Storage::disk('public')->exists($news->thumbnail)) {
                Storage::disk('public')->delete($news->thumbnail);
            }

            $news->thumbnail = $request->file('thumbnail')
                ->store('news/thumbnails', 'public');
        }

        /* ================= DELETE PHOTOS ================= */
        $deletedPhotos = $request->input('deleted_photos', []);

        if (is_string($deletedPhotos)) {
            $deletedPhotos = json_decode($deletedPhotos, true);
        }

        if (is_array($deletedPhotos)) {
            foreach ($news->photos()->whereIn('id', $deletedPhotos)->get() as $photo) {
                if ($photo->path && Storage::disk('public')->exists($photo->path)) {
                    Storage::disk('public')->delete($photo->path);
                }
                $photo->delete();
            }
        }

        /* ================= ADD NEW PHOTOS ================= */
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('news/photos', 'public');
                $news->photos()->create(['path' => $path]);
            }
        }

        $news->save();

        return response()->json([
            'success' => true,
            'message' => 'Berita berhasil diperbarui',
            'data' => $news->load('photos')
        ]);
    }


    /**
     * DELETE /api/news/{id}
     */
    public function destroy($id)
    {
        $news = News::find($id);

        if (! $news) {
            return response()->json([
                'success' => false,
                'message' => 'News not found'
            ], 404);
        }

        /** hapus thumbnail */
        if ($news->thumbnail) {
            Storage::disk('public')->delete($news->thumbnail);
        }

        /** hapus photos */
        foreach ($news->photos as $photo) {
            Storage::disk('public')->delete($photo->path);
        }

        $news->delete();

        return response()->json([
            'success' => true,
            'message' => 'News deleted successfully'
        ]);
    }
}
