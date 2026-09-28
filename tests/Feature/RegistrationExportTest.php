<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminRegistrationController;
use App\Models\Role;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;
use ZipArchive;

class RegistrationExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // users.role_id punya foreign key ke tabel role
        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function buatPendaftaran(array $override = []): StudentRegistration
    {
        $user = User::factory()->create(['role_id' => 2]);

        return StudentRegistration::create(array_merge([
            'user_id' => $user->id,
            'full_name' => 'Ahmad Fauzan',
            'nickname' => 'Fauzan',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2018-05-01',
            'father_name' => 'Budi',
            'mother_name' => 'Siti',
            'address' => 'Jl. Dalang No. 1',
            'phone' => '08123456789',
            'contact_email' => 'orangtua@example.com',
            'photo' => 'registrations/photos/foto.jpg',
            'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
            'family_card' => 'registrations/family_cards/kk.jpg',
            'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
            'status' => 'submitted',
        ], $override));
    }

    public function test_export_data_pendaftar_menghasilkan_excel_berisi_data(): void
    {
        $this->buatPendaftaran();
        $this->buatPendaftaran(['full_name' => 'Siti Aminah', 'gender' => 'P', 'status' => 'approved']);

        $response = (new AdminRegistrationController)->export(
            Request::create('/api/admin/registrations/export', 'GET')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

        $excelPath = $response->getFile()->getPathname();
        $this->assertFileExists($excelPath);

        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Data Pendaftar', $sheet->getTitle());

        // Header
        $this->assertSame('No', $sheet->getCell('A1')->getValue());
        $this->assertSame('ID Pendaftaran', $sheet->getCell('B1')->getValue());
        $this->assertSame('No. Pendaftaran', $sheet->getCell('C1')->getValue());
        $this->assertSame('Nama Lengkap', $sheet->getCell('D1')->getValue());
        $this->assertSame('Tanggal Lahir', $sheet->getCell('H1')->getValue());
        $this->assertSame('Status', $sheet->getCell('N1')->getValue());
        $this->assertSame('Tanggal Daftar', $sheet->getCell('P1')->getValue());
        $this->assertSame('Tahun Ajaran', $sheet->getCell('Q1')->getValue());
        $this->assertSame('Kelas', $sheet->getCell('R1')->getValue());

        // Kolom URL dokumen tidak diikutkan lagi
        $this->assertSame('R', $sheet->getHighestColumn());
        $this->assertStringNotContainsString('URL', implode(' ', $sheet->rangeToArray('A1:R1')[0]));

        // Pendaftar tanpa penempatan kelas ditandai jelas
        $this->assertSame('Belum Ditempatkan', $sheet->getCell('R2')->getValue());

        // Header berwarna biru dan tebal
        $this->assertSame('FF004AAD', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        // Baris data (urut terbaru dahulu)
        $names = [
            $sheet->getCell('D2')->getValue(),
            $sheet->getCell('D3')->getValue(),
        ];
        sort($names);
        $this->assertSame(['Ahmad Fauzan', 'Siti Aminah'], $names);

        $this->assertSame('Perempuan', $sheet->getCell('F3')->getValue());
        $this->assertSame('Diterima', $sheet->getCell('N3')->getValue());
        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame(2, $sheet->getCell('A3')->getValue());

        // Tanggal tersimpan sebagai tanggal Excel, bukan teks
        $birthDate = $sheet->getCell('H2')->getValue();
        $this->assertIsNumeric($birthDate);
        $this->assertSame('2018-05-01', ExcelDate::excelToDateTimeObject($birthDate)->format('Y-m-d'));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());

        // Header dibekukan + auto filter aktif
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:R3', $sheet->getAutoFilter()->getRange());

        $spreadsheet->disconnectWorksheets();

        @unlink($excelPath);
    }

    public function test_export_mengikuti_filter_status(): void
    {
        $this->buatPendaftaran(['full_name' => 'Anak Dikirim', 'status' => 'submitted']);
        $this->buatPendaftaran(['full_name' => 'Anak Diterima', 'status' => 'approved']);

        $response = (new AdminRegistrationController)->export(
            Request::create('/api/admin/registrations/export', 'GET', ['status' => 'approved'])
        );

        $excelPath = $response->getFile()->getPathname();
        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Anak Diterima', $sheet->getCell('D2')->getValue());
        $this->assertNull($sheet->getCell('D3')->getValue());

        $spreadsheet->disconnectWorksheets();

        @unlink($excelPath);
    }

    public function test_download_dokumen_pendaftar_menghasilkan_zip_berisi_semua_dokumen(): void
    {
        Storage::fake('public');

        foreach ([
            'registrations/photos/foto.jpg',
            'registrations/birth_certificates/akte.jpg',
            'registrations/family_cards/kk.jpg',
            'registrations/payment_proofs/bukti.jpg',
        ] as $path) {
            Storage::disk('public')->put($path, 'isi-file');
        }

        $registration = $this->buatPendaftaran();

        $response = (new AdminRegistrationController)->downloadDocuments($registration->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('ahmad-fauzan', $response->headers->get('content-disposition'));

        $zipPath = $response->getFile()->getPathname();
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame(4, $zip->numFiles);
        $this->assertSame('1-Foto-Calon-Murid.jpg', $zip->getNameIndex(0));
        $this->assertSame('2-Akte-Kelahiran.jpg', $zip->getNameIndex(1));
        $this->assertSame('3-Kartu-Keluarga.jpg', $zip->getNameIndex(2));
        $this->assertSame('4-Bukti-Pembayaran.jpg', $zip->getNameIndex(3));
        $zip->close();

        @unlink($zipPath);
    }

    public function test_dokumen_yang_tidak_ada_dilewati_dan_tetap_bisa_diunduh(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('registrations/photos/foto.jpg', 'isi-file');

        $registration = $this->buatPendaftaran([
            'birth_certificate' => 'registrations/birth_certificates/hilang.jpg',
            'family_card' => 'registrations/family_cards/hilang.jpg',
            'payment_proof' => 'registrations/payment_proofs/hilang.jpg',
        ]);

        $response = (new AdminRegistrationController)->downloadDocuments($registration->id);

        $this->assertSame(200, $response->getStatusCode());

        $zipPath = $response->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('1-Foto-Calon-Murid.jpg', $zip->getNameIndex(0));
        $zip->close();

        @unlink($zipPath);
    }
}
