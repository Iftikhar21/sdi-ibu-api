<?php

namespace Tests\Feature;

use App\Http\Controllers\GalleryController;
use App\Http\Controllers\ViewController;
use App\Models\Gallery;
use App\Models\GalleryCategory;
use App\Models\GalleryPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ambil id kategori bawaan berdasarkan slug.
     */
    private function kategoriId(string $slug = 'dokumentasi'): int
    {
        return (int) GalleryCategory::where('slug', $slug)->value('id');
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, UploadedFile>
     */
    private function foto(array $names): array
    {
        return array_map(
            fn ($name) => UploadedFile::fake()->image($name, 800, 600),
            $names
        );
    }

    /**
     * @param  array<string, mixed>  $override
     * @param  array<int, string>  $photoNames
     * @return array<string, mixed>
     */
    private function buatAlbum(array $override = [], array $photoNames = ['a.jpg']): array
    {
        $payload = array_merge([
            'gallery_category_id' => $this->kategoriId(),
            'title' => 'Album Contoh',
            'description' => 'Keterangan album.',
            'sort_order' => 0,
            'is_active' => true,
        ], $override);

        $response = (new GalleryController)->store(Request::create(
            '/api/gallery/create',
            'POST',
            $payload,
            [],
            ['photos' => $this->foto($photoNames)]
        ));

        return $response->getData(true)['data'] ?? [];
    }

    public function test_bisa_menambah_album_dengan_beberapa_foto(): void
    {
        Storage::fake('public');

        $data = $this->buatAlbum(
            [
                'gallery_category_id' => $this->kategoriId('outing'),
                'title' => 'Outing Class Kelas 4',
                'sort_order' => 3,
            ],
            ['a.jpg', 'b.jpg', 'c.jpg']
        );

        $this->assertSame('outing', $data['category']['slug']);
        $this->assertSame('Outing', $data['category']['name']);
        $this->assertSame(3, $data['photos_count']);
        $this->assertCount(3, $data['photos']);
        $this->assertSame(1, Gallery::count());
        $this->assertSame(3, GalleryPhoto::count());

        // Foto pertama menjadi sampul album
        $this->assertSame($data['photos'][0]['thumb_url'], $data['cover_url']);

        foreach (GalleryPhoto::all() as $photo) {
            Storage::disk('public')->assertExists($photo->image);
        }
    }

    public function test_index_dan_endpoint_publik_hanya_menampilkan_album_aktif(): void
    {
        Storage::fake('public');

        $this->buatAlbum(['title' => 'Album Tampil', 'is_active' => true]);
        $this->buatAlbum(['title' => 'Album Disembunyikan', 'is_active' => false]);

        // Admin melihat semuanya
        $adminData = (new GalleryController)->index()->getData(true)['data'];
        $this->assertCount(2, $adminData);

        // Pengunjung beranda hanya melihat yang aktif
        $publicData = (new ViewController)
            ->getGallery(Request::create('/api/gallery-list', 'GET'))
            ->getData(true)['data'];

        $this->assertCount(1, $publicData);
        $this->assertSame('Album Tampil', $publicData[0]['title']);
    }

    public function test_update_bisa_menambah_dan_menghapus_foto(): void
    {
        Storage::fake('public');

        $created = $this->buatAlbum(['title' => 'Pekan Olahraga'], ['a.jpg', 'b.jpg']);

        $galleryId = $created['id'];
        $removedPhotoId = $created['photos'][0]['id'];
        $removedPhotoPath = GalleryPhoto::find($removedPhotoId)->image;

        $response = (new GalleryController)->update(Request::create(
            "/api/gallery/{$galleryId}/update",
            'PUT',
            [
                'title' => 'Pekan Olahraga 2026',
                'deleted_photo_ids' => [$removedPhotoId],
            ],
            [],
            ['photos' => $this->foto(['c.jpg'])]
        ), $galleryId);

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'];

        $this->assertSame('Pekan Olahraga 2026', $data['title']);
        $this->assertSame(2, $data['photos_count']);
        $this->assertSame(2, GalleryPhoto::where('gallery_id', $galleryId)->count());

        // File yang dihapus ikut hilang dari penyimpanan
        Storage::disk('public')->assertMissing($removedPhotoPath);
    }

    public function test_album_tidak_boleh_kehabisan_foto(): void
    {
        Storage::fake('public');

        $created = $this->buatAlbum(['title' => 'Belajar Bersama']);
        $photoId = $created['photos'][0]['id'];

        $response = (new GalleryController)->update(Request::create(
            "/api/gallery/{$created['id']}/update",
            'PUT',
            ['deleted_photo_ids' => [$photoId]]
        ), $created['id']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
    }

    public function test_hapus_album_menghapus_semua_fotonya(): void
    {
        Storage::fake('public');

        $created = $this->buatAlbum(['title' => 'Lomba HUT RI'], ['a.jpg', 'b.jpg']);
        $paths = GalleryPhoto::pluck('image')->all();

        $response = (new GalleryController)->destroy($created['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, Gallery::count());
        $this->assertSame(0, GalleryPhoto::count());

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_validasi_menolak_kategori_tidak_dikenal(): void
    {
        Storage::fake('public');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new GalleryController)->store(Request::create(
            '/api/gallery/create',
            'POST',
            ['gallery_category_id' => 9999, 'title' => 'Album Ngawur'],
            [],
            ['photos' => $this->foto(['a.jpg'])]
        ));
    }

    public function test_urutan_tampil_mengikuti_sort_order(): void
    {
        Storage::fake('public');

        $this->buatAlbum(['title' => 'Kedua', 'sort_order' => 2]);
        $this->buatAlbum(['title' => 'Pertama', 'sort_order' => 1]);

        $data = (new GalleryController)->index()->getData(true)['data'];

        $this->assertSame('Pertama', $data[0]['title']);
        $this->assertSame('Kedua', $data[1]['title']);
    }

    public function test_foto_besar_dibuatkan_versi_kecil_untuk_beranda(): void
    {
        Storage::fake('public');

        $response = (new GalleryController)->store(Request::create(
            '/api/gallery/create',
            'POST',
            ['gallery_category_id' => $this->kategoriId(), 'title' => 'Foto Besar'],
            [],
            ['photos' => [UploadedFile::fake()->image('besar.jpg', 2400, 1600)]]
        ));

        $data = $response->getData(true)['data'];
        $photo = GalleryPhoto::where('gallery_id', $data['id'])->first();

        Storage::disk('public')->assertExists($photo->image);
        $this->assertNotNull($photo->thumb);
        Storage::disk('public')->assertExists($photo->thumb);

        $this->assertNotSame($data['photos'][0]['image_url'], $data['photos'][0]['thumb_url']);
        $this->assertSame($data['photos'][0]['thumb_url'], $data['cover_url']);

        // Lebar versi kecil dibatasi 1200px dan ukurannya lebih ringan
        $thumbPath = Storage::disk('public')->path($photo->thumb);
        $this->assertSame(1200, getimagesize($thumbPath)[0]);
        $this->assertLessThan(
            Storage::disk('public')->size($photo->image),
            Storage::disk('public')->size($photo->thumb)
        );
    }

    public function test_foto_yang_sudah_kecil_tidak_dibuatkan_thumbnail(): void
    {
        Storage::fake('public');

        $data = $this->buatAlbum(['title' => 'Foto Kecil']);
        $photo = GalleryPhoto::where('gallery_id', $data['id'])->first();

        $this->assertNull($photo->thumb);
        $this->assertSame($data['photos'][0]['image_url'], $data['photos'][0]['thumb_url']);
    }

    public function test_beranda_hanya_mengirim_album_teratas_dengan_foto_sampul(): void
    {
        Storage::fake('public');

        for ($index = 1; $index <= 12; $index++) {
            $this->buatAlbum(
                ['title' => 'Album '.$index, 'sort_order' => $index],
                ['a.jpg', 'b.jpg']
            );
        }

        $home = (new ViewController)->getHomeData()->getData(true)['data'];

        // 12 album tersimpan, tetapi beranda hanya menerima 9 album teratas
        $this->assertCount(9, $home['galleries']);
        $this->assertSame('Album 1', $home['galleries'][0]['title']);

        // Setiap album hanya membawa foto sampul, jumlah aslinya tetap dilaporkan
        $this->assertCount(1, $home['galleries'][0]['photos']);
        $this->assertSame(2, $home['galleries'][0]['photos_count']);
        $this->assertSame(
            $home['galleries'][0]['cover_url'],
            $home['galleries'][0]['photos'][0]['thumb_url']
        );
    }

    public function test_daftar_galeri_publik_bisa_difilter_dengan_slug_atau_id(): void
    {
        Storage::fake('public');

        $outing = $this->kategoriId('outing');

        $this->buatAlbum(['gallery_category_id' => $outing, 'title' => 'Outing 1']);
        $this->buatAlbum(['gallery_category_id' => $outing, 'title' => 'Outing 2']);
        $this->buatAlbum([
            'gallery_category_id' => $this->kategoriId('olahraga'),
            'title' => 'Olahraga 1',
        ]);

        // Filter memakai slug kategori
        $bySlug = (new ViewController)->getGallery(
            Request::create('/api/gallery-list', 'GET', ['category' => 'outing', 'per_page' => 1])
        )->getData(true);

        $this->assertCount(1, $bySlug['data']);
        $this->assertSame('Outing 1', $bySlug['data'][0]['title']);
        $this->assertSame(2, $bySlug['meta']['total']);
        $this->assertSame(2, $bySlug['meta']['last_page']);

        // Filter juga bisa memakai id kategori
        $byId = (new ViewController)->getGallery(
            Request::create('/api/gallery-list', 'GET', ['category' => $outing])
        )->getData(true);

        $this->assertSame(2, $byId['meta']['total']);
    }

    public function test_detail_album_publik_mengirim_semua_foto(): void
    {
        Storage::fake('public');

        $created = $this->buatAlbum(
            ['title' => 'Pertemuan Wali Murid'],
            ['a.jpg', 'b.jpg', 'c.jpg']
        );

        $detail = (new ViewController)
            ->getGalleryDetail($created['id'])
            ->getData(true)['data'];

        $this->assertCount(3, $detail['photos']);
        $this->assertSame(3, $detail['photos_count']);
    }

    public function test_album_non_aktif_tidak_bisa_dibuka_publik(): void
    {
        Storage::fake('public');

        $created = $this->buatAlbum(['title' => 'Album Rahasia', 'is_active' => false]);

        $response = (new ViewController)->getGalleryDetail($created['id']);

        $this->assertSame(404, $response->getStatusCode());
    }
}
