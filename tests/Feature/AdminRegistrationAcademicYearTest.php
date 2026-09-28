<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminRegistrationController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRegistrationAcademicYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function tahunAjaran(string $name, bool $active = false): AcademicYear
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

    /**
     * Pendaftaran lama: belum punya tahun ajaran (academic_year_id = null).
     */
    private function pendaftarTanpaTahunAjaran(string $status = 'approved'): StudentRegistration
    {
        $user = User::factory()->create(['role_id' => 2]);

        return StudentRegistration::create([
            'user_id' => $user->id,
            'academic_year_id' => null,
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
            'photo' => 'registrations/photos/foto.jpg',
            'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
            'family_card' => 'registrations/family_cards/kk.jpg',
            'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
            'status' => $status,
        ]);
    }

    public function test_admin_dapat_mengisi_tahun_ajaran_pendaftar(): void
    {
        $year = $this->tahunAjaran('2026/2027', true);
        $registration = $this->pendaftarTanpaTahunAjaran();

        $response = (new AdminRegistrationController)->setAcademicYear(
            Request::create("/api/admin/registrations/{$registration->id}/academic-year", 'PUT', [
                'academic_year_id' => $year->id,
            ]),
            $registration->id
        );

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('2026/2027', $data['data']['academic_year']['name']);
        $this->assertSame($year->id, $registration->fresh()->academic_year_id);
    }

    public function test_admin_dapat_mengubah_tahun_ajaran_selama_belum_ada_kelas(): void
    {
        $lama = $this->tahunAjaran('2025/2026');
        $baru = $this->tahunAjaran('2026/2027', true);

        $registration = $this->pendaftarTanpaTahunAjaran();
        $registration->update(['academic_year_id' => $lama->id]);

        (new AdminRegistrationController)->setAcademicYear(
            Request::create("/api/admin/registrations/{$registration->id}/academic-year", 'PUT', [
                'academic_year_id' => $baru->id,
            ]),
            $registration->id
        );

        $this->assertSame($baru->id, $registration->fresh()->academic_year_id);
    }

    public function test_tahun_ajaran_tidak_boleh_diubah_kalau_siswa_sudah_punya_kelas(): void
    {
        $tahunKelas = $this->tahunAjaran('2025/2026');
        $tahunLain = $this->tahunAjaran('2026/2027', true);

        $registration = $this->pendaftarTanpaTahunAjaran();
        $registration->update(['academic_year_id' => $tahunKelas->id]);

        $classroom = Classroom::create([
            'academic_year_id' => $tahunKelas->id,
            'grade_level' => 1,
            'name' => 'A',
            'quota' => 28,
            'is_active' => true,
        ]);

        ClassroomPlacement::create([
            'classroom_id' => $classroom->id,
            'student_registration_id' => $registration->id,
            'academic_year_id' => $tahunKelas->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $response = (new AdminRegistrationController)->setAcademicYear(
            Request::create("/api/admin/registrations/{$registration->id}/academic-year", 'PUT', [
                'academic_year_id' => $tahunLain->id,
            ]),
            $registration->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah ditempatkan', $response->getData(true)['message']);
        $this->assertSame($tahunKelas->id, $registration->fresh()->academic_year_id);
    }

    public function test_tahun_ajaran_wajib_diisi_dan_harus_terdaftar(): void
    {
        $registration = $this->pendaftarTanpaTahunAjaran();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new AdminRegistrationController)->setAcademicYear(
            Request::create("/api/admin/registrations/{$registration->id}/academic-year", 'PUT', [
                'academic_year_id' => 999,
            ]),
            $registration->id
        );
    }

    public function test_route_isi_tahun_ajaran_terdaftar_untuk_admin(): void
    {
        $year = $this->tahunAjaran('2026/2027', true);
        $registration = $this->pendaftarTanpaTahunAjaran();

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/registrations/{$registration->id}/academic-year", [
            'academic_year_id' => $year->id,
        ])->assertStatus(200);

        $this->assertSame($year->id, $registration->fresh()->academic_year_id);
    }

    public function test_endpoint_menolak_user_biasa(): void
    {
        $year = $this->tahunAjaran('2026/2027', true);
        $registration = $this->pendaftarTanpaTahunAjaran();

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->putJson("/api/admin/registrations/{$registration->id}/academic-year", [
            'academic_year_id' => $year->id,
        ])->assertStatus(403);

        $this->assertNull($registration->fresh()->academic_year_id);
    }
}
