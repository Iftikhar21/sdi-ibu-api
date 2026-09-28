<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ClassroomController;
use App\Models\AcademicYear;
use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    private function tahunAjaran(string $name = '2026/2027'): AcademicYear
    {
        $existing = AcademicYear::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        (new AcademicYearController)->store(Request::create('/api/academic-year/create', 'POST', [
            'name' => $name,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]));

        return AcademicYear::where('name', $name)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function buatKelas(array $override = []): array
    {
        return (new ClassroomController)->store(Request::create(
            '/api/classroom/create',
            'POST',
            array_merge([
                'academic_year_id' => $this->tahunAjaran()->id,
                'grade_level' => 1,
                'name' => 'A',
                'quota' => 28,
                'is_active' => true,
            ], $override)
        ))->getData(true)['data'];
    }

    public function test_kelas_bisa_disimpan_dengan_nama_tampil(): void
    {
        $data = $this->buatKelas();

        $this->assertSame(1, $data['grade_level']);
        $this->assertSame('A', $data['name']);
        $this->assertSame(28, $data['quota']);
        $this->assertSame('1A', $data['display_name']);
        $this->assertSame('2026/2027', $data['academic_year']['name']);
    }

    public function test_nama_kelas_huruf_kecil_disimpan_sebagai_huruf_besar(): void
    {
        $data = $this->buatKelas(['name' => 'b']);

        $this->assertSame('B', $data['name']);
        $this->assertSame('1B', $data['display_name']);
    }

    public function test_kombinasi_yang_sama_pada_tahun_ajaran_yang_sama_ditolak(): void
    {
        $this->buatKelas();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->buatKelas();
    }

    public function test_kelas_yang_sama_boleh_ada_di_tahun_ajaran_berbeda(): void
    {
        $tahunPertama = $this->tahunAjaran('2025/2026');

        (new ClassroomController)->store(Request::create('/api/classroom/create', 'POST', [
            'academic_year_id' => $tahunPertama->id,
            'grade_level' => 1,
            'name' => 'A',
            'quota' => 28,
        ]));

        $this->buatKelas(['academic_year_id' => $this->tahunAjaran()->id]);

        $this->assertSame(2, Classroom::count());
    }

    public function test_validasi_kuota_tingkat_dan_tahun_ajaran(): void
    {
        $controller = new ClassroomController;
        $tahun = $this->tahunAjaran();

        $kasus = [
            'kuota nol' => ['academic_year_id' => $tahun->id, 'grade_level' => 1, 'name' => 'A', 'quota' => 0],
            'kuota negatif' => ['academic_year_id' => $tahun->id, 'grade_level' => 1, 'name' => 'B', 'quota' => -5],
            'tingkat 7' => ['academic_year_id' => $tahun->id, 'grade_level' => 7, 'name' => 'A', 'quota' => 20],
            'tahun ajaran tidak ada' => ['academic_year_id' => 9999, 'grade_level' => 1, 'name' => 'A', 'quota' => 20],
        ];

        foreach ($kasus as $label => $payload) {
            try {
                $controller->store(Request::create('/api/classroom/create', 'POST', $payload));
                $this->fail("Kasus {$label} seharusnya ditolak.");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), "Kasus {$label} tidak menghasilkan pesan validasi.");
            }
        }

        // Tahun ajaran wajib diisi
        try {
            $controller->store(Request::create('/api/classroom/create', 'POST', [
                'grade_level' => 1,
                'name' => 'A',
                'quota' => 20,
            ]));
            $this->fail('Kelas tanpa tahun ajaran seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('academic_year_id', $exception->errors());
        }

        $this->assertSame(0, Classroom::count());
    }

    public function test_update_kelas_dan_tidak_boleh_duplikat(): void
    {
        $kelasA = $this->buatKelas(['name' => 'A']);
        $kelasB = $this->buatKelas(['name' => 'B', 'quota' => 30]);

        $controller = new ClassroomController;

        // Ubah kuota kelas B
        $updated = $controller->update(Request::create(
            "/api/classroom/{$kelasB['id']}/update",
            'PUT',
            ['quota' => 32]
        ), $kelasB['id'])->getData(true)['data'];

        $this->assertSame(32, $updated['quota']);

        // Ubah nama kelas B menjadi A (bentrok dengan kelas A)
        try {
            $controller->update(Request::create(
                "/api/classroom/{$kelasB['id']}/update",
                'PUT',
                ['name' => 'A']
            ), $kelasB['id']);
            $this->fail('Nama kelas duplikat seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame('B', Classroom::find($kelasB['id'])->name);
        $this->assertSame('A', Classroom::find($kelasA['id'])->name);
    }

    public function test_filter_tahun_ajaran_dan_urutan_tampil(): void
    {
        $tahunLama = $this->tahunAjaran('2025/2026');
        $tahunBaru = $this->tahunAjaran('2026/2027');

        $controller = new ClassroomController;

        foreach ([[2, 'B'], [1, 'B'], [1, 'A']] as [$tingkat, $nama]) {
            $controller->store(Request::create('/api/classroom/create', 'POST', [
                'academic_year_id' => $tahunBaru->id,
                'grade_level' => $tingkat,
                'name' => $nama,
                'quota' => 28,
            ]));
        }

        $controller->store(Request::create('/api/classroom/create', 'POST', [
            'academic_year_id' => $tahunLama->id,
            'grade_level' => 1,
            'name' => 'A',
            'quota' => 25,
        ]));

        // Urut per tingkat lalu nama (1A, 1B, 2B)
        $semuaBaru = $controller->index(Request::create('/api/classroom', 'GET', [
            'academic_year_id' => $tahunBaru->id,
        ]))->getData(true)['data'];

        $this->assertSame(['1A', '1B', '2B'], array_column($semuaBaru, 'display_name'));

        // Filter tahun ajaran memisahkan data antar tahun
        $semuaLama = $controller->index(Request::create('/api/classroom', 'GET', [
            'academic_year_id' => $tahunLama->id,
        ]))->getData(true)['data'];

        $this->assertCount(1, $semuaLama);
        $this->assertSame('2025/2026', $semuaLama[0]['academic_year']['name']);
    }

    public function test_kelas_bisa_dihapus(): void
    {
        $kelas = $this->buatKelas();

        $response = (new ClassroomController)->destroy($kelas['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, Classroom::count());
        $this->assertDatabaseHas('academic_years', ['id' => $kelas['academic_year_id']]);
    }
}
