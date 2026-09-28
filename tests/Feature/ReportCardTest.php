<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\ReportCardController;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Grade;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportCardTest extends TestCase
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

    private function siswa(AcademicYear $year, Classroom $classroom, string $name): Student
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

        return $student->fresh();
    }

    private function mapel(string $code, string $name, ?int $grade = null): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => $grade,
            'is_active' => true,
        ]);
    }

    private function simpanNilai(AcademicYear $year, Classroom $classroom, int $semester, array $scores)
    {
        return (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => $semester,
            'scores' => $scores,
        ]));
    }

    private function absen(AcademicYear $year, Classroom $classroom, string $date, array $records)
    {
        return (new AttendanceController)->store(Request::create('/api/attendance', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'date' => $date,
            'records' => $records,
        ]));
    }

    private function rapor(array $params): array
    {
        return (new ReportCardController)->index(Request::create('/api/report-card', 'GET', $params))
            ->getData(true)['data'];
    }

    public function test_nilai_terikat_semester(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);
        $this->simpanNilai($year, $classroom, 2, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 90],
        ]);

        $this->assertSame(2, Grade::count());

        $semester1 = Grade::where('semester', 1)->firstOrFail();
        $semester2 = Grade::where('semester', 2)->firstOrFail();

        $this->assertSame(80.0, $semester1->score);
        $this->assertSame(90.0, $semester2->score);
        $this->assertSame('Semester 1 (Ganjil)', $semester1->semester_label);
        $this->assertSame('Semester 2 (Genap)', $semester2->semester_label);
    }

    public function test_nilai_semester_yang_sama_tidak_duplikat(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 70],
        ]);
        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 75],
        ]);

        $this->assertSame(1, Grade::count());
        $this->assertSame(75.0, Grade::firstOrFail()->score);
    }

    public function test_nilai_tidak_boleh_duplikat_di_database_per_semester(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        Grade::create([
            'student_id' => $budi->id,
            'subject_id' => $mtk->id,
            'academic_year_id' => $year->id,
            'semester' => 1,
            'score' => 80,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Grade::create([
            'student_id' => $budi->id,
            'subject_id' => $mtk->id,
            'academic_year_id' => $year->id,
            'semester' => 1,
            'score' => 85,
        ]);
    }

    public function test_rapor_menampilkan_mapel_dan_nilai_siswa(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $this->mapel('BIND', 'Bahasa Indonesia', null);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 85],
        ]);

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'student_id' => $budi->id,
        ]);

        $this->assertSame('Budi Santoso', $data['student']['full_name']);
        $this->assertSame('1A', $data['student']['classroom']);
        $this->assertCount(2, $data['subjects']);

        $matematika = collect($data['subjects'])->firstWhere('code', 'MTK');
        $bahasa = collect($data['subjects'])->firstWhere('code', 'BIND');

        $this->assertEquals(85, $matematika['score']);
        $this->assertNull($bahasa['score']);
        $this->assertEquals(85, $data['average']);
    }

    public function test_rapor_semester_2_tidak_menampilkan_nilai_semester_1(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
        ]);
        $this->simpanNilai($year, $classroom, 2, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 92],
        ]);

        $semester2 = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 2,
            'student_id' => $budi->id,
        ]);

        $this->assertEquals(92, $semester2['subjects'][0]['score']);
        $this->assertEquals(92, $semester2['average']);

        $semester1 = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'student_id' => $budi->id,
        ]);

        $this->assertEquals(80, $semester1['subjects'][0]['score']);
    }

    public function test_rapor_menampilkan_rekap_absensi_siswa(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $andi = $this->siswa($year, $classroom, 'Andi Wijaya');

        $this->absen($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);
        $this->absen($year, $classroom, '2026-08-02', [
            ['student_id' => $budi->id, 'status' => 'izin'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);
        $this->absen($year, $classroom, '2026-08-03', [
            ['student_id' => $budi->id, 'status' => 'sakit'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);
        // Semester 2: tidak dihitung untuk rapor semester 1
        $this->absen($year, $classroom, '2027-02-01', [
            ['student_id' => $budi->id, 'status' => 'alpa'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'student_id' => $budi->id,
        ]);

        $this->assertSame(1, $data['attendance']['hadir']);
        $this->assertSame(1, $data['attendance']['izin']);
        $this->assertSame(1, $data['attendance']['sakit']);
        $this->assertSame(0, $data['attendance']['alpa']);
        $this->assertSame(3, $data['attendance']['total']);
        $this->assertSame('2026-07-01', $data['period']['from']);
        $this->assertSame('2026-12-31', $data['period']['to']);
    }

    public function test_semester_2_memakai_periode_januari_sampai_akhir_tahun(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->absen($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'sakit'],
        ]);
        $this->absen($year, $classroom, '2027-02-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 2,
            'student_id' => $budi->id,
        ]);

        $this->assertSame('2027-01-01', $data['period']['from']);
        $this->assertSame('2027-06-30', $data['period']['to']);
        $this->assertSame(1, $data['attendance']['hadir']);
        $this->assertSame(0, $data['attendance']['sakit']);
    }

    public function test_daftar_siswa_rapor_diambil_dari_penempatan_kelas(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');

        $this->siswa($year, $kelasA, 'Budi Santoso');
        $this->siswa($year, $kelasA, 'Andi Wijaya');
        $this->siswa($year, $kelasB, 'Siswa Kelas Lain');

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasA->id,
            'semester' => 1,
        ]);

        $this->assertCount(2, $data['students']);
        $this->assertSame('Andi Wijaya', $data['students'][0]['full_name']);
        $this->assertNull($data['student']);
    }

    public function test_siswa_dari_kelas_lain_tidak_bisa_dibuka_rapornya(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $this->siswa($year, $kelasA, 'Budi Santoso');
        $orangLain = $this->siswa($year, $kelasB, 'Andi Wijaya');

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasA->id,
            'semester' => 1,
            'student_id' => $orangLain->id,
        ]);

        $this->assertNull($data['student']);
        $this->assertSame([], $data['subjects']);
    }

    public function test_semester_tidak_dikenal_ditolak_saat_menyimpan_nilai(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->postJson('/api/grade', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 3,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');

        $this->assertSame(0, Grade::count());
    }

    public function test_endpoint_rapor_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson("/api/report-card?academic_year_id={$year->id}&classroom_id={$classroom->id}&student_id={$budi->id}")
            ->assertStatus(403);
    }

    public function test_route_rapor_dapat_dipakai_admin(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 88],
        ]);

        $this->getJson("/api/report-card?academic_year_id={$year->id}&classroom_id={$classroom->id}&semester=1&student_id={$budi->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.student.full_name', 'Budi Santoso')
            ->assertJsonPath('data.subjects.0.score', 88);

        // Tidak ada data baru yang dibuat oleh rapor
        $this->assertSame(1, Grade::count());
        $this->assertSame(0, Attendance::count());
        $this->assertSame(1, ClassroomPlacement::count());
    }

    public function test_payload_rapor_memuat_data_yang_dibutuhkan_dokumen_cetak(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        // NIS diisi supaya muncul di dokumen
        $budi->update(['nis' => '10001']);

        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->simpanNilai($year, $classroom, 1, [
            ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 88],
        ]);
        $this->absen($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $data = $this->rapor([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'student_id' => $budi->id,
        ]);

        // Identitas siswa
        $this->assertSame('Budi Santoso', $data['student']['full_name']);
        $this->assertSame('10001', $data['student']['nis']);
        $this->assertSame('1A', $data['student']['classroom']);

        // Tahun ajaran + semester + periode
        $this->assertSame('2026/2027', $data['academic_year']['name']);
        $this->assertSame(1, $data['semester']);
        $this->assertNotNull($data['period']['from']);
        $this->assertNotNull($data['period']['to']);

        // Daftar mata pelajaran + nilai
        $this->assertSame('MTK', $data['subjects'][0]['code']);
        $this->assertSame('Matematika', $data['subjects'][0]['name']);
        $this->assertEquals(88, $data['subjects'][0]['score']);
        $this->assertEquals(88, $data['average']);

        // Rekap absensi
        $this->assertSame(1, $data['attendance']['hadir']);
        $this->assertSame(0, $data['attendance']['izin']);
        $this->assertSame(0, $data['attendance']['sakit']);
        $this->assertSame(0, $data['attendance']['alpa']);
    }
}
