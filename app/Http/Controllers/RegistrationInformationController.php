<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\RegistrationFee;
use App\Models\RegistrationRequirement;
use App\Models\RegistrationSetting;
use App\Models\StudentRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationInformationController extends Controller
{
    public function publicShow()
    {
        return response()->json([
            'success' => true,
            'data' => $this->data(false),
        ]);
    }

    public function adminShow()
    {
        return response()->json([
            'success' => true,
            'data' => $this->data(true),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'phase' => 'required|in:closed,account,open',
            'phase_message' => 'nullable|string|max:2000',
            'quota' => 'required|integer|min:0|max:100000',
            'quota_description' => 'nullable|string|max:1000',
            'payment_bank' => 'nullable|required_with:payment_account_number,payment_account_name|string|max:100',
            'payment_account_number' => 'nullable|required_with:payment_bank,payment_account_name|string|max:100',
            'payment_account_name' => 'nullable|required_with:payment_bank,payment_account_number|string|max:150',
            'requirements' => 'present|array|max:50',
            'requirements.*.content' => 'required|string|max:500',
            'requirements.*.is_active' => 'required|boolean',
            'fees' => 'present|array|max:50',
            'fees.*.program' => 'required|string|max:255',
            'fees.*.amount' => 'required|integer|min:0|max:999999999999',
            'fees.*.description' => 'nullable|string|max:1000',
            'fees.*.is_active' => 'required|boolean',
        ], [
            'quota.min' => 'Kuota tidak boleh kurang dari 0.',
            'payment_bank.required_with' => 'Nama bank wajib diisi jika informasi rekening digunakan.',
            'payment_account_number.required_with' => 'Nomor rekening wajib diisi jika informasi rekening digunakan.',
            'payment_account_name.required_with' => 'Nama pemilik rekening wajib diisi jika informasi rekening digunakan.',
            'requirements.*.content.required' => 'Isi persyaratan wajib diisi.',
            'fees.*.program.required' => 'Nama program biaya wajib diisi.',
            'fees.*.amount.required' => 'Nominal biaya wajib diisi.',
        ]);

        DB::transaction(function () use ($validated) {
            $setting = RegistrationSetting::query()->firstOrNew();
            $setting->fill([
                'phase' => $validated['phase'],
                'phase_message' => $validated['phase_message'] ?? null,
                'quota' => $validated['quota'],
                'quota_description' => $validated['quota_description'] ?? null,
                'payment_bank' => $validated['payment_bank'] ?? null,
                'payment_account_number' => $validated['payment_account_number'] ?? null,
                'payment_account_name' => $validated['payment_account_name'] ?? null,
            ])->save();

            RegistrationRequirement::query()->delete();
            foreach ($validated['requirements'] as $index => $requirement) {
                RegistrationRequirement::create([
                    'content' => $requirement['content'],
                    'sort_order' => $index + 1,
                    'is_active' => $requirement['is_active'],
                ]);
            }

            RegistrationFee::query()->delete();
            foreach ($validated['fees'] as $index => $fee) {
                RegistrationFee::create([
                    'program' => $fee['program'],
                    'amount' => $fee['amount'],
                    'description' => $fee['description'] ?? null,
                    'sort_order' => $index + 1,
                    'is_active' => $fee['is_active'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Informasi pendaftaran berhasil diperbarui.',
            'data' => $this->data(true),
        ]);
    }

    private function data(bool $includeInactive): array
    {
        $setting = RegistrationSetting::query()->first();
        $activeYear = AcademicYear::query()->where('is_active', true)->first();
        $quota = (int) ($setting?->quota ?? 0);
        $registered = $activeYear
            ? StudentRegistration::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('status', '!=', 'rejected')
                ->count()
            : 0;
        $classQuotas = $activeYear
            ? Classroom::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('is_active', true)
                ->withCount('activePlacements')
                ->ordered()
                ->get()
                ->map(fn (Classroom $classroom) => [
                    'id' => $classroom->id,
                    'name' => $classroom->display_name,
                    'grade_level' => $classroom->grade_level,
                    'quota' => $classroom->quota,
                    'filled' => $classroom->filled_count,
                    'available' => $classroom->available_count,
                ])
                ->values()
            : collect();

        $requirements = RegistrationRequirement::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->ordered()
            ->get();

        $fees = RegistrationFee::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->ordered()
            ->get();

        return [
            'phase' => $setting?->phase ?? 'closed',
            'phase_message' => $setting?->phase_message,
            'academic_year' => $activeYear?->only(['id', 'name']),
            'quota' => $quota,
            'registered' => $registered,
            'available' => $quota > 0 ? max(0, $quota - $registered) : null,
            'quota_description' => $setting?->quota_description,
            'payment_bank' => $setting?->payment_bank,
            'payment_account_number' => $setting?->payment_account_number,
            'payment_account_name' => $setting?->payment_account_name,
            'class_quotas' => $classQuotas,
            'requirements' => $requirements,
            'fees' => $fees,
        ];
    }
}
