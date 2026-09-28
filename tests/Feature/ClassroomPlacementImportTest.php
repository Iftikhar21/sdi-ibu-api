<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ClassroomPlacementController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ClassroomPlacementImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function tahunAjaran(string $name = '2026/2027', bool $active = true): AcademicYear
    {
        $existing = AcademicYear::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => $active,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    private function kelas(AcademicYear $year, int $grade, string $name, int $quota = 28): Classroom
    {
        return Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => $grade,
            'name' => $name,
            'quota' => $quota,
            'is_active' => true,
        ]);
    }

    private function pendaftar(
        string $status = 'approved',
        ?AcademicYear $year = null,
        string $name = 'Budi Santoso'
    ): StudentRegistration {
        $year ??= $this->tahunAjaran();

        $user = User::factory()->create(['role_id' => 2]);

        return StudentRegistration::create([
            'user_id' => $user->id,
            'academic_year_id' => $year->id,
            'full_name' => $name,
            'nickname' => 'Budi',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2019-05-01',
            'father_name' => 'Ayah '.$name,
            'mother_name' => 'Ibu '.$name,
            'address' => 'Jl. Dalang No. 1',
            'phone' => '08123456789',
            'contact_email' => 'ortu@example.com',
            'photo' => 'registrations/photos/foto.jpg',
            'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
            'family_card' => 'registrations/family_cards/kk.jpg',
            'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
            'status' => $status,
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function fileExcel(array $headers, array $rows, string $filename = 'penempatan.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        if (! empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'penempatan-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function headers(): array
    {
        return ['No. Pendaftaran', 'NIS', 'Nama', 'Tahun Ajaran', 'Kelas Saat Ini', 'Kelas Tujuan'];
    }

    private function importFile(UploadedFile $file)
    {
        return (new ClassroomPlacementController)->import(Request::create(
            '/api/admin/classroom-placements/import',
            'POST',
            [],
            [],
            ['file' => $file]
        ));
    }

    public function test_daftar_kandidat_hanya_pendaftar_yang_diterima(): void
    {
        $this->pendaftar('submitted', null, 'Belum Diterima');
        $this->pendaftar('rejected', null, 'Ditolak');
        $this->pendaftar('approved', null, 'Diterima');

        $response = (new ClassroomPlacementController)->index(Request::create(
            '/api/admin/classroom-placements',
            'GET'
        ));

        $data = $response->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('Diterima', $data[0]['full_name']);
    }

    public function test_export_menghasilkan_excel_dengan_kolom_kelas_tujuan_kosong(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');
        $registration = $this->pendaftar('approved', $year);

        Student::ensureForRegistration($registration)->placeIntoClassroom($classroom);

        $response = (new ClassroomPlacementController)->export(Request::create(
            '/api/admin/classroom-placements/export',
            'GET'
        ));

        $path = $response->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('No. Pendaftaran', $sheet->getCell('A1')->getValue());
        $this->assertSame('Kelas Tujuan', $sheet->getCell('F1')->getValue());
        $this->assertSame($registration->id, $sheet->getCell('A2')->getValue());
        $this->assertSame('Budi Santoso', $sheet->getCell('C2')->getValue());
        $this->assertSame('2026/2027', $sheet->getCell('D2')->getValue());
        $this->assertSame('1A', $sheet->getCell('E2')->getValue());
        $this->assertNull($sheet->getCell('F2')->getValue());

        @unlink($path);
    }

    public function test_template_berisi_petunjuk(): void
    {
        $response = (new ClassroomPlacementController)->template();
        $path = $response->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);

        $this->assertSame('Penempatan', $spreadsheet->getSheet(0)->getTitle());
        $this->assertSame('Kelas Tujuan', $spreadsheet->getSheet(0)->getCell('F1')->getValue());
        $this->assertSame('Petunjuk', $spreadsheet->getSheet(1)->getTitle());

        @unlink($path);
    }

    public function test_import_menempatkan_siswa_berdasarkan_nomor_pendaftaran(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');
        $this->kelas($year, 1, 'B');

        $budi = $this->pendaftar('approved', $year, 'Budi Santoso');
        $andi = $this->pendaftar('approved', $year, 'Andi Wijaya');

        $file = $this->fileExcel($this->headers(), [
            [$budi->id, '', 'Budi Santoso', '2026/2027', '', '1A'],
            [$andi->id, '', 'Andi Wijaya', '2026/2027', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertSame(2, $data['data']['placed']);
        $this->assertSame(0, $data['data']['moved']);
        $this->assertSame(2, Student::count());
        $this->assertSame(2, ClassroomPlacement::where('is_active', true)->count());
        $this->assertSame('1A', Student::where('full_name', 'Budi Santoso')->first()->classroom_label);
    }

    public function test_import_memindahkan_siswa_dan_menutup_penempatan_lama(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $this->kelas($year, 1, 'B');

        $registration = $this->pendaftar('approved', $year);
        $student = Student::ensureForRegistration($registration);
        $student->placeIntoClassroom($kelasA);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '1A', '1B'],
        ]);

        $data = $this->importFile($file)->getData(true);

        $this->assertSame(0, $data['data']['placed']);
        $this->assertSame(1, $data['data']['moved']);

        $this->assertSame('1B', $student->fresh()->classroom_label);
        $this->assertSame(1, ClassroomPlacement::where('is_active', true)->count());
        $this->assertSame(2, ClassroomPlacement::count());
        $this->assertFalse(ClassroomPlacement::orderBy('id')->first()->is_active);
    }

    public function test_import_mengabaikan_baris_tanpa_kelas_tujuan(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        $registration = $this->pendaftar('approved', $year);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '', ''],
        ]);

        $response = $this->importFile($file);

        // Baris tanpa Kelas Tujuan bukan error, tetapi tidak ada yang diproses
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Tidak ada baris', $response->getData(true)['message']);
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_import_menolak_pendaftar_yang_belum_diterima(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        $registration = $this->pendaftar('submitted', $year);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('belum berstatus Diterima', implode(' ', $response->getData(true)['errors']));
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_import_menolak_kelas_yang_tidak_ada(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        $registration = $this->pendaftar('approved', $year);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '', '3C'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tidak ditemukan pada tahun ajaran', implode(' ', $response->getData(true)['errors']));
    }

    public function test_import_menolak_bila_melebihi_kuota(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A', 2);

        $satu = $this->pendaftar('approved', $year, 'Budi Santoso');
        $dua = $this->pendaftar('approved', $year, 'Andi Wijaya');
        $tiga = $this->pendaftar('approved', $year, 'Citra Dewi');

        $file = $this->fileExcel($this->headers(), [
            [$satu->id, '', 'Budi Santoso', '2026/2027', '', '1A'],
            [$dua->id, '', 'Andi Wijaya', '2026/2027', '', '1A'],
            [$tiga->id, '', 'Citra Dewi', '2026/2027', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('kuota kelas 1A penuh', implode(' ', $response->getData(true)['errors']));

        // Tidak ada data yang tersimpan walau dua baris pertama masih muat
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_import_menghitung_kuota_setelah_siswa_pindah_keluar(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 1);
        $this->kelas($year, 1, 'B', 1);

        $budi = $this->pendaftar('approved', $year, 'Budi Santoso');
        $andi = $this->pendaftar('approved', $year, 'Andi Wijaya');

        // Budi sudah menempati 1A (kuota 1), Andi belum punya kelas
        Student::ensureForRegistration($budi)->placeIntoClassroom($kelasA);

        // Budi pindah ke 1B, Andi masuk ke 1A -> keduanya masih dalam kuota
        $file = $this->fileExcel($this->headers(), [
            [$budi->id, '', 'Budi Santoso', '2026/2027', '1A', '1B'],
            [$andi->id, '', 'Andi Wijaya', '2026/2027', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1B', Student::where('full_name', 'Budi Santoso')->first()->classroom_label);
        $this->assertSame('1A', Student::where('full_name', 'Andi Wijaya')->first()->classroom_label);
    }

    public function test_import_melewati_siswa_yang_sudah_ada_di_kelas_tujuan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');

        $registration = $this->pendaftar('approved', $year);
        Student::ensureForRegistration($registration)->placeIntoClassroom($classroom);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '1A', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Tidak ada baris', $response->getData(true)['message']);
        $this->assertSame(1, ClassroomPlacement::count());
    }

    public function test_import_bisa_mencari_siswa_lewat_nama(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        $this->pendaftar('approved', $year, 'Budi Santoso');

        $file = $this->fileExcel($this->headers(), [
            ['', '', 'Budi Santoso', '2026/2027', '', '1A'],
        ]);

        $data = $this->importFile($file)->getData(true);

        $this->assertSame(1, $data['data']['placed']);
    }

    public function test_import_menolak_nama_yang_ambigu(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        $this->pendaftar('approved', $year, 'Budi Santoso');
        $this->pendaftar('approved', $year, 'Budi Santoso');

        $file = $this->fileExcel($this->headers(), [
            ['', '', 'Budi Santoso', '2026/2027', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('lebih dari satu pendaftar', implode(' ', $response->getData(true)['errors']));
    }

    public function test_import_menolak_berkas_tanpa_kolom_kelas_tujuan(): void
    {
        $file = $this->fileExcel(['Nama', 'Tahun Ajaran'], [['Budi Santoso', '2026/2027']]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Kelas Tujuan', $response->getData(true)['message']);
    }

    public function test_import_melengkapi_tahun_ajaran_dari_kolom_berkas(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');

        // Pendaftaran lama: belum punya tahun ajaran
        $registration = $this->pendaftar('approved', $year);
        $registration->update(['academic_year_id' => null]);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2026/2027', '', '1A'],
        ]);

        $data = $this->importFile($file)->getData(true);

        $this->assertSame(1, $data['data']['placed']);
        $this->assertSame($year->id, $registration->fresh()->academic_year_id);
        $this->assertSame('1A', Student::firstOrFail()->classroom_label);

        // Siswa yang terbentuk ikut membawa tahun masuk yang benar
        $this->assertSame($year->id, Student::firstOrFail()->admission_year_id);
    }

    public function test_import_menolak_tahun_ajaran_berkas_yang_berbeda_dengan_data(): void
    {
        $year = $this->tahunAjaran('2026/2027', true);
        $this->tahunAjaran('2025/2026', false);
        $this->kelas($year, 1, 'A');

        $registration = $this->pendaftar('approved', $year);

        $file = $this->fileExcel($this->headers(), [
            [$registration->id, '', 'Budi Santoso', '2025/2026', '', '1A'],
        ]);

        $response = $this->importFile($file);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('berbeda dengan tahun ajaran', implode(' ', $response->getData(true)['errors']));
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_isi_tahun_ajaran_massal_untuk_pendaftaran_kosong(): void
    {
        $year = $this->tahunAjaran();

        $satu = $this->pendaftar('approved');
        $dua = $this->pendaftar('submitted', null, 'Andi Wijaya');
        $sudahTerisi = $this->pendaftar('approved', $year, 'Citra Dewi');

        $satu->update(['academic_year_id' => null]);
        $dua->update(['academic_year_id' => null]);

        $response = (new ClassroomPlacementController)->assignAcademicYear(
            Request::create('/api/admin/classroom-placements/academic-year', 'POST', [
                'academic_year_id' => $year->id,
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['updated']);
        $this->assertSame($year->id, $satu->fresh()->academic_year_id);
        $this->assertSame($year->id, $dua->fresh()->academic_year_id);
        $this->assertSame($year->id, $sudahTerisi->fresh()->academic_year_id);
    }

    public function test_meta_jumlah_pendaftar_tanpa_tahun_ajaran(): void
    {
        $year = $this->tahunAjaran();

        $this->pendaftar('approved', $year);
        $tanpaTahun = $this->pendaftar('approved');
        $tanpaTahun->update(['academic_year_id' => null]);

        $response = (new ClassroomPlacementController)->index(Request::create(
            '/api/admin/classroom-placements',
            'GET'
        ));

        $this->assertSame(1, $response->getData(true)['meta']['without_academic_year']);
    }
}
