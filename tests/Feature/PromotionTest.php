<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\PromotionController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use App\Support\StudentPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionTest extends TestCase
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

    private function siswa(
        AcademicYear $year,
        Classroom $classroom,
        string $name,
        string $status = 'active'
    ): Student {
        $user = User::factory()->create(['role_id' => 2]);

        $registration = StudentRegistration::create([
            'user_id' => $user->id,
            'academic_year_id' => $year->id,
            'full_name' => $name,
            'nickname' => 'Panggilan',
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
            'status' => 'approved',
        ]);

        $student = Student::ensureForRegistration($registration);
        $student->placeIntoClassroom($classroom);
        $student->update(['status' => $status]);

        return $student->fresh();
    }

    private function daftar(AcademicYear $from, AcademicYear $to): array
    {
        return $this->controller()->index(Request::create(
            '/api/admin/promotions',
            'GET',
            ['from_academic_year_id' => $from->id, 'to_academic_year_id' => $to->id]
        ))->getData(true)['data'];
    }

    private function controller(): PromotionController
    {
        return new PromotionController(new StudentPromotion);
    }

    private function proses(AcademicYear $from, AcademicYear $to, array $promotions)
    {
        return $this->controller()->store(Request::create(
            '/api/admin/promotions',
            'POST',
            [
                'from_academic_year_id' => $from->id,
                'to_academic_year_id' => $to->id,
                'promotions' => $promotions,
            ]
        ));
    }

    public function test_kandidat_hanya_siswa_yang_punya_kelas_di_tahun_asal(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $this->kelas($tujuan, 2, 'A');

        $this->siswa($asal, $kelas1A, 'Punya Kelas');
        $this->siswa($tujuan, $this->kelas($tujuan, 3, 'A'), 'Siswa Tahun Tujuan');

        $data = $this->daftar($asal, $tujuan);

        $this->assertSame(1, $data['total']);
        $this->assertSame('Punya Kelas', $data['rows'][0]['full_name']);
        $this->assertSame('1A', $data['rows'][0]['from_classroom_label']);
    }

    public function test_usulan_kelas_tujuan_adalah_tingkat_satu_lebih_tinggi(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas2B = $this->kelas($asal, 2, 'B');
        $kelas3B = $this->kelas($tujuan, 3, 'B');
        $this->kelas($tujuan, 3, 'A');

        $this->siswa($asal, $kelas2B, 'Budi Santoso');

        $row = $this->daftar($asal, $tujuan)['rows'][0];

        $this->assertSame('2B', $row['from_classroom_label']);
        $this->assertSame('3B', $row['suggested_classroom_label']);
        $this->assertSame($kelas3B->id, $row['suggested_classroom_id']);
    }

    public function test_siswa_tingkat_6_tidak_ikut_kenaikan_kelas(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $this->kelas($tujuan, 6, 'A');
        $this->siswa($asal, $this->kelas($asal, 6, 'A'), 'Siswa Kelas Enam');

        $this->assertSame(0, $this->daftar($asal, $tujuan)['total']);
    }

    public function test_siswa_yang_sudah_punya_kelas_di_tahun_tujuan_tidak_muncul(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $this->kelas($tujuan, 2, 'A');
        $kelas2B = $this->kelas($tujuan, 2, 'B');

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        // Sudah dipindahkan lebih dulu ke tahun tujuan
        $budi->placeIntoClassroom($kelas2B);

        $this->assertSame(0, $this->daftar($asal, $tujuan)['total']);
    }

    public function test_proses_kenaikan_kelas_membuat_riwayat_baru_tanpa_menghapus_yang_lama(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A');

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas2A->id],
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['data']['promoted']);

        $budi->refresh();

        // Riwayat tahun asal tetap ada dan tetap aktif
        $placementAsal = ClassroomPlacement::where('student_id', $budi->id)
            ->where('academic_year_id', $asal->id)
            ->firstOrFail();

        $this->assertTrue($placementAsal->is_active);
        $this->assertSame($kelas1A->id, $placementAsal->classroom_id);

        // Penempatan baru pada tahun tujuan
        $placementTujuan = ClassroomPlacement::where('student_id', $budi->id)
            ->where('academic_year_id', $tujuan->id)
            ->firstOrFail();

        $this->assertTrue($placementTujuan->is_active);
        $this->assertSame($kelas2A->id, $placementTujuan->classroom_id);
        $this->assertStringContainsString('Naik dari 1A', $placementTujuan->notes);

        // Riwayat kelas bertambah, bukan diganti
        $this->assertSame(2, $budi->classHistories()->count());
    }

    public function test_kuota_kelas_tujuan_dicek(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A', 2);

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');
        $andi = $this->siswa($asal, $kelas1A, 'Andi Wijaya');
        $citra = $this->siswa($asal, $kelas1A, 'Citra Dewi');

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas2A->id],
            ['student_id' => $andi->id, 'classroom_id' => $kelas2A->id],
            ['student_id' => $citra->id, 'classroom_id' => $kelas2A->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'Kuota kelas 2A tidak cukup',
            implode(' ', $response->getData(true)['errors'])
        );
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tujuan->id)->count());
    }

    public function test_menolak_kelas_tujuan_dari_tahun_ajaran_lain(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');
        $tahunLain = $this->tahunAjaran('2028/2029');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $this->kelas($tujuan, 2, 'A');
        $kelasSalah = $this->kelas($tahunLain, 2, 'A');

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelasSalah->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bukan milik tahun ajaran', $response->getData(true)['errors'][0]);
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tahunLain->id)->count());
    }

    public function test_menolak_siswa_tanpa_kelas_di_tahun_asal(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $this->kelas($tujuan, 2, 'A');
        $kelas2A = Classroom::where('academic_year_id', $tujuan->id)->firstOrFail();

        // Siswa ada, tetapi tidak punya kelas di tahun asal
        $budi = $this->siswa($tujuan, $kelas2A, 'Budi Santoso');
        ClassroomPlacement::where('student_id', $budi->id)->update([
            'is_active' => false,
            'unassigned_at' => now(),
        ]);

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas2A->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_menolak_siswa_yang_sudah_punya_kelas_di_tahun_tujuan(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A');
        $kelas2B = $this->kelas($tujuan, 2, 'B');

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');
        $budi->placeIntoClassroom($kelas2B);

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas2A->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah punya kelas', $response->getData(true)['errors'][0]);
        $this->assertSame(1, ClassroomPlacement::where('student_id', $budi->id)
            ->where('academic_year_id', $tujuan->id)
            ->where('is_active', true)
            ->count()
        );
    }

    public function test_tahun_ajaran_tujuan_harus_berbeda(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $kelas1A = $this->kelas($asal, 1, 'A');
        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->proses($asal, $asal, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas1A->id],
        ]);
    }

    public function test_endpoint_kenaikan_kelas_hanya_untuk_admin(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A');
        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/promotions')->assertStatus(403);
        $this->postJson('/api/admin/promotions', [
            'from_academic_year_id' => $asal->id,
            'to_academic_year_id' => $tujuan->id,
            'promotions' => [['student_id' => $budi->id, 'classroom_id' => $kelas2A->id]],
        ])->assertStatus(403);

        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tujuan->id)->count());
    }

    public function test_route_kenaikan_kelas_dapat_dipakai_admin(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A');
        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/promotions?from_academic_year_id={$asal->id}&to_academic_year_id={$tujuan->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);

        $this->postJson('/api/admin/promotions', [
            'from_academic_year_id' => $asal->id,
            'to_academic_year_id' => $tujuan->id,
            'promotions' => [['student_id' => $budi->id, 'classroom_id' => $kelas2A->id]],
        ])->assertStatus(201);
    }

    public function test_menolak_kelas_tujuan_yang_tidak_satu_tingkat_di_atas(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $this->kelas($tujuan, 4, 'A'); // lompat dari 1 ke 4

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');
        $kelasSalah = Classroom::where('academic_year_id', $tujuan->id)->firstOrFail();

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelasSalah->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'harus naik ke tingkat 2',
            implode(' ', $response->getData(true)['errors'])
        );
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tujuan->id)->count());
    }

    public function test_siswa_tingkat_6_ditolak_backend(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas6A = $this->kelas($asal, 6, 'A');
        $kelasTujuan = $this->kelas($tujuan, 6, 'A');

        $budi = $this->siswa($asal, $kelas6A, 'Budi Santoso');

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelasTujuan->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'diproses lewat menu Kelulusan',
            implode(' ', $response->getData(true)['errors'])
        );
    }

    public function test_siswa_nonaktif_dan_lulus_tidak_bisa_dinaikkan(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A');

        $nonaktif = $this->siswa($asal, $kelas1A, 'Siswa Nonaktif', 'inactive');
        $lulus = $this->siswa($asal, $kelas1A, 'Siswa Lulus', 'graduated');

        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $nonaktif->id, 'classroom_id' => $kelas2A->id],
            ['student_id' => $lulus->id, 'classroom_id' => $kelas2A->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());

        $errors = implode(' | ', $response->getData(true)['errors']);

        $this->assertStringContainsString('tidak berstatus aktif', $errors);
        $this->assertStringContainsString('sudah dinyatakan lulus', $errors);
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tujuan->id)->count());
    }

    public function test_satu_baris_gagal_maka_semua_dibatalkan(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $this->kelas($tujuan, 2, 'A');
        $kelas4A = $this->kelas($tujuan, 4, 'A');

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');
        $andi = $this->siswa($asal, $kelas1A, 'Andi Wijaya');

        // Baris kedua memakai kelas yang salah -> seluruh proses dibatalkan
        $response = $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => Classroom::where('academic_year_id', $tujuan->id)->where('grade_level', 2)->firstOrFail()->id],
            ['student_id' => $andi->id, 'classroom_id' => $kelas4A->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, ClassroomPlacement::where('academic_year_id', $tujuan->id)->count());
        $this->assertSame(2, ClassroomPlacement::where('academic_year_id', $asal->id)->count());
    }

    public function test_dashboard_ikut_menghitung_hasil_kenaikan(): void
    {
        $asal = $this->tahunAjaran('2026/2027', true);
        $tujuan = $this->tahunAjaran('2027/2028');

        $kelas1A = $this->kelas($asal, 1, 'A');
        $kelas2A = $this->kelas($tujuan, 2, 'A', 30);

        $budi = $this->siswa($asal, $kelas1A, 'Budi Santoso');
        $andi = $this->siswa($asal, $kelas1A, 'Andi Wijaya');

        $this->proses($asal, $tujuan, [
            ['student_id' => $budi->id, 'classroom_id' => $kelas2A->id],
            ['student_id' => $andi->id, 'classroom_id' => $kelas2A->id],
        ]);

        $ringkasan = (new \App\Http\Controllers\DashboardAdminController)
            ->schoolSummary(Request::create('/api/admin/dashboard/school-summary', 'GET', [
                'academic_year_id' => $tujuan->id,
            ]))
            ->getData(true)['data'];

        $this->assertSame(1, $ringkasan['classes']['total']);
        $this->assertSame(2, $ringkasan['classes']['filled']);
        $this->assertSame(28, $ringkasan['classes']['available']);
    }
}
