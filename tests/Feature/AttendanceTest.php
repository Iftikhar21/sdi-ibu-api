<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AttendanceController;
use App\Models\AcademicYear;
use App\Models\Attendance;
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

class AttendanceTest extends TestCase
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

    private function absensi(?AcademicYear $year, ?Classroom $classroom = null, string $date = '2026-08-01')
    {
        $params = ['date' => $date];

        if ($year) {
            $params['academic_year_id'] = $year->id;
        }

        if ($classroom) {
            $params['classroom_id'] = $classroom->id;
        }

        return (new AttendanceController)->index(Request::create('/api/attendance', 'GET', $params));
    }

    private function simpan(AcademicYear $year, Classroom $classroom, string $date, array $records)
    {
        return (new AttendanceController)->store(Request::create('/api/attendance', 'POST', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'date' => $date,
            'records' => $records,
        ]));
    }

    public function test_daftar_absensi_menampilkan_siswa_dari_penempatan(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');

        $this->siswa($year, $kelasA, 'Budi Santoso');
        $this->siswa($year, $kelasA, 'Andi Wijaya');
        $this->siswa($year, $kelasB, 'Siswa Kelas Lain');

        $data = $this->absensi($year, $kelasA)->getData(true)['data'];

        $this->assertCount(2, $data['students']);
        $this->assertSame('Andi Wijaya', $data['students'][0]['full_name']);
        $this->assertSame('1A', $data['classroom']['display_name']);
        $this->assertSame('2026-08-01', $data['date']);
        $this->assertSame(
            ['hadir', 'izin', 'sakit', 'alpa'],
            array_keys($data['statuses'])
        );
    }

    public function test_admin_dapat_menyimpan_absensi(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $andi = $this->siswa($year, $classroom, 'Andi Wijaya');

        $response = $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'sakit', 'notes' => 'Demam'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['data']['saved']);
        $this->assertSame(1, $response->getData(true)['data']['summary']['hadir']);
        $this->assertSame(1, $response->getData(true)['data']['summary']['sakit']);

        $andiAttendance = Attendance::where('student_id', $andi->id)->firstOrFail();

        $this->assertSame('sakit', $andiAttendance->status);
        $this->assertSame('Demam', $andiAttendance->notes);
        $this->assertSame($year->id, $andiAttendance->academic_year_id);
        $this->assertSame($classroom->id, $andiAttendance->classroom_id);
        $this->assertSame('2026-08-01', $andiAttendance->date->toDateString());
    }

    public function test_absen_tidak_duplikat_untuk_siswa_dan_tanggal_yang_sama(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'alpa'],
        ]);
        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(1, Attendance::count());
        $this->assertSame('hadir', Attendance::firstOrFail()->status);
    }

    public function test_absen_tidak_boleh_duplikat_di_database(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        Attendance::create([
            'student_id' => $budi->id,
            'classroom_id' => $classroom->id,
            'academic_year_id' => $year->id,
            'date' => '2026-08-01',
            'status' => 'hadir',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Attendance::create([
            'student_id' => $budi->id,
            'classroom_id' => $classroom->id,
            'academic_year_id' => $year->id,
            'date' => '2026-08-01',
            'status' => 'izin',
        ]);
    }

    public function test_absen_tanggal_lain_tetap_bisa_disimpan(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);
        $this->simpan($year, $classroom, '2026-08-02', [
            ['student_id' => $budi->id, 'status' => 'izin'],
        ]);

        $this->assertSame(2, Attendance::count());
    }

    public function test_menolak_siswa_yang_bukan_anggota_kelas(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $this->siswa($year, $kelasA, 'Budi Santoso');
        $orangLain = $this->siswa($year, $kelasB, 'Andi Wijaya');

        $response = $this->simpan($year, $kelasA, '2026-08-01', [
            ['student_id' => $orangLain->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'tidak terdaftar di kelas',
            implode(' ', $response->getData(true)['errors']['records'])
        );
        $this->assertSame(0, Attendance::count());
    }

    public function test_menolak_kelas_dari_tahun_ajaran_lain(): void
    {
        $this->admin();
        $tahunLain = $this->tahunAjaran('2027/2028', false);
        $tahunIni = $this->tahunAjaran('2026/2027', true);
        $classroom = $this->kelas($tahunLain, 1);
        $budi = $this->siswa($tahunLain, $classroom, 'Budi Santoso');

        $response = $this->simpan($tahunIni, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bukan milik tahun ajaran', $response->getData(true)['message']);
    }

    public function test_tanggal_harus_di_dalam_rentang_tahun_ajaran(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $response = $this->simpan($year, $classroom, '2025-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Attendance::count());
    }

    public function test_status_kehadiran_harus_sesuai_daftar(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->postJson('/api/attendance', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-08-01',
            'records' => [['student_id' => $budi->id, 'status' => 'bolos']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('records.0.status');
    }

    public function test_satu_baris_gagal_maka_semua_absensi_dibatalkan(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $budi = $this->siswa($year, $kelasA, 'Budi Santoso');
        $orangLain = $this->siswa($year, $kelasB, 'Andi Wijaya');

        $response = $this->simpan($year, $kelasA, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $orangLain->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Attendance::count());
    }

    public function test_absen_masih_bisa_diisi_pada_tahun_kelulusannya(): void
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

        $response = $this->simpan($tahunLulus, $kelas6, '2031-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, Attendance::count());
    }

    public function test_absensi_yang_sudah_ada_tampil_saat_dibuka_lagi(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'izin', 'notes' => 'Acara keluarga'],
        ]);

        $data = $this->absensi($year, $classroom, '2026-08-01')->getData(true)['data'];

        $this->assertSame('izin', $data['students'][0]['status']);
        $this->assertSame('Acara keluarga', $data['students'][0]['notes']);
        $this->assertSame(1, $data['summary']['izin']);
    }

    public function test_rekap_kehadiran_menghitung_total_per_status(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');
        $andi = $this->siswa($year, $classroom, 'Andi Wijaya');

        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'izin'],
        ]);
        $this->simpan($year, $classroom, '2026-08-02', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
            ['student_id' => $andi->id, 'status' => 'sakit'],
        ]);
        $this->simpan($year, $classroom, '2026-08-03', [
            ['student_id' => $budi->id, 'status' => 'alpa'],
            ['student_id' => $andi->id, 'status' => 'hadir'],
        ]);

        $data = (new AttendanceController)->recap(Request::create('/api/attendance/recap', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
        ]))->getData(true)['data'];

        $this->assertSame(3, $data['summary']['hadir']);
        $this->assertSame(1, $data['summary']['izin']);
        $this->assertSame(1, $data['summary']['sakit']);
        $this->assertSame(1, $data['summary']['alpa']);

        $budiRekap = collect($data['students'])->firstWhere('student_id', $budi->id);

        $this->assertSame(2, $budiRekap['hadir']);
        $this->assertSame(0, $budiRekap['izin']);
        $this->assertSame(1, $budiRekap['alpa']);
        $this->assertSame(3, $budiRekap['total']);
    }

    public function test_rekap_bisa_dibatasi_periode(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $this->simpan($year, $classroom, '2026-08-01', [
            ['student_id' => $budi->id, 'status' => 'hadir'],
        ]);
        $this->simpan($year, $classroom, '2026-09-01', [
            ['student_id' => $budi->id, 'status' => 'alpa'],
        ]);

        $data = (new AttendanceController)->recap(Request::create('/api/attendance/recap', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))->getData(true)['data'];

        $this->assertSame(1, $data['summary']['hadir']);
        $this->assertSame(0, $data['summary']['alpa']);
    }

    public function test_endpoint_absensi_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $budi = $this->siswa($year, $classroom, 'Budi Santoso');

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/attendance')->assertStatus(403);
        $this->postJson('/api/attendance', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'date' => '2026-08-01',
            'records' => [['student_id' => $budi->id, 'status' => 'hadir']],
        ])->assertStatus(403);

        $this->assertSame(0, Attendance::count());
    }
}
