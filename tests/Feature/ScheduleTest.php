<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ScheduleController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleTest extends TestCase
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

    private function guru(string $name = 'Ustadz Ahmad'): Teacher
    {
        return Teacher::create([
            'name' => $name,
            'gender' => 'L',
            'position' => 'Guru Kelas',
            'is_active' => true,
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

    private function payload(AcademicYear $year, Classroom $classroom, Subject $subject, Teacher $teacher, array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day' => 'senin',
            'start_time' => '07:00',
            'end_time' => '08:30',
        ], $overrides);
    }

    private function simpan(array $payload)
    {
        return (new ScheduleController)->store(Request::create('/api/schedule/create', 'POST', $payload));
    }

    private function daftar(AcademicYear $year, Classroom $classroom, string $day = 'all'): array
    {
        return (new ScheduleController)->index(Request::create('/api/schedule', 'GET', [
            'academic_year_id' => $year->id,
            'classroom_id' => $classroom->id,
            'day' => $day,
        ]))->getData(true)['data'];
    }

    public function test_admin_dapat_menambah_jadwal(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $response = $this->simpan($this->payload($year, $classroom, $mapel, $guru));

        $this->assertSame(201, $response->getStatusCode());

        $schedule = Schedule::firstOrFail();

        $this->assertSame($year->id, $schedule->academic_year_id);
        $this->assertSame($classroom->id, $schedule->classroom_id);
        $this->assertSame($mapel->id, $schedule->subject_id);
        $this->assertSame($guru->id, $schedule->teacher_id);
        $this->assertSame('senin', $schedule->day);
        $this->assertSame('07:00', $schedule->start_label);
        $this->assertSame('08:30', $schedule->end_label);
    }

    public function test_daftar_jadwal_menampilkan_kolom_yang_dibutuhkan(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));

        $data = $this->daftar($year, $classroom);

        $this->assertCount(1, $data['schedules']);

        $jadwal = $data['schedules'][0];

        $this->assertSame('Senin', $jadwal['day_label']);
        $this->assertSame('07:00', $jadwal['start_time']);
        $this->assertSame('08:30', $jadwal['end_time']);
        $this->assertSame('Matematika', $jadwal['subject']);
        $this->assertSame('Ustadz Ahmad', $jadwal['teacher']);
        $this->assertSame('1A', $jadwal['classroom']);

        // Hari ditampilkan berurutan
        $this->assertSame(
            ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu'],
            array_keys($data['days'])
        );
    }

    public function test_filter_hari_bekerja(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru, ['day' => 'senin']));
        $this->simpan($this->payload($year, $classroom, $mapel, $guru, [
            'day' => 'selasa',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]));

        $this->assertCount(1, $this->daftar($year, $classroom, 'selasa')['schedules']);
        $this->assertCount(2, $this->daftar($year, $classroom, 'all')['schedules']);
    }

    public function test_menolak_jadwal_bentrok_untuk_guru(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $kelasA, $mapel, $guru));

        // Guru yang sama, jam bertumpuk, kelas berbeda
        $response = $this->simpan($this->payload($year, $kelasB, $mapel, $guru, [
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah mengajar', $response->getData(true)['message']);
        $this->assertSame(1, Schedule::count());
    }

    public function test_menolak_jadwal_bentrok_untuk_kelas(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();
        $guruLain = $this->guru('Ustadzah Fatimah');

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));

        // Kelas yang sama, jam bertumpuk, guru berbeda
        $response = $this->simpan($this->payload($year, $classroom, $mapel, $guruLain, [
            'start_time' => '07:30',
            'end_time' => '09:00',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('sudah punya jadwal', $response->getData(true)['message']);
        $this->assertSame(1, Schedule::count());
    }

    public function test_jadwal_yang_tidak_bertumpuk_tetap_boleh(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));

        // Bersambung tepat setelah jam sebelumnya -> tidak dianggap bentrok
        $response = $this->simpan($this->payload($year, $classroom, $mapel, $guru, [
            'start_time' => '08:30',
            'end_time' => '10:00',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, Schedule::count());
    }

    public function test_guru_boleh_mengajar_kelas_lain_di_jam_berbeda(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A');
        $kelasB = $this->kelas($year, 1, 'B');
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $kelasA, $mapel, $guru));
        $response = $this->simpan($this->payload($year, $kelasB, $mapel, $guru, [
            'start_time' => '10:00',
            'end_time' => '11:30',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, Schedule::count());
    }

    public function test_menolak_jam_selesai_yang_tidak_lebih_akhir(): void
    {
        $this->admin();
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->postJson('/api/schedule/create', $this->payload($year, $classroom, $mapel, $guru, [
            'start_time' => '09:00',
            'end_time' => '08:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');

        $this->assertSame(0, Schedule::count());
    }

    public function test_menolak_mapel_yang_tidak_sesuai_tingkat(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapelKelas5 = $this->mapel('MTK5', 'Matematika Kelas 5', 5);
        $guru = $this->guru();

        $response = $this->simpan($this->payload($year, $classroom, $mapelKelas5, $guru));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tidak berlaku untuk tingkat', $response->getData(true)['message']);
    }

    public function test_menolak_mapel_yang_tidak_aktif(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1, false);
        $guru = $this->guru();

        $response = $this->simpan($this->payload($year, $classroom, $mapel, $guru));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tidak aktif', $response->getData(true)['message']);
    }

    public function test_menolak_kelas_dari_tahun_ajaran_lain(): void
    {
        $tahunLain = $this->tahunAjaran('2027/2028', false);
        $tahunIni = $this->tahunAjaran('2026/2027', true);
        $classroom = $this->kelas($tahunLain, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $response = $this->simpan($this->payload($tahunIni, $classroom, $mapel, $guru));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bukan milik tahun ajaran', $response->getData(true)['message']);
    }

    public function test_admin_dapat_mengubah_jadwal(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));
        $schedule = Schedule::firstOrFail();

        $response = (new ScheduleController)->update(
            Request::create("/api/schedule/{$schedule->id}/update", 'PUT', $this->payload(
                $year,
                $classroom,
                $mapel,
                $guru,
                ['day' => 'rabu', 'start_time' => '10:00', 'end_time' => '11:00']
            )),
            $schedule->id
        );

        $this->assertSame(200, $response->getStatusCode());

        $schedule->refresh();

        $this->assertSame('rabu', $schedule->day);
        $this->assertSame('10:00', $schedule->start_label);
    }

    public function test_ubah_jadwal_tidak_bentrok_dengan_dirinya_sendiri(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));
        $schedule = Schedule::firstOrFail();

        $response = (new ScheduleController)->update(
            Request::create("/api/schedule/{$schedule->id}/update", 'PUT', $this->payload(
                $year,
                $classroom,
                $mapel,
                $guru,
                ['end_time' => '09:00']
            )),
            $schedule->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('09:00', $schedule->fresh()->end_label);
    }

    public function test_admin_dapat_menghapus_jadwal(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $this->simpan($this->payload($year, $classroom, $mapel, $guru));
        $schedule = Schedule::firstOrFail();

        $response = (new ScheduleController)->destroy($schedule->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, Schedule::count());
    }

    public function test_endpoint_jadwal_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/schedule')->assertStatus(403);
        $this->postJson('/api/schedule/create', $this->payload($year, $classroom, $mapel, $guru))
            ->assertStatus(403);

        $this->assertSame(0, Schedule::count());
    }

    public function test_slot_kelas_yang_sama_tidak_bisa_duplikat_di_database(): void
    {
        $year = $this->tahunAjaran();
        $classroom = $this->kelas($year, 1);
        $mapel = $this->mapel('MTK', 'Matematika', 1);
        $guru = $this->guru();

        Schedule::create($this->payload($year, $classroom, $mapel, $guru));

        $this->expectException(\Illuminate\Database\QueryException::class);

        Schedule::create($this->payload($year, $classroom, $mapel, $guru));
    }
}
