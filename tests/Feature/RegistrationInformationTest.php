<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\RegistrationFee;
use App\Models\RegistrationRequirement;
use App\Models\RegistrationSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationInformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'admin']);
        Role::create(['role_name' => 'user']);
    }

    private function activeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
    }

    private function registrationPayload(string $name = 'Budi Santoso'): array
    {
        return [
            'full_name' => $name,
            'nickname' => 'Budi',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2019-05-01',
            'father_name' => 'Ayah '.$name,
            'mother_name' => 'Ibu '.$name,
            'address' => 'Jl. Dalang No. 1',
            'phone' => '08123456789',
            'contact_email' => 'ortu@example.com',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
            'birth_certificate' => UploadedFile::fake()->image('akte.jpg'),
            'family_card' => UploadedFile::fake()->image('kk.jpg'),
            'payment_proof' => UploadedFile::fake()->image('bayar.jpg'),
        ];
    }

    public function test_admin_can_update_information_and_public_only_sees_active_items(): void
    {
        $this->activeYear();
        Sanctum::actingAs(User::factory()->create(['role_id' => 1]));

        $this->putJson('/api/admin/registration-information', [
            'phase' => 'open',
            'phase_message' => null,
            'quota' => 30,
            'quota_description' => 'Kuota siswa baru.',
            'requirements' => [
                ['content' => 'Akta kelahiran', 'is_active' => true],
                ['content' => 'Dokumen internal', 'is_active' => false],
            ],
            'fees' => [
                ['program' => 'Uang Pangkal', 'amount' => 2500000, 'description' => 'Sekali bayar', 'is_active' => true],
                ['program' => 'Biaya tersembunyi', 'amount' => 1000, 'description' => null, 'is_active' => false],
            ],
        ])->assertOk()->assertJsonPath('data.quota', 30);

        $this->assertSame(1, RegistrationSetting::count());
        $this->assertSame(2, RegistrationRequirement::count());
        $this->assertSame(2, RegistrationFee::count());

        $this->getJson('/api/registration-information')
            ->assertOk()
            ->assertJsonPath('data.available', 30)
            ->assertJsonCount(1, 'data.requirements')
            ->assertJsonCount(1, 'data.fees');
    }

    public function test_quota_blocks_new_registration_and_transfer_proof_is_optional(): void
    {
        Storage::fake('public');
        $this->activeYear();
        RegistrationSetting::query()->firstOrFail()->update([
            'phase' => 'open',
            'quota' => 1,
        ]);

        Sanctum::actingAs(User::factory()->create(['role_id' => 2]));
        $this->post('/api/registrations', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.transfer_proof_url', null);

        Sanctum::actingAs(User::factory()->create(['role_id' => 2]));
        $this->post('/api/registrations', $this->registrationPayload('Citra Dewi'))
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_transfer_proof_accepts_pdf_document(): void
    {
        Storage::fake('public');
        $this->activeYear();
        RegistrationSetting::query()->firstOrFail()->update(['phase' => 'open']);
        Sanctum::actingAs(User::factory()->create(['role_id' => 2]));

        $payload = $this->registrationPayload();
        $payload['transfer_proof'] = UploadedFile::fake()->create(
            'surat-pindah.pdf',
            100,
            'application/pdf'
        );

        $response = $this->post('/api/registrations', $payload)->assertCreated();
        $path = $response->json('data.transfer_proof');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_closed_and_account_phases_hide_the_form_at_api_level(): void
    {
        Storage::fake('public');
        $this->activeYear();
        Sanctum::actingAs(User::factory()->create(['role_id' => 2]));

        $this->getJson('/api/registration-information')
            ->assertOk()
            ->assertJsonPath('data.phase', 'closed');

        $this->post('/api/registrations', $this->registrationPayload())
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        RegistrationSetting::query()->firstOrFail()->update(['phase' => 'account']);

        $this->post('/api/registrations', $this->registrationPayload('Citra Dewi'))
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_account_creation_opens_in_account_phase(): void
    {
        $payload = [
            'name' => 'Orang Tua Siswa',
            'email' => 'orangtua@example.com',
            'password' => 'rahasia123',
        ];

        $this->postJson('/api/register', $payload)
            ->assertStatus(422);

        RegistrationSetting::query()->firstOrFail()->update(['phase' => 'account']);

        $this->postJson('/api/register', $payload)
            ->assertOk()
            ->assertJsonPath('user.email', 'orangtua@example.com');
    }
}
