<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GradeSpreadsheetController extends Controller
{
    private const HEADER_ROW = 5;

    private const FIRST_DATA_ROW = 6;

    public function export(Request $request)
    {
        $context = $this->validateContext($request);
        $data = $this->gradeData($request);

        if (! $data['classroom']) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak tersedia pada tahun ajaran yang dipilih.',
            ], 422);
        }

        if (empty($data['students']) || empty($data['subjects'])) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas harus memiliki siswa dan mata pelajaran sebelum nilai diekspor.',
            ], 422);
        }

        $grades = collect($data['grades'])->keyBy(
            fn (array $grade) => $grade['student_id'].'|'.$grade['subject_id']
        );
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nilai');

        $lastColumnIndex = 3 + count($data['subjects']);
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
        $lastRow = self::FIRST_DATA_ROW + count($data['students']) - 1;

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', 'Input Nilai Siswa');
        $sheet->setCellValue('A2', 'Tahun Ajaran');
        $sheet->setCellValue('B2', $data['academic_year']['name']);
        $sheet->setCellValue('A3', 'Kelas');
        $sheet->setCellValue('B3', $data['classroom']['display_name']);
        $sheet->setCellValue('C3', 'Semester');
        $sheet->setCellValue('D3', $context['semester'].' ('.$data['semesters'][$context['semester']].')');
        $sheet->setCellValue('A'.self::HEADER_ROW, '_ID_SISWA');
        $sheet->setCellValue('B'.self::HEADER_ROW, 'NIS');
        $sheet->setCellValue('C'.self::HEADER_ROW, 'Nama Siswa');

        foreach ($data['subjects'] as $offset => $subject) {
            $column = Coordinate::stringFromColumnIndex($offset + 4);
            $sheet->setCellValue(
                $column.self::HEADER_ROW,
                $subject['code'].' - '.$subject['name']
            );
            $sheet->getColumnDimension($column)->setWidth(18);
        }

        foreach ($data['students'] as $offset => $student) {
            $row = self::FIRST_DATA_ROW + $offset;
            $sheet->setCellValueExplicit('A'.$row, (string) $student['id'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, (string) ($student['nis'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $student['full_name']);

            foreach ($data['subjects'] as $subjectOffset => $subject) {
                $column = Coordinate::stringFromColumnIndex($subjectOffset + 4);
                $grade = $grades->get($student['id'].'|'.$subject['id']);

                if ($grade) {
                    $sheet->setCellValue($column.$row, (float) $grade['score']);
                }
            }
        }

        $sheet->getColumnDimension('A')->setVisible(false);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(32);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.self::HEADER_ROW.':'.$lastColumn.self::HEADER_ROW)->getFont()->setBold(true);
        $sheet->getStyle('A'.self::HEADER_ROW.':'.$lastColumn.self::HEADER_ROW)
            ->getFill()->setFillType('solid')->getStartColor()->setRGB('DCEBFF');
        $sheet->getStyle('D'.self::FIRST_DATA_ROW.':'.$lastColumn.$lastRow)
            ->getNumberFormat()->setFormatCode('0.00');
        $sheet->freezePane('D'.self::FIRST_DATA_ROW);
        $sheet->setAutoFilter('B'.self::HEADER_ROW.':'.$lastColumn.$lastRow);

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->fromArray([
            ['Petunjuk import nilai siswa'],
            ['Edit nilai pada sheet Nilai. Jangan mengubah nama sheet, judul kolom, NIS, atau Nama Siswa.'],
            ['Nilai harus berupa angka 0 sampai 100. Maksimal dua angka di belakang koma.'],
            ['Sel nilai yang dikosongkan akan menghapus nilai yang sebelumnya tersimpan.'],
            ['File hanya dapat diimport pada tahun ajaran, kelas, dan semester yang sama dengan saat export.'],
            ['Kolom identitas sistem disembunyikan dan tidak perlu diubah.'],
            ['Jika satu nilai salah, seluruh import dibatalkan.'],
        ]);
        $guide->getColumnDimension('A')->setWidth(115);

        $system = $spreadsheet->createSheet();
        $system->setTitle('_Sistem');
        $system->fromArray([
            ['academic_year_id', $context['academic_year_id']],
            ['classroom_id', $context['classroom_id']],
            ['semester', $context['semester']],
        ]);
        $system->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = tempnam($directory, 'grade-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $className = preg_replace('/[^A-Za-z0-9_-]/', '-', $data['classroom']['display_name']);
        $filename = 'nilai-'.$className.'-semester-'.$context['semester'].'-'.now()->format('Y-m-d-His').'.xlsx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request)
    {
        $context = $this->validateContext($request);
        $request->validate([
            'file' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'file.required' => 'Berkas nilai wajib dipilih.',
            'file.mimes' => 'Format berkas nilai harus .xlsx dari hasil export.',
            'file.max' => 'Ukuran berkas maksimal 5MB.',
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas tidak dapat dibaca. Gunakan hasil export nilai dari sistem.',
            ], 422);
        }

        $sheet = $spreadsheet->getSheetByName('Nilai');
        $system = $spreadsheet->getSheetByName('_Sistem');

        if (! $sheet || ! $system) {
            $spreadsheet->disconnectWorksheets();

            return response()->json([
                'success' => false,
                'message' => 'Struktur berkas tidak sesuai. Gunakan hasil export nilai dari sistem.',
            ], 422);
        }

        $fileContext = [
            'academic_year_id' => (int) $system->getCell('B1')->getValue(),
            'classroom_id' => (int) $system->getCell('B2')->getValue(),
            'semester' => (int) $system->getCell('B3')->getValue(),
        ];

        if ($fileContext !== $context) {
            $spreadsheet->disconnectWorksheets();

            return response()->json([
                'success' => false,
                'message' => 'Berkas berasal dari tahun ajaran, kelas, atau semester yang berbeda. Sesuaikan pilihan lalu coba lagi.',
            ], 422);
        }

        $data = $this->gradeData($request);
        if (! $data['classroom']) {
            $spreadsheet->disconnectWorksheets();

            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak tersedia pada tahun ajaran yang dipilih.',
            ], 422);
        }

        $normalize = fn ($value) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
        $subjectsByHeader = [];
        foreach ($data['subjects'] as $subject) {
            $subjectsByHeader[$normalize($subject['code'])] = $subject['id'];
            $subjectsByHeader[$normalize($subject['code'].' - '.$subject['name'])] = $subject['id'];
        }
        $students = collect($data['students'])->keyBy('id');
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestRow = $sheet->getHighestDataRow();
        $errors = [];
        $subjectColumns = [];
        $seenSubjects = [];

        if ($highestRow > 1005) {
            $errors[] = 'Berkas maksimal berisi 1000 baris siswa.';
        }

        for ($column = 4; $column <= $highestColumn; $column++) {
            $header = $normalize($sheet->getCell([$column, self::HEADER_ROW])->getValue());
            if ($header === '') {
                continue;
            }

            $subjectId = $subjectsByHeader[$header] ?? null;
            if (! $subjectId) {
                $errors[] = 'Kolom "'.$sheet->getCell([$column, self::HEADER_ROW])->getValue().'" bukan mata pelajaran yang tersedia.';

                continue;
            }
            if (isset($seenSubjects[$subjectId])) {
                $errors[] = 'Mata pelajaran pada kolom '.$column.' tercantum lebih dari sekali.';

                continue;
            }

            $seenSubjects[$subjectId] = true;
            $subjectColumns[$column] = $subjectId;
        }

        if (! $subjectColumns) {
            $errors[] = 'Tidak ada kolom mata pelajaran yang dapat diimport.';
        }

        $prepared = [];
        $seenStudents = [];
        for ($row = self::FIRST_DATA_ROW; $row <= min($highestRow, 1005); $row++) {
            $studentIdRaw = trim((string) $sheet->getCell([1, $row])->getValue());
            $rowHasValue = $studentIdRaw !== '';
            foreach (array_keys($subjectColumns) as $column) {
                if (trim((string) $sheet->getCell([$column, $row])->getValue()) !== '') {
                    $rowHasValue = true;
                    break;
                }
            }
            if (! $rowHasValue) {
                continue;
            }

            $studentId = ctype_digit($studentIdRaw) ? (int) $studentIdRaw : 0;
            $student = $students->get($studentId);
            if (! $student) {
                $errors[] = "Baris {$row}: siswa tidak ditemukan pada kelas yang dipilih.";

                continue;
            }
            if (isset($seenStudents[$studentId])) {
                $errors[] = "Baris {$row}: siswa {$student['full_name']} tercantum lebih dari sekali.";

                continue;
            }
            $seenStudents[$studentId] = true;

            foreach ($subjectColumns as $column => $subjectId) {
                $raw = $sheet->getCell([$column, $row])->getValue();
                $value = trim((string) $raw);

                if ($value === '') {
                    $score = null;
                } elseif (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                    $errors[] = "Baris {$row}, kolom {$column}: nilai harus berupa angka 0 sampai 100.";

                    continue;
                } else {
                    $score = (float) $raw;
                }

                $prepared[] = [
                    'student_id' => $studentId,
                    'subject_id' => $subjectId,
                    'score' => $score,
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        if (! $prepared || $errors) {
            return response()->json([
                'success' => false,
                'message' => ! $prepared
                    ? 'Tidak ada nilai yang dapat diimport.'
                    : 'Ada '.count($errors).' kesalahan. Tidak ada nilai yang disimpan.',
                'errors' => ['scores' => $errors],
            ], 422);
        }

        $request->merge([
            'academic_year_id' => $context['academic_year_id'],
            'classroom_id' => $context['classroom_id'],
            'semester' => $context['semester'],
            'scores' => $prepared,
        ]);

        return (new GradeController)->store($request);
    }

    private function validateContext(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'classroom_id' => 'required|integer|exists:classrooms,id',
            'semester' => 'required|integer|in:1,2',
        ]);

        return [
            'academic_year_id' => (int) $validated['academic_year_id'],
            'classroom_id' => (int) $validated['classroom_id'],
            'semester' => (int) $validated['semester'],
        ];
    }

    private function gradeData(Request $request): array
    {
        return (new GradeController)->index($request)->getData(true)['data'];
    }
}
