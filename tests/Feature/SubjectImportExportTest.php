<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SubjectImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Kode', 'Nama Mata Pelajaran', 'Tingkat', 'Status'];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['role_name' => 'admin']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    private function file(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([self::HEADERS, ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'subject-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'mata-pelajaran.xlsx', null, null, true);
    }

    public function test_template_dan_export_tersedia_untuk_admin(): void
    {
        Subject::create([
            'code' => 'MTK', 'name' => 'Matematika', 'grade_level' => 4, 'is_active' => true,
        ]);

        $template = $this->get('/api/subject/template')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=template-import-mata-pelajaran.xlsx');
        $templateBook = IOFactory::load($template->baseResponse->getFile()->getPathname());
        $this->assertSame('Kode', $templateBook->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Status', $templateBook->getSheet(0)->getCell('D1')->getValue());
        $this->assertNull($templateBook->getSheet(0)->getCell('E1')->getValue());
        $this->assertSame('Petunjuk', $templateBook->getSheet(1)->getTitle());
        $templateBook->disconnectWorksheets();

        $export = $this->get('/api/subject/export')->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $book = IOFactory::load($export->baseResponse->getFile()->getPathname());
        $this->assertSame('MTK', $book->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('Tingkat 4', $book->getSheet(0)->getCell('C2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_import_menambah_dan_memperbarui_mata_pelajaran_berdasarkan_kode(): void
    {
        $subject = Subject::create([
            'code' => 'MTK', 'name' => 'Matematika Lama', 'grade_level' => null, 'is_active' => true,
        ]);

        $response = $this->post('/api/subject/import', ['file' => $this->file([
            ['mtk', 'Matematika', 'Tingkat 5', 'Tidak Aktif'],
            ['BIN', 'Bahasa Indonesia', '', 'Aktif'],
        ])]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id, 'code' => 'MTK', 'name' => 'Matematika',
            'grade_level' => 5, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('subjects', [
            'code' => 'BIN', 'name' => 'Bahasa Indonesia',
            'grade_level' => null, 'is_active' => true,
        ]);
        $this->assertDatabaseCount('subjects', 2);
    }

    public function test_hasil_export_bisa_diimpor_ulang_tanpa_duplikasi(): void
    {
        Subject::create(['code' => 'PAI', 'name' => 'Pendidikan Agama Islam']);
        Subject::create(['code' => 'IPA', 'name' => 'Ilmu Pengetahuan Alam', 'grade_level' => 6]);

        $export = $this->get('/api/subject/export')->assertOk();
        $file = new UploadedFile(
            $export->baseResponse->getFile()->getPathname(),
            'data-mata-pelajaran.xlsx',
            null,
            null,
            true
        );

        $this->post('/api/subject/import', ['file' => $file])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 2);
        $this->assertDatabaseCount('subjects', 2);
    }

    public function test_satu_baris_salah_membatalkan_seluruh_import(): void
    {
        $response = $this->post('/api/subject/import', ['file' => $this->file([
            ['MTK', 'Matematika', '3', 'Aktif'],
            ['BIN', 'Bahasa Indonesia', '9', 'Aktif'],
        ])]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseCount('subjects', 0);
    }

    public function test_kode_ganda_dalam_satu_berkas_ditolak(): void
    {
        $response = $this->post('/api/subject/import', ['file' => $this->file([
            ['MTK', 'Matematika', '3', 'Aktif'],
            ['mtk', 'Matematika Baru', '4', 'Aktif'],
        ])]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseCount('subjects', 0);
    }
}
