<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::query()->ordered();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function show($id)
    {
        $activity = Activity::find($id);

        if (! $activity) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $activity]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:'.implode(',', array_keys(Activity::TYPES)),
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'type.in' => 'Jenis kegiatan tidak dikenali.',
            'title.required' => 'Judul wajib diisi.',
            'description.required' => 'Deskripsi wajib diisi.',
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        $upload = $request->hasFile('image')
            ? ImageUploader::store($request->file('image'), 'kegiatan')
            : ['path' => null, 'thumb' => null];

        $activity = Activity::create([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'image' => $upload['path'],
            'thumb' => $upload['thumb'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => $activity->type_label.' berhasil ditambahkan',
            'data' => $activity,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $activity = Activity::find($id);

        if (! $activity) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'type' => 'sometimes|required|in:'.implode(',', array_keys(Activity::TYPES)),
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'image.max' => 'Ukuran foto maksimal 5MB.',
            'image.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
        ]);

        foreach (['type', 'title', 'description'] as $field) {
            if ($request->has($field)) {
                $activity->{$field} = $request->input($field);
            }
        }

        if ($request->has('sort_order')) {
            $activity->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $activity->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($activity->image, $activity->thumb);

            $upload = ImageUploader::store($request->file('image'), 'kegiatan');
            $activity->image = $upload['path'];
            $activity->thumb = $upload['thumb'];
        }

        $activity->save();

        return response()->json([
            'success' => true,
            'message' => $activity->type_label.' berhasil diperbarui',
            'data' => $activity,
        ]);
    }

    public function destroy($id)
    {
        $activity = Activity::find($id);

        if (! $activity) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($activity->image, $activity->thumb);
        $activity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data kegiatan berhasil dihapus',
        ]);
    }
}
