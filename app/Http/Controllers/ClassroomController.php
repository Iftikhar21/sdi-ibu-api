<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ClassroomController extends Controller
{
    /** Kolom yang dipakai untuk export, template, dan import. */
    private const IMPORT_HEADERS = ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'];

    /** Pastikan nama kelas hanya Ikhwan/Akhwat; A/B tetap diterima sebagai alias lama. */
    private function normalizeClassroomName(string $name): string
    {
        $normalized = Classroom::normalizeName($name);

        if (! $normalized) {
            throw ValidationException::withMessages([
                'name' => 'Nama kelas harus Ikhwan atau Akhwat.',
            ]);
        }

        return $normalized;
    }

    /**
     * Satu kombinasi tahun ajaran + tingkat + nama kelas tidak boleh ganda.
     */
    private function assertNotDuplicate(array $data, ?int $ignoreId = null): void
    {
        $exists = Classroom::where('academic_year_id', $data['academic_year_id'])
            ->where('grade_level', $data['grade_level'])
            ->where('name', $data['name'])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Kelas ini sudah ada pada tahun ajaran tersebut.',
            ]);
        }
    }

    public function index(Request $request)
    {
        $query = Classroom::with('academicYear')
            ->withCount('activePlacements')
            ->ordered();

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('grade_level', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function show($id)
    {
        $classroom = Classroom::with('academicYear')->withCount('activePlacements')->find($id);

        if (! $classroom) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $classroom,
        ]);
    }

    /**
     * Daftar siswa yang sudah ditempatkan di kelas ini (penempatan aktif).
     */
    public function students($id)
    {
        $classroom = Classroom::with('academicYear')->withCount('activePlacements')->find($id);

        if (! $classroom) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        $students = ClassroomPlacement::with([
            'registration' => function ($query) {
                $query->select(
                    'id',
                    'full_name',
                    'nickname',
                    'gender',
                    'birth_place',
                    'birth_date',
                    'contact_email',
                    'phone',
                    'status'
                );
            },
        ])
            ->where('classroom_id', $classroom->id)
            ->where('is_active', true)
            ->orderBy('assigned_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'classroom' => $classroom,
                'students' => $students,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'grade_level' => 'required|integer|between:1,6',
            'name' => 'required|string|max:10',
            'quota' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
        ], [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
            'grade_level.required' => 'Tingkat kelas wajib dipilih.',
            'grade_level.between' => 'Tingkat kelas harus antara 1 sampai 6.',
            'name.required' => 'Nama kelas wajib diisi.',
            'quota.required' => 'Kuota wajib diisi.',
            'quota.min' => 'Kuota harus berupa angka positif.',
        ]);

        $payload = [
            'academic_year_id' => (int) $validated['academic_year_id'],
            'grade_level' => (int) $validated['grade_level'],
            'name' => $this->normalizeClassroomName($validated['name']),
        ];

        $this->assertNotDuplicate($payload);

        $classroom = Classroom::create([
            ...$payload,
            'quota' => (int) $validated['quota'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil ditambahkan',
            'data' => $classroom->load('academicYear'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $classroom = Classroom::find($id);

        if (! $classroom) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        $request->validate([
            'academic_year_id' => 'sometimes|required|integer|exists:academic_years,id',
            'grade_level' => 'sometimes|required|integer|between:1,6',
            'name' => 'sometimes|required|string|max:10',
            'quota' => 'sometimes|required|integer|min:1',
            'is_active' => 'nullable|boolean',
        ], [
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan.',
            'grade_level.between' => 'Tingkat kelas harus antara 1 sampai 6.',
            'quota.min' => 'Kuota harus berupa angka positif.',
        ]);

        $payload = [
            'academic_year_id' => (int) $request->input('academic_year_id', $classroom->academic_year_id),
            'grade_level' => (int) $request->input('grade_level', $classroom->grade_level),
            'name' => $this->normalizeClassroomName(
                (string) $request->input('name', $classroom->name)
            ),
        ];

        $this->assertNotDuplicate($payload, $classroom->id);

        $classroom->fill([
            ...$payload,
            'quota' => $request->has('quota')
                ? (int) $request->input('quota')
                : $classroom->quota,
        ]);

        if ($request->has('is_active')) {
            $classroom->is_active = $request->boolean('is_active');
        }

        $classroom->save();

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil diperbarui',
            'data' => $classroom->load('academicYear'),
        ]);
    }

    public function destroy($id)
    {
        $classroom = Classroom::find($id);

        if (! $classroom) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        $classroom->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil dihapus',
        ]);
    }

    /**
     * Susun file Excel dari data kelas.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildSpreadsheet(array $rows, bool $withExampleSheet = false): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('SDI Ikhlas Bakti Umat')
            ->setTitle('Data Kelas');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kelas');
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

        // Lembar petunjuk: tidak dibaca oleh proses import
        if ($withExampleSheet) {
            $guide = $spreadsheet->createSheet();
            $guide->setTitle('Petunjuk');
            $guide->fromArray([
                ['Petunjuk pengisian'],
                ['1. Isi data pada sheet "Kelas", mulai baris ke-2.'],
                ['2. Jangan mengubah nama kolom pada baris pertama.'],
                ['3. Kolom Tahun Ajaran harus sama persis dengan Master Tahun Ajaran, mis. 2026/2027.'],
                ['4. Tingkat diisi angka 1 sampai 6.'],
                ['5. Nama Kelas diisi "Ikhwan" atau "Akhwat". A/B dari format lama tetap dapat diimport.'],
                ['6. Kuota diisi angka lebih dari 0.'],
                ['7. Status diisi "Aktif" atau "Tidak Aktif" (boleh dikosongkan, berarti Aktif).'],
                ['8. Bila kombinasi Tahun Ajaran + Tingkat + Nama Kelas sudah ada, datanya akan diperbarui.'],
                [],
                ['Contoh isian:'],
                self::IMPORT_HEADERS,
                ['2026/2027', 1, 'Ikhwan', 28, 'Aktif'],
                ['2026/2027', 1, 'Akhwat', 28, 'Aktif'],
            ]);
            $guide->getColumnDimension('A')->setWidth(90);
            $spreadsheet->setActiveSheetIndex(0);
        }

        return $spreadsheet;
    }

    /**
     * Unduh data kelas yang sudah ada (untuk diedit lalu diimport kembali).
     */
    public function export()
    {
        $rows = Classroom::with('academicYear')
            ->orderBy('academic_year_id')
            ->ordered()
            ->get()
            ->map(fn ($classroom) => [
                $classroom->academicYear->name ?? '',
                $classroom->grade_level,
                $classroom->name,
                $classroom->quota,
                $classroom->is_active ? 'Aktif' : 'Tidak Aktif',
            ])
            ->all();

        $path = $this->saveSpreadsheet($this->buildSpreadsheet($rows), 'data-kelas-');

        return response()->download($path, 'data-kelas-'.now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Unduh template kosong beserta lembar petunjuk.
     */
    public function template()
    {
        $path = $this->saveSpreadsheet($this->buildSpreadsheet([], true), 'template-kelas-');

        return response()->download($path, 'template-import-kelas.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
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

    /**
     * Import data kelas dari file Excel/CSV.
     *
     * Baris yang sudah ada (tahun ajaran + tingkat + nama kelas) diperbarui,
     * sisanya dibuat baru. Bila ada satu baris bermasalah, seluruh file ditolak
     * agar tidak ada data yang masuk setengah-setengah.
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
                'message' => 'Berkas belum berisi data. Isi baris di bawah baris judul.',
            ], 422);
        }

        // Pemetaan kolom berdasarkan nama judul agar urutan kolom lebih fleksibel
        $normalize = fn ($value) => preg_replace('/[^a-z]/', '', strtolower((string) $value));
        $headerMap = [];

        foreach ($rows[0] as $index => $header) {
            $headerMap[$normalize($header)] = $index;
        }

        $requiredColumns = ['tahunajaran', 'tingkat', 'namakelas', 'kuota'];

        foreach ($requiredColumns as $column) {
            if (! array_key_exists($column, $headerMap)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kolom wajib tidak ditemukan. Gunakan template yang disediakan '
                        .'(butuh kolom: '.implode(', ', self::IMPORT_HEADERS).').',
                ], 422);
            }
        }

        $academicYearIds = AcademicYear::pluck('id', 'name');
        $errors = [];
        $prepared = [];
        $seen = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;

            $name = trim((string) ($row[$headerMap['tahunajaran']] ?? ''));
            $gradeRaw = trim((string) ($row[$headerMap['tingkat']] ?? ''));
            $classRaw = trim((string) ($row[$headerMap['namakelas']] ?? ''));
            $quotaRaw = trim((string) ($row[$headerMap['kuota']] ?? ''));
            $statusRaw = trim((string) ($row[$headerMap['status']] ?? ''));

            // Lewati baris yang benar-benar kosong
            if ($name === '' && $gradeRaw === '' && $classRaw === '' && $quotaRaw === '') {
                continue;
            }

            if ($name === '' || ! $academicYearIds->has($name)) {
                $errors[] = "Baris {$rowNumber}: tahun ajaran \"{$name}\" belum terdaftar di Master Tahun Ajaran.";

                continue;
            }

            $grade = (int) preg_replace('/[^0-9]/', '', $gradeRaw);

            if ($grade < 1 || $grade > 6) {
                $errors[] = "Baris {$rowNumber}: tingkat harus angka 1 sampai 6.";

                continue;
            }

            $className = Classroom::normalizeName($classRaw);

            if (! $className) {
                $errors[] = "Baris {$rowNumber}: nama kelas harus Ikhwan atau Akhwat.";

                continue;
            }

            $quota = (int) preg_replace('/[^0-9]/', '', $quotaRaw);

            if ($quota < 1) {
                $errors[] = "Baris {$rowNumber}: kuota harus angka lebih dari 0.";

                continue;
            }

            $key = $name.'|'.$grade.'|'.$className;

            if (isset($seen[$key])) {
                $errors[] = "Baris {$rowNumber}: kelas {$grade} {$className} pada {$name} tercantum lebih dari sekali.";

                continue;
            }

            $seen[$key] = true;

            $prepared[] = [
                'row' => $rowNumber,
                'academic_year_id' => $academicYearIds[$name],
                'grade_level' => $grade,
                'name' => $className,
                'quota' => $quota,
                'is_active' => $statusRaw === '' ? true : ! in_array($normalize($statusRaw), ['tidakaktif', 'nonaktif', '0', 'false'], true),
            ];
        }

        // Jangan sampai kuota baru lebih kecil dari siswa yang sudah ditempatkan di kelas itu.
        $filledCounts = Classroom::withCount([
            'placements as filled_count' => fn ($query) => $query->where('is_active', true),
        ])->get()->keyBy(fn ($classroom) => $classroom->academic_year_id.'|'.$classroom->grade_level.'|'.$classroom->name);

        foreach ($prepared as &$row) {
            $classroom = $filledCounts->get($row['academic_year_id'].'|'.$row['grade_level'].'|'.$row['name']);

            if ($classroom && $row['quota'] < $classroom->filled_count) {
                $errors[] = "Baris {$row['row']}: kuota {$row['quota']} lebih kecil dari "
                    ."{$classroom->filled_count} siswa yang sudah ditempatkan di kelas {$classroom->display_name}.";
            }
        }

        unset($row);

        if (empty($prepared)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data yang bisa diimport.',
                'errors' => $errors,
            ], 422);
        }

        if (! empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ada '.count($errors).' baris yang perlu diperbaiki. Tidak ada data yang disimpan.',
                'errors' => $errors,
            ], 422);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($prepared, &$created, &$updated) {
            foreach ($prepared as $data) {
                unset($data['row']);

                $classroom = Classroom::where('academic_year_id', $data['academic_year_id'])
                    ->where('grade_level', $data['grade_level'])
                    ->where('name', $data['name'])
                    ->first();

                if ($classroom) {
                    $classroom->update([
                        'quota' => $data['quota'],
                        'is_active' => $data['is_active'],
                    ]);
                    $updated++;

                    continue;
                }

                Classroom::create($data);
                $created++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Import selesai: {$created} kelas baru, {$updated} kelas diperbarui.",
            'data' => [
                'created' => $created,
                'updated' => $updated,
            ],
        ]);
    }
}
