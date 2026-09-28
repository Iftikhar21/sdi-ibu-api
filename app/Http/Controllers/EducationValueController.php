<?php

namespace App\Http\Controllers;

use App\Models\EducationValue;
use Illuminate\Http\Request;

class EducationValueController extends Controller
{
    /**
     * Ganti daftar rincian nilai (mis. Iman, Adab, Ilmu, Amal).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(EducationValue $value, array $items): void
    {
        $value->items()->delete();

        $order = 0;

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $order++;

            $value->items()->create([
                'title' => $title,
                'description' => $item['description'] ?? null,
                'sort_order' => $order,
            ]);
        }
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => EducationValue::with('items')->ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $value = EducationValue::with('items')->find($id);

        if (! $value) {
            return response()->json([
                'success' => false,
                'message' => 'Nilai pendidikan tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $value]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.title' => 'nullable|string|max:150',
            'items.*.description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'title.required' => 'Judul nilai pendidikan wajib diisi.',
        ]);

        $value = EducationValue::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->syncItems($value, $request->input('items', []));

        return response()->json([
            'success' => true,
            'message' => 'Nilai pendidikan berhasil ditambahkan',
            'data' => $value->load('items'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $value = EducationValue::find($id);

        if (! $value) {
            return response()->json([
                'success' => false,
                'message' => 'Nilai pendidikan tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.title' => 'nullable|string|max:150',
            'items.*.description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->has('title')) {
            $value->title = $request->input('title');
        }

        if ($request->has('description')) {
            $value->description = $request->input('description');
        }

        if ($request->has('sort_order')) {
            $value->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $value->is_active = $request->boolean('is_active');
        }

        $value->save();

        if ($request->has('items')) {
            $this->syncItems($value, $request->input('items', []));
        }

        return response()->json([
            'success' => true,
            'message' => 'Nilai pendidikan berhasil diperbarui',
            'data' => $value->load('items'),
        ]);
    }

    public function destroy($id)
    {
        $value = EducationValue::find($id);

        if (! $value) {
            return response()->json([
                'success' => false,
                'message' => 'Nilai pendidikan tidak ditemukan',
            ], 404);
        }

        $value->delete(); // rincian ikut terhapus (cascade)

        return response()->json([
            'success' => true,
            'message' => 'Nilai pendidikan berhasil dihapus',
        ]);
    }
}
