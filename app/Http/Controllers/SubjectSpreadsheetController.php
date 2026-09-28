<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SubjectSpreadsheetController extends Controller
{
    private const HEADERS = ['Kode', 'Nama Mata Pelajaran', 'Tingkat', 'Status'];

    public function export()
    {
        $rows = Subject::ordered()->get()->map(fn (Subject $subject) => [
            $subject->code,
            $subject->name,
            $subject->grade_level ? 'Tingkat '.$subject->grade_level : 'Semua Tingkat',
            $subject->is_active ? 'Aktif' : 'Tidak Aktif',
        ])->all();

        return $this->download($rows, 'data-mata-pelajaran-'.now()->format('Y-m-d-His').'.xlsx');
    }

    public function template()
    {
        return $this->download([], 'template-import-mata-pelajaran.xlsx', true);
    }

    private function download(array $rows, string $filename, bool $withGuide = false)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mata Pelajaran');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType('solid')
            ->getStartColor()->setRGB('DCEBFF');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:D1');
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(36);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(18);

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2;
            foreach ($row as $index => $value) {
                $sheet->getCell(chr(65 + $index).$rowNumber)->setValueExplicit(
                    (string) $value,
                    DataType::TYPE_STRING
                );
            }
        }

        if ($withGuide) {
            $guide = $spreadsheet->createSheet();
            $guide->setTitle('Petunjuk');
            $guide->fromArray([
                ['Petunjuk impor mata pelajaran'],
                ['Isi sheet Mata Pelajaran mulai baris 2. Jangan ubah judul kolom.'],
                ['Kode dan Nama Mata Pelajaran wajib diisi. Kode digunakan untuk mencocokkan data.'],
                ['Kode yang sudah ada akan diperbarui; kode baru akan ditambahkan. ID ditentukan sistem.'],
                ['Tingkat diisi 1 sampai 6, "Tingkat 1" sampai "Tingkat 6", atau "Semua Tingkat". Kosong berarti Semua Tingkat.'],
                ['Status diisi Aktif atau Tidak Aktif. Kosong berarti Aktif.'],
                ['Jika satu baris salah, seluruh impor dibatalkan. Maksimal 1000 baris data.'],
            ]);
            $guide->getColumnDimension('A')->setWidth(115);
            $spreadsheet->setActiveSheetIndex(0);
        }

        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = tempnam($directory, 'subject-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

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
            $rows = $spreadsheet->getSheet(0)->toArray(null, false, true, false);
            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas tidak dapat dibaca. Gunakan template atau hasil ekspor mata pelajaran.',
            ], 422);
        }

        if (count($rows) > 1001) {
            return response()->json([
                'success' => false,
                'message' => 'Maksimal 1000 baris mata pelajaran per berkas.',
            ], 422);
        }

        $normalize = fn ($value) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value)));
        $headers = [];
        foreach ($rows[0] ?? [] as $index => $header) {
            $headers[$normalize($header)] = $index;
        }
        foreach (self::HEADERS as $header) {
            if (! array_key_exists($normalize($header), $headers)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kolom "'.$header.'" tidak ditemukan. Gunakan template atau hasil ekspor mata pelajaran.',
                ], 422);
            }
        }

        $prepared = [];
        $errors = [];
        $seenCodes = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $line = $offset + 2;
            $cell = fn (string $column) => trim((string) ($row[$headers[$normalize($column)]] ?? ''));
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $code = strtoupper($cell('Kode'));
            $name = $cell('Nama Mata Pelajaran');
            $gradeRaw = $normalize($cell('Tingkat'));
            $statusRaw = $normalize($cell('Status'));

            $grade = match (true) {
                $gradeRaw === '', $gradeRaw === 'semua', $gradeRaw === 'semuatingkat' => null,
                preg_match('/^(?:tingkat|kelas)?([1-6])$/', $gradeRaw, $matches) === 1 => (int) $matches[1],
                default => false,
            };
            $active = match ($statusRaw) {
                '', 'aktif' => true,
                'tidakaktif', 'nonaktif' => false,
                default => null,
            };

            if ($code === '' || mb_strlen($code) > 30) {
                $errors[] = "Baris {$line}: kode wajib diisi, maksimal 30 karakter.";
            } elseif (isset($seenCodes[$code])) {
                $errors[] = "Baris {$line}: kode {$code} muncul lebih dari sekali dalam berkas.";
            }
            $seenCodes[$code] = true;

            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = "Baris {$line}: nama mata pelajaran wajib diisi, maksimal 255 karakter.";
            }
            if ($grade === false) {
                $errors[] = "Baris {$line}: tingkat harus 1 sampai 6 atau Semua Tingkat.";
            }
            if ($active === null) {
                $errors[] = "Baris {$line}: status harus Aktif atau Tidak Aktif.";
            }

            $prepared[] = [
                'code' => $code,
                'name' => $name,
                'grade_level' => $grade === false ? null : $grade,
                'is_active' => $active,
            ];
        }

        if (! $prepared || $errors) {
            return response()->json([
                'success' => false,
                'message' => ! $prepared
                    ? 'Tidak ada data mata pelajaran yang bisa diimpor.'
                    : 'Ada '.count($errors).' kesalahan. Tidak ada data yang disimpan.',
                'errors' => $errors,
            ], 422);
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($prepared, &$created, &$updated) {
            $existing = Subject::query()->lockForUpdate()->get()->keyBy(
                fn (Subject $subject) => strtoupper($subject->code)
            );

            foreach ($prepared as $data) {
                $subject = $existing->get($data['code']);
                if ($subject) {
                    $subject->update($data);
                    $updated++;
                } else {
                    $subject = Subject::create($data);
                    $existing->put($data['code'], $subject);
                    $created++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Impor mata pelajaran selesai: {$created} ditambahkan, {$updated} diperbarui.",
            'data' => ['created' => $created, 'updated' => $updated],
        ]);
    }
}
