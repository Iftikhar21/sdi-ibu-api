<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\RegistrationSetting;
use App\Models\StudentRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        $storedPaths = [];

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
                'previous_school' => 'required|string|max:255',

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
                'previous_school.required' => 'Asal sekolah wajib diisi.',
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

            $fileFolders = [
                'photo' => 'registrations/photos',
                'birth_certificate' => 'registrations/birth_certificates',
                'family_card' => 'registrations/family_cards',
                'payment_proof' => 'registrations/payment_proofs',
                'transfer_proof' => 'registrations/transfer_proofs',
            ];

            /*
             * Kunci baris pengaturan selama pemeriksaan kuota dan penyimpanan.
             * Dua pendaftaran yang masuk bersamaan tidak bisa sama-sama melewati
             * kursi terakhir. File yang sempat tersimpan dibersihkan saat gagal.
             */
            $registration = DB::transaction(function () use (
                $request,
                $validated,
                $activeYear,
                $fileFolders,
                &$storedPaths
            ) {
                $lockedSetting = RegistrationSetting::query()->lockForUpdate()->first();
                $lockedPhase = $lockedSetting?->phase ?? 'closed';

                if ($lockedPhase !== 'open') {
                    throw ValidationException::withMessages([
                        'registration' => $lockedSetting?->phase_message
                            ?: 'Pendaftaran siswa baru sedang tidak dibuka.',
                    ]);
                }

                $lockedYear = AcademicYear::query()
                    ->whereKey($activeYear->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedYear) {
                    throw ValidationException::withMessages([
                        'academic_year' => 'Tahun ajaran aktif berubah. Muat ulang halaman lalu coba kembali.',
                    ]);
                }

                $quota = (int) ($lockedSetting?->quota ?? 0);
                $registered = StudentRegistration::query()
                    ->where('academic_year_id', $lockedYear->id)
                    ->where('status', '!=', 'rejected')
                    ->count();

                if ($quota > 0 && $registered >= $quota) {
                    throw ValidationException::withMessages([
                        'quota' => 'Kuota pendaftaran tahun ajaran '.$lockedYear->name
                            .' sudah penuh. Silakan hubungi admin sekolah.',
                    ]);
                }

                $registration = new StudentRegistration;
                $registration->user_id = $request->user()->id;
                $registration->academic_year_id = $lockedYear->id;
                $registration->fill(Arr::except($validated, array_keys($fileFolders)));

                foreach ($fileFolders as $field => $folder) {
                    if (! $request->hasFile($field)) {
                        continue;
                    }

                    $path = $request->file($field)->store($folder, 'public');
                    $storedPaths[] = $path;
                    $registration->{$field} = $path;
                }

                $registration->status = 'submitted';
                $registration->save();

                // Nomor pendaftaran unik, dibuat setelah id terbentuk.
                $registration->registration_number = StudentRegistration::makeRegistrationNumber(
                    $registration->id,
                    $lockedYear
                );
                $registration->save();

                return $registration;
            }, 3);

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
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran gagal diproses oleh server. Silakan coba kembali.',
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
