<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicDashboardController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\GradeController;
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

class AcademicDashboardTest extends TestCase
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

    private function kelas(AcademicYear $year, int $grade, string $name = 'A', int $quota = 28): Classroom
    {
        return Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => $grade,
            'name' => $name,
            'quota' => $quota,
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

    private function mapel(string $code, string $name, ?int $grade = null): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => $grade,
            'is_active' => true,
        ]);
    }

    private function daftar(array $params = []): array
    {
        return (new AcademicDashboardController)->index(
            Request::create('/api/academic-dashboard', 'GET', $params)
        )->getData(true)['data'];
    }

    public function test_dashboard_menampilkan_ringkasan_siswa_dan_kelas(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 28);
        $kelasB = $this->kelas($year, 1, 'B', 30);

        $this->siswa($year, $kelasA, 'Budi Santoso');
        $this->siswa($year, $kelasA, 'Andi Wijaya');
        $this->siswa($year, $kelasB, 'Citra Dewi');

        $data = $this->daftar(['academic_year_id' => $year->id]);

        $this->assertSame(3, $data['students']['active_in_year']);
        $this->assertSame(3, $data['students']['active_total']);
        $this->assertSame(2, $data['classes']['total']);
        $this->assertSame(58, $data['classes']['capacity']);
        $this->assertSame(3, $data['classes']['filled']);
        $this->assertSame(55, $data['classes']['available']);
        $this->assertSame(3, $data['classes']['students']);

        // Jumlah siswa per kelas
        $this->assertSame(2, $data['classes']['rows'][0]['students']);
        $this->assertSame(1, $data['classes']['rows'][1]['students']);
        $this->assertSame(28, $data['classes']['rows'][0]['quota']);
        $this->assertSame(26, $data['classes']['rows'][0]['available']);
    }

    public function test_dashboard_bisa_difilter_per_kelas(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');

        $this->siswa($year, $kelasA, 'Budi Santoso');
        $this->siswa($year, $kelasB, 'Andi Wijaya');

        $data = $this->daftar([
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasA->id,
        ]);

        $this->assertSame('1A', $data['classroom']['display_name']);
        $this->assertSame(1, $data['students']['active_in_year']);
        $this->assertSame(1, $data['classes']['total']);
        $this->assertSame(28, $data['classes']['capacity']);
        $this->assertSame(1, $data['classes']['filled']);
    }

    public function test_dashboard_menghitung_siswa_belum_ditempatkan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);

        $this->siswa($year, $classroom, 'Sudah Ditempatkan');
        $belum = $this->siswa($year, $classroom, 'Belum Ditempatkan');
        $belum->classHistories()->update(['is_active' => false, 'unassigned_at' => now()]);

        $data = $this->daftar(['academic_year_id' => $year->id]);

        $this->assertSame(1, $data['unplaced_students']);
        $this->assertSame(1, $data['students']['active_in_year']);
    }

    public function test_dashboard_merangkum_nilai_per_mapel_dan_kelas(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');

        $budi = $this->siswa($year, $kelasA, 'Budi Santoso');
        $andi = $this->siswa($year, $kelasA, 'Andi Wijaya');
        $citra = $this->siswa($year, $kelasB, 'Citra Dewi');

        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $bindo = $this->mapel('BIND', 'Bahasa Indonesia', 1);

        (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasA->id,
            'semester' => 1,
            'scores' => [
                ['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80],
                ['student_id' => $andi->id, 'subject_id' => $mtk->id, 'score' => 90],
                ['student_id' => $budi->id, 'subject_id' => $bindo->id, 'score' => 70],
            ],
        ]));

        (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasB->id,
            'semester' => 1,
            'scores' => [
                ['student_id' => $citra->id, 'subject_id' => $mtk->id, 'score' => 100],
            ],
        ]));

        $data = $this->daftar(['academic_year_id' => $year->id, 'semester' => 1]);

        $this->assertSame(4, $data['grades']['filled']);
        $this->assertEquals(85, $data['grades']['average']);
        $this->assertSame(0, $data['grades']['students_without_score']);

        $matematika = collect($data['grades']['by_subject'])->firstWhere('code', 'MTK');

        $this->assertSame(3, $matematika['filled']);
        $this->assertEquals(90, $matematika['average']);
        $this->assertEquals(100, $matematika['highest']);
        $this->assertEquals(80, $matematika['lowest']);

        $perKelasA = collect($data['grades']['by_class'])->firstWhere('classroom_id', $kelasA->id);

        $this->assertSame(3, $perKelasA['filled']);
        $this->assertEquals(80, $perKelasA['average']);
    }

    public function test_dashboard_menghitung_siswa_belum_punya_nilai(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $this->siswa($year, $classroom, 'Andi Wijaya');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80]],
        ]));

        $data = $this->daftar(['academic_year_id' => $year->id, 'semester' => 1]);

        $this->assertSame(1, $data['grades']['students_without_score']);
    }

    public function test_nilai_semester_lain_tidak_ikut_terhitung(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 2,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 95]],
        ]));

        $semester1 = $this->daftar(['academic_year_id' => $year->id, 'semester' => 1]);
        $semester2 = $this->daftar(['academic_year_id' => $year->id, 'semester' => 2]);

        $this->assertSame(0, $semester1['grades']['filled']);
        $this->assertSame(1, $semester2['grades']['filled']);
        $this->assertEquals(95, $semester2['grades']['average']);
    }

    public function test_dashboard_merangkum_absensi_per_status(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $andi = $this->siswa($year, $classroom, 'Andi Wijaya');

        $absen = function (string $date, array $records) use ($year, $classroom) {
            return (new AttendanceController)->store(Request::create('/api/attendance', 'POST', [
                'academic_year_id' => $year->id,
                'classroom_id' => $classroom->id,
                'date' => $date,
                'records' => $records,
            ]));
        };

        $absen('2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'izin'],
        ]);
        $absen('2026-08-02', [
            ['student_id' => $budi->id, 'status' => 'sakit'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);
        $absen('2026-08-03', [
            ['student_id' => $budi->id, 'status' => 'alpa'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);

        // Semester 2 tidak dihitung di rekap semester 1
        $absen('2027-02-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);

        $data = $this->daftar(['academic_year_id' => $year->id, 'semester' => 1]);

        $this->assertSame(3, $data['attendance']['hadir']);
        $this->assertSame(1, $data['attendance']['izin']);
        $this->assertSame(1, $data['attendance']['sakit']);
        $this->assertSame(1, $data['attendance']['alpa']);
        $this->assertSame(6, $data['attendance']['total']);
        $this->assertEquals(50, $data['attendance']['rate']);
        $this->assertSame('2026-07-01', $data['period']['from']);
        $this->assertSame('2026-12-31', $data['period']['to']);

        $perKelas = collect($data['attendance']['by_class'])->firstWhere('classroom_id', $classroom->id);

        $this->assertSame(3, $perKelas['hadir']);
    }

    public function test_dashboard_tidak_mengubah_data(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        (new GradeController)->store(Request::create('/api/grade', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 80]],
        ]));

        (new AttendanceController)->store(Request::create('/api/attendance', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-08-01',
            'records' => [['student_id' => $budi->id, 'status' => 'hadir']],
        ]));

        $sebelum = [
            'grades' => Grade::count(),
            'attendances' => Attendance::count(),
            'placements' => ClassroomPlacement::count(),
            'students' => Student::count(),
        ];

        $this->daftar(['academic_year_id' => $year->id, 'semester' => 1]);

        $this->assertSame($sebelum, [
            'grades' => Grade::count(),
            'attendances' => Attendance::count(),
            'placements' => ClassroomPlacement::count(),
            'students' => Student::count(),
        ]);
    }

    public function test_dashboard_memakai_tahun_ajaran_aktif_sebagai_default(): void
    {
        $lama = $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $this->kelas($lama, 1, 'A', 20);
        $this->kelas($aktif, 1, 'A', 30);

        $data = $this->daftar();

        $this->assertSame($aktif->id, $data['academic_year']['id']);
        $this->assertSame(30, $data['classes']['capacity']);
    }

    public function test_endpoint_dashboard_akademik_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $this->kelas($year, 1);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/academic-dashboard')->assertStatus(403);

        $admin = $this->admin();

        $this->getJson("/api/academic-dashboard?academic_year_id={$year->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.academic_year.id', $year->id);

        $this->assertNotNull($admin->id);
    }
}
