<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicYearController;
use App\Models\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     */
    private function buatTahunAjaran(array $override = []): array
    {
        return (new AcademicYearController)->store(Request::create(
            '/api/academic-year/create',
            'POST',
            array_merge([
                'name' => '2026/2027',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'is_active' => false,
            ], $override)
        ))->getData(true)['data'];
    }

    public function test_tahun_ajaran_bisa_disimpan(): void
    {
        $data = $this->buatTahunAjaran();

        $this->assertSame('2026/2027', $data['name']);
        $this->assertSame('2026-07-01', $data['start_date']);
        $this->assertSame('2027-06-30', $data['end_date']);
        $this->assertFalse($data['is_active']);
        $this->assertSame(1, AcademicYear::count());
    }

    public function test_hanya_satu_tahun_ajaran_yang_aktif(): void
    {
        $this->buatTahunAjaran(['name' => '2025/2026', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $this->assertSame(1, AcademicYear::where('is_active', true)->count());

        // Mengaktifkan tahun ajaran baru otomatis menonaktifkan yang sebelumnya
        $this->buatTahunAjaran(['is_active' => true]);

        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
        $this->assertTrue(AcademicYear::where('name', '2026/2027')->first()->is_active);
        $this->assertFalse(AcademicYear::where('name', '2025/2026')->first()->is_active);
    }

    public function test_mengaktifkan_lewat_update_menonaktifkan_yang_lain(): void
    {
        $lama = $this->buatTahunAjaran([
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);
        $baru = $this->buatTahunAjaran();

        (new AcademicYearController)->update(Request::create(
            "/api/academic-year/{$baru['id']}/update",
            'PUT',
            ['is_active' => true]
        ), $baru['id']);

        $this->assertTrue(AcademicYear::find($baru['id'])->is_active);
        $this->assertFalse(AcademicYear::find($lama['id'])->is_active);
        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
    }

    public function test_tahun_ajaran_duplikat_ditolak(): void
    {
        $this->buatTahunAjaran();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->buatTahunAjaran();
    }

    public function test_format_tahun_ajaran_dan_tanggal_divalidasi(): void
    {
        $controller = new AcademicYearController;

        // Format nama salah
        try {
            $controller->store(Request::create('/api/academic-year/create', 'POST', [
                'name' => '2026-2027',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
            ]));
            $this->fail('Format tahun ajaran salah seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        // Tanggal selesai lebih awal dari tanggal mulai
        try {
            $controller->store(Request::create('/api/academic-year/create', 'POST', [
                'name' => '2026/2027',
                'start_date' => '2026-07-01',
                'end_date' => '2026-06-30',
            ]));
            $this->fail('Tanggal selesai sebelum tanggal mulai seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
        }

        $this->assertSame(0, AcademicYear::count());
    }

    public function test_tahun_ajaran_yang_masih_dipakai_kelas_tidak_bisa_dihapus(): void
    {
        $tahun = $this->buatTahunAjaran();

        AcademicYear::find($tahun['id'])->classrooms()->create([
            'grade_level' => 1,
            'name' => 'A',
            'quota' => 28,
            'is_active' => true,
        ]);

        $response = (new AcademicYearController)->destroy($tahun['id']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('masih dipakai', $response->getData(true)['message']);
        $this->assertDatabaseHas('academic_years', ['id' => $tahun['id']]);
    }

    public function test_tahun_ajaran_aktif_tidak_bisa_dihapus(): void
    {
        $tahun = $this->buatTahunAjaran(['is_active' => true]);

        $response = (new AcademicYearController)->destroy($tahun['id']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('academic_years', ['id' => $tahun['id']]);
    }

    public function test_tahun_ajaran_tanpa_kelas_bisa_dihapus(): void
    {
        $tahun = $this->buatTahunAjaran();

        $response = (new AcademicYearController)->destroy($tahun['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, AcademicYear::count());
    }
}
