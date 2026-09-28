<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\GraduationController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Graduation;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GraduationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function tahunAjaran(string $name = '2031/2032', bool $active = true): AcademicYear
    {
        $existing = AcademicYear::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        // Tanggal mengikuti nama tahun ajaran, mis. "2031/2032" -> 2031-07-01
        [$startYear, $endYear] = array_pad(explode('/', $name), 2, null);

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => ($startYear ?: '2031').'-07-01',
            'end_date' => ($endYear ?: '2032').'-06-30',
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

    /**
     * Buat siswa lengkap dengan penempatan kelas pada tahun ajaran tertentu.
     */
    private function siswa(
        AcademicYear $year,
        int $grade,
        string $className,
        string $name
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

        $classroom = Classroom::firstOrCreate(
            [
                'academic_year_id' => $year->id,
                'grade_level' => $grade,
                'name' => $className,
            ],
            ['quota' => 28, 'is_active' => true]
        );

        $student = Student::ensureForRegistration($registration);
        $student->placeIntoClassroom($classroom);

        return $student->fresh();
    }

    private function proses(array $studentIds, AcademicYear $year, array $extra = [])
    {
        return (new GraduationController)->store(Request::create(
            '/api/admin/graduations',
            'POST',
            array_merge([
                'student_ids' => $studentIds,
                'academic_year_id' => $year->id,
            ], $extra)
        ));
    }

    private function kandidat(?AcademicYear $year = null)
    {
        $request = Request::create('/api/admin/graduations', 'GET', $year ? [
            'academic_year_id' => $year->id,
        ] : []);

        return (new GraduationController)->index($request)->getData(true)['data'];
    }

    public function test_siswa_kelas_1_sampai_5_tidak_muncul_sebagai_calon_lulusan(): void
    {
        $year = $this->tahunAjaran();

        $this->siswa($year, 1, 'A', 'Siswa Kelas Satu');
        $this->siswa($year, 5, 'A', 'Siswa Kelas Lima');
        $this->siswa($year, 6, 'A', 'Siswa Kelas Enam');

        $data = $this->kandidat($year);

        $this->assertSame(1, $data['total']);
        $this->assertSame('Siswa Kelas Enam', $data['groups'][0]['students'][0]['full_name']);
    }

    public function test_calon_lulusan_dikelompokkan_per_kelas(): void
    {
        $year = $this->tahunAjaran();

        $this->siswa($year, 6, 'A', 'Budi Santoso');
        $this->siswa($year, 6, 'A', 'Andi Wijaya');
        $this->siswa($year, 6, 'B', 'Citra Dewi');

        $data = $this->kandidat($year);

        $this->assertSame(3, $data['total']);
        $this->assertCount(2, $data['groups']);
        $this->assertSame('6A', $data['groups'][0]['classroom_label']);
        $this->assertCount(2, $data['groups'][0]['students']);
        $this->assertSame('6B', $data['groups'][1]['classroom_label']);
    }

    public function test_siswa_kelas_6_di_tahun_ajaran_lain_tidak_muncul(): void
    {
        $tahunLain = $this->tahunAjaran('2030/2031', false);
        $tahunIni = $this->tahunAjaran('2031/2032', true);

        $this->siswa($tahunLain, 6, 'A', 'Lulus Tahun Lain');

        $this->assertSame(0, $this->kandidat($tahunIni)['total']);
    }

    public function test_proses_kelulusan_mengubah_status_dan_menyimpan_data(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');
        $andi = $this->siswa($year, 6, 'A', 'Andi Wijaya');

        $response = $this->proses([$budi->id, $andi->id], $year, ['graduation_date' => '2032-06-15']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['graduated']);
        $this->assertSame('graduated', $budi->fresh()->status);
        $this->assertSame('graduated', $andi->fresh()->status);

        $graduation = Graduation::where('student_id', $budi->id)->firstOrFail();

        $this->assertSame($year->id, $graduation->graduation_year_id);
        $this->assertSame('2032-06-15', $graduation->graduation_date->toDateString());
    }

    public function test_siswa_yang_sudah_lulus_tidak_bisa_diproses_lagi(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $this->proses([$budi->id], $year);

        $response = $this->proses([$budi->id], $year);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah dinyatakan lulus', implode(' ', $response->getData(true)['errors']));
        $this->assertSame(1, Graduation::count());
    }

    public function test_validasi_backend_menolak_siswa_yang_tidak_memenuhi_syarat(): void
    {
        $year = $this->tahunAjaran();

        $kelasLima = $this->siswa($year, 5, 'A', 'Siswa Kelas Lima');
        $nonaktif = $this->siswa($year, 6, 'A', 'Siswa Nonaktif');
        $nonaktif->update(['status' => 'inactive']);

        $response = $this->proses([$kelasLima->id, $nonaktif->id], $year);

        $this->assertSame(422, $response->getStatusCode());

        $errors = implode(' | ', $response->getData(true)['errors']);

        $this->assertStringContainsString('tidak tercatat di tingkat 6', $errors);
        $this->assertStringContainsString('tidak berstatus aktif', $errors);
        $this->assertSame(0, Graduation::count());
    }

    public function test_kelulusan_tidak_boleh_duplikat_di_database(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $this->proses([$budi->id], $year);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Graduation::create([
            'student_id' => $budi->id,
            'graduation_year_id' => $year->id,
        ]);
    }

    public function test_daftar_lulusan_admin_bisa_difilter_per_tahun(): void
    {
        $tahunLama = $this->tahunAjaran('2030/2031', false);
        $tahunBaru = $this->tahunAjaran('2031/2032', true);

        $budi = $this->siswa($tahunLama, 6, 'A', 'Budi Santoso');
        $andi = $this->siswa($tahunBaru, 6, 'A', 'Andi Wijaya');

        $this->proses([$budi->id], $tahunLama);
        $this->proses([$andi->id], $tahunBaru);

        $controller = new GraduationController;
        $semua = $controller->graduates(Request::create('/api/admin/graduations/graduates', 'GET'))
            ->getData(true)['data'];

        $this->assertSame(2, $semua['total']);

        $filter = $controller->graduates(Request::create(
            '/api/admin/graduations/graduates',
            'GET',
            ['academic_year_id' => $tahunBaru->id]
        ))->getData(true)['data'];

        $this->assertSame(1, $filter['total']);
        $this->assertSame('Andi Wijaya', $filter['graduates'][0]['full_name']);
        $this->assertSame('6A', $filter['graduates'][0]['last_class']);
        $this->assertSame('2031/2032', $filter['graduates'][0]['graduation_year']);
    }

    public function test_pembatalan_kelulusan_mengembalikan_status_siswa(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $this->proses([$budi->id], $year);
        $this->assertSame('graduated', $budi->fresh()->status);

        $response = (new GraduationController)->cancel(
            Request::create("/api/admin/graduations/{$budi->id}", 'DELETE'),
            $budi->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('active', $budi->fresh()->status);
        $this->assertSame(0, Graduation::count());

        // Siswa kembali muncul sebagai calon lulusan
        $this->assertSame(1, $this->kandidat($year)['total']);
    }

    public function test_pembatalan_kelulusan_menolak_siswa_yang_belum_lulus(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $response = (new GraduationController)->cancel(
            Request::create("/api/admin/graduations/{$budi->id}", 'DELETE'),
            $budi->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_data_pendaftaran_dan_riwayat_kelas_tetap_ada_setelah_lulus(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $this->proses([$budi->id], $year);

        $budi->refresh();

        $this->assertSame('graduated', $budi->status);
        $this->assertNotNull($budi->registration_id);
        $this->assertSame($year->id, $budi->admission_year_id);
        $this->assertSame(1, $budi->classHistories()->count());
        $this->assertSame('6A', $budi->classroom_label);
    }

    public function test_endpoint_publik_menampilkan_lulusan_tanpa_data_pribadi(): void
    {
        $tahunLama = $this->tahunAjaran('2030/2031', false);
        $tahunBaru = $this->tahunAjaran('2031/2032', true);

        $budi = $this->siswa($tahunLama, 6, 'A', 'Budi Santoso');
        $andi = $this->siswa($tahunBaru, 6, 'B', 'Andi Wijaya');

        $this->proses([$budi->id], $tahunLama);
        $this->proses([$andi->id], $tahunBaru);

        // Tanpa parameter: memakai tahun kelulusan terbaru
        $response = $this->getJson('/api/profil/lulusan')->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data['years']);
        $this->assertSame('2031/2032', $data['academic_year']['name']);
        $this->assertSame(1, $data['total']);
        $this->assertSame('Andi Wijaya', $data['graduates'][0]['name']);
        $this->assertSame('6B', $data['graduates'][0]['last_class']);

        // Hanya field aman yang dikirim
        $this->assertSame(
            ['id', 'name', 'last_class'],
            array_keys($data['graduates'][0])
        );

        // Filter tahun kelulusan
        $filtered = $this->getJson('/api/profil/lulusan?academic_year_id='.$tahunLama->id)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('Budi Santoso', $filtered['graduates'][0]['name']);
        $this->assertSame('6A', $filtered['graduates'][0]['last_class']);
    }

    public function test_siswa_yang_belum_lulus_tidak_muncul_di_halaman_publik(): void
    {
        $year = $this->tahunAjaran();
        $this->siswa($year, 6, 'A', 'Budi Santoso');

        $data = $this->getJson('/api/profil/lulusan')->assertStatus(200)->json('data');

        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['graduates']);
        $this->assertSame([], $data['years']);
    }

    public function test_endpoint_kelulusan_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/graduations', [
            'student_ids' => [$budi->id],
            'academic_year_id' => $year->id,
        ])->assertStatus(403);

        $this->assertSame(0, Graduation::count());
    }

    public function test_route_kelulusan_admin_dapat_diakses_admin(): void
    {
        $year = $this->tahunAjaran();
        $budi = $this->siswa($year, 6, 'A', 'Budi Santoso');

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/graduations')->assertStatus(200);

        $this->postJson('/api/admin/graduations', [
            'student_ids' => [$budi->id],
            'academic_year_id' => $year->id,
        ])->assertStatus(201);

        $this->deleteJson("/api/admin/graduations/{$budi->id}")->assertStatus(200);
    }
}
