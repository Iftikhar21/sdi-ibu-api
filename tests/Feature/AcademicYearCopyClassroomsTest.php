<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Graduation;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicYearCopyClassroomsTest extends TestCase
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

        [$startYear, $endYear] = array_pad(explode('/', $name), 2, null);

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => ($startYear ?: '2026').'-07-01',
            'end_date' => ($endYear ?: '2027').'-06-30',
            'is_active' => $active,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    private function kelas(AcademicYear $year, int $grade, string $name, int $quota, bool $active = true): Classroom
    {
        return Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => $grade,
            'name' => $name,
            'quota' => $quota,
            'is_active' => $active,
        ]);
    }

    private function copy(AcademicYear $to, ?int $fromId)
    {
        return (new AcademicYearController)->copyClassrooms(
            Request::create("/api/academic-year/{$to->id}/copy-classrooms", 'POST', [
                'from_academic_year_id' => $fromId,
            ]),
            $to->id
        );
    }

    public function test_copy_struktur_kelas_beserta_kuota(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $this->kelas($lama, 1, 'A', 28);
        $this->kelas($lama, 1, 'B', 28);
        $this->kelas($lama, 2, 'A', 30);
        $this->kelas($lama, 6, 'B', 32, false);

        $response = $this->copy($baru, $lama->id);

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(4, $data['data']['created']);
        $this->assertSame(0, $data['data']['skipped']);
        $this->assertSame(4, $data['data']['classrooms_count']);

        // Kelas dan kuota tersalin
        $kelas1A = Classroom::where('academic_year_id', $baru->id)
            ->where('grade_level', 1)->where('name', 'A')->firstOrFail();

        $this->assertSame(28, $kelas1A->quota);
        $this->assertTrue($kelas1A->is_active);

        // Status kelas asal ikut tersalin
        $kelas6B = Classroom::where('academic_year_id', $baru->id)
            ->where('grade_level', 6)->where('name', 'B')->firstOrFail();

        $this->assertFalse($kelas6B->is_active);

        // Tahun ajaran lama tidak berubah
        $this->assertSame(4, Classroom::where('academic_year_id', $lama->id)->count());
    }

    public function test_copy_tidak_menduplikasi_kelas_yang_sudah_ada(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $this->kelas($lama, 1, 'A', 28);
        $this->kelas($lama, 1, 'B', 28);

        // Kelas 1A sudah dibuat manual di tahun tujuan
        $this->kelas($baru, 1, 'A', 25);

        $data = $this->copy($baru, $lama->id)->getData(true);

        $this->assertSame(1, $data['data']['created']);
        $this->assertSame(1, $data['data']['skipped']);
        $this->assertSame(2, Classroom::where('academic_year_id', $baru->id)->count());

        // Kuota kelas yang sudah ada tidak ditimpa
        $this->assertSame(25, Classroom::where('academic_year_id', $baru->id)
            ->where('grade_level', 1)->where('name', 'A')->first()->quota);
    }

    public function test_copy_bisa_diulang_tanpa_menambah_kelas_baru(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $this->kelas($lama, 1, 'A', 28);
        $this->kelas($lama, 2, 'A', 30);

        $this->copy($baru, $lama->id);
        $kedua = $this->copy($baru, $lama->id)->getData(true);

        $this->assertSame(0, $kedua['data']['created']);
        $this->assertSame(2, $kedua['data']['skipped']);
        $this->assertSame(2, Classroom::where('academic_year_id', $baru->id)->count());
        $this->assertStringContainsString('sudah ada', $kedua['message']);
    }

    public function test_copy_tidak_menyalin_siswa_pendaftaran_dan_penempatan(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($lama, 1, 'A', 28);

        $user = User::factory()->create(['role_id' => 2]);
        $registration = StudentRegistration::create([
            'user_id' => $user->id,
            'academic_year_id' => $lama->id,
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
            'status' => 'approved',
        ]);

        $student = Student::ensureForRegistration($registration);
        $student->placeIntoClassroom($kelas1A);

        $this->copy($baru, $lama->id);

        // Hanya kelas yang tersalin
        $this->assertSame(1, Classroom::where('academic_year_id', $baru->id)->count());
        $this->assertSame(1, Student::count());
        $this->assertSame(1, StudentRegistration::count());
        $this->assertSame(1, ClassroomPlacement::count());
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $baru->id)->count());
        $this->assertSame(0, Graduation::count());
    }

    public function test_menolak_tahun_ajaran_sumber_yang_sama(): void
    {
        $baru = $this->tahunAjaran('2027/2028');
        $this->kelas($baru, 1, 'A', 28);

        $this->expectException(ValidationException::class);

        $this->copy($baru, $baru->id);
    }

    public function test_menolak_tahun_ajaran_sumber_tanpa_kelas(): void
    {
        $kosong = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $response = $this->copy($baru, $kosong->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Classroom::where('academic_year_id', $baru->id)->count());
    }

    public function test_menolak_tahun_ajaran_tujuan_yang_tidak_ada(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $this->kelas($lama, 1, 'A', 28);

        $response = (new AcademicYearController)->copyClassrooms(
            Request::create('/api/academic-year/999/copy-classrooms', 'POST', [
                'from_academic_year_id' => $lama->id,
            ]),
            999
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_endpoint_copy_hanya_untuk_admin(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');
        $this->kelas($lama, 1, 'A', 28);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->postJson("/api/academic-year/{$baru->id}/copy-classrooms", [
            'from_academic_year_id' => $lama->id,
        ])->assertStatus(403);

        $this->assertSame(0, Classroom::where('academic_year_id', $baru->id)->count());
    }

    public function test_route_copy_dapat_dipakai_admin(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');
        $this->kelas($lama, 1, 'A', 28);
        $this->kelas($lama, 2, 'A', 30);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/academic-year/{$baru->id}/copy-classrooms", [
            'from_academic_year_id' => $lama->id,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Classroom::where('academic_year_id', $baru->id)->count());
    }

    public function test_dashboard_langsung_membaca_tahun_ajaran_dan_kelas_baru(): void
    {
        $lama = $this->tahunAjaran('2026/2027', true);
        $baru = $this->tahunAjaran('2027/2028');

        $this->kelas($lama, 1, 'A', 28);
        $this->kelas($lama, 2, 'A', 30);

        $this->copy($baru, $lama->id);

        $ringkasan = (new \App\Http\Controllers\DashboardAdminController)
            ->schoolSummary(Request::create('/api/admin/dashboard/school-summary', 'GET', [
                'academic_year_id' => $baru->id,
            ]))
            ->getData(true)['data'];

        $this->assertSame('2027/2028', $ringkasan['academic_year']['name']);
        $this->assertSame(2, $ringkasan['classes']['total']);
        $this->assertSame(58, $ringkasan['classes']['capacity']);
        $this->assertSame(0, $ringkasan['classes']['filled']);
        $this->assertSame(58, $ringkasan['classes']['available']);
    }
}
