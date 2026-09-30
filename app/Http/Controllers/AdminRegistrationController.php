<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

class AdminRegistrationController extends Controller
{
    /**
     * Query dasar pendaftaran beserta filter yang sedang aktif.
     * Dipakai bersama oleh index dan export agar hasilnya selalu sama.
     */
    private function filteredQuery(Request $request, bool $withUser = true)
    {
        $status = $request->get('status');
        $search = $request->get('search');
        $academicYearId = $request->get('academic_year_id');
        $placement = $request->get('placement');

        $query = StudentRegistration::query();

        if ($withUser) {
            $query->with([
                'user' => function ($query) {
                    $query->select('id', 'name', 'email');
                },
                'academicYear',
                'activePlacement.classroom',
                'student',
            ]);
        }

        $query->latest();

        // Filter by status
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        // Filter tahun ajaran
        if ($academicYearId && $academicYearId !== 'all') {
            $query->where('academic_year_id', $academicYearId);
        }

        // Filter status penempatan kelas
        if ($placement === 'placed') {
            $query->whereHas('activePlacement');
        } elseif ($placement === 'unplaced') {
            $query->whereDoesntHave('activePlacement');
        }

        // Search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%")
                    ->orWhere('previous_school', 'like', "%{$search}%")
                    ->orWhere('father_name', 'like', "%{$search}%")
                    ->orWhere('mother_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $registrations = $this->filteredQuery($request)->paginate($perPage);

        // Add URLs
        $registrations->getCollection()->transform(function ($item) {
            $item->photo_url = $item->photo ? asset('storage/'.$item->photo) : null;
            $item->birth_certificate_url = $item->birth_certificate ? asset('storage/'.$item->birth_certificate) : null;
            $item->family_card_url = $item->family_card ? asset('storage/'.$item->family_card) : null;
            $item->payment_proof_url = $item->payment_proof ? asset('storage/'.$item->payment_proof) : null;
            $item->transfer_proof_url = $item->transfer_proof ? asset('storage/'.$item->transfer_proof) : null;

            return $item;
        });

        // Get statistics
        $stats = [
            'total' => StudentRegistration::count(),
            'submitted' => StudentRegistration::where('status', 'submitted')->count(),
            'review' => StudentRegistration::where('status', 'review')->count(),
            'approved' => StudentRegistration::where('status', 'approved')->count(),
            'rejected' => StudentRegistration::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $registrations,
            'stats' => $stats,
            'meta' => [
                'total' => $registrations->total(),
                'per_page' => $registrations->perPage(),
                'current_page' => $registrations->currentPage(),
                'last_page' => $registrations->lastPage(),
            ],
        ]);
    }

    /**
     * Export data pendaftar ke Excel (.xlsx) mengikuti filter yang aktif.
     */
    public function export(Request $request)
    {
        $statusLabels = [
            'submitted' => 'Dikirim',
            'review' => 'Dalam Review',
            'approved' => 'Diterima',
            'rejected' => 'Ditolak',
        ];

        $genderLabels = [
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
        ];

        $filename = 'data-pendaftaran-'.now()->format('Y-m-d-His').'.xlsx';
        $query = $this->filteredQuery($request, false)
            ->with(['academicYear', 'activePlacement.classroom']);

        // Nilai tanggal bisa berupa string (kolom date) atau Carbon (created_at).
        $toExcelDate = function ($value) {
            if (! $value) {
                return null;
            }

            return ExcelDate::PHPToExcel(
                $value instanceof \DateTimeInterface ? $value : new \DateTime((string) $value)
            );
        };

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('SDI Ikhlas Bakti Umat')
            ->setTitle('Data Pendaftar');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pendaftar');

        $headers = [
            'No',
            'ID Pendaftaran',
            'No. Pendaftaran',
            'Nama Lengkap',
            'Nama Panggilan',
            'Asal Sekolah',
            'Jenis Kelamin',
            'Tempat Lahir',
            'Tanggal Lahir',
            'Nama Ayah',
            'Nama Ibu',
            'Alamat',
            'No. HP',
            'Email Kontak',
            'Status',
            'Catatan',
            'Tanggal Daftar',
            'Tahun Ajaran',
            'Kelas',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        $number = 0;

        foreach ($query->get() as $item) {
            $number++;

            $sheet->fromArray([
                $number,
                (int) $item->id,
                $item->registration_number ?? '',
                $item->full_name,
                $item->nickname,
                $item->previous_school ?? '',
                $genderLabels[$item->gender] ?? $item->gender,
                $item->birth_place,
                $toExcelDate($item->birth_date),
                $item->father_name,
                $item->mother_name,
                $item->address,
                $item->phone,
                $item->contact_email,
                $statusLabels[$item->status] ?? $item->status,
                $item->notes,
                $toExcelDate($item->created_at),
                $item->academicYear->name ?? '',
                $item->classroom_label ?? 'Belum Ditempatkan',
            ], null, 'A'.$rowNumber);

            $rowNumber++;
        }

        $lastRow = max($rowNumber - 1, 1);
        $lastColumn = $sheet->getHighestColumn();
        $range = 'A1:'.$lastColumn.$lastRow;

        // Judul kolom: teks putih, latar biru, tebal
        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '004AAD'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(28);

        // Isi tabel: garis tipis dan teks naik ke atas
        $sheet->getStyle('A2:'.$lastColumn.$lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D5DCE8'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);

        // Format tanggal asli Excel supaya bisa diurutkan/difilter
        $birthDateColumn = Coordinate::stringFromColumnIndex(7);
        $createdAtColumn = Coordinate::stringFromColumnIndex(15);

        if ($lastRow >= 2) {
            $sheet->getStyle($birthDateColumn.'2:'.$birthDateColumn.$lastRow)
                ->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->getStyle($createdAtColumn.'2:'.$createdAtColumn.$lastRow)
                ->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        }

        // Lebar kolom otomatis, header dibekukan, dan filter aktif
        for ($index = 1; $index <= Coordinate::columnIndexFromString($lastColumn); $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($range);

        $tempDirectory = storage_path('app/tmp');

        if (! is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0755, true);
        }

        $excelPath = $tempDirectory.'/'.uniqid('data-pendaftaran-', true).'.xlsx';

        (new Xlsx($spreadsheet))->save($excelPath);

        $spreadsheet->disconnectWorksheets();

        return response()->download($excelPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Unduh semua dokumen pendukung pendaftar dalam satu file ZIP.
     */
    public function downloadDocuments($id)
    {
        $registration = StudentRegistration::findOrFail($id);

        $documents = [
            '1-Foto-Calon-Murid' => $registration->photo,
            '2-Akte-Kelahiran' => $registration->birth_certificate,
            '3-Kartu-Keluarga' => $registration->family_card,
            '4-Bukti-Pembayaran' => $registration->payment_proof,
            '5-Bukti-Pindahan' => $registration->transfer_proof,
        ];

        $files = [];

        foreach ($documents as $label => $path) {
            if ($path && Storage::disk('public')->exists($path)) {
                $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
                $files[$label.'.'.$extension] = Storage::disk('public')->path($path);
            }
        }

        if (empty($files)) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen pendukung pendaftar ini tidak ditemukan',
            ], 404);
        }

        $tempDirectory = storage_path('app/tmp');

        if (! is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0755, true);
        }

        $zipPath = $tempDirectory.'/'.uniqid('dokumen-pendaftaran-', true).'.zip';

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat file ZIP',
            ], 500);
        }

        foreach ($files as $name => $fullPath) {
            $zip->addFile($fullPath, $name);
        }

        $zip->close();

        $zipName = 'dokumen-'.Str::slug($registration->full_name).'-'.$registration->id.'.zip';

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    public function show($id)
    {
        $registration = StudentRegistration::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email', 'created_at');
            },
            'academicYear',
            'activePlacement.classroom',
            'student',
        ])->findOrFail($id);

        $registration->photo_url = $registration->photo ? asset('storage/'.$registration->photo) : null;
        $registration->birth_certificate_url = $registration->birth_certificate ? asset('storage/'.$registration->birth_certificate) : null;
        $registration->family_card_url = $registration->family_card ? asset('storage/'.$registration->family_card) : null;
        $registration->payment_proof_url = $registration->payment_proof ? asset('storage/'.$registration->payment_proof) : null;
        $registration->transfer_proof_url = $registration->transfer_proof ? asset('storage/'.$registration->transfer_proof) : null;

        return response()->json([
            'success' => true,
            'data' => $registration,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:submitted,review,approved,rejected',
            'notes' => 'nullable|string|max:500',
        ]);

        $registration = StudentRegistration::findOrFail($id);

        $oldStatus = $registration->status;
        $registration->status = $request->status;
        $registration->notes = $request->notes;
        $registration->save();

        // Bila status tidak lagi "Diterima", penempatan kelasnya dinonaktifkan.
        // Riwayatnya tetap tersimpan (is_active = false), bukan dihapus.
        if ($request->status !== 'approved') {
            ClassroomPlacement::where('student_registration_id', $registration->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'unassigned_at' => now(),
                ]);

            // Siswa tidak dihapus, cukup dinonaktifkan (data tetap terlacak)
            Student::where('registration_id', $registration->id)
                ->where('status', Student::STATUS_ACTIVE)
                ->update(['status' => Student::STATUS_INACTIVE]);
        } else {
            // Diterima -> siswa dibentuk. Idempotent, jadi aman diulang.
            $student = Student::ensureForRegistration($registration);

            // Bila sebelumnya dinonaktifkan karena status pendaftaran berubah,
            // aktifkan kembali. Status "Lulus" tidak diubah otomatis.
            if ($student->status === Student::STATUS_INACTIVE) {
                $student->update(['status' => Student::STATUS_ACTIVE]);
            }
        }

        // Log activity
        // activity('registration')
        //     ->performedOn($registration)
        //     ->causedBy(auth()->user())
        //     ->withProperties([
        //         'old_status' => $oldStatus,
        //         'new_status' => $request->status,
        //         'notes' => $request->notes
        //     ])
        //     ->log('Status pendaftaran diubah');

        $registration->load(['user', 'academicYear', 'activePlacement.classroom', 'student']);

        return response()->json([
            'success' => true,
            'message' => 'Status pendaftaran berhasil diperbarui',
            'data' => $registration,
        ]);
    }

    /**
     * Hapus satu pendaftaran beserta data siswa turunannya.
     *
     * Foreign key akan menghapus siswa, riwayat kelas, nilai, kehadiran,
     * dan kelulusan terkait. Akun orang tua tetap disimpan karena dapat
     * dipakai untuk pendaftaran anak lain.
     */
    public function destroy($id)
    {
        $registration = StudentRegistration::find($id);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran tidak ditemukan',
            ], 404);
        }

        $name = $registration->full_name;
        $files = array_values(array_filter([
            $registration->photo,
            $registration->birth_certificate,
            $registration->family_card,
            $registration->payment_proof,
            $registration->transfer_proof,
        ]));

        DB::transaction(function () use ($registration) {
            $registration->delete();
        });

        if ($files !== []) {
            Storage::disk('public')->delete($files);
        }

        return response()->json([
            'success' => true,
            'message' => "Pendaftaran {$name} berhasil dihapus",
        ]);
    }

    /**
     * Isi / ubah tahun ajaran pada satu pendaftaran.
     *
     * Dipakai untuk melengkapi pendaftaran lama yang belum punya tahun ajaran
     * (mis. data sebelum Master Tahun Ajaran dibuat) agar bisa ditempatkan ke kelas.
     */
    public function setAcademicYear(Request $request, $id)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
        ], [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
        ]);

        $registration = StudentRegistration::with(['academicYear', 'activePlacement.classroom.academicYear'])
            ->find($id);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran tidak ditemukan',
            ], 404);
        }

        $placement = $registration->activePlacement;

        // Tahun ajaran tidak boleh diubah kalau siswa sudah menempati kelas
        // di tahun ajaran yang berbeda, supaya penempatan tidak jadi tidak sinkron.
        if ($placement && (int) $placement->academic_year_id !== (int) $validated['academic_year_id']) {
            $classroomName = $placement->classroom->display_name ?? '-';

            return response()->json([
                'success' => false,
                'message' => 'Siswa sudah ditempatkan di kelas '.$classroomName
                    .' pada tahun ajaran '.($placement->classroom->academicYear->name ?? '-')
                    .'. Pindahkan siswa dari kelas tersebut terlebih dahulu sebelum mengubah tahun ajaran.',
            ], 422);
        }

        $registration->academic_year_id = (int) $validated['academic_year_id'];
        $registration->save();

        $registration->load(['academicYear', 'activePlacement.classroom']);

        return response()->json([
            'success' => true,
            'message' => 'Tahun ajaran pendaftar berhasil disimpan.',
            'data' => $registration,
        ]);
    }

    /**
     * Bentuk entitas Siswa dari pendaftaran yang sudah Diterima.
     *
     * Aman dipanggil berulang: kalau siswanya sudah ada, data yang ada
     * dikembalikan tanpa membuat duplikat.
     */
    public function createStudent($id)
    {
        $registration = StudentRegistration::find($id);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran tidak ditemukan',
            ], 404);
        }

        if ($registration->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Siswa hanya dibentuk dari pendaftaran yang sudah berstatus Diterima.',
            ], 422);
        }

        $sudahAda = $registration->student()->exists();
        $student = Student::ensureForRegistration($registration);

        return response()->json([
            'success' => true,
            'message' => $sudahAda
                ? 'Pendaftar ini sudah memiliki data siswa.'
                : 'Data siswa berhasil dibentuk dari pendaftaran.',
            'data' => $student->load(['admissionYear', 'activePlacement.classroom']),
        ], $sudahAda ? 200 : 201);
    }

    /**
     * Penempatan / pemindahan kelas untuk pendaftar yang sudah Diterima.
     */
    public function assignClassroom(Request $request, $id)
    {
        $validated = $request->validate([
            'classroom_id' => 'required|integer|exists:classrooms,id',
        ], [
            'classroom_id.required' => 'Kelas wajib dipilih.',
            'classroom_id.exists' => 'Kelas tidak ditemukan.',
        ]);

        $registration = StudentRegistration::with(['academicYear', 'activePlacement.classroom'])
            ->find($id);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran tidak ditemukan',
            ], 404);
        }

        if ($registration->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Penempatan kelas hanya untuk pendaftar yang sudah berstatus Diterima.',
            ], 422);
        }

        if (! $registration->academic_year_id) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran ini belum memiliki tahun ajaran. '
                    .'Lengkapi tahun ajaran pendaftar terlebih dahulu.',
            ], 422);
        }

        $classroom = Classroom::with('academicYear')->find($validated['classroom_id']);

        if ((int) $classroom->academic_year_id !== (int) $registration->academic_year_id) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas '.$classroom->display_name.' berada pada tahun ajaran '
                    .($classroom->academicYear->name ?? '-').', berbeda dengan tahun ajaran pendaftar '
                    .($registration->academicYear->name ?? '-').'.',
            ], 422);
        }

        // Penempatan selalu berangkat dari entitas Siswa
        $student = Student::ensureForRegistration($registration);

        // Kelas aktif yang dicari dibatasi pada tahun ajaran yang sama supaya
        // riwayat tahun ajaran lain tetap aktif sebagai bagian dari riwayat siswa.
        $currentPlacement = ClassroomPlacement::where('student_id', $student->id)
            ->where('academic_year_id', $registration->academic_year_id)
            ->where('is_active', true)
            ->first();

        if ($currentPlacement && (int) $currentPlacement->classroom_id === (int) $classroom->id) {
            return response()->json([
                'success' => false,
                'message' => 'Siswa sudah berada di kelas '.$classroom->display_name.'.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $classroom, $currentPlacement, $student) {
                // Kunci baris kelas agar pengecekan kuota tidak bentrok
                // ketika dua admin menyimpan bersamaan.
                $lockedClassroom = Classroom::whereKey($classroom->id)->lockForUpdate()->first();

                $filled = ClassroomPlacement::where('classroom_id', $lockedClassroom->id)
                    ->where('is_active', true)
                    ->count();

                if ($filled >= $lockedClassroom->quota) {
                    throw ValidationException::withMessages([
                        'classroom_id' => 'Kuota kelas '.$lockedClassroom->display_name.' sudah penuh.',
                    ]);
                }

                // Satu siswa hanya boleh punya satu kelas aktif per tahun ajaran.
                $bentrok = ClassroomPlacement::where('student_id', $student->id)
                    ->where('academic_year_id', $lockedClassroom->academic_year_id)
                    ->where('is_active', true)
                    ->where('id', '!=', $currentPlacement?->id)
                    ->exists();

                if ($bentrok) {
                    throw ValidationException::withMessages([
                        'classroom_id' => 'Siswa masih memiliki kelas aktif pada tahun ajaran ini.',
                    ]);
                }

                // Penempatan lama dinonaktifkan, bukan dihapus, agar riwayat tetap ada
                $student->placeIntoClassroom($lockedClassroom, $request->user()?->id);
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        $registration->load(['academicYear', 'activePlacement.classroom']);

        return response()->json([
            'success' => true,
            'message' => $currentPlacement
                ? 'Siswa berhasil dipindahkan ke kelas '.$classroom->display_name.'.'
                : 'Siswa berhasil ditempatkan di kelas '.$classroom->display_name.'.',
            'data' => $registration,
        ]);
    }

    public function statistics()
    {
        $totalByMonth = StudentRegistration::select(
            DB::raw('YEAR(created_at) as year'),
            DB::raw('MONTH(created_at) as month'),
            DB::raw('COUNT(*) as total')
        )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        $byGender = StudentRegistration::select('gender', DB::raw('COUNT(*) as total'))
            ->groupBy('gender')
            ->get();

        $byStatus = StudentRegistration::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'by_month' => $totalByMonth,
                'by_gender' => $byGender,
                'by_status' => $byStatus,
            ],
        ]);
    }
}
