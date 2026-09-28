<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Student;
use App\Models\StudentRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Penempatan kelas untuk pendaftar yang sudah Diterima.
 *
 * Halaman ini khusus mengurus penempatan / pemindahan kelas, termasuk cara
 * cepat lewat Excel: export daftar, isi kolom "Kelas Tujuan", lalu import.
 */
class ClassroomPlacementController extends Controller
{
    /** Kolom yang dipakai untuk export, template, dan import. */
    private const IMPORT_HEADERS = [
        'No. Pendaftaran',
        'NIS',
        'Nama',
        'Tahun Ajaran',
        'Kelas Saat Ini',
        'Kelas Tujuan',
    ];

    /**
     * Query pendaftar yang sudah Diterima (kandidat penempatan kelas).
     */
    private function filteredQuery(Request $request)
    {
        $search = $request->get('search');
        $academicYearId = $request->get('academic_year_id');
        $placement = $request->get('placement');

        $query = StudentRegistration::query()
            ->with(['student', 'academicYear', 'activePlacement.classroom'])
            ->where('status', 'approved');

        if ($academicYearId && $academicYearId !== 'all') {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($placement === 'placed') {
            $query->whereHas('activePlacement');
        } elseif ($placement === 'unplaced') {
            $query->whereDoesntHave('activePlacement');
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('full_name', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($studentQuery) use ($search) {
                        $studentQuery->where('nis', 'like', "%{$search}%");
                    });
            });
        }

        return $query->latest();
    }

    public function index(Request $request)
    {
        // Jumlah pendaftar Diterima yang belum punya tahun ajaran (dihitung
        // dari seluruh data, bukan hanya yang sedang tampil karena filter)
        $tanpaTahunAjaran = StudentRegistration::where('status', 'approved')
            ->whereNull('academic_year_id')
            ->count();

        return response()->json([
            'success' => true,
            'data' => $this->filteredQuery($request)->get(),
            'meta' => [
                'without_academic_year' => $tanpaTahunAjaran,
            ],
        ]);
    }

    /**
     * Isi tahun ajaran untuk semua pendaftaran yang masih kosong sekaligus.
     *
     * Dipakai agar data pendaftaran lama bisa langsung ditempatkan ke kelas
     * tanpa harus dibuka satu per satu.
     */
    public function assignAcademicYear(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
        ], [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
        ]);

        $academicYearId = (int) $validated['academic_year_id'];
        $academicYear = AcademicYear::find($academicYearId);

        $registrationIds = StudentRegistration::whereNull('academic_year_id')->pluck('id');

        if ($registrationIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Semua pendaftaran sudah memiliki tahun ajaran.',
            ], 422);
        }

        $updated = 0;

        DB::transaction(function () use ($registrationIds, $academicYearId, &$updated) {
            $updated = StudentRegistration::whereIn('id', $registrationIds)
                ->update(['academic_year_id' => $academicYearId]);

            // Siswa yang terlanjur terbentuk tanpa tahun masuk ikut dilengkapi
            Student::whereIn('registration_id', $registrationIds)
                ->whereNull('admission_year_id')
                ->update(['admission_year_id' => $academicYearId]);
        });

        return response()->json([
            'success' => true,
            'message' => "Tahun ajaran {$academicYear->name} diisi ke {$updated} pendaftaran "
                .'yang sebelumnya kosong.',
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }

    /**
     * Export daftar pendaftar yang sudah Diterima (mengikuti filter aktif).
     */
    public function export(Request $request)
    {
        $rows = $this->filteredQuery($request)
            ->get()
            ->map(fn (StudentRegistration $registration) => [
                $registration->id,
                $registration->student?->nis ?? '',
                $registration->full_name,
                $registration->academicYear?->name ?? '',
                $registration->activePlacement?->classroom?->display_name ?? '',
                '',
            ])
            ->all();

        $path = $this->saveSpreadsheet($this->buildSpreadsheet($rows), 'data-penempatan-');

        return response()->download($path, 'data-penempatan-kelas-'.now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Template kosong beserta lembar petunjuk.
     */
    public function template()
    {
        $path = $this->saveSpreadsheet($this->buildSpreadsheet([], true), 'template-penempatan-');

        return response()->download($path, 'template-penempatan-kelas.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Import penempatan kelas.
     *
     * Hanya baris yang kolom "Kelas Tujuan"-nya terisi yang diproses, sehingga
     * hasil export bisa langsung diimport kembali tanpa mengubah apa pun.
     * Bila ada satu baris bermasalah, seluruh file ditolak.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ], [
            'file.required' => 'Berkas wajib dipilih.',
            'file.mimes' => 'Format berkas harus .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran berkas maksimal 5MB.',
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas tidak dapat dibaca. Pastikan memakai template yang disediakan.',
            ], 422);
        }

        $rows = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas belum berisi data. Isi kolom Kelas Tujuan pada baris siswa.',
            ], 422);
        }

        $normalize = fn ($value) => preg_replace('/[^a-z]/', '', strtolower((string) $value));
        $headerMap = [];

        foreach ($rows[0] as $index => $header) {
            $headerMap[$normalize($header)] = $index;
        }

        if (! array_key_exists('kelastujuan', $headerMap)) {
            return response()->json([
                'success' => false,
                'message' => 'Kolom "Kelas Tujuan" tidak ditemukan. Gunakan template atau file hasil export '
                    .'yang disediakan (kolom: '.implode(', ', self::IMPORT_HEADERS).').',
            ], 422);
        }

        if (! array_intersect(['nopendaftaran', 'nis', 'nama'], array_keys($headerMap))) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada kolom pengenal siswa. Sediakan minimal salah satu dari '
                    .'"No. Pendaftaran", "NIS", atau "Nama".',
            ], 422);
        }

        $errors = [];
        $prepared = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;

            $registrationId = trim((string) ($row[$headerMap['nopendaftaran'] ?? -1] ?? ''));
            $nis = trim((string) ($row[$headerMap['nis'] ?? -1] ?? ''));
            $name = trim((string) ($row[$headerMap['nama'] ?? -1] ?? ''));
            $yearName = trim((string) ($row[$headerMap['tahunajaran'] ?? -1] ?? ''));
            $targetClass = trim((string) ($row[$headerMap['kelastujuan']] ?? ''));

            // Baris tanpa tujuan kelas tidak diproses (mis. hasil export yang belum diisi)
            if ($targetClass === '') {
                continue;
            }

            $registration = $this->resolveRegistration(
                $registrationId,
                $nis,
                $name,
                $yearName,
                $rowNumber,
                $errors
            );

            if (! $registration) {
                continue;
            }

            // Tahun ajaran pendaftar boleh dilengkapi dari kolom "Tahun Ajaran"
            // di berkas, supaya data pendaftaran lama bisa langsung ditempatkan.
            $fillYear = false;
            $sheetYear = $yearName !== '' ? AcademicYear::where('name', $yearName)->first() : null;

            if (! $registration->academic_year_id) {
                if (! $sheetYear) {
                    $errors[] = "Baris {$rowNumber}: {$registration->full_name} belum memiliki tahun ajaran. "
                        .'Isi kolom "Tahun Ajaran" pada baris ini (mis. 2026/2027), atau pakai tombol '
                        .'"Isi Tahun Ajaran" di halaman Penempatan Kelas.';

                    continue;
                }

                $registration->academic_year_id = $sheetYear->id;
                $registration->setRelation('academicYear', $sheetYear);
                $fillYear = true;
            } elseif ($sheetYear && (int) $registration->academic_year_id !== (int) $sheetYear->id) {
                $errors[] = "Baris {$rowNumber}: tahun ajaran di berkas ({$yearName}) berbeda dengan "
                    .'tahun ajaran '.($registration->academicYear->name ?? '-')
                    ." milik {$registration->full_name}.";

                continue;
            }

            $classroom = $this->resolveClassroom($registration, $targetClass);

            if (! $classroom) {
                $errors[] = "Baris {$rowNumber}: kelas \"{$targetClass}\" tidak ditemukan pada tahun ajaran "
                    .($registration->academicYear->name ?? '-').'.';

                continue;
            }

            if (! $classroom->is_active) {
                $errors[] = "Baris {$rowNumber}: kelas {$classroom->display_name} sedang tidak aktif.";

                continue;
            }

            // Pengecekan memakai data penempatan langsung agar tidak ada data
            // yang dibuat sebelum berkas dinyatakan valid seluruhnya.
            $currentPlacement = ClassroomPlacement::where('student_registration_id', $registration->id)
                ->where('academic_year_id', $classroom->academic_year_id)
                ->where('is_active', true)
                ->first();

            // Sudah di kelas tersebut: tidak perlu diubah
            if ($currentPlacement && (int) $currentPlacement->classroom_id === (int) $classroom->id) {
                continue;
            }

            $prepared[] = [
                'row' => $rowNumber,
                'registration' => $registration,
                'classroom' => $classroom,
                'from_classroom_id' => $currentPlacement?->classroom_id,
                'fill_year' => $fillYear,
            ];
        }

        if (empty($prepared) && empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada baris dengan "Kelas Tujuan" yang bisa diproses.',
            ], 422);
        }

        // Simulasi kuota: memperhitungkan siswa yang pindah keluar dari kelas asalnya
        if (empty($errors)) {
            $errors = $this->checkQuota($prepared);
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' baris yang perlu diperbaiki. Tidak ada data yang disimpan.',
                'errors' => $errors,
            ], 422);
        }

        $assignedBy = $request->user()?->id;
        $moved = 0;

        DB::transaction(function () use ($prepared, $assignedBy, &$moved) {
            foreach ($prepared as $item) {
                if ($item['from_classroom_id']) {
                    $moved++;
                }

                // Simpan tahun ajaran yang dilengkapi dari berkas lebih dulu
                if ($item['fill_year']) {
                    $item['registration']->save();
                }

                // Siswa dibentuk setelah seluruh berkas dinyatakan valid
                $student = Student::ensureForRegistration($item['registration']);

                $student->placeIntoClassroom($item['classroom'], $assignedBy);
            }
        });

        $placed = count($prepared) - $moved;

        return response()->json([
            'success' => true,
            'message' => "Penempatan selesai: {$placed} siswa ditempatkan, {$moved} siswa dipindahkan.",
            'data' => [
                'placed' => $placed,
                'moved' => $moved,
            ],
        ]);
    }

    /**
     * Cari pendaftaran dari kolom pengenal yang tersedia di berkas.
     *
     * @param  array<int, string>  $errors
     */
    private function resolveRegistration(
        string $registrationId,
        string $nis,
        string $name,
        string $yearName,
        int $rowNumber,
        array &$errors
    ): ?StudentRegistration {
        if ($registrationId !== '') {
            $registration = StudentRegistration::with('academicYear')->find((int) $registrationId);

            if (! $registration) {
                $errors[] = "Baris {$rowNumber}: nomor pendaftaran {$registrationId} tidak ditemukan.";

                return null;
            }

            return $this->assertApproved($registration, $rowNumber, $errors);
        }

        if ($nis !== '') {
            $registration = StudentRegistration::with('academicYear')
                ->whereHas('student', fn ($query) => $query->where('nis', $nis))
                ->first();

            if (! $registration) {
                $errors[] = "Baris {$rowNumber}: tidak ada siswa dengan NIS {$nis}.";

                return null;
            }

            return $this->assertApproved($registration, $rowNumber, $errors);
        }

        if ($name !== '') {
            $matches = StudentRegistration::with('academicYear')
                ->where('status', 'approved')
                ->where('full_name', $name)
                ->when($yearName !== '', fn ($query) => $query->whereHas(
                    'academicYear',
                    fn ($yearQuery) => $yearQuery->where('name', $yearName)
                ))
                ->get();

            if ($matches->isEmpty()) {
                $errors[] = "Baris {$rowNumber}: tidak ada pendaftar Diterima bernama \"{$name}\".";

                return null;
            }

            if ($matches->count() > 1) {
                $errors[] = "Baris {$rowNumber}: nama \"{$name}\" lebih dari satu pendaftar. "
                    .'Isi kolom No. Pendaftaran atau NIS agar tidak salah orang.';

                return null;
            }

            return $matches->first();
        }

        $errors[] = "Baris {$rowNumber}: salah satu dari No. Pendaftaran, NIS, atau Nama wajib diisi.";

        return null;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function assertApproved(
        StudentRegistration $registration,
        int $rowNumber,
        array &$errors
    ): ?StudentRegistration {
        if ($registration->status !== 'approved') {
            $errors[] = "Baris {$rowNumber}: {$registration->full_name} belum berstatus Diterima, "
                .'jadi belum bisa ditempatkan ke kelas.';

            return null;
        }

        return $registration;
    }

    /**
     * Ubah "1A" / "1 A" menjadi kelas pada tahun ajaran pendaftar.
     */
    private function resolveClassroom(StudentRegistration $registration, string $target): ?Classroom
    {
        $normalized = strtoupper(str_replace(' ', '', $target));

        preg_match('/^([0-9]+)(.*)$/', $normalized, $matches);

        if (empty($matches[1]) || trim($matches[2] ?? '') === '') {
            return null;
        }

        return Classroom::where('academic_year_id', $registration->academic_year_id)
            ->where('grade_level', (int) $matches[1])
            ->where('name', trim($matches[2]))
            ->first();
    }

    /**
     * Pastikan hasil akhir penempatan tidak melebihi kuota kelas.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array<int, string>
     */
    private function checkQuota(array $prepared): array
    {
        $occupancy = [];
        $quotas = [];
        $errors = [];

        foreach ($prepared as $item) {
            /** @var Classroom $classroom */
            $classroom = $item['classroom'];

            if (! isset($occupancy[$classroom->id])) {
                $occupancy[$classroom->id] = ClassroomPlacement::where('classroom_id', $classroom->id)
                    ->where('is_active', true)
                    ->count();
                $quotas[$classroom->id] = $classroom->quota;
            }
        }

        foreach ($prepared as $item) {
            $fromId = $item['from_classroom_id'];
            $toId = $item['classroom']->id;

            // Siswa yang pindah keluar membebaskan kuota kelas asalnya
            if ($fromId && isset($occupancy[$fromId])) {
                $occupancy[$fromId]--;
            }

            $occupancy[$toId]++;

            if ($occupancy[$toId] > $quotas[$toId]) {
                $name = $item['classroom']->display_name;

                $errors[] = "Baris {$item['row']}: kuota kelas {$name} penuh "
                    ."({$quotas[$toId]} kursi). Kurangi jumlah siswa yang ditempatkan ke kelas ini.";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildSpreadsheet(array $rows, bool $withExampleSheet = false): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('SDI Ikhlas Bakti Umat')
            ->setTitle('Penempatan Kelas');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Penempatan');
        $sheet->fromArray(self::IMPORT_HEADERS, null, 'A1');

        $rowNumber = 2;

        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $lastRow = max($rowNumber - 1, 1);
        $lastColumn = $sheet->getHighestColumn();

        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '004AAD'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        if ($lastRow >= 2) {
            $sheet->getStyle('A2:'.$lastColumn.$lastRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D5DCE8'],
                    ],
                ],
            ]);
        }

        for ($index = 1; $index <= Coordinate::columnIndexFromString($lastColumn); $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        $sheet->freezePane('A2');

        if ($withExampleSheet) {
            $guide = $spreadsheet->createSheet();
            $guide->setTitle('Petunjuk');
            $guide->fromArray([
                ['Petunjuk pengisian'],
                ['1. Isi hanya kolom "Kelas Tujuan" pada siswa yang ingin ditempatkan, mis. 1A atau 2B.'],
                ['2. Baris yang kolom "Kelas Tujuan"-nya dikosongkan tidak akan diubah.'],
                ['3. Jangan mengubah nama kolom pada baris pertama.'],
                ['4. Kolom No. Pendaftaran, NIS, Nama, dan Tahun Ajaran dipakai untuk mengenali siswa.'],
                ['5. Paling aman: export daftar dari aplikasi, isi kolom Kelas Tujuan, lalu import kembali.'],
                ['6. Kelas tujuan harus ada pada Master Kelas di tahun ajaran siswa tersebut.'],
                ['7. Kuota kelas tetap dicek: bila melebihi kuota, seluruh berkas ditolak.'],
                ['8. Siswa yang dipindah otomatis keluar dari kelas lamanya (riwayatnya tetap tersimpan).'],
                [],
                ['Contoh isian:'],
                self::IMPORT_HEADERS,
                [12, '10001', 'Budi Santoso', '2026/2027', '', '1A'],
                [13, '10002', 'Andi Wijaya', '2026/2027', '1B', '1A'],
            ]);
            $guide->getColumnDimension('A')->setWidth(95);
            $spreadsheet->setActiveSheetIndex(0);
        }

        return $spreadsheet;
    }

    private function saveSpreadsheet(Spreadsheet $spreadsheet, string $prefix): string
    {
        $directory = storage_path('app/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/'.uniqid($prefix, true).'.xlsx';

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
