<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\GradeController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\Graduation;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        return $admin;
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

    private function kelas(AcademicYear $year, int $grade, string $name = 'A'): Classroom
    {
        return Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => $grade,
            'name' => $name,
            'quota' => 28,
            'is_active' => true,
        ]);
    }

    private function siswa(AcademicYear $year, Classroom $classroom, string $name, string $status = 'active'): Student
    {
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

    private function mapel(string $code, string $name, ?int $grade = null, bool $active = true): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => $grade,
            'is_active' => $active,
        ]);
    }

    private function daftar(?AcademicYear $year, ?Classroom $classroom = null): array
    {
        $params = [];

        if ($year) {
            $params['academic_year_id'] = $year->id;
        }

        if ($classroom) {
            $params['classroom_id'] = $classroom->id;
        }

        return (new GradeController)->index(Request::create('/api/grade', 'GET', $params))
            ->getData(true)['data'];
    }

    private function simpan(AcademicYear $year, Classroom $classroom, array $scores)
    {
        return (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'scores' => $scores,
        ]));
    }

    /* ---------------------------------------------------------------- */
    /* Master Mata Pelajaran */
    /* ---------------------------------------------------------------- */

    public function test_admin_dapat_menambah_mata_pelajaran(): void
    {
        $this->admin();

        $this->postJson('/api/subject/create', [
            'code' => 'mtk',
            'name' => 'Matematika',
            'grade_level' => 1,
            'is_active' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'MTK');

        $subject = Subject::firstOrFail();

        $this->assertSame('MTK', $subject->code);
        $this->assertSame('Matematika', $subject->name);
        $this->assertSame(1, $subject->grade_level);
        $this->assertSame('Tingkat 1', $subject->grade_label);
    }

    public function test_mata_pelajaran_boleh_berlaku_untuk_semua_tingkat(): void
    {
        $this->admin();

        $this->postJson('/api/subject/create', [
            'code' => 'PJOK',
            'name' => 'Pendidikan Jasmani',
        ])->assertStatus(201);

        $this->assertNull(Subject::firstOrFail()->grade_level);
        $this->assertSame('Semua Tingkat', Subject::firstOrFail()->grade_label);
    }

    public function test_kode_mata_pelajaran_tidak_boleh_duplikat(): void
    {
        $this->admin();
        $this->mapel('MTK', 'Matematika');

        $this->postJson('/api/subject/create', ['code' => 'MTK', 'name' => 'Lain'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_tingkat_mata_pelajaran_harus_1_sampai_6(): void
    {
        $this->admin();

        $this->postJson('/api/subject/create', [
            'code' => 'IPA',
            'name' => 'Ilmu Pengetahuan Alam',
            'grade_level' => 7,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade_level');
    }

    public function test_admin_dapat_mengubah_dan_menonaktifkan_mata_pelajaran(): void
    {
        $this->admin();
        $subject = $this->mapel('MTK', 'Matematika');

        $this->putJson("/api/subject/{$subject->id}/update", [
            'name' => 'Matematika Dasar',
            'is_active' => false,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Matematika Dasar');

        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_mata_pelajaran_yang_sudah_dipakai_tidak_bisa_dihapus(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $student = $this->siswa($year, $classroom, 'Budi Santoso');
        $subject = $this->mapel('MTK', 'Matematika', 1);

        $this->simpan($year, $classroom, [
            ['student_id' => $student->id, 'subject_id' => $subject->id, 'score' => 80],
        ]);

        $this->deleteJson("/api/subject/{$subject->id}/delete")
            ->assertStatus(422);

        $this->assertSame(1, Subject::count());
    }

    public function test_endpoint_mata_pelajaran_hanya_untuk_admin(): void
    {
        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/subject')->assertStatus(403);
        $this->postJson('/api/subject/create', ['code' => 'X', 'name' => 'X'])->assertStatus(403);
    }

    /* ---------------------------------------------------------------- */
    /* Akademik dasar + input nilai */
    /* ---------------------------------------------------------------- */

    public function test_daftar_kelas_menampilkan_siswa_dari_penempatan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1, 'A');
        $kelasLain = $this->kelas($year, 1, 'B');

        $this->siswa($year, $classroom, 'Budi Santoso');
        $this->siswa($year, $classroom, 'Andi Wijaya');
        $this->siswa($year, $kelasLain, 'Siswa Kelas Lain');

        $data = $this->daftar($year, $classroom);

        $this->assertCount(2, $data['students']);
        $this->assertSame('Andi Wijaya', $data['students'][0]['full_name']);
        $this->assertSame('1A', $data['classroom']['display_name']);
    }

    public function test_daftar_mata_pelajaran_mengikuti_tingkat_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 2, 'A');

        $this->mapel('MTK2', 'Matematika Kelas 2', 2);
        $this->mapel('BIND', 'Bahasa Indonesia', null);
        $this->mapel('MTK5', 'Matematika Kelas 5', 5);
        $this->mapel('OFF', 'Mapel Nonaktif', 2, false);

        $data = $this->daftar($year, $classroom);

        $this->assertSame(['BIND', 'MTK2'], array_column($data['subjects'], 'code'));
    }

    public function test_admin_dapat_menyimpan_nilai_siswa(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $response = $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 85.5],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['data']['saved']);

        $grade = Grade::firstOrFail();

        $this->assertSame($budi->id, $grade->student_id);
        $this->assertSame($mtk->id, $grade->subject_id);
        $this->assertSame($year->id, $grade->academic_year_id);
        $this->assertSame(85.5, $grade->score);
    }

    public function test_nilai_tidak_duplikat_untuk_siswa_mapel_tahun_yang_sama(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 70],
        ]);
        $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 90],
        ]);

        $this->assertSame(1, Grade::count());
        $this->assertSame(90.0, Grade::firstOrFail()->score);
    }

    public function test_nilai_tidak_boleh_duplikat_di_database(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        Grade::create([
            'student_id' => $budi->id,
            'subject_id' => $mtk->id,
            'academic_year_id' => $year->id,
            'score' => 80,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Grade::create([
            'student_id' => $budi->id,
            'subject_id' => $mtk->id,
            'academic_year_id' => $year->id,
            'score' => 90,
        ]);
    }

    public function test_menolak_siswa_yang_bukan_anggota_kelas(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');

        $this->siswa($year, $kelasA, 'Budi Santoso');
        $orangLain = $this->siswa($year, $kelasB, 'Andi Wijaya');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $response = $this->simpan($year, $kelasA, [
            ['student_id' => $orangLain->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'tidak terdaftar di kelas',
            implode(' ', $response->getData(true)['errors']['scores'])
        );
        $this->assertSame(0, Grade::count());
    }

    public function test_menolak_mata_pelajaran_yang_tidak_berlaku_untuk_tingkat(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mapelKelas5 = $this->mapel('MTK5', 'Matematika Kelas 5', 5);

        $response = $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mapelKelas5->id, 'score' => 80],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'tidak berlaku untuk tingkat',
            implode(' ', $response->getData(true)['errors']['scores'])
        );
    }

    public function test_menolak_kelas_dari_tahun_ajaran_lain(): void
    {
        $this->admin();
        $tahunLain = $this->tahunAjaran('2027/2028', false);
        $tahunIni = $this->tahunAjaran('2026/2027', true);

        $classroom = $this->kelas($tahunLain, 1);
        $budi = $this->siswa($tahunLain, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $response = $this->simpan($tahunIni, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bukan milik tahun ajaran', $response->getData(true)['message']);
    }

    public function test_siswa_lulus_tidak_bisa_dinilai_pada_tahun_setelahnya(): void
    {
        $this->admin();
        $tahunLulus = $this->tahunAjaran('2031/2032', false);
        $tahunSetelah = $this->tahunAjaran('2032/2033', true);

        $kelas6 = $this->kelas($tahunLulus, 6);
        $budi = $this->siswa($tahunLulus, $kelas6, 'Budi Santoso');

        Graduation::create([
            'student_id' => $budi->id,
            'graduation_year_id' => $tahunLulus->id,
        ]);
        $budi->update(['status' => 'graduated']);

        // Tahun setelah lulus: siswa tidak punya penempatan kelas di tahun itu
        $kelasTujuan = $this->kelas($tahunSetelah, 6);
        $mtk = $this->mapel('MTK', 'Matematika', 6);

        $response = $this->simpan($tahunSetelah, $kelasTujuan, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Grade::count());
    }

    public function test_nilai_masih_bisa_diisi_pada_tahun_kelulusannya(): void
    {
        $this->admin();
        $tahunLulus = $this->tahunAjaran('2031/2032', true);
        $kelas6 = $this->kelas($tahunLulus, 6);
        $budi = $this->siswa($tahunLulus, $kelas6, 'Budi Santoso');

        Graduation::create([
            'student_id' => $budi->id,
            'graduation_year_id' => $tahunLulus->id,
        ]);
        $budi->update(['status' => 'graduated']);

        $mtk = $this->mapel('MTK', 'Matematika', 6);

        $response = $this->simpan($tahunLulus, $kelas6, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 88],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, Grade::count());
    }

    public function test_nilai_di_luar_0_sampai_100_ditolak(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->postJson('/api/grade', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'scores' => [
                ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 120],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scores.0.score');

        $this->assertSame(0, Grade::count());
    }

    public function test_satu_baris_gagal_maka_semua_nilai_dibatalkan(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $mapelSalah = $this->mapel('MTK5', 'Matematika Kelas 5', 5);

        $response = $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
            ['student_id' => $budi->id, 'subject_id' => $mapelSalah->id, 'score' => 80],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Grade::count());
    }

    public function test_mengosongkan_nilai_berarti_menghapus_nilainya(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);

        $response = $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => null],
        ]);

        $this->assertSame(1, $response->getData(true)['data']['cleared']);
        $this->assertSame(0, Grade::count());
    }

    public function test_daftar_nilai_menampilkan_nilai_yang_sudah_tersimpan(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpan($year, $classroom, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 77],
        ]);

        $data = $this->daftar($year, $classroom);

        $this->assertCount(1, $data['grades']);
        $this->assertEquals(77, $data['grades'][0]['score']);
    }

    public function test_endpoint_nilai_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/grade')->assertStatus(403);
        $this->postJson('/api/grade', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80]],
        ])->assertStatus(403);

        $this->assertSame(0, Grade::count());
        $this->assertNotNull($budi->id);
    }
}
