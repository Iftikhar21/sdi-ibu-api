<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    /**
     * Hanya boleh ada satu tahun ajaran aktif.
     */
    private function deactivateOthers(?int $exceptId = null): void
    {
        AcademicYear::where('is_active', true)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_active' => false]);
    }

    public function index()
    {
        $academicYears = AcademicYear::ordered()
            ->withCount('classrooms')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $academicYears,
        ]);
    }

    public function show($id)
    {
        $academicYear = AcademicYear::withCount('classrooms')->find($id);

        if (! $academicYear) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $academicYear,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/', 'unique:academic_years,name'],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Tahun ajaran wajib diisi.',
            'name.regex' => 'Format tahun ajaran harus seperti 2026/2027.',
            'name.unique' => 'Tahun ajaran tersebut sudah ada.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
        ]);

        $academicYear = DB::transaction(function () use ($validated, $request) {
            $isActive = $request->boolean('is_active');

            if ($isActive) {
                $this->deactivateOthers();
            }

            return AcademicYear::create([
                'name' => trim($validated['name']),
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'is_active' => $isActive,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Tahun ajaran berhasil ditambahkan',
            'data' => $academicYear,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $academicYear = AcademicYear::find($id);

        if (! $academicYear) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^\d{4}\/\d{4}$/',
                'unique:academic_years,name,'.$academicYear->id,
            ],
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ], [
            'name.regex' => 'Format tahun ajaran harus seperti 2026/2027.',
            'name.unique' => 'Tahun ajaran tersebut sudah ada.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
        ]);

        DB::transaction(function () use ($request, $academicYear) {
            if ($request->has('name')) {
                $academicYear->name = trim((string) $request->input('name'));
            }

            if ($request->has('start_date')) {
                $academicYear->start_date = $request->input('start_date');
            }

            if ($request->has('end_date')) {
                $academicYear->end_date = $request->input('end_date');
            }

            if ($request->has('is_active')) {
                $isActive = $request->boolean('is_active');

                if ($isActive) {
                    $this->deactivateOthers($academicYear->id);
                }

                $academicYear->is_active = $isActive;
            }

            $academicYear->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Tahun ajaran berhasil diperbarui',
            'data' => $academicYear->loadCount('classrooms'),
        ]);
    }

    /**
     * Copy struktur kelas (kelas + kuota) dari tahun ajaran lain.
     *
     * Hanya data kelas yang disalin: siswa, pendaftaran, penempatan kelas,
     * riwayat, dan kelulusan tidak ikut dipindahkan.
     */
    public function copyClassrooms(Request $request, $id)
    {
        $academicYear = AcademicYear::find($id);

        if (! $academicYear) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'from_academic_year_id' => [
                'required',
                'integer',
                'exists:academic_years,id',
                Rule::notIn([$academicYear->id]),
            ],
        ], [
            'from_academic_year_id.required' => 'Tahun ajaran sumber wajib dipilih.',
            'from_academic_year_id.exists' => 'Tahun ajaran sumber tidak ditemukan.',
            'from_academic_year_id.not_in' => 'Tahun ajaran sumber harus berbeda dengan tahun ajaran tujuan.',
        ]);

        $source = AcademicYear::find($validated['from_academic_year_id']);

        $sourceClassrooms = Classroom::where('academic_year_id', $source->id)
            ->ordered()
            ->get();

        if ($sourceClassrooms->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "Tahun ajaran {$source->name} belum memiliki kelas untuk dicopy.",
            ], 422);
        }

        // Kelas yang sudah ada di tahun ajaran tujuan tidak dibuat ulang
        $existing = Classroom::where('academic_year_id', $academicYear->id)
            ->get()
            ->keyBy(fn (Classroom $classroom) => $classroom->grade_level.'|'.$classroom->name);

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($sourceClassrooms, $existing, $academicYear, &$created, &$skipped) {
            foreach ($sourceClassrooms as $classroom) {
                if ($existing->has($classroom->grade_level.'|'.$classroom->name)) {
                    $skipped++;

                    continue;
                }

                Classroom::create([
                    'academic_year_id' => $academicYear->id,
                    'grade_level' => $classroom->grade_level,
                    'name' => $classroom->name,
                    'quota' => $classroom->quota,
                    'is_active' => $classroom->is_active,
                ]);

                $created++;
            }
        });

        $message = $created > 0
            ? "Copy struktur kelas selesai: {$created} kelas dibuat"
                .($skipped > 0 ? ", {$skipped} dilewati karena sudah ada." : '.')
            : 'Semua kelas dari tahun ajaran sumber sudah ada di tahun ajaran tujuan.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'from_academic_year' => $source->name,
                'to_academic_year' => $academicYear->name,
                'classrooms_count' => Classroom::where('academic_year_id', $academicYear->id)->count(),
            ],
        ]);
    }

    public function destroy($id)
    {
        $academicYear = AcademicYear::withCount('classrooms')->find($id);

        if (! $academicYear) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran tidak ditemukan',
            ], 404);
        }

        if ($academicYear->classrooms_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Tahun ajaran ini masih dipakai oleh {$academicYear->classrooms_count} kelas. "
                    .'Hapus atau pindahkan kelasnya terlebih dahulu.',
            ], 422);
        }

        if ($academicYear->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Tahun ajaran yang sedang aktif tidak dapat dihapus. '
                    .'Aktifkan tahun ajaran lain terlebih dahulu.',
            ], 422);
        }

        $academicYear->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tahun ajaran berhasil dihapus',
        ]);
    }
}
