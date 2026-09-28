<?php

namespace Tests\Feature;

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ViewController;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestasi_dan_agenda_bisa_disimpan_dengan_foto(): void
    {
        Storage::fake('public');

        $controller = new ActivityController;

        $prestasi = $controller->store(Request::create(
            '/api/activity/create',
            'POST',
            [
                'type' => 'prestasi',
                'title' => 'Juara 1 Lomba Tahfidz',
                'description' => 'Meraih juara 1 tingkat kota.',
            ],
            [],
            ['image' => UploadedFile::fake()->image('prestasi.jpg', 1600, 1000)]
        ))->getData(true)['data'];

        $agenda = $controller->store(Request::create(
            '/api/activity/create',
            'POST',
            [
                'type' => 'agenda',
                'title' => 'Peringatan Maulid Nabi',
                'description' => 'Kegiatan bersama wali murid di aula sekolah.',
            ]
        ))->getData(true)['data'];

        $this->assertSame('Prestasi', $prestasi['type_label']);
        $this->assertNotNull($prestasi['image_url']);
        $this->assertSame('Agenda Sekolah', $agenda['type_label']);
        $this->assertNull($agenda['image_url']);

        $this->assertSame(2, Activity::count());
        $this->assertSame(1, Activity::where('type', 'prestasi')->count());
    }

    public function test_filter_jenis_dan_endpoint_publik(): void
    {
        $controller = new ActivityController;

        foreach (['prestasi', 'agenda'] as $type) {
            $controller->store(Request::create('/api/activity/create', 'POST', [
                'type' => $type,
                'title' => 'Judul '.$type,
                'description' => 'Deskripsi '.$type,
            ]));
        }

        // Filter di sisi admin
        $adminPrestasi = $controller->index(
            Request::create('/api/activity', 'GET', ['type' => 'prestasi'])
        )->getData(true)['data'];

        $this->assertCount(1, $adminPrestasi);
        $this->assertSame('prestasi', $adminPrestasi[0]['type']);

        // Endpoint publik hanya mengirim yang aktif
        Activity::where('type', 'agenda')->update(['is_active' => false]);

        $publicAll = (new ViewController)->getActivities(Request::create('/api/kegiatan-list', 'GET'))
            ->getData(true)['data'];
        $this->assertCount(1, $publicAll);

        $publicAgenda = (new ViewController)->getActivities(
            Request::create('/api/kegiatan-list', 'GET', ['type' => 'agenda'])
        )->getData(true)['data'];
        $this->assertCount(0, $publicAgenda);
    }

    public function test_beranda_mengirim_prestasi_dan_agenda(): void
    {
        $controller = new ActivityController;

        foreach (['prestasi', 'agenda'] as $type) {
            $controller->store(Request::create('/api/activity/create', 'POST', [
                'type' => $type,
                'title' => 'Judul '.$type,
                'description' => 'Deskripsi '.$type,
            ]));
        }

        $home = (new ViewController)->getHomeData()->getData(true)['data'];

        $this->assertCount(2, $home['activities']);
        $this->assertSame(
            ['prestasi', 'agenda'],
            array_column($home['activities'], 'type')
        );
    }

    public function test_update_dan_hapus_menghapus_foto(): void
    {
        Storage::fake('public');

        $controller = new ActivityController;

        $created = $controller->store(Request::create(
            '/api/activity/create',
            'POST',
            ['type' => 'prestasi', 'title' => 'Judul Awal', 'description' => 'Deskripsi'],
            [],
            ['image' => UploadedFile::fake()->image('foto.jpg', 1400, 1000)]
        ))->getData(true)['data'];

        $activity = Activity::first();
        $oldImage = $activity->image;
        $oldThumb = $activity->thumb;

        $updated = $controller->update(Request::create(
            "/api/activity/{$created['id']}/update",
            'PUT',
            ['title' => 'Judul Baru', 'is_active' => false]
        ), $created['id'])->getData(true)['data'];

        $this->assertSame('Judul Baru', $updated['title']);
        $this->assertFalse($updated['is_active']);

        $controller->destroy($created['id']);

        $this->assertSame(0, Activity::count());
        Storage::disk('public')->assertMissing($oldImage);

        if ($oldThumb) {
            Storage::disk('public')->assertMissing($oldThumb);
        }
    }

    public function test_validasi_menolak_jenis_tidak_dikenal(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new ActivityController)->store(Request::create('/api/activity/create', 'POST', [
            'type' => 'ngawur',
            'title' => 'Judul',
            'description' => 'Deskripsi',
        ]));
    }
}
