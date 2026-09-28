<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TeacherSpreadsheetController extends Controller
{
    private const HEADERS = [
        'Nama Lengkap', 'Email', 'Jenis Kelamin', 'Pendidikan Terakhir',
        'Jabatan', 'No Telepon', 'Alamat', 'Status',
    ];

    public function export()
    {
        $rows = Teacher::ordered()->get()->map(fn (Teacher $teacher) => [
            $teacher->name,
            $teacher->email ?? '',
            $teacher->gender,
            $teacher->last_education ?? '',
            $teacher->position ?? '',
            $teacher->phone ?? '',
            $teacher->address ?? '',
            $teacher->is_active ? 'Aktif' : 'Tidak Aktif',
        ])->all();

        return $this->download($rows, 'data-guru-'.now()->format('Y-m-d-His').'.xlsx');
    }

    public function template()
    {
        return $this->download([], 'template-import-guru.xlsx', true);
    }

    private function download(array $rows, string $filename, bool $withGuide = false)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Guru');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType('solid')
            ->getStartColor()->setRGB('DCEBFF');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:H1');

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(match ($column) {
                'A', 'D', 'E' => 25,
                'B', 'G' => 32,
                default => 17,
            });
        }

        // Kolom teks ditulis eksplisit agar nomor telepon dan karakter awal = tidak berubah.
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
            $sheet->getStyle('F2:F1001')->getNumberFormat()->setFormatCode('@');
            $guide = $spreadsheet->createSheet();
            $guide->setTitle('Petunjuk');
            $guide->fromArray([
                ['Petunjuk impor guru'],
                ['Isi sheet Guru mulai baris 2. Jangan ubah judul kolom.'],
                ['Sistem mencocokkan guru lewat email; jika email kosong, lewat nama dan jenis kelamin.'],
                ['Guru yang belum cocok ditambahkan otomatis. Kecocokan yang ambigu harus diperbaiki dulu.'],
                ['Nama Lengkap dan Jenis Kelamin wajib diisi. Jenis Kelamin: L atau P.'],
                ['Status: Aktif atau Tidak Aktif. Kosong berarti Aktif.'],
                ['Urutan guru baru mengikuti baris file dan dimulai setelah guru yang sudah ada.'],
                ['Email harus unik. Kolom Email guru yang sudah punya akun login tidak boleh diubah lewat impor.'],
                ['Simpan nomor telepon sebagai teks agar angka nol di depan tidak hilang.'],
                ['Foto dan akun login tidak dibuat atau diubah oleh impor.'],
                ['Jika satu baris salah, seluruh impor dibatalkan. Maksimal 1000 baris data.'],
            ]);
            $guide->getColumnDimension('A')->setWidth(110);
            $spreadsheet->setActiveSheetIndex(0);
        }

        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = tempnam($directory, 'guru-');
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
                'message' => 'Berkas tidak dapat dibaca. Gunakan template atau hasil ekspor guru.',
            ], 422);
        }

        if (count($rows) > 1001) {
            return response()->json([
                'success' => false,
                'message' => 'Maksimal 1000 baris guru per berkas.',
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
                    'message' => 'Kolom "'.$header.'" tidak ditemukan. Gunakan template atau hasil ekspor guru.',
                ], 422);
            }
        }

        $prepared = [];
        $errors = [];
        $seenTargets = [];
        $seenEmails = [];
        $seenNames = [];
        $teachers = Teacher::all();

        foreach (array_slice($rows, 1) as $offset => $row) {
            $line = $offset + 2;
            $cell = fn (string $column) => trim((string) ($row[$headers[$normalize($column)]] ?? ''));
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $name = $cell('Nama Lengkap');
            $email = strtolower($cell('Email'));
            $genderRaw = strtolower($cell('Jenis Kelamin'));
            $gender = match ($genderRaw) {
                'l', 'laki-laki', 'laki laki' => 'L',
                'p', 'perempuan' => 'P',
                default => null,
            };
            $education = $cell('Pendidikan Terakhir');
            $position = $cell('Jabatan');
            $phone = $cell('No Telepon');
            $address = $cell('Alamat');
            $statusRaw = $normalize($cell('Status'));
            $active = match ($statusRaw) {
                '', 'aktif' => true,
                'tidakaktif', 'nonaktif' => false,
                default => null,
            };

            $emailMatches = $email === '' ? collect() : $teachers->filter(
                fn (Teacher $item) => strtolower($item->email ?? '') === $email
            );
            $nameMatches = $name === '' || ! $gender ? collect() : $teachers->filter(
                fn (Teacher $item) => strtolower($item->name) === strtolower($name)
                    && $item->gender === $gender
            );
            $teacher = $emailMatches->first();

            if (! $teacher && $nameMatches->count() > 1) {
                $errors[] = "Baris {$line}: nama dan jenis kelamin cocok dengan beberapa guru. Isi email untuk menentukan guru yang benar.";
            } elseif (! $teacher && $nameMatches->count() === 1) {
                $candidate = $nameMatches->first();
                if ($email !== '' && $candidate->email && strtolower($candidate->email) !== $email) {
                    $errors[] = "Baris {$line}: nama ini sudah ada dengan email berbeda. Periksa data guru tersebut.";
                } else {
                    $teacher = $candidate;
                }
            }

            if ($teacher && isset($seenTargets[$teacher->id])) {
                $errors[] = "Baris {$line}: guru {$teacher->name} muncul lebih dari sekali dalam berkas.";
            }
            if ($teacher) {
                $seenTargets[$teacher->id] = true;
            }
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = "Baris {$line}: nama lengkap wajib diisi, maksimal 255 karakter.";
            }
            if (! $gender) {
                $errors[] = "Baris {$line}: jenis kelamin harus L atau P.";
            }
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255)) {
                $errors[] = "Baris {$line}: format email tidak valid.";
            }
            if ($email !== '' && isset($seenEmails[$email])) {
                $errors[] = "Baris {$line}: email {$email} muncul lebih dari sekali.";
            }
            if ($email !== '') {
                $seenEmails[$email] = true;
            }
            if (mb_strlen($education) > 150 || mb_strlen($position) > 150) {
                $errors[] = "Baris {$line}: pendidikan dan jabatan maksimal 150 karakter.";
            }
            if (mb_strlen($phone) > 30 || ($phone !== '' && ! preg_match('/^[0-9+\-\s()]*$/', $phone))) {
                $errors[] = "Baris {$line}: nomor telepon tidak valid (maksimal 30 karakter).";
            }
            if (mb_strlen($address) > 65535) {
                $errors[] = "Baris {$line}: alamat terlalu panjang.";
            }
            if ($active === null) {
                $errors[] = "Baris {$line}: status harus Aktif atau Tidak Aktif.";
            }

            if ($teacher && $teacher->user_id && $email !== '' && $email !== strtolower($teacher->email ?? '')) {
                $errors[] = "Baris {$line}: email guru yang punya akun login tidak boleh diubah lewat impor.";
            }
            if ($email !== '') {
                $otherUser = User::whereRaw('LOWER(email) = ?', [$email])->first();
                if ($otherUser && $otherUser->id !== $teacher?->user_id) {
                    $errors[] = "Baris {$line}: email {$email} sudah dipakai akun lain.";
                }
            } elseif (! $teacher && $name !== '' && $gender) {
                $nameKey = strtolower($name).'|'.$gender;
                if (isset($seenNames[$nameKey])) {
                    $errors[] = "Baris {$line}: guru bernama {$name} muncul lebih dari sekali dalam berkas.";
                }
                $seenNames[$nameKey] = true;
            }

            $prepared[] = [
                'id' => $teacher?->id,
                'name' => $name,
                'email' => $email !== '' ? $email : $teacher?->email,
                'gender' => $gender,
                'last_education' => $education ?: null,
                'position' => $position ?: null,
                'phone' => $phone ?: null,
                'address' => $address ?: null,
                'is_active' => $active,
            ];
        }

        if (! $prepared || $errors) {
            return response()->json([
                'success' => false,
                'message' => ! $prepared ? 'Tidak ada data guru yang bisa diimpor.' : 'Ada '.count($errors).' kesalahan. Tidak ada data yang disimpan.',
                'errors' => $errors,
            ], 422);
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($prepared, &$created, &$updated) {
            $existing = Teacher::query()->lockForUpdate()->get(['id', 'sort_order']);
            $nextOrder = max($existing->count(), (int) $existing->max('sort_order')) + 1;

            foreach ($prepared as $data) {
                $id = $data['id'];
                unset($data['id']);
                if ($id) {
                    Teacher::findOrFail($id)->update($data);
                    $updated++;
                } else {
                    $data['sort_order'] = $nextOrder++;
                    Teacher::create($data);
                    $created++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Impor guru selesai: {$created} ditambahkan, {$updated} diperbarui.",
            'data' => ['created' => $created, 'updated' => $updated],
        ]);
    }
}
