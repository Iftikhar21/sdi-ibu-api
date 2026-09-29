<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Models\AcademicYear;
use App\Models\RegistrationSetting;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RegistrationNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
        RegistrationSetting::query()->firstOrFail()->update(['phase' => 'open']);
    }

    private function tahunAjaran(string $name = '2026/2027', bool $active = true): AcademicYear
    {
        $existing = AcademicYear::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        [$startYear, $endYear] = array_pad(explode('/', $name), 2, null);

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => ($startYear ?: '2026').'-07-01',
            'end_date' => ($endYear ?: '2027').'-06-30',
            'is_active' => $active,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    private function payload(string $name = 'Budi Santoso'): array
    {
        return [
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
            'photo' => UploadedFile::fake()->image('foto.jpg'),
            'birth_certificate' => UploadedFile::fake()->image('akte.jpg'),
            'family_card' => UploadedFile::fake()->image('kk.jpg'),
            'payment_proof' => UploadedFile::fake()->image('bukti.jpg'),
        ];
    }

    private function pendaftarBaru(string $name = 'Budi Santoso')
    {
        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        return $this->post('/api/registrations', $this->payload($name));
    }

    public function test_pendaftaran_baru_mendapat_nomor_pendaftaran_unik(): void
    {
        Storage::fake('public');
        $this->tahunAjaran('2026/2027', true);

        $response = $this->pendaftarBaru();

        $response->assertStatus(201);

        $registration = StudentRegistration::firstOrFail();

        $this->assertSame('REG-2026-0001', $registration->registration_number);
        $this->assertSame(
            'REG-2026-0001',
            $response->json('data.registration_number')
        );
        $this->assertSame('submitted', $registration->status);
        $this->assertSame(0, Student::count());
    }

    public function test_nomor_pendaftaran_tidak_sama_antar_pendaftar(): void
    {
        Storage::fake('public');
        $this->tahunAjaran('2026/2027', true);

        $this->pendaftarBaru('Budi Santoso');
        $this->pendaftarBaru('Andi Wijaya');
        $this->pendaftarBaru('Citra Dewi');

        $numbers = StudentRegistration::orderBy('id')->pluck('registration_number')->all();

        $this->assertSame(
            ['REG-2026-0001', 'REG-2026-0002', 'REG-2026-0003'],
            $numbers
        );
        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }

    public function test_nomor_tidak_boleh_duplikat_di_database(): void
    {
        Storage::fake('public');
        $year = $this->tahunAjaran('2026/2027', true);
        $this->pendaftarBaru();

        $this->expectException(\Illuminate\Database\QueryException::class);

        StudentRegistration::create([
            'registration_number' => 'REG-2026-0001',
            'user_id' => User::factory()->create(['role_id' => 2])->id,
            'academic_year_id' => $year->id,
            'full_name' => 'Duplikat',
            'nickname' => 'Duplikat',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2019-05-01',
            'father_name' => 'Ayah',
            'mother_name' => 'Ibu',
            'address' => 'Jl. Dalima',
            'phone' => '08123456789',
            'contact_email' => 'duplikat@example.com',
            'photo' => 'registrations/photos/foto.jpg',
            'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
            'family_card' => 'registrations/family_cards/kk.jpg',
            'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
            'status' => 'submitted',
        ]);
    }

    public function test_pendaftaran_ditolak_bila_tidak_ada_tahun_ajaran_aktif(): void
    {
        Storage::fake('public');
        $this->tahunAjaran('2026/2027', false);

        $response = $this->pendaftarBaru();

        $response->assertStatus(422);
        $this->assertStringContainsString('tahun ajaran aktif', $response->json('message'));
        $this->assertSame(0, StudentRegistration::count());
    }

    public function test_pendaftaran_memakai_tahun_ajaran_aktif(): void
    {
        Storage::fake('public');
        $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $this->pendaftarBaru();

        $this->assertSame($aktif->id, StudentRegistration::firstOrFail()->academic_year_id);
    }

    public function test_pendaftar_bisa_melihat_nomor_pendaftarannya_sendiri(): void
    {
        Storage::fake('public');
        $this->tahunAjaran('2026/2027', true);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);
        $this->post('/api/registrations', $this->payload());

        $data = $this->getJson('/api/registrations')->assertStatus(200)->json('data');

        $this->assertSame('REG-2026-0001', $data[0]['registration_number']);
    }

    public function test_export_admin_menyertakan_nomor_pendaftaran(): void
    {
        Storage::fake('public');
        $year = $this->tahunAjaran('2026/2027', true);
        $this->pendaftarBaru();

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->get('/api/admin/registrations/export');
        $response->assertStatus(200);

        $path = $response->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('No. Pendaftaran', $sheet->getCell('C1')->getValue());
        $this->assertSame('REG-2026-0001', $sheet->getCell('C2')->getValue());

        @unlink($path);
        $this->assertNotNull($year->id);
    }
}
