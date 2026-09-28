<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AdminRegistrationController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTest extends TestCase
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

    private function ubahStatus(StudentRegistration $registration, string $status)
    {
        return (new AdminRegistrationController)->updateStatus(
            Request::create("/api/admin/registrations/{$registration->id}/status", 'PUT', [
                'status' => $status,
            ]),
            $registration->id
        );
    }

    public function test_pendaftar_belum_diterima_belum_punya_siswa(): void
    {
        $registration = $this->pendaftar('submitted');

        $this->assertSame(0, Student::count());
        $this->assertNull($registration->student);
    }

    public function test_pendaftar_ditolak_tidak_punya_siswa(): void
    {
        $this->pendaftar('rejected');

        $this->assertSame(0, Student::count());
    }

    public function test_pendaftar_diterima_menghasilkan_siswa_aktif(): void
    {
        $year = $this->tahunAjaran();
        $registration = $this->pendaftar('submitted', $year);

        $this->ubahStatus($registration, 'approved');

        $student = Student::firstOrFail();

        $this->assertSame($registration->id, $student->registration_id);
        $this->assertSame('Budi Santoso', $student->full_name);
        $this->assertSame('L', $student->gender);
        $this->assertSame('Jakarta', $student->birth_place);
        $this->assertSame('active', $student->status);
        $this->assertSame($year->id, $student->admission_year_id);
        $this->assertSame('Aktif', $student->status_label);
    }

    public function test_data_siswa_disalin_dari_pendaftaran(): void
    {
        $registration = $this->pendaftar();

        Student::ensureForRegistration($registration);

        $student = Student::firstOrFail();

        $this->assertSame($registration->full_name, $student->full_name);
        $this->assertSame($registration->address, $student->address);
        $this->assertSame(
            $registration->birth_date,
            $student->birth_date->toDateString()
        );
    }

    public function test_siswa_tidak_dibuat_dua_kali_walau_proses_diulang(): void
    {
        $registration = $this->pendaftar();

        $pertama = Student::ensureForRegistration($registration);
        $kedua = Student::ensureForRegistration($registration);
        $ketiga = Student::ensureForRegistration($registration->fresh());

        $this->assertTrue($pertama->is($kedua));
        $this->assertTrue($pertama->is($ketiga));
        $this->assertSame(1, Student::count());
    }

    public function test_penempatan_kelas_membuat_siswa_dan_menautkan_riwayat(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');
        $registration = $this->pendaftar('approved', $year);

        $response = $this->tempatkan($registration, $classroom);

        $this->assertSame(200, $response->getStatusCode());

        $student = Student::firstOrFail();
        $placement = ClassroomPlacement::firstOrFail();

        $this->assertSame($student->id, $placement->student_id);
        $this->assertSame('1A', $student->classroom_label);
        $this->assertSame(1, ClassroomPlacement::where('student_id', $student->id)->count());
    }

    public function test_penempatan_lama_ikut_tertaut_saat_siswa_dibentuk(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');
        $registration = $this->pendaftar('approved', $year);

        // Penempatan dibuat lebih dulu (kondisi data Step 2)
        ClassroomPlacement::create([
            'student_registration_id' => $registration->id,
            'classroom_id' => $classroom->id,
            'academic_year_id' => $year->id,
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->assertNull(ClassroomPlacement::firstOrFail()->student_id);

        $student = Student::ensureForRegistration($registration);

        $this->assertSame($student->id, ClassroomPlacement::firstOrFail()->student_id);
        $this->assertSame('1A', $student->fresh()->classroom_label);
    }

    public function test_pemindahan_kelas_hanya_menyisakan_satu_kelas_aktif(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $registration = $this->pendaftar('approved', $year);

        $this->tempatkan($registration, $kelasA);
        $this->tempatkan($registration->fresh(), $kelasB);

        $student = Student::firstOrFail();
        $aktif = ClassroomPlacement::where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $aktif);
        $this->assertSame($kelasB->id, $aktif->first()->classroom_id);

        // Riwayat kelas sebelumnya tetap tersimpan
        $riwayat = ClassroomPlacement::where('student_id', $student->id)->orderBy('id')->get();

        $this->assertCount(2, $riwayat);
        $this->assertSame($kelasA->id, $riwayat[0]->classroom_id);
        $this->assertFalse($riwayat[0]->is_active);
        $this->assertNotNull($riwayat[0]->unassigned_at);
    }

    public function test_tahun_ajaran_berbeda_membuat_riwayat_kelas_baru(): void
    {
        $tahun1 = $this->tahunAjaran('2026/2027', true);
        $tahun2 = $this->tahunAjaran('2027/2028', false);

        $kelas1A = $this->kelas($tahun1, 1, 'A');
        $kelas2A = $this->kelas($tahun2, 2, 'A');

        $registration = $this->pendaftar('approved', $tahun1);

        $this->tempatkan($registration, $kelas1A);

        $student = Student::firstOrFail();

        // Tahun berikutnya: siswa ditempatkan di kelas 2A
        $registration->update(['academic_year_id' => $tahun2->id]);
        $this->tempatkan($registration->fresh(), $kelas2A);

        $this->assertSame(2, ClassroomPlacement::where('student_id', $student->id)
            ->where('is_active', true)
            ->count()
        );
    }

    public function test_halaman_daftar_siswa_menampilkan_data_utama(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');
        $registration = $this->pendaftar('approved', $year);
        $student = Student::ensureForRegistration($registration);

        $student->update(['nis' => '10001']);
        $this->tempatkan($registration, $classroom);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/student')->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('10001', $data[0]['nis']);
        $this->assertSame('Budi Santoso', $data[0]['full_name']);
        $this->assertSame('2026/2027', $data[0]['admission_year']['name']);
        $this->assertSame('1A', $data[0]['classroom_label']);
        $this->assertSame('Aktif', $data[0]['status_label']);
    }

    public function test_detail_siswa_berisi_riwayat_kelas(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $registration = $this->pendaftar('approved', $year);
        $student = Student::ensureForRegistration($registration);

        $this->tempatkan($registration, $kelasA);
        $this->tempatkan($registration->fresh(), $kelasB);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $data = $this->getJson("/api/student/{$student->id}")->assertStatus(200)->json('data');

        $this->assertSame('Budi Santoso', $data['full_name']);
        $this->assertCount(2, $data['class_histories']);
    }

    public function test_admin_dapat_mengisi_nis_dan_mengubah_status(): void
    {
        $registration = $this->pendaftar('approved');
        $student = Student::ensureForRegistration($registration);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/student/{$student->id}/update", [
            'nis' => '10001',
            'status' => 'inactive',
        ])->assertStatus(200);

        $student->refresh();

        $this->assertSame('10001', $student->nis);
        $this->assertSame('inactive', $student->status);
    }

    public function test_nis_tidak_boleh_duplikat(): void
    {
        $student1 = Student::ensureForRegistration($this->pendaftar('approved', null, 'Budi'));
        $student2 = Student::ensureForRegistration($this->pendaftar('approved', null, 'Andi'));

        $student1->update(['nis' => '10001']);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/student/{$student2->id}/update", ['nis' => '10001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nis');

        // NIS sendiri tetap boleh disimpan ulang
        $this->putJson("/api/student/{$student1->id}/update", ['nis' => '10001'])
            ->assertStatus(200);
    }

    public function test_siswa_hanya_dibentuk_dari_pendaftaran_yang_diterima(): void
    {
        $registration = $this->pendaftar('submitted');

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/registrations/{$registration->id}/student")
            ->assertStatus(422);

        $this->assertSame(0, Student::count());
    }

    public function test_endpoint_buat_siswa_aman_dipanggil_berulang(): void
    {
        $registration = $this->pendaftar('approved');

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $pertama = $this->postJson("/api/admin/registrations/{$registration->id}/student")
            ->assertStatus(201)
            ->json('data');

        $kedua = $this->postJson("/api/admin/registrations/{$registration->id}/student")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($pertama['id'], $kedua['id']);
        $this->assertSame(1, Student::count());
    }

    public function test_siswa_dinonaktifkan_saat_status_pendaftaran_tidak_lagi_diterima(): void
    {
        $registration = $this->pendaftar('approved');

        $student = Student::ensureForRegistration($registration);

        $this->assertSame('active', $student->status);

        $this->ubahStatus($registration, 'rejected');

        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertSame(1, Student::count());
    }

    public function test_siswa_aktif_kembali_saat_pendaftaran_diterima_lagi(): void
    {
        $registration = $this->pendaftar('approved');
        $student = Student::ensureForRegistration($registration);

        $this->ubahStatus($registration, 'rejected');
        $this->ubahStatus($registration->fresh(), 'approved');

        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(1, Student::count());
    }

    public function test_status_lulus_tidak_diubah_otomatis_oleh_status_pendaftaran(): void
    {
        $registration = $this->pendaftar('approved');
        $student = Student::ensureForRegistration($registration);

        $student->update(['status' => 'graduated']);

        $this->ubahStatus($registration->fresh(), 'approved');

        $this->assertSame('graduated', $student->fresh()->status);
    }
}
