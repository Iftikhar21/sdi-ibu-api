<?php

namespace Tests\Feature;

use App\Http\Controllers\AboutSchoolController;
use App\Http\Controllers\EducationValueController;
use App\Http\Controllers\LegalityController;
use App\Http\Controllers\PrincipalController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ViewController;
use App\Models\AboutSchool;
use App\Models\EducationValueItem;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_tentang_sekolah_bisa_disimpan_diperbarui_dan_dihapus(): void
    {
        Storage::fake('public');

        $controller = new AboutSchoolController;

        $created = $controller->store(Request::create(
            '/api/about-school/create',
            'POST',
            ['title' => 'Tentang Sekolah IBU', 'description' => 'Sekolah dasar Islam.'],
            [],
            ['image' => UploadedFile::fake()->image('sekolah.jpg', 1600, 1000)]
        ))->getData(true)['data'];

        $this->assertNotNull($created['image_url']);
        $this->assertSame(1, AboutSchool::count());

        // Foto besar dibuatkan versi kecil
        $about = AboutSchool::first();
        $this->assertNotNull($about->thumb);
        Storage::disk('public')->assertExists($about->thumb);

        $path = $about->image;
        $thumb = $about->thumb;

        // Update tanpa mengirim foto baru tidak menghapus foto lama
        $updated = $controller->update(Request::create(
            "/api/about-school/{$about->id}/update",
            'PUT',
            ['title' => 'Tentang SDI IBU']
        ), $about->id)->getData(true)['data'];

        $this->assertSame('Tentang SDI IBU', $updated['title']);
        Storage::disk('public')->assertExists($path);

        // Hapus data sekaligus filenya
        $controller->destroy($about->id);

        $this->assertSame(0, AboutSchool::count());
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing($thumb);
    }

    public function test_nilai_pendidikan_menyimpan_rincian_item(): void
    {
        $controller = new EducationValueController;

        $created = $controller->store(Request::create(
            '/api/education-value/create',
            'POST',
            [
                'title' => 'Nilai Pendidikan',
                'description' => 'Nilai yang ditanamkan di Sekolah IBU.',
                'items' => [
                    ['title' => 'Iman', 'description' => 'Menanamkan aqidah yang lurus.'],
                    ['title' => 'Adab', 'description' => 'Membiasakan akhlak mulia.'],
                    ['title' => '', 'description' => 'Baris kosong diabaikan'],
                ],
            ]
        ))->getData(true)['data'];

        $this->assertSame('Nilai Pendidikan', $created['title']);
        $this->assertCount(2, $created['items']);
        $this->assertSame('Iman', $created['items'][0]['title']);
        $this->assertSame('Adab', $created['items'][1]['title']);
        $this->assertSame(2, EducationValueItem::count());

        // Update mengganti seluruh daftar rincian
        $updated = $controller->update(Request::create(
            "/api/education-value/{$created['id']}/update",
            'PUT',
            ['items' => [['title' => 'Ilmu'], ['title' => 'Amal']]]
        ), $created['id'])->getData(true)['data'];

        $this->assertCount(2, $updated['items']);
        $this->assertSame('Ilmu', $updated['items'][0]['title']);
        $this->assertSame(2, EducationValueItem::count());

        // Hapus nilai menghapus rinciannya juga
        $controller->destroy($created['id']);
        $this->assertSame(0, EducationValueItem::count());
    }

    public function test_kepala_sekolah_menyimpan_riwayat_pendidikan(): void
    {
        Storage::fake('public');

        $created = (new PrincipalController)->store(Request::create(
            '/api/principal/create',
            'POST',
            [
                'name' => 'Ustadz Ahmad',
                'employee_number' => '1234567890',
                'greeting' => 'Assalamualaikum warahmatullah.',
                'education_history' => ['S1 PGSD - UNJ', '', 'S2 Manajemen Pendidikan - UIN'],
                'started_at' => '2015',
            ],
            [],
            ['photo' => UploadedFile::fake()->image('kepsek.jpg', 1000, 1200)]
        ))->getData(true)['data'];

        $this->assertSame('Ustadz Ahmad', $created['name']);
        $this->assertSame('Kepala Sekolah', $created['position']);
        $this->assertCount(2, $created['education_history']);
        $this->assertSame(['S1 PGSD - UNJ', 'S2 Manajemen Pendidikan - UIN'], $created['education_history']);
    }

    public function test_guru_menyimpan_data_lengkap(): void
    {
        Storage::fake('public');

        $created = (new TeacherController)->store(Request::create(
            '/api/teacher/create',
            'POST',
            [
                'name' => 'Ustadzah Fatimah',
                'gender' => 'P',
                'last_education' => 'S1 Pendidikan Islam',
                'position' => 'Guru Kelas 1',
                'phone' => '081234567890',
                'address' => 'Jl. Dalang No. 1, Jakarta Timur',
            ],
            [],
            ['photo' => UploadedFile::fake()->image('guru.jpg', 800, 1000)]
        ))->getData(true)['data'];

        $this->assertSame('Ustadzah Fatimah', $created['name']);
        $this->assertSame('P', $created['gender']);
        $this->assertSame('Guru Kelas 1', $created['position']);
        $this->assertNotNull($created['photo_url']);
        $this->assertSame(1, Teacher::count());
    }

    public function test_legalitas_tersimpan_dengan_deskripsi(): void
    {
        Storage::fake('public');

        $created = (new LegalityController)->store(Request::create(
            '/api/legality/create',
            'POST',
            ['title' => 'NPSN', 'description' => 'NPSN: 20104041'],
            [],
            ['image' => UploadedFile::fake()->image('npsn.jpg', 1400, 1000)]
        ))->getData(true)['data'];

        $this->assertSame('NPSN', $created['title']);
        $this->assertNotNull($created['image_url']);
    }

    public function test_endpoint_publik_profil_hanya_mengirim_data_aktif(): void
    {
        Storage::fake('public');

        (new AboutSchoolController)->store(Request::create(
            '/api/about-school/create',
            'POST',
            ['title' => 'Tampil', 'description' => 'Aktif', 'is_active' => true]
        ));
        (new AboutSchoolController)->store(Request::create(
            '/api/about-school/create',
            'POST',
            ['title' => 'Disembunyikan', 'description' => 'Nonaktif', 'is_active' => false]
        ));

        (new TeacherController)->store(Request::create(
            '/api/teacher/create',
            'POST',
            ['name' => 'Guru Aktif', 'gender' => 'L', 'is_active' => true]
        ));
        (new TeacherController)->store(Request::create(
            '/api/teacher/create',
            'POST',
            ['name' => 'Guru Nonaktif', 'gender' => 'L', 'is_active' => false]
        ));

        (new LegalityController)->store(Request::create(
            '/api/legality/create',
            'POST',
            ['description' => 'Izin operasional', 'is_active' => true]
        ));
        (new LegalityController)->store(Request::create(
            '/api/legality/create',
            'POST',
            ['description' => 'Arsip lama', 'is_active' => false]
        ));

        (new EducationValueController)->store(Request::create(
            '/api/education-value/create',
            'POST',
            ['title' => 'Nilai Tampil', 'is_active' => true, 'items' => [['title' => 'Iman']]]
        ));
        (new EducationValueController)->store(Request::create(
            '/api/education-value/create',
            'POST',
            ['title' => 'Nilai Nonaktif', 'is_active' => false]
        ));

        (new PrincipalController)->store(Request::create(
            '/api/principal/create',
            'POST',
            ['name' => 'Kepsek Aktif', 'is_active' => true]
        ));
        (new PrincipalController)->store(Request::create(
            '/api/principal/create',
            'POST',
            ['name' => 'Kepsek Lama', 'is_active' => false]
        ));

        $view = new ViewController;

        $this->assertCount(1, $view->getAboutSchool()->getData(true)['data']);
        $this->assertCount(1, $view->getTeachers()->getData(true)['data']);
        $this->assertCount(1, $view->getLegalities()->getData(true)['data']);
        // Kepala sekolah: is_active menandai yang sedang menjabat,
        // jadi arsip periode sebelumnya tetap dikirim ke website.
        $this->assertCount(2, $view->getPrincipals()->getData(true)['data']);

        $values = $view->getEducationValues()->getData(true)['data'];
        $this->assertCount(1, $values);
        $this->assertCount(1, $values[0]['items']);
    }

    public function test_validasi_menolak_data_wajib_yang_kosong(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new TeacherController)->store(Request::create(
            '/api/teacher/create',
            'POST',
            ['name' => '', 'gender' => '']
        ));
    }

    public function test_telepon_dan_nip_menolak_input_huruf(): void
    {
        Storage::fake('public');

        $teacherFailed = false;
        $principalFailed = false;

        // Nomor telepon berisi huruf
        try {
            (new TeacherController)->store(Request::create(
                '/api/teacher/create',
                'POST',
                ['name' => 'Guru Uji', 'gender' => 'L', 'phone' => '0812ABC']
            ));
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $teacherFailed = true;
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        // NIP dan tahun berisi huruf
        try {
            (new PrincipalController)->store(Request::create(
                '/api/principal/create',
                'POST',
                [
                    'name' => 'Kepsek Uji',
                    'employee_number' => 'NIP123',
                    'started_at' => 'dua ribu',
                ]
            ));
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $principalFailed = true;
            $this->assertArrayHasKey('employee_number', $exception->errors());
            $this->assertArrayHasKey('started_at', $exception->errors());
        }

        $this->assertTrue($teacherFailed, 'Telepon berisi huruf seharusnya ditolak.');
        $this->assertTrue($principalFailed, 'NIP/tahun berisi huruf seharusnya ditolak.');
        $this->assertSame(0, Teacher::count());
    }

    public function test_telepon_dengan_simbol_yang_wajar_tetap_diterima(): void
    {
        Storage::fake('public');

        $created = (new TeacherController)->store(Request::create(
            '/api/teacher/create',
            'POST',
            ['name' => 'Guru Telp', 'gender' => 'P', 'phone' => '+62 812-3456 (78)']
        ))->getData(true)['data'];

        $this->assertSame('+62 812-3456 (78)', $created['phone']);
    }
}
