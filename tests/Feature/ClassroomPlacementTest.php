<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminRegistrationController;
use App\Http\Controllers\ClassroomController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\RegistrationSetting;
use App\Models\Role;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassroomPlacementTest extends TestCase
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

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => $active,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    private function kelas(AcademicYear $year, int $grade, string $name, int $quota): Classroom
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
            'father_name' => 'Ayah Budi',
            'mother_name' => 'Ibu Budi',
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

    private function tempatkan(StudentRegistration $registration, Classroom $classroom)
    {
        return (new AdminRegistrationController)->assignClassroom(
            Request::create("/api/admin/registrations/{$registration->id}/classroom", 'POST', [
                'classroom_id' => $classroom->id,
            ]),
            $registration->id
        );
    }

    public function test_pendaftaran_baru_otomatis_memakai_tahun_ajaran_aktif(): void
    {
        Storage::fake('public');

        $lama = $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/registrations', [
            'full_name' => 'Budi Santoso',
            'nickname' => 'Budi',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2019-05-01',
            'father_name' => 'Ayah Budi',
            'mother_name' => 'Ibu Budi',
            'address' => 'Jl. Dalang No. 1',
            'phone' => '08123456789',
            'contact_email' => 'ortu@example.com',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
            'birth_certificate' => UploadedFile::fake()->image('akte.jpg'),
            'family_card' => UploadedFile::fake()->image('kk.jpg'),
            'payment_proof' => UploadedFile::fake()->image('bukti.jpg'),
        ]);

        $response->assertStatus(201);

        $registration = StudentRegistration::firstOrFail();

        $this->assertSame($aktif->id, $registration->academic_year_id);
        $this->assertNotSame($lama->id, $registration->academic_year_id);
        $this->assertNull($registration->classroom_id);
    }

    public function test_siswa_diterima_bisa_ditempatkan_dan_kuota_bertambah(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);
        $registration = $this->pendaftar('approved', $year);

        $response = $this->tempatkan($registration, $classroom);

        $this->assertSame(200, $response->getStatusCode());

        $registration->refresh();

        $this->assertSame($classroom->id, $registration->classroom_id);
        $this->assertSame('1A', $registration->classroom_label);
        $this->assertSame(1, ClassroomPlacement::where('classroom_id', $classroom->id)->where('is_active', true)->count());
        $this->assertSame(1, $classroom->refresh()->filled_count);
        $this->assertSame(27, $classroom->available_count);
    }

    public function test_penempatan_ditolak_bila_belum_diterima(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);
        $registration = $this->pendaftar('submitted', $year);

        $response = $this->tempatkan($registration, $classroom);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Diterima', $response->getData(true)['message']);
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_penempatan_ditolak_bila_kuota_penuh(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 2);

        // Isi kuota sampai penuh
        foreach (['Budi', 'Andi'] as $name) {
            $this->tempatkan($this->pendaftar('approved', $year, $name), $classroom);
        }

        $this->assertSame(0, $classroom->refresh()->available_count);

        // Siswa ketiga harus ditolak
        $response = $this->tempatkan($this->pendaftar('approved', $year, 'Citra'), $classroom);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Kuota kelas 1A sudah penuh', $response->getData(true)['message']);
        $this->assertSame(2, ClassroomPlacement::where('is_active', true)->count());
    }

    public function test_penempatan_ditolak_bila_berbeda_tahun_ajaran(): void
    {
        $tahunA = $this->tahunAjaran('2025/2026', false);
        $tahunB = $this->tahunAjaran('2026/2027', true);

        $kelasTahunB = $this->kelas($tahunB, 1, 'A', 28);
        $pendaftarTahunA = $this->pendaftar('approved', $tahunA);

        $response = $this->tempatkan($pendaftarTahunA, $kelasTahunB);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tahun ajaran', $response->getData(true)['message']);
        $this->assertSame(0, ClassroomPlacement::count());
    }

    public function test_tidak_bisa_menempatkan_siswa_dua_kali_di_kelas_yang_sama(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);
        $registration = $this->pendaftar('approved', $year);

        $this->tempatkan($registration, $classroom);
        $response = $this->tempatkan($registration, $classroom);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah berada di kelas 1A', $response->getData(true)['message']);
        $this->assertSame(1, ClassroomPlacement::where('classroom_id', $classroom->id)->count());
    }

    public function test_pemindahan_kelas_menjaga_riwayat_dan_kuota(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 28);
        $kelasB = $this->kelas($year, 1, 'B', 28);
        $registration = $this->pendaftar('approved', $year);

        $this->tempatkan($registration, $kelasA);
        $response = $this->tempatkan($registration, $kelasB);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('dipindahkan ke kelas 1B', $response->getData(true)['message']);

        // Penempatan lama tidak dihapus, hanya dinonaktifkan
        $this->assertSame(2, ClassroomPlacement::count());
        $this->assertSame(0, $kelasA->refresh()->filled_count);
        $this->assertSame(1, $kelasB->refresh()->filled_count);

        $riwayat = ClassroomPlacement::where('student_registration_id', $registration->id)
            ->orderBy('id')
            ->get();

        $this->assertSame($kelasA->id, $riwayat[0]->classroom_id);
        $this->assertFalse($riwayat[0]->is_active);
        $this->assertNotNull($riwayat[0]->unassigned_at);
        $this->assertSame($kelasB->id, $riwayat[1]->classroom_id);
        $this->assertTrue($riwayat[1]->is_active);
        $this->assertSame('1B', $registration->refresh()->classroom_label);
    }

    public function test_pemindahan_ditolak_bila_kelas_tujuan_penuh(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 28);
        $kelasB = $this->kelas($year, 1, 'B', 1);

        $this->tempatkan($this->pendaftar('approved', $year, 'Andi'), $kelasB);

        $registration = $this->pendaftar('approved', $year, 'Budi');
        $this->tempatkan($registration, $kelasA);

        $response = $this->tempatkan($registration, $kelasB);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Kuota kelas 1B sudah penuh', $response->getData(true)['message']);

        // Tetap berada di kelas semula
        $this->assertSame('1A', $registration->refresh()->classroom_label);
        $this->assertSame(1, $kelasB->refresh()->filled_count);
    }

    public function test_kuota_hanya_menghitung_siswa_yang_ditempatkan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);

        // 5 pendaftar: 3 diterima (2 ditempatkan), 1 menunggu, 1 ditolak
        $this->tempatkan($this->pendaftar('approved', $year, 'Budi'), $classroom);
        $this->tempatkan($this->pendaftar('approved', $year, 'Andi'), $classroom);
        $this->pendaftar('approved', $year, 'Citra');
        $this->pendaftar('submitted', $year, 'Dimas');
        $this->pendaftar('rejected', $year, 'Eka');

        $this->assertSame(5, StudentRegistration::count());
        $this->assertSame(3, StudentRegistration::where('status', 'approved')->count());
        $this->assertSame(2, $classroom->refresh()->filled_count);
        $this->assertSame(26, $classroom->available_count);
    }

    public function test_daftar_kelas_mengirim_kuota_terisi_dan_daftar_siswa(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);

        $this->tempatkan($this->pendaftar('approved', $year, 'Budi Santoso'), $classroom);
        $this->tempatkan($this->pendaftar('approved', $year, 'Andi Wijaya'), $classroom);

        // Daftar kelas
        $list = (new ClassroomController)->index(Request::create('/api/classroom', 'GET', [
            'academic_year_id' => $year->id,
        ]))->getData(true)['data'];

        $this->assertSame(2, $list[0]['filled_count']);
        $this->assertSame(26, $list[0]['available_count']);
        $this->assertSame(28, $list[0]['quota']);

        // Daftar siswa di kelas
        $detail = (new ClassroomController)->students($classroom->id)->getData(true)['data'];

        $this->assertSame('1A', $detail['classroom']['display_name']);
        $this->assertCount(2, $detail['students']);
        $this->assertSame('Budi Santoso', $detail['students'][0]['registration']['full_name']);
    }

    public function test_status_yang_tidak_lagi_diterima_menonaktifkan_penempatan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);
        $registration = $this->pendaftar('approved', $year);

        $this->tempatkan($registration, $classroom);
        $this->assertSame(1, $classroom->refresh()->filled_count);

        (new AdminRegistrationController)->updateStatus(Request::create(
            "/api/admin/registrations/{$registration->id}/status",
            'PUT',
            ['status' => 'rejected', 'notes' => 'Dokumen tidak lengkap']
        ), $registration->id);

        $this->assertSame(0, $classroom->refresh()->filled_count);
        $this->assertNull($registration->refresh()->classroom_label);

        // Riwayat tetap tersimpan
        $this->assertSame(1, ClassroomPlacement::count());
        $this->assertFalse(ClassroomPlacement::first()->is_active);
    }

    public function test_daftar_pendaftaran_mengirim_kolom_tahun_ajaran_dan_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A', 28);

        $tertempat = $this->pendaftar('approved', $year, 'Budi Santoso');
        $this->pendaftar('approved', $year, 'Andi Wijaya');
        $this->tempatkan($tertempat, $classroom);

        $response = (new AdminRegistrationController)->index(Request::create(
            '/api/admin/registrations',
            'GET',
            ['placement' => 'unplaced']
        ))->getData(true);

        // Filter "belum ditempatkan" hanya mengembalikan Andi
        $unplaced = $response['data']['data'];

        $this->assertCount(1, $unplaced);
        $this->assertSame('Andi Wijaya', $unplaced[0]['full_name']);
        $this->assertNull($unplaced[0]['classroom_label']);
        $this->assertSame('2026/2027', $unplaced[0]['academic_year']['name']);

        $placed = (new AdminRegistrationController)->index(Request::create(
            '/api/admin/registrations',
            'GET',
            ['placement' => 'placed']
        ))->getData(true);

        $this->assertCount(1, $placed['data']['data']);
        $this->assertSame('1A', $placed['data']['data'][0]['classroom_label']);
    }
}
