<?php

namespace App\Http\Controllers;

use App\Models\Principal;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class PrincipalController extends Controller
{
    /**
     * Bersihkan daftar riwayat pendidikan dari baris kosong.
     *
     * @param  array<int, mixed>|null  $history
     * @return array<int, string>
     */
    private function cleanHistory(?array $history): array
    {
        return collect($history ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Principal::ordered()->get(),
        ]);
    }

    public function show($id)
    {
        $principal = Principal::find($id);

        if (! $principal) {
            return response()->json([
                'success' => false,
                'message' => 'Data kepala sekolah tidak ditemukan',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $principal]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:150',
            'employee_number' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]*$/'],
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'greeting' => 'nullable|string',
            'education_history' => 'nullable|array',
            'education_history.*' => 'nullable|string|max:255',
            'started_at' => ['nullable', 'string', 'max:4', 'regex:/^[0-9]*$/'],
            'ended_at' => ['nullable', 'string', 'max:4', 'regex:/^[0-9]*$/'],
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ], [
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
            'employee_number.regex' => 'NIP/NUPTK hanya boleh berisi angka.',
            'started_at.regex' => 'Tahun mulai menjabat hanya boleh berisi angka.',
            'ended_at.regex' => 'Tahun selesai menjabat hanya boleh berisi angka.',
        ]);

        $upload = $request->hasFile('photo')
            ? ImageUploader::store($request->file('photo'), 'profile/principals')
            : ['path' => null, 'thumb' => null];

        $principal = Principal::create([
            'name' => $validated['name'],
            'position' => $validated['position'] ?? 'Kepala Sekolah',
            'employee_number' => $validated['employee_number'] ?? null,
            'photo' => $upload['path'],
            'thumb' => $upload['thumb'],
            'greeting' => $validated['greeting'] ?? null,
            'education_history' => $this->cleanHistory($validated['education_history'] ?? []),
            'started_at' => $validated['started_at'] ?? null,
            'ended_at' => $validated['ended_at'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data kepala sekolah berhasil ditambahkan',
            'data' => $principal,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $principal = Principal::find($id);

        if (! $principal) {
            return response()->json([
                'success' => false,
                'message' => 'Data kepala sekolah tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'position' => 'nullable|string|max:150',
            'employee_number' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]*$/'],
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'greeting' => 'nullable|string',
            'education_history' => 'nullable|array',
            'education_history.*' => 'nullable|string|max:255',
            'started_at' => ['nullable', 'string', 'max:4', 'regex:/^[0-9]*$/'],
            'ended_at' => ['nullable', 'string', 'max:4', 'regex:/^[0-9]*$/'],
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ], [
            'photo.max' => 'Ukuran foto maksimal 5MB.',
            'photo.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp.',
            'employee_number.regex' => 'NIP/NUPTK hanya boleh berisi angka.',
            'started_at.regex' => 'Tahun mulai menjabat hanya boleh berisi angka.',
            'ended_at.regex' => 'Tahun selesai menjabat hanya boleh berisi angka.',
        ]);

        foreach ([
            'name',
            'position',
            'employee_number',
            'greeting',
            'started_at',
            'ended_at',
        ] as $field) {
            if ($request->has($field)) {
                $principal->{$field} = $request->input($field);
            }
        }

        if ($request->has('education_history')) {
            $principal->education_history = $this->cleanHistory($request->input('education_history'));
        }

        if ($request->has('sort_order')) {
            $principal->sort_order = $request->input('sort_order') ?? 0;
        }

        if ($request->has('is_active')) {
            $principal->is_active = $request->boolean('is_active');
        }

        if ($request->hasFile('photo')) {
            ImageUploader::delete($principal->photo, $principal->thumb);

            $upload = ImageUploader::store($request->file('photo'), 'profile/principals');
            $principal->photo = $upload['path'];
            $principal->thumb = $upload['thumb'];
        }

        $principal->save();

        return response()->json([
            'success' => true,
            'message' => 'Data kepala sekolah berhasil diperbarui',
            'data' => $principal,
        ]);
    }

    public function destroy($id)
    {
        $principal = Principal::find($id);

        if (! $principal) {
            return response()->json([
                'success' => false,
                'message' => 'Data kepala sekolah tidak ditemukan',
            ], 404);
        }

        ImageUploader::delete($principal->photo, $principal->thumb);
        $principal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data kepala sekolah berhasil dihapus',
        ]);
    }
}
