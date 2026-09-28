<?php

namespace Tests\Feature;

use App\Http\Controllers\GalleryCategoryController;
use App\Http\Controllers\ViewController;
use App\Models\GalleryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class GalleryCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_kategori_bawaan_tersedia_setelah_migrasi(): void
    {
        $this->assertSame(8, GalleryCategory::count());

        $data = (new GalleryCategoryController)->index()->getData(true)['data'];

        $this->assertCount(8, $data);
        $this->assertSame('Pembelajaran', $data[0]['name']);
        $this->assertSame('pembelajaran', $data[0]['slug']);
        $this->assertSame(1, $data[0]['sort_order']);
    }

    public function test_admin_bisa_menambah_kategori_baru(): void
    {
        $response = (new GalleryCategoryController)->store(Request::create(
            '/api/gallery-category/create',
            'POST',
            ['name' => 'Kunjungan Industri', 'sort_order' => 9],
        ));

        $this->assertSame(201, $response->getStatusCode());

        $data = $response->getData(true)['data'];

        $this->assertSame('Kunjungan Industri', $data['name']);
        $this->assertSame('kunjungan-industri', $data['slug']);
        $this->assertTrue($data['is_active']);
        $this->assertSame(9, GalleryCategory::count());
    }

    public function test_nama_kategori_duplikat_ditolak(): void
    {
        $response = (new GalleryCategoryController)->store(Request::create(
            '/api/gallery-category/create',
            'POST',
            ['name' => 'Outing'],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(8, GalleryCategory::count());
    }

    public function test_slug_diperbarui_saat_nama_diubah(): void
    {
        $category = GalleryCategory::where('slug', 'outing')->first();

        $response = (new GalleryCategoryController)->update(Request::create(
            "/api/gallery-category/{$category->id}/update",
            'PUT',
            ['name' => 'Outing Class'],
        ), $category->id);

        $this->assertSame(200, $response->getStatusCode());

        $category->refresh();

        $this->assertSame('Outing Class', $category->name);
        $this->assertSame('outing-class', $category->slug);
    }

    public function test_kategori_yang_masih_dipakai_album_tidak_bisa_dihapus(): void
    {
        $category = GalleryCategory::where('slug', 'dokumentasi')->first();

        $category->galleries()->create([
            'title' => 'Album Uji',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = (new GalleryCategoryController)->destroy($category->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('masih dipakai', $response->getData(true)['message']);
        $this->assertDatabaseHas('gallery_categories', ['id' => $category->id]);
    }

    public function test_kategori_tanpa_album_bisa_dihapus(): void
    {
        $category = GalleryCategory::where('slug', 'hut-ri')->first();

        $response = (new GalleryCategoryController)->destroy($category->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('gallery_categories', ['id' => $category->id]);
    }

    public function test_endpoint_publik_hanya_mengirim_kategori_aktif(): void
    {
        GalleryCategory::where('slug', 'olahraga')->update(['is_active' => false]);

        $data = (new ViewController)->getGalleryCategories()->getData(true)['data'];

        $this->assertCount(7, $data);
        $this->assertNotContains('olahraga', array_column($data, 'slug'));
    }

    public function test_urutan_kategori_bisa_disimpan_sekaligus(): void
    {
        $outgoing = GalleryCategory::where('slug', 'outing')->first();
        $sports = GalleryCategory::where('slug', 'olahraga')->first();
        $worship = GalleryCategory::where('slug', 'kegiatan-ibadah')->first();

        // Pindahkan Outing ke urutan pertama
        $response = (new GalleryCategoryController)->reorder(Request::create(
            '/api/gallery-category/reorder',
            'POST',
            ['ids' => [$outgoing->id, $sports->id, $worship->id]],
        ));

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(1, $outgoing->refresh()->sort_order);
        $this->assertSame(2, $sports->refresh()->sort_order);
        $this->assertSame(3, $worship->refresh()->sort_order);

        // Daftar yang dikirim balik sudah mengikuti urutan baru
        $data = $response->getData(true)['data'];

        $this->assertSame('Outing', $data[0]['name']);
        $this->assertSame('Olahraga', $data[1]['name']);
        $this->assertSame('Kegiatan Ibadah', $data[2]['name']);
    }

    public function test_reorder_menolak_id_yang_tidak_ada(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new GalleryCategoryController)->reorder(Request::create(
            '/api/gallery-category/reorder',
            'POST',
            ['ids' => [9999]],
        ));
    }
}
