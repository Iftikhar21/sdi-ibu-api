<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassroomPlacement;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRegistrationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dapat_menghapus_pendaftaran_dan_data_turunannya_tanpa_menghapus_akun(): void
    {
        Storage::fake('public');

        $adminRole = Role::create(['role_name' => 'admin']);
        $userRole = Role::create(['role_name' => 'user']);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);
        $parent = User::factory()->create(['role_id' => $userRole->id]);

        Sanctum::actingAs($admin);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $classroom = Classroom::create([
            'academic_year_id' => $year->id,
            'grade_level' => 1,
            'name' => 'A',
            'quota' => 28,
            'is_active' => true,
        ]);

        $files = [
            'registrations/photos/foto.jpg',
            'registrations/birth_certificates/akte.jpg',
            'registrations/family_cards/kk.jpg',
            'registrations/payment_proofs/bukti.jpg',
            'registrations/transfer_proofs/pindahan.pdf',
        ];
        foreach ($files as $file) {
            Storage::disk('public')->put($file, 'test');
        }

        $registration = StudentRegistration::create([
            'user_id' => $parent->id,
            'academic_year_id' => $year->id,
            'full_name' => 'Akun Percobaan',
            'nickname' => 'Tes',
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2019-01-01',
            'father_name' => 'Ayah Tes',
            'mother_name' => 'Ibu Tes',
            'address' => 'Alamat Tes',
            'phone' => '081234567890',
            'contact_email' => 'orangtua@example.com',
            'photo' => $files[0],
            'birth_certificate' => $files[1],
            'family_card' => $files[2],
            'payment_proof' => $files[3],
            'transfer_proof' => $files[4],
            'status' => 'approved',
        ]);
        $student = Student::ensureForRegistration($registration);
        ClassroomPlacement::create([
            'student_registration_id' => $registration->id,
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'academic_year_id' => $year->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/admin/registrations/{$registration->id}");

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Pendaftaran Akun Percobaan berhasil dihapus',
            ]);

        $this->assertDatabaseMissing('student_registrations', ['id' => $registration->id]);
        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('classroom_placements', [
            'student_registration_id' => $registration->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $parent->id]);

        foreach ($files as $file) {
            Storage::disk('public')->assertMissing($file);
        }
    }
}
