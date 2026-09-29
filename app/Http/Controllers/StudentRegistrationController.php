<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\RegistrationSetting;
use App\Models\StudentRegistration;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentRegistrationController extends Controller
{
    public function index(Request $request)
    {
        $registrations = StudentRegistration::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        $registrations->map(function ($item) {
            $item->photo_url = $item->photo
                ? asset('storage/'.$item->photo)
                : null;

            $item->birth_certificate_url = $item->birth_certificate
                ? asset('storage/'.$item->birth_certificate)
                : null;

            $item->family_card_url = $item->family_card
                ? asset('storage/'.$item->family_card)
                : null;

            $item->payment_proof_url = $item->payment_proof
                ? asset('storage/'.$item->payment_proof)
                : null;

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $registrations,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $registrationSetting = RegistrationSetting::query()->first();
            $phase = $registrationSetting?->phase ?? 'closed';

            if ($phase !== 'open') {
                $message = $phase === 'account'
                    ? 'Formulir pendaftaran belum dibuka. Silakan buat akun terlebih dahulu dan pantau informasi berikutnya.'
                    : 'Pendaftaran siswa baru belum dibuka. Silakan pantau kembali halaman pendaftaran.';

                return response()->json([
                    'success' => false,
                    'message' => $registrationSetting?->phase_message ?: $message,
                ], 422);
            }

            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'nickname' => 'required|string|max:100',
                'gender' => 'required|in:L,P',
                'birth_place' => 'required|string|max:255',
                'birth_date' => 'required|date|before_or_equal:today',

                'father_name' => 'required|string|max:255',
                'mother_name' => 'required|string|max:255',
                'address' => 'required|string',
                'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]*$/'],
                'contact_email' => 'required|email|max:255',

                'photo' => 'required|image|mimes:jpeg,png,jpg|max:10240',
                'birth_certificate' => 'required|image|mimes:jpeg,png,jpg|max:10240',
                'family_card' => 'required|image|mimes:jpeg,png,jpg|max:10240',
                'payment_proof' => 'required|image|mimes:jpeg,png,jpg|max:10240',
                'transfer_proof' => 'nullable|file|mimes:jpeg,png,jpg,pdf,doc,docx|max:10240',
            ], [
                'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh lebih dari hari ini.',
                'photo.max' => 'Ukuran foto maksimal 10MB.',
                'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, dan simbol + - ( ).',
                'birth_certificate.max' => 'Ukuran akte kelahiran maksimal 10MB.',
                'family_card.max' => 'Ukuran kartu keluarga maksimal 10MB.',
                'payment_proof.max' => 'Ukuran bukti pembayaran maksimal 10MB.',
                'photo.mimes' => 'Foto harus berupa file gambar: jpeg, png, jpg.',
                'birth_certificate.mimes' => 'Akte kelahiran harus berupa file gambar: jpeg, png, jpg.',
                'family_card.mimes' => 'Kartu keluarga harus berupa file gambar: jpeg, png, jpg.',
                'payment_proof.mimes' => 'Bukti pembayaran harus berupa file gambar: jpeg, png, jpg.',
                'transfer_proof.max' => 'Ukuran bukti pindahan maksimal 10MB.',
                'transfer_proof.mimes' => 'Bukti pindahan harus berupa JPG, PNG, PDF, DOC, atau DOCX.',
            ]);

            // Pendaftaran hanya dibuka bila tahun ajaran aktif sudah ditetapkan
            $activeYear = AcademicYear::where('is_active', true)->first();

            if (! $activeYear) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pendaftaran belum dibuka karena tahun ajaran aktif belum '
                        .'ditetapkan. Silakan hubungi admin sekolah.',
                ], 422);
            }

            $quota = (int) ($registrationSetting?->quota ?? 0);
            $registered = StudentRegistration::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('status', '!=', 'rejected')
                ->count();

            if ($quota > 0 && $registered >= $quota) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kuota pendaftaran tahun ajaran '.$activeYear->name
                        .' sudah penuh. Silakan hubungi admin sekolah.',
                ], 422);
            }

            $registration = new StudentRegistration;
            $registration->user_id = $request->user()->id;
            $registration->academic_year_id = $activeYear->id;

            // Simpan data text
            $registration->fill($validated);

            // Upload dan simpan file
            if ($request->hasFile('photo')) {
                $registration->photo = $request->file('photo')
                    ->store('registrations/photos', 'public');
            }

            if ($request->hasFile('birth_certificate')) {
                $registration->birth_certificate = $request->file('birth_certificate')
                    ->store('registrations/birth_certificates', 'public');
            }

            if ($request->hasFile('family_card')) {
                $registration->family_card = $request->file('family_card')
                    ->store('registrations/family_cards', 'public');
            }

            if ($request->hasFile('payment_proof')) {
                $registration->payment_proof = $request->file('payment_proof')
                    ->store('registrations/payment_proofs', 'public');
            }

            if ($request->hasFile('transfer_proof')) {
                $registration->transfer_proof = $request->file('transfer_proof')
                    ->store('registrations/transfer_proofs', 'public');
            }

            $registration->status = 'submitted';
            $registration->save();

            // Nomor pendaftaran unik, dibuat setelah id terbentuk
            $registration->registration_number = StudentRegistration::makeRegistrationNumber(
                $registration->id,
                $activeYear
            );
            $registration->save();

            // Generate URL untuk response
            $registration->photo_url = asset('storage/'.$registration->photo);
            $registration->birth_certificate_url = asset('storage/'.$registration->birth_certificate);
            $registration->family_card_url = asset('storage/'.$registration->family_card);
            $registration->payment_proof_url = asset('storage/'.$registration->payment_proof);
            $registration->transfer_proof_url = $registration->transfer_proof
                ? asset('storage/'.$registration->transfer_proof)
                : null;

            return response()->json([
                'success' => true,
                'message' => 'Pendaftaran berhasil dikirim!',
                'data' => $registration,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: '.$e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $registration = StudentRegistration::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $registration->photo_url = asset('storage/'.$registration->photo);
            $registration->birth_certificate_url = asset('storage/'.$registration->birth_certificate);
            $registration->family_card_url = asset('storage/'.$registration->family_card);
            $registration->payment_proof_url = asset('storage/'.$registration->payment_proof);
            $registration->transfer_proof_url = $registration->transfer_proof
                ? asset('storage/'.$registration->transfer_proof)
                : null;

            return response()->json([
                'success' => true,
                'data' => $registration,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran tidak ditemukan',
            ], 404);
        }
    }

    // Tambahkan method untuk menghitung jumlah pendaftaran
    public function count(Request $request)
    {
        $count = StudentRegistration::where('user_id', $request->user()->id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $count,
            ],
        ]);
    }
}
