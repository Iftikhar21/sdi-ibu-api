<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Support\StudentPromotion;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kenaikan kelas siswa antar tahun ajaran.
 *
 * Controller ini hanya mengurus HTTP; business rule-nya ada di
 * App\Support\StudentPromotion.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly StudentPromotion $promotion) {}

    /**
     * Daftar siswa yang bisa dinaikkan beserta usulan kelas tujuannya.
     */
    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->promotion->candidates(
                (int) $request->get('from_academic_year_id'),
                (int) $request->get('to_academic_year_id')
            ),
        ]);
    }

    /**
     * Proses kenaikan kelas (bulk, all-or-nothing).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_academic_year_id' => 'required|integer|exists:academic_years,id',
            'to_academic_year_id' => 'required|integer|exists:academic_years,id|different:from_academic_year_id',
            'promotions' => 'required|array|min:1',
            'promotions.*.student_id' => 'required|integer|distinct|exists:students,id',
            'promotions.*.classroom_id' => 'required|integer|exists:classrooms,id',
        ], [
            'to_academic_year_id.different' => 'Tahun ajaran tujuan harus berbeda dengan tahun ajaran asal.',
            'promotions.required' => 'Pilih minimal satu siswa untuk dinaikkan.',
            'promotions.*.student_id.exists' => 'Ada siswa yang tidak ditemukan.',
            'promotions.*.classroom_id.exists' => 'Ada kelas tujuan yang tidak ditemukan.',
        ]);

        try {
            $promoted = $this->promotion->promote(
                (int) $validated['from_academic_year_id'],
                (int) $validated['to_academic_year_id'],
                $validated['promotions'],
                $request->user()?->id
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($exception->errors()['promotions'] ?? []).' baris yang perlu '
                    .'diperbaiki. Tidak ada data yang disimpan.',
                'errors' => $exception->errors()['promotions'] ?? [],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $promoted.' siswa berhasil dinaikkan ke tahun ajaran '
                .AcademicYear::find($validated['to_academic_year_id'])?->name.'.',
            'data' => [
                'promoted' => $promoted,
            ],
        ], 201);
    }
}
