<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\DashboardAdminController;
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

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
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

    private function kelas(AcademicYear $year, int $grade, string $name, int $quota): Classroom
    {
        return Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => $grade,
            'name' => $name,
            'quota' => $quota,
            'is_active' => true,
        ]);
    }

    private function pendaftar(
        AcademicYear $year,
        string $status,
        string $name = 'Calon Siswa'
    ): StudentRegistration {
        $user = User::factory()->create(['role_id' => 2]);

        return StudentRegistration::create([
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
            'status' => $status,
        ]);
    }

    private function siswa(
        AcademicYear $year,
        Classroom $classroom,
        string $name,
        string $registrationStatus = 'approved'
    ): Student {
        $registration = $this->pendaftar($year, $registrationStatus, $name);
        $student = Student::ensureForRegistration($registration);
        $student->placeIntoClassroom($classroom);

        return $student->fresh();
    }

    private function ringkasan(?AcademicYear $year = null): array
    {
        $request = Request::create('/api/admin/dashboard/school-summary', 'GET', $year ? [
            'academic_year_id' => $year->id,
        ] : []);

        return (new DashboardAdminController)->schoolSummary($request)->getData(true)['data'];
    }

    public function test_default_menggunakan_tahun_ajaran_aktif(): void
    {
        $lama = $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $this->kelas($lama, 1, 'A', 20);
        $this->kelas($aktif, 1, 'A', 30);

        $data = $this->ringkasan();

        $this->assertSame($aktif->id, $data['academic_year']['id']);
        $this->assertSame('2026/2027', $data['academic_year']['name']);
        $this->assertSame(30, $data['classes']['capacity']);
    }

    public function test_rekap_pendaftaran_mengikuti_status_existing(): void
    {
        $tahunLain = $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $this->pendaftar($aktif, 'submitted', 'Satu');
        $this->pendaftar($aktif, 'submitted', 'Dua');
        $this->pendaftar($aktif, 'review', 'Tiga');
        $this->pendaftar($aktif, 'approved', 'Empat');
        $this->pendaftar($aktif, 'rejected', 'Lima');
        $this->pendaftar($tahunLain, 'submitted', 'Tahun Lain');

        $registrations = $this->ringkasan($aktif)['registrations'];

        $this->assertSame(5, $registrations['total']);
        $this->assertSame(2, $registrations['submitted']);
        $this->assertSame(1, $registrations['review']);
        $this->assertSame(1, $registrations['approved']);
        $this->assertSame(1, $registrations['rejected']);
    }

    public function test_terisi_dihitung_dari_penempatan_bukan_pendaftaran(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 30);

        // 5 pendaftar Diterima, tetapi baru 2 yang benar-benar ditempatkan
        $this->siswa($year, $kelasA, 'Siswa Satu');
        $this->siswa($year, $kelasA, 'Siswa Dua');
        $this->pendaftar($year, 'approved', 'Belum Ditempatkan 1');
        $this->pendaftar($year, 'approved', 'Belum Ditempatkan 2');
        $this->pendaftar($year, 'submitted', 'Masih Dikirim');

        $data = $this->ringkasan($year);

        $this->assertSame(1, $data['classes']['total']);
        $this->assertSame(30, $data['classes']['capacity']);
        $this->assertSame(2, $data['classes']['filled']);
        $this->assertSame(28, $data['classes']['available']);
        $this->assertSame(2, $data['classes']['rows'][0]['filled']);
    }

    public function test_siswa_belum_ditempatkan_dihitung_per_tahun_ajaran(): void
    {
        $tahunLain = $this->tahunAjaran('2025/2026', false);
        $aktif = $this->tahunAjaran('2026/2027', true);

        $kelasA = $this->kelas($aktif, 1, 'A', 30);
        $kelasLama = $this->kelas($tahunLain, 1, 'A', 30);

        $this->siswa($aktif, $kelasA, 'Sudah Ditempatkan');
        $this->siswa($aktif, $kelasA, 'Belum Ditempatkan');
        $this->siswa($tahunLain, $kelasLama, 'Siswa Tahun Lain');

        // Siswa kedua dikeluarkan dari kelasnya
        $belum = Student::where('full_name', 'Belum Ditempatkan')->firstOrFail();
        $belum->classHistories()->update(['is_active' => false, 'unassigned_at' => now()]);

        $this->assertSame(1, $this->ringkasan($aktif)['unplaced_students']);
        // Siswa tahun ajaran lain sudah punya kelas pada tahunnya sendiri
        $this->assertSame(0, $this->ringkasan($tahunLain)['unplaced_students']);
    }

    public function test_rekap_kelas_menampilkan_kuota_terisi_dan_tersedia(): void
    {
        $year = $this->tahunAjaran();
        $kelasA = $this->kelas($year, 1, 'A', 30);
        $kelasB = $this->kelas($year, 1, 'B', 28);

        $this->siswa($year, $kelasA, 'Siswa 1A');
        $this->siswa($year, $kelasB, 'Siswa 1B Satu');
        $this->siswa($year, $kelasB, 'Siswa 1B Dua');

        $data = $this->ringkasan($year);

        $this->assertCount(2, $data['classes']['rows']);
        $this->assertSame('1A', $data['classes']['rows'][0]['display_name']);
        $this->assertSame(1, $data['classes']['rows'][0]['filled']);
        $this->assertSame(29, $data['classes']['rows'][0]['available']);
        $this->assertSame(2, $data['classes']['rows'][1]['filled']);
        $this->assertSame(26, $data['classes']['rows'][1]['available']);

        $this->assertSame(58, $data['classes']['capacity']);
        $this->assertSame(3, $data['classes']['filled']);
        $this->assertSame(55, $data['classes']['available']);
    }

    public function test_kelas_tidak_aktif_tidak_dihitung_ke_total(): void
    {
        $year = $this->tahunAjaran();
        $aktif = $this->kelas($year, 1, 'A', 30);
        $nonaktif = $this->kelas($year, 1, 'B', 40);
        $nonaktif->update(['is_active' => false]);

        $this->siswa($year, $aktif, 'Siswa Kelas Aktif');

        $data = $this->ringkasan($year);

        $this->assertSame(1, $data['classes']['total']);
        $this->assertSame(30, $data['classes']['capacity']);
        $this->assertCount(2, $data['classes']['rows']);
        $this->assertSame(1, $data['classes']['rows'][0]['filled']);
        // Kelas tidak aktif tetap tampil di tabel, tetapi tidak ikut total kapasitas
        $this->assertFalse($data['classes']['rows'][1]['is_active']);
        $this->assertSame(40, $data['classes']['rows'][1]['quota']);
    }

    public function test_status_siswa_dan_lulusan_tampil(): void
    {
        $tahunLulus = $this->tahunAjaran('2031/2032', true);
        $tahunSebelumnya = $this->tahunAjaran('2030/2031', false);

        $kelas6 = $this->kelas($tahunLulus, 6, 'A', 30);
        $kelas6Lama = $this->kelas($tahunSebelumnya, 6, 'A', 30);
        $kelas1 = $this->kelas($tahunLulus, 1, 'A', 30);

        $lulusBaru = $this->siswa($tahunLulus, $kelas6, 'Lulus Baru');
        $lulusLama = $this->siswa($tahunSebelumnya, $kelas6Lama, 'Lulus Lama');
        $aktif = $this->siswa($tahunLulus, $kelas1, 'Masih Aktif');
        $nonaktif = $this->siswa($tahunLulus, $kelas1, 'Nonaktif');

        Graduation::create(['student_id' => $lulusBaru->id, 'graduation_year_id' => $tahunLulus->id]);
        $lulusBaru->update(['status' => 'graduated']);

        Graduation::create(['student_id' => $lulusLama->id, 'graduation_year_id' => $tahunSebelumnya->id]);
        $lulusLama->update(['status' => 'graduated']);

        $nonaktif->update(['status' => 'inactive']);

        $data = $this->ringkasan($tahunLulus);

        $this->assertSame(4, $data['students']['total']);
        $this->assertSame(1, $data['students']['active']);
        $this->assertSame(1, $data['students']['inactive']);
        $this->assertSame(2, $data['students']['graduated']);

        $this->assertSame(2, $data['graduates']['total']);
        $this->assertSame(1, $data['graduates']['this_year']);
        $this->assertCount(2, $data['graduates']['by_year']);
        $this->assertSame('2031/2032', $data['graduates']['by_year'][0]['name']);
        $this->assertSame(1, $data['graduates']['by_year'][0]['total']);
        $this->assertNotNull($aktif->id);
    }

    public function test_pendaftaran_tanpa_tahun_ajaran_dilaporkan_terpisah(): void
    {
        $year = $this->tahunAjaran();

        $tanpaTahun = $this->pendaftar($year, 'approved', 'Pendaftar Lama');
        $tanpaTahun->update(['academic_year_id' => null]);

        $this->pendaftar($year, 'submitted', 'Pendaftar Baru');

        $registrations = $this->ringkasan($year)['registrations'];

        $this->assertSame(1, $registrations['total']);
        $this->assertSame(1, $registrations['without_academic_year']);
    }

    public function test_dashboard_tidak_mengubah_data(): void
    {
        $year = $this->tahunAjaran();
        $kelas = $this->kelas($year, 1, 'A', 30);
        $this->siswa($year, $kelas, 'Siswa Satu');

        $sebelum = [
            'registrations' => StudentRegistration::count(),
            'students' => Student::count(),
            'classes' => Classroom::count(),
            'placements' => \App\Models\ClassroomPlacement::count(),
            'graduates' => Graduation::count(),
        ];

        $this->ringkasan($year);

        $this->assertSame($sebelum, [
            'registrations' => StudentRegistration::count(),
            'students' => Student::count(),
            'classes' => Classroom::count(),
            'placements' => \App\Models\ClassroomPlacement::count(),
            'graduates' => Graduation::count(),
        ]);
    }

    public function test_endpoint_ringkasan_hanya_untuk_admin(): void
    {
        $year = $this->tahunAjaran();

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard/school-summary')->assertStatus(403);

        $admin = User::factory()->create(['role_id' => 1]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard/school-summary')->assertStatus(200);

        $this->getJson('/api/admin/dashboard/school-summary?academic_year_id='.$year->id)
            ->assertStatus(200)
            ->assertJsonPath('data.academic_year.id', $year->id);
    }
}
