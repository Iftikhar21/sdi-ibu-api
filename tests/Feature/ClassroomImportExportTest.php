<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ClassroomController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ClassroomImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function tahunAjaran(string $name = '2026/2027'): AcademicYear
    {
        $existing = AcademicYear::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    /**
     * Buat berkas Excel untuk diuji import.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function fileExcel(array $headers, array $rows, string $filename = 'kelas.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        if (! empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'kelas-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function importFile(UploadedFile $file)
    {
        return (new ClassroomController)->import(Request::create(
            '/api/classroom/import',
            'POST',
            [],
            [],
            ['file' => $file]
        ));
    }

    public function test_export_kelas_menghasilkan_excel_berisi_data(): void
    {
        $year = $this->tahunAjaran();

        Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => 1,
            'name' => 'Ikhwan',
            'quota' => 28,
            'is_active' => true,
        ]);
        Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => 2,
            'name' => 'Akhwat',
            'quota' => 30,
            'is_active' => false,
        ]);

        $response = (new ClassroomController)->export();
        $path = $response->getFile()->getPathname();

        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Tahun Ajaran', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nama Kelas', $sheet->getCell('C1')->getValue());
        $this->assertSame('2026/2027', $sheet->getCell('A2')->getValue());
        $this->assertSame(1, $sheet->getCell('B2')->getValue());
        $this->assertSame('Ikhwan', $sheet->getCell('C2')->getValue());
        $this->assertSame(28, $sheet->getCell('D2')->getValue());
        $this->assertSame('Aktif', $sheet->getCell('E2')->getValue());
        $this->assertSame('Tidak Aktif', $sheet->getCell('E3')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    public function test_template_berisi_header_dan_lembar_petunjuk(): void
    {
        $response = (new ClassroomController)->template();
        $path = $response->getFile()->getPathname();

        $spreadsheet = IOFactory::load($path);

        $this->assertSame('Kelas', $spreadsheet->getSheet(0)->getTitle());
        $this->assertSame('Tahun Ajaran', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Petunjuk', $spreadsheet->getSheet(1)->getTitle());
        $this->assertSame('Ikhwan', $spreadsheet->getSheet(1)->getCell('C13')->getValue());
        $this->assertSame('Akhwat', $spreadsheet->getSheet(1)->getCell('C14')->getValue());

        // Baris data pada sheet pertama masih kosong
        $this->assertNull($spreadsheet->getSheet(0)->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    public function test_import_membuat_kelas_baru(): void
    {
        $year = $this->tahunAjaran();

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [
                ['2026/2027', 1, 'A', 28, 'Aktif'],
                ['2026/2027', 1, 'b', 28, ''],
                ['2026/2027', 2, 'A', 30, 'Tidak Aktif'],
            ]
        );

        $response = $this->importFile($file);

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertSame(3, $data['data']['created']);
        $this->assertSame(0, $data['data']['updated']);
        $this->assertSame(3, Classroom::count());

        // Alias lama A/B tetap diterima, tetapi disimpan memakai nama baru.
        $this->assertSame(
            'Akhwat',
            Classroom::where('grade_level', 1)->where('name', 'Akhwat')->first()->name
        );

        // Status "Tidak Aktif" terbaca dengan benar
        $this->assertFalse(Classroom::where('grade_level', 2)->first()->is_active);

        // Semua kelas masuk ke tahun ajaran yang benar
        $this->assertSame(3, Classroom::where('academic_year_id', $year->id)->count());
    }

    public function test_import_memperbarui_kelas_yang_sudah_ada(): void
    {
        $year = $this->tahunAjaran();

        Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => 1,
            'name' => 'Ikhwan',
            'quota' => 28,
            'is_active' => true,
        ]);

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [
                ['2026/2027', 1, 'A', 32, 'Tidak Aktif'],
                ['2026/2027', 1, 'Akhwat', 26, 'Aktif'],
            ]
        );

        $data = $this->importFile($file)->getData(true);

        $this->assertSame(1, $data['data']['created']);
        $this->assertSame(1, $data['data']['updated']);
        $this->assertSame(2, Classroom::count());

        $kelasA = Classroom::where('name', 'Ikhwan')->firstOrFail();

        $this->assertSame(32, $kelasA->quota);
        $this->assertFalse($kelasA->is_active);
    }

    public function test_import_menolak_tahun_ajaran_yang_belum_terdaftar(): void
    {
        $this->tahunAjaran();

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [
                ['2026/2027', 1, 'A', 28, 'Aktif'],
                ['2030/2031', 1, 'B', 28, 'Aktif'],
            ]
        );

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertStringContainsString('2030/2031', implode(' ', $data['errors']));
        $this->assertStringContainsString('Baris 3', implode(' ', $data['errors']));

        // Tidak ada data yang tersimpan walau baris pertama valid
        $this->assertSame(0, Classroom::count());
    }

    public function test_import_menolak_tingkat_kuota_dan_duplikat(): void
    {
        $this->tahunAjaran();

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [
                ['2026/2027', 7, 'A', 28, 'Aktif'],
                ['2026/2027', 1, 'B', 0, 'Aktif'],
                ['2026/2027', 1, 'Ikhwan', 20, 'Aktif'],
                ['2026/2027', 1, 'Ikhwan', 20, 'Aktif'],
            ]
        );

        $data = $this->importFile($file)->getData(true);
        $pesan = implode(' | ', $data['errors']);

        $this->assertStringContainsString('tingkat harus angka 1 sampai 6', $pesan);
        $this->assertStringContainsString('kuota harus angka lebih dari 0', $pesan);
        $this->assertStringContainsString('tercantum lebih dari sekali', $pesan);
        $this->assertSame(0, Classroom::count());
    }

    public function test_import_menolak_berkas_tanpa_kolom_wajib(): void
    {
        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Nama Kelas'],
            [['2026/2027', 'A']]
        );

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Kolom wajib tidak ditemukan', $response->getData(true)['message']);
    }

    public function test_import_melewati_baris_kosong(): void
    {
        $year = $this->tahunAjaran();

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [
                ['2026/2027', 1, 'A', 28, 'Aktif'],
                ['', '', '', '', ''],
                ['2026/2027', 1, 'B', 28, 'Aktif'],
            ]
        );

        $data = $this->importFile($file)->getData(true);

        $this->assertSame(2, $data['data']['created']);
        $this->assertSame(2, Classroom::where('academic_year_id', $year->id)->count());
    }

    public function test_import_menolak_kuota_lebih_kecil_dari_siswa_yang_sudah_ditempatkan(): void
    {
        $year = $this->tahunAjaran();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);

        $classroom = Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => 1,
            'name' => 'Ikhwan',
            'quota' => 28,
            'is_active' => true,
        ]);

        foreach (['Budi Santoso', 'Andi Wijaya'] as $index => $fullName) {
            $user = User::factory()->create(['role_id' => 2]);

            $registration = StudentRegistration::create([
                'user_id' => $user->id,
                'academic_year_id' => $year->id,
                'full_name' => $fullName,
                'nickname' => 'Siswa '.($index + 1),
                'gender' => 'L',
                'birth_place' => 'Jakarta',
                'birth_date' => '2019-05-01',
                'father_name' => 'Ayah '.$fullName,
                'mother_name' => 'Ibu '.$fullName,
                'address' => 'Jl. Dalang No. '.($index + 1),
                'phone' => '0812345678'.$index,
                'contact_email' => 'ortu'.$index.'@example.com',
                'photo' => 'registrations/photos/foto.jpg',
                'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
                'family_card' => 'registrations/family_cards/kk.jpg',
                'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
                'status' => 'approved',
            ]);

            ClassroomPlacement::create([
                'classroom_id' => $classroom->id,
                'student_registration_id' => $registration->id,
                'academic_year_id' => $year->id,
                'is_active' => true,
                'assigned_at' => now(),
            ]);
        }

        $file = $this->fileExcel(
            ['Tahun Ajaran', 'Tingkat', 'Nama Kelas', 'Kuota', 'Status'],
            [['2026/2027', 1, 'A', 1, 'Aktif']]
        );

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertStringContainsString('Baris 2', implode(' ', $data['errors']));
        $this->assertStringContainsString('sudah ditempatkan', implode(' ', $data['errors']));

        // Kuota kelas tidak ikut berubah
        $this->assertSame(28, $classroom->fresh()->quota);
    }
}
