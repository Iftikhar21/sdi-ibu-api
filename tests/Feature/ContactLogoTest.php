<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['role_name' => 'admin']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id]));
    }

    public function test_contact_can_use_the_default_website_logo(): void
    {
        $this->postJson('/api/contact/create', [
            'deskripsi' => 'Sekolah Islam',
            'alamat' => 'Jakarta',
            'telepon' => '021123456',
            'email' => 'sekolah@example.com',
            'map_embed' => 'https://maps.example.com',
        ])->assertCreated()->assertJsonPath('data.logo', null);

        $this->getJson('/api/kontak')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }

    public function test_admin_can_remove_a_custom_logo_and_restore_the_default(): void
    {
        Storage::fake('public');
        $path = 'contact/logos/custom.png';
        Storage::disk('public')->put($path, 'logo');
        $contact = Contact::create(['logo' => $path]);

        $this->postJson("/api/contact/{$contact->id}/update", [
            'remove_logo' => true,
        ])->assertOk();

        $this->assertNull($contact->fresh()->logo);
        Storage::disk('public')->assertMissing($path);
    }
}
