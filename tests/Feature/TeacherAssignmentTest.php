<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TeacherAssignmentController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\HomeroomAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
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

    private function guru(string $name = 'Ustadz Ahmad', bool $active = true): Teacher
    {
        return Teacher::create([
            'name' => $name,
            'gender' => 'L',
            'position' => 'Guru Kelas',
            'is_active' => $active,
        ]);
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

    private function daftar(AcademicYear $year, Classroom $classroom): array
    {
        return (new TeacherAssignmentController)->index(Request::create('/api/teacher-assignment', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
        ]))->getData(true)['data'];
    }

    private function setWaliKelas(AcademicYear $year, Classroom $classroom, ?int $teacherId)
    {
        return (new TeacherAssignmentController)->storeHomeroom(Request::create(
            '/api/teacher-assignment/homeroom',
            'POST',
            [
                'academic_year_id' => $year->id,
                'classroom_id' => $classroom->id,
                'teacher_id' => $teacherId,
            ]
        ));
    }

    private function setPengampu(AcademicYear $year, Classroom $classroom, array $assignments)
    {
        return (new TeacherAssignmentController)->storeTeaching(Request::create(
            '/api/teacher-assignment/teaching',
            'POST',
            [
                'academic_year_id' => $year->id,
                'classroom_id' => $classroom->id,
                'assignments' => $assignments,
            ]
        ));
    }

    public function test_admin_dapat_menentukan_wali_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();

        $response = $this->setWaliKelas($year, $classroom, $guru->id);

        $this->assertSame(200, $response->getStatusCode());

        $assignment = HomeroomAssignment::firstOrFail();

        $this->assertSame($year->id, $assignment->academic_year_id);
        $this->assertSame($classroom->id, $assignment->classroom_id);
        $this->assertSame($guru->id, $assignment->teacher_id);

        $data = $this->daftar($year, $classroom);

        $this->assertSame($guru->id, $data['homeroom']['teacher_id']);
        $this->assertSame('Ustadz Ahmad', $data['homeroom']['teacher']);
    }

    public function test_satu_kelas_hanya_punya_satu_wali_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guruA = $this->guru('Ustadz Ahmad');
        $guruB = $this->guru('Ustadzah Fatimah');

        $this->setWaliKelas($year, $classroom, $guruA->id);
        $this->setWaliKelas($year, $classroom, $guruB->id);

        $this->assertSame(1, HomeroomAssignment::count());
        $this->assertSame($guruB->id, HomeroomAssignment::firstOrFail()->teacher_id);
    }

    public function test_satu_guru_tidak_boleh_jadi_wali_dua_kelas_pada_tahun_yang_sama(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $guru = $this->guru();

        $this->setWaliKelas($year, $kelasA, $guru->id);

        $response = $this->setWaliKelas($year, $kelasB, $guru->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah menjadi wali kelas', $response->getData(true)['message']);
        $this->assertSame(1, HomeroomAssignment::count());
    }

    public function test_wali_kelas_dapat_dikosongkan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();

        $this->setWaliKelas($year, $classroom, $guru->id);
        $this->setWaliKelas($year, $classroom, null);

        $this->assertSame(0, HomeroomAssignment::count());
    }

    public function test_penugasan_tahun_sebelumnya_tidak_tertimpa(): void
    {
        $lama = $this->tahunAjaran('2026/2027', false);
        $baru = $this->tahunAjaran('2027/2028', true);

        $kelasLama = $this->kelas($lama, 1, 'A');
        $kelasBaru = $this->kelas($baru, 2, 'A');

        $guruLama = $this->guru('Ustadz Ahmad');
        $guruBaru = $this->guru('Ustadzah Fatimah');

        $this->setWaliKelas($lama, $kelasLama, $guruLama->id);
        $this->setWaliKelas($baru, $kelasBaru, $guruBaru->id);

        $this->assertSame(2, HomeroomAssignment::count());
        $this->assertSame(
            $guruLama->id,
            HomeroomAssignment::where('academic_year_id', $lama->id)->firstOrFail()->teacher_id
        );
        $this->assertSame(
            $guruBaru->id,
            HomeroomAssignment::where('academic_year_id', $baru->id)->firstOrFail()->teacher_id
        );
    }

    public function test_admin_dapat_menentukan_guru_pengampu_mapel(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $bindo = $this->mapel('BIND', 'Bahasa Indonesia', null);
        $guruA = $this->guru('Ustadz Ahmad');
        $guruB = $this->guru('Ustadzah Fatimah');

        $response = $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => $guruA->id],
            ['subject_id' => $bindo->id, 'teacher_id' => $guruB->id],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['saved']);
        $this->assertSame(2, TeachingAssignment::count());

        $data = $this->daftar($year, $classroom);
        $matematika = collect($data['subjects'])->firstWhere('code', 'MTK');

        $this->assertSame($guruA->id, $matematika['teacher_id']);
        $this->assertSame('Ustadz Ahmad', $matematika['teacher']);
    }

    public function test_satu_mapel_di_satu_kelas_hanya_punya_satu_guru(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $guruA = $this->guru('Ustadz Ahmad');
        $guruB = $this->guru('Ustadzah Fatimah');

        $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => $guruA->id],
        ]);
        $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => $guruB->id],
        ]);

        $this->assertSame(1, TeachingAssignment::count());
        $this->assertSame($guruB->id, TeachingAssignment::firstOrFail()->teacher_id);
    }

    public function test_pengampu_dapat_dikosongkan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => $guru->id],
        ]);

        $response = $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => null],
        ]);

        $this->assertSame(1, $response->getData(true)['data']['cleared']);
        $this->assertSame(0, TeachingAssignment::count());
    }

    public function test_menolak_mapel_yang_tidak_berlaku_untuk_tingkat_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapelKelas5 = $this->mapel('MTK5', 'Matematika Kelas 5', 5);
        $guru = $this->guru();

        $response = $this->setPengampu($year, $classroom, [
            ['subject_id' => $mapelKelas5->id, 'teacher_id' => $guru->id],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tidak berlaku untuk tingkat', implode(' ', $response->getData(true)['errors']['assignments']));
        $this->assertSame(0, TeachingAssignment::count());
    }

    public function test_menolak_guru_yang_tidak_aktif(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru('Ustadz Nonaktif', false);

        $response = $this->setWaliKelas($year, $classroom, $guru->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tidak aktif', $response->getData(true)['message']);
    }

    public function test_menolak_kelas_dari_tahun_ajaran_lain(): void
    {
        $tahunLain = $this->tahunAjaran('2027/2028', false);
        $tahunIni = $this->tahunAjaran('2026/2027', true);
        $classroom = $this->kelas($tahunLain, 1);
        $guru = $this->guru();

        $response = $this->setWaliKelas($tahunIni, $classroom, $guru->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bukan milik tahun ajaran', $response->getData(true)['message']);
    }

    public function test_wali_kelas_muncul_di_rapor(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru('Ustadz Ahmad');
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->setWaliKelas($year, $classroom, $guru->id);

        $data = (new ReportCardController)->index(Request::create('/api/report-card', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'student_id' => $budi->id,
        ]))->getData(true)['data'];

        $this->assertSame('Ustadz Ahmad', $data['homeroom_teacher']);
    }

    public function test_guru_pengampu_muncul_di_halaman_jadwal(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru('Ustadz Ahmad');

        $this->setWaliKelas($year, $classroom, $guru->id);
        $this->setPengampu($year, $classroom, [
            ['subject_id' => $mtk->id, 'teacher_id' => $guru->id],
        ]);

        $data = (new ScheduleController)->index(Request::create('/api/schedule', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
        ]))->getData(true)['data'];

        $this->assertSame('Ustadz Ahmad', $data['homeroom_teacher']);
        $this->assertSame($guru->id, (int) $data['subject_teachers'][$mtk->id]);
    }

    public function test_endpoint_penugasan_guru_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/teacher-assignment')->assertStatus(403);
        $this->postJson('/api/teacher-assignment/homeroom', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'teacher_id' => $guru->id,
        ])->assertStatus(403);

        $this->assertSame(0, HomeroomAssignment::count());
    }

    public function test_route_penugasan_dapat_dipakai_admin(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->getJson("/api/teacher-assignment?academic_year_id={$year->id}&classroom_id={$classroom->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.classroom.display_name', '1A');

        $this->postJson('/api/teacher-assignment/homeroom', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'teacher_id' => $guru->id,
        ])->assertStatus(200);

        $this->postJson('/api/teacher-assignment/teaching', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'assignments' => [['subject_id' => $mtk->id, 'teacher_id' => $guru->id]],
        ])->assertStatus(200);

        $this->assertSame(1, HomeroomAssignment::count());
        $this->assertSame(1, TeachingAssignment::count());
    }
}
