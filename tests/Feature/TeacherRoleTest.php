<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\GradeController;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\HomeroomAssignment;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherRoleTest extends TestCase
{
    use RefreshDatabase;

    /** Password awal yang dikembalikan saat akun guru dibuat. */
    private string $passwordGuru = '';

    private ?int $guruUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
        Role::create(['role_name' => 'guru']);
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

    private function guru(string $name = 'Ustadz Ahmad', ?string $email = 'ahmad@example.com'): Teacher
    {
        return Teacher::create([
            'name' => $name,
            'email' => $email,
            'gender' => 'L',
            'position' => 'Guru Kelas',
            'is_active' => true,
        ]);
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

    /** Buat akun guru lalu login sebagai guru tersebut. */
    private function loginSebagaiGuru(Teacher $teacher): User
    {
        $this->admin();

        $response = $this->postJson("/api/teacher/{$teacher->id}/account", [
            'email' => $teacher->email,
        ]);

        $password = $response->json('data.password');
        $user = User::where('email', $teacher->email)->firstOrFail();

        $this->passwordGuru = $password;
        $this->guruUserId = $user->id;

        Sanctum::actingAs($user);

        $this->assertTrue(Hash::check($password, $user->password));

        return $user;
    }

    private function assignWaliKelas(AcademicYear $year, Classroom $classroom, Teacher $teacher): void
    {
        HomeroomAssignment::create([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    private function assignPengampu(AcademicYear $year, Classroom $classroom, Subject $subject, Teacher $teacher): void
    {
        TeachingAssignment::create([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_admin_dapat_membuatkan_akun_untuk_guru(): void
    {
        $admin = $this->admin();
        $guru = $this->guru();

        $response = $this->postJson("/api/teacher/{$guru->id}/account", [
            'email' => 'ahmad@example.com',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'ahmad@example.com')->firstOrFail();

        $this->assertSame('guru', $user->role->role_name);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($user->id, $guru->fresh()->user_id);
        $this->assertNotEmpty($response->json('data.password'));
    }

    public function test_tidak_bisa_membuat_akun_ganda_atau_email_duplikat(): void
    {
        $this->admin();
        $guru = $this->guru();

        $this->postJson("/api/teacher/{$guru->id}/account", ['email' => 'ahmad@example.com'])
            ->assertStatus(201);

        $this->postJson("/api/teacher/{$guru->id}/account", ['email' => 'ahmad@example.com'])
            ->assertStatus(422);

        $guruLain = $this->guru('Ustadzah Fatimah', 'fatimah@example.com');

        $this->postJson("/api/teacher/{$guruLain->id}/account", ['email' => 'ahmad@example.com'])
            ->assertStatus(422);
    }

    public function test_email_wajib_diisi_saat_membuat_akun(): void
    {
        $this->admin();
        $guru = $this->guru('Guru Tanpa Email', null);

        $this->postJson("/api/teacher/{$guru->id}/account", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_guru_dapat_login_dan_wajib_mengganti_password(): void
    {
        $guru = $this->guru();
        $this->admin();

        $response = $this->postJson("/api/teacher/{$guru->id}/account", [
            'email' => 'ahmad@example.com',
        ]);

        $password = $response->json('data.password');
        $user = User::where('email', 'ahmad@example.com')->firstOrFail();

        $this->assertTrue($user->must_change_password);

        $login = $this->postJson('/api/login', [
            'email' => 'ahmad@example.com',
            'password' => $password,
        ]);

        $login->assertStatus(200);
        $this->assertSame('guru', $login->json('user.role.role_name'));
        $this->assertTrue((bool) $login->json('user.must_change_password'));
    }

    public function test_guru_dapat_mengganti_password_sendiri(): void
    {
        $guru = $this->guru();
        $user = $this->loginSebagaiGuru($guru);

        $this->postJson('/api/change-password', [
            'current_password' => $this->passwordGuru,
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertStatus(200);

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('passwordbaru123', $user->password));
    }

    public function test_ganti_password_menolak_password_saat_ini_yang_salah(): void
    {
        $guru = $this->guru();
        $this->loginSebagaiGuru($guru);

        $this->postJson('/api/change-password', [
            'current_password' => 'salahsekali',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertStatus(422);
    }

    public function test_guru_hanya_melihat_kelas_yang_diampu_untuk_input_nilai(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $guru = $this->guru();
        $mapel = $this->mapel('MTK', 'Matematika', 1);

        $this->assignPengampu($year, $kelasA, $mapel, $guru);

        $this->loginSebagaiGuru($guru);

        $data = $this->getJson("/api/grade?academic_year_id={$year->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data['classrooms']);
        $this->assertSame($kelasA->id, $data['classrooms'][0]['id']);
    }

    public function test_guru_hanya_melihat_mapel_yang_diampu(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $this->mapel('BIND', 'Bahasa Indonesia', 1);

        $this->assignPengampu($year, $classroom, $mtk, $guru);

        $this->loginSebagaiGuru($guru);

        $data = $this->getJson(
            "/api/grade?academic_year_id={$year->id}&classroom_id={$classroom->id}"
        )->assertStatus(200)->json('data');

        $this->assertCount(1, $data['subjects']);
        $this->assertSame('MTK', $data['subjects'][0]['code']);
    }

    public function test_guru_tidak_bisa_menyimpan_nilai_mapel_yang_bukan_ampuannya(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $bindo = $this->mapel('BIND', 'Bahasa Indonesia', 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->assignPengampu($year, $classroom, $mtk, $guru);

        $this->loginSebagaiGuru($guru);

        $response = $this->postJson('/api/grade', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'scores' => [
                ['student_id' => $budi->id, 'subject_id' => $bindo->id, 'score' => 80],
            ],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'tidak mengampu',
            implode(' ', $response->json('errors.scores'))
        );
        $this->assertSame(0, Grade::count());
    }

    public function test_guru_bisa_menyimpan_nilai_mapel_yang_diampu(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();
        $mtk = $this->mapel('MTK', 'Matematika', 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->assignPengampu($year, $classroom, $mtk, $guru);

        $this->loginSebagaiGuru($guru);

        $response = $this->postJson('/api/grade', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'semester' => 1,
            'scores' => [['student_id' => $budi->id, 'subject_id' => $mtk->id, 'score' => 88]],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, Grade::count());
        $this->assertSame($this->guruUserId, Grade::firstOrFail()->recorded_by);
    }

    public function test_guru_hanya_bisa_absen_kelas_yang_dia_walikan(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $guru = $this->guru();

        $this->assignWaliKelas($year, $kelasA, $guru);

        $this->loginSebagaiGuru($guru);

        $data = $this->getJson("/api/attendance?academic_year_id={$year->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data['classrooms']);
        $this->assertSame($kelasA->id, $data['classrooms'][0]['id']);

        // Kelas yang bukan walinya ditolak
        $budi = $this->siswa($year, $kelasB, 'Budi Santoso');

        $response = $this->postJson('/api/attendance', [
            'academic_year_id' => $year->id,
            'classroom_id' => $kelasB->id,
            'date' => '2026-08-01',
            'records' => [['student_id' => $budi->id, 'status' => 'hadir']],
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, Attendance::count());
    }

    public function test_guru_hanya_bisa_membuka_rapor_kelas_yang_dia_walikan(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $guru = $this->guru();

        $this->assignWaliKelas($year, $kelasA, $guru);

        $this->loginSebagaiGuru($guru);

        $data = $this->getJson("/api/report-card?academic_year_id={$year->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data['classrooms']);
        $this->assertSame($kelasA->id, $data['classrooms'][0]['id']);
        $this->assertNull($data['classroom']);
        $this->assertNotNull($kelasB->id);
    }

    public function test_guru_bisa_melihat_jadwal_tapi_tidak_bisa_mengubahnya(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->assignPengampu($year, $classroom, $mtk, $guru);

        $this->loginSebagaiGuru($guru);

        $this->getJson("/api/schedule?academic_year_id={$year->id}&classroom_id={$classroom->id}")
            ->assertStatus(200);

        $this->postJson('/api/schedule/create', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $mtk->id,
            'teacher_id' => $guru->id,
            'day' => 'senin',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ])->assertStatus(403);

        $this->assertSame(0, Schedule::count());
    }

    public function test_guru_tidak_bisa_mengakses_area_admin(): void
    {
        $guru = $this->guru();
        $this->loginSebagaiGuru($guru);

        $this->getJson('/api/teacher')->assertStatus(403);
        $this->getJson('/api/subject')->assertStatus(403);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/academic-dashboard')->assertStatus(403);
        $this->getJson('/api/admin/registrations')->assertStatus(403);
        $this->getJson('/api/manage-user')->assertStatus(403);
    }

    public function test_profil_guru_menampilkan_penugasannya(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $guru = $this->guru();
        $mtk = $this->mapel('MTK', 'Matematika', 1);

        $this->assignWaliKelas($year, $classroom, $guru);
        $this->assignPengampu($year, $classroom, $mtk, $guru);

        $this->loginSebagaiGuru($guru);

        $data = $this->getJson('/api/guru/profile')->assertStatus(200)->json('data');

        $this->assertSame('Ustadz Ahmad', $data['teacher']['name']);
        $this->assertCount(1, $data['homerooms']);
        $this->assertSame('1A', $data['homerooms'][0]['classroom']);
        $this->assertCount(1, $data['teachings']);
        $this->assertSame('Matematika', $data['teachings'][0]['subject']);
    }

    public function test_admin_tidak_terpengaruh_pembatasan_guru(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $this->kelas($year, 1, 'A');
        $this->kelas($year, 1, 'B');

        $data = (new GradeController)->index(Request::create('/api/grade', 'GET', [
            'academic_year_id' => $year->id,
        ]))->getData(true)['data'];

        $this->assertCount(2, $data['classrooms']);
    }
}
