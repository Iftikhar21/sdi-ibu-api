<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TeacherImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'Nama Lengkap', 'Email', 'Jenis Kelamin', 'Pendidikan Terakhir',
        'Jabatan', 'No Telepon', 'Alamat', 'Status',
    ];

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
        $path = tempnam(sys_get_temp_dir(), 'guru-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'guru.xlsx', null, null, true);
    }

    public function test_template_dan_export_tersedia_untuk_admin(): void
    {
        Teacher::create(['name' => 'Ibu Siti', 'gender' => 'P', 'phone' => '08123456789']);

        $template = $this->get('/api/teacher/template')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=template-import-guru.xlsx');
        $templateBook = IOFactory::load($template->baseResponse->getFile()->getPathname());
        $this->assertSame('Nama Lengkap', $templateBook->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Status', $templateBook->getSheet(0)->getCell('H1')->getValue());
        $this->assertNull($templateBook->getSheet(0)->getCell('I1')->getValue());
        $this->assertSame('Petunjuk', $templateBook->getSheet(1)->getTitle());
        $templateBook->disconnectWorksheets();

        $export = $this->get('/api/teacher/export')->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $book = IOFactory::load($export->baseResponse->getFile()->getPathname());
        $this->assertSame('Ibu Siti', $book->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('08123456789', $book->getSheet(0)->getCell('F2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_import_menambah_dan_memperbarui_profil_tanpa_membuat_akun(): void
    {
        $teacher = Teacher::create([
            'name' => 'Guru Lama', 'email' => 'lama@example.com', 'gender' => 'L',
            'photo' => 'foto-lama.jpg',
        ]);

        $response = $this->post('/api/teacher/import', ['file' => $this->file([
            ['Guru Diperbarui', 'lama@example.com', 'L', 'S1', 'Guru Kelas', '08123456789', 'Bandung', 'Aktif'],
            ['Guru Baru', 'baru@example.com', 'P', 'S2', 'Guru Mapel', '', '', 'Tidak Aktif'],
        ])]);

        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);
        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id, 'name' => 'Guru Diperbarui', 'photo' => 'foto-lama.jpg',
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('teachers', [
            'name' => 'Guru Baru', 'email' => 'baru@example.com', 'is_active' => false,
            'user_id' => null,
            'sort_order' => 2,
        ]);
    }

    public function test_urutan_guru_baru_mengikuti_baris_dan_dimulai_setelah_data_lama(): void
    {
        $rows = [
            ['Guru A', 'a@example.com', 'L', '', '', '', '', 'Aktif'],
            ['Guru B', 'b@example.com', 'P', '', '', '', '', 'Aktif'],
        ];

        $this->post('/api/teacher/import', ['file' => $this->file($rows)])->assertOk();
        $this->assertSame(1, Teacher::where('name', 'Guru A')->firstOrFail()->sort_order);
        $this->assertSame(2, Teacher::where('name', 'Guru B')->firstOrFail()->sort_order);

        Teacher::create(['name' => 'Guru C', 'gender' => 'L']);
        $this->post('/api/teacher/import', ['file' => $this->file([
            ['Guru D', 'd@example.com', 'L', '', '', '', '', 'Aktif'],
            ['Guru E', 'e@example.com', 'P', '', '', '', '', 'Aktif'],
        ])])->assertOk();

        $this->assertSame(4, Teacher::where('name', 'Guru D')->firstOrFail()->sort_order);
        $this->assertSame(5, Teacher::where('name', 'Guru E')->firstOrFail()->sort_order);
    }

    public function test_guru_tanpa_email_dicocokkan_lewat_nama_dan_jenis_kelamin(): void
    {
        $teacher = Teacher::create(['name' => 'Ibu Rina', 'gender' => 'P', 'sort_order' => 7]);

        $this->post('/api/teacher/import', ['file' => $this->file([
            ['Ibu Rina', '', 'P', 'S1', 'Guru Kelas', '', '', 'Aktif'],
        ])])->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertDatabaseCount('teachers', 1);
        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id, 'position' => 'Guru Kelas', 'sort_order' => 7,
        ]);
    }

    public function test_nama_tanpa_email_yang_ambigu_ditolak(): void
    {
        Teacher::create(['name' => 'Ibu Rina', 'gender' => 'P']);
        Teacher::create(['name' => 'Ibu Rina', 'gender' => 'P']);

        $this->post('/api/teacher/import', ['file' => $this->file([
            ['Ibu Rina', '', 'P', '', 'Guru Kelas', '', '', 'Aktif'],
        ])])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('teachers', 2);
        $this->assertSame(0, Teacher::where('position', 'Guru Kelas')->count());
    }

    public function test_hasil_export_bisa_diimport_ulang_tanpa_duplikasi(): void
    {
        Teacher::create(['name' => 'Ibu Siti', 'email' => 'siti@example.com', 'gender' => 'P']);
        Teacher::create(['name' => 'Pak Budi', 'gender' => 'L']);

        $export = $this->get('/api/teacher/export')->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $file = new UploadedFile($path, 'data-guru.xlsx', null, null, true);

        $this->post('/api/teacher/import', ['file' => $file])->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 2);
        $this->assertDatabaseCount('teachers', 2);
    }

    public function test_satu_baris_salah_membatalkan_seluruh_import(): void
    {
        $response = $this->post('/api/teacher/import', ['file' => $this->file([
            ['Guru Valid', 'valid@example.com', 'L', '', '', '', '', 'Aktif'],
            ['Guru Salah', 'salah@example.com', 'X', '', '', '', '', 'Aktif'],
        ])]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseCount('teachers', 0);
    }

    public function test_email_guru_yang_punya_akun_tidak_bisa_diubah_lewat_import(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'guru']);
        $account = User::factory()->create(['role_id' => $role->id, 'email' => 'guru@example.com']);
        $teacher = Teacher::create([
            'name' => 'Ibu Guru', 'email' => $account->email, 'gender' => 'P',
            'user_id' => $account->id,
        ]);

        $response = $this->post('/api/teacher/import', ['file' => $this->file([
            ['Ibu Guru', 'baru@example.com', 'P', '', '', '', '', 'Aktif'],
        ])]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'email' => 'guru@example.com']);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => 'guru@example.com']);
    }
}
