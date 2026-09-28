<?php

namespace Tests\Feature;

use App\Http\Controllers\OrganizationStructureController;
use App\Models\OrganizationStructure;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
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

    public function test_admin_dapat_menambah_data_struktur_organisasi(): void
    {
        Storage::fake('public');
        $this->admin();

        $response = $this->postJson('/api/organization-structure/create', [
            'name' => 'Ustadz Ahmad Fauzi',
            'position' => 'Kepala Sekolah',
            'photo' => UploadedFile::fake()->image('foto.jpg', 600, 800),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Ustadz Ahmad Fauzi');

        $item = OrganizationStructure::firstOrFail();

        $this->assertSame('Kepala Sekolah', $item->position);
        $this->assertSame(1, $item->sort_order);
        $this->assertTrue($item->is_active);
        $this->assertNotNull($item->photo);

        Storage::disk('public')->assertExists($item->photo);
    }

    public function test_nama_dan_jabatan_wajib_diisi(): void
    {
        $this->admin();

        $this->postJson('/api/organization-structure/create', [
            'name' => '',
            'position' => '',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);
    }

    public function test_foto_harus_berformat_gambar_dan_tidak_lebih_5mb(): void
    {
        Storage::fake('public');
        $this->admin();

        $this->postJson('/api/organization-structure/create', [
            'name' => 'Ustadz Ahmad',
            'position' => 'Wakil Kepala',
            'photo' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->postJson('/api/organization-structure/create', [
            'name' => 'Ustadz Ahmad',
            'position' => 'Wakil Kepala',
            'photo' => UploadedFile::fake()->image('besar.jpg')->size(6000),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_admin_dapat_mengubah_dan_menonaktifkan_data(): void
    {
        Storage::fake('public');
        $this->admin();

        $item = OrganizationStructure::create([
            'name' => 'Ustadzah Fatimah',
            'position' => 'Guru Kelas 1',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $this->putJson("/api/organization-structure/{$item->id}/update", [
            'position' => 'Wali Kelas 1A',
            'sort_order' => 2,
            'is_active' => false,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.position', 'Wali Kelas 1A');

        $item->refresh();

        $this->assertSame('Wali Kelas 1A', $item->position);
        $this->assertSame(2, $item->sort_order);
        $this->assertFalse($item->is_active);
    }

    public function test_admin_dapat_menghapus_data(): void
    {
        Storage::fake('public');
        $this->admin();

        $item = OrganizationStructure::create([
            'name' => 'Ustadzah Fatimah',
            'position' => 'Guru Kelas 1',
        ]);

        $this->deleteJson("/api/organization-structure/{$item->id}/delete")->assertStatus(200);

        $this->assertSame(0, OrganizationStructure::count());
    }

    public function test_daftar_admin_diurutkan_sesuai_sort_order(): void
    {
        $this->admin();

        OrganizationStructure::create(['name' => 'C', 'position' => 'Posisi C', 'sort_order' => 3]);
        OrganizationStructure::create(['name' => 'A', 'position' => 'Posisi A', 'sort_order' => 1]);
        OrganizationStructure::create(['name' => 'B', 'position' => 'Posisi B', 'sort_order' => 2]);

        $data = $this->getJson('/api/organization-structure')->assertStatus(200)->json('data');

        $this->assertSame(['A', 'B', 'C'], array_column($data, 'name'));
    }

    public function test_halaman_publik_hanya_menampilkan_data_aktif(): void
    {
        OrganizationStructure::create([
            'name' => 'Tampil',
            'position' => 'Kepala Sekolah',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        OrganizationStructure::create([
            'name' => 'Disembunyikan',
            'position' => 'Bendahara',
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $data = $this->getJson('/api/profil/struktur-organisasi')->assertStatus(200)->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Tampil', $data[0]['name']);
        $this->assertArrayHasKey('photo_url', $data[0]);
    }

    public function test_endpoint_ubah_data_hanya_untuk_admin(): void
    {
        $item = OrganizationStructure::create([
            'name' => 'Ustadz Ahmad',
            'position' => 'Kepala Sekolah',
        ]);

        $user = User::factory()->create(['role_id' => 2]);
        Sanctum::actingAs($user);

        $this->getJson('/api/organization-structure')->assertStatus(403);

        $this->putJson("/api/organization-structure/{$item->id}/update", ['name' => 'Diubah'])
            ->assertStatus(403);

        $this->assertSame('Ustadz Ahmad', $item->fresh()->name);
    }

    public function test_show_menolak_data_yang_tidak_ada(): void
    {
        $this->admin();

        $this->getJson('/api/organization-structure/999')->assertStatus(404);
    }

    public function test_kontroller_langsung_menyimpan_data(): void
    {
        $response = (new OrganizationStructureController)->store(Request::create(
            '/api/organization-structure/create',
            'POST',
            ['name' => 'Ustadzah Aisyah', 'position' => 'Guru Kelas 2']
        ));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, OrganizationStructure::count());
    }
}
