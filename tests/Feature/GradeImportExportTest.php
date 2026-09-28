<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class GradeImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicYear $year;

    private Classroom $classroom;

    private Student $student;

    private Subject $mathematics;

    private Subject $indonesian;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['role_name' => 'admin']);
        $userRole = Role::create(['role_name' => 'user']);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        Sanctum::actingAs($this->admin);

        $this->year = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $this->classroom = Classroom::create([
            'academic_year_id' => $this->year->id,
            'grade_level' => 4,
            'name' => 'A',
            'quota' => 28,
            'is_active' => true,
        ]);

        $parent = User::factory()->create(['role_id' => $userRole->id]);
        $registration = StudentRegistration::create([
            'user_id' => $parent->id,
            'academic_year_id' => $this->year->id,
            'full_name' => 'Budi Santoso',
            'nickname' => 'Budi',
            'gender' => 'L',
            'birth_place' => 'Bandung',
            'birth_date' => '2017-05-01',
            'father_name' => 'Ayah Budi',
            'mother_name' => 'Ibu Budi',
            'address' => 'Bandung',
            'phone' => '08123456789',
            'contact_email' => 'orangtua@example.com',
            'photo' => 'registrations/photos/foto.jpg',
            'birth_certificate' => 'registrations/birth_certificates/akte.jpg',
            'family_card' => 'registrations/family_cards/kk.jpg',
            'payment_proof' => 'registrations/payment_proofs/bukti.jpg',
            'status' => 'approved',
        ]);
        $this->student = Student::ensureForRegistration($registration);
        $this->student->update(['nis' => '2026001']);
        $this->student->placeIntoClassroom($this->classroom, $this->admin->id);

        $this->mathematics = Subject::create([
            'code' => 'MTK', 'name' => 'Matematika', 'grade_level' => 4, 'is_active' => true,
        ]);
        $this->indonesian = Subject::create([
            'code' => 'BIN', 'name' => 'Bahasa Indonesia', 'grade_level' => null, 'is_active' => true,
        ]);
    }

    private function context(int $semester = 1): array
    {
        return [
            'academic_year_id' => $this->year->id,
            'classroom_id' => $this->classroom->id,
            'semester' => $semester,
        ];
    }

    private function exportedWorkbookPath(): string
    {
        return $this->get('/api/grade/export?'.http_build_query($this->context()))
            ->assertOk()
            ->baseResponse
            ->getFile()
            ->getPathname();
    }

    private function editedExport(array $cells): UploadedFile
    {
        $spreadsheet = IOFactory::load($this->exportedWorkbookPath());
        $sheet = $spreadsheet->getSheetByName('Nilai');

        foreach ($cells as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        $path = tempnam(sys_get_temp_dir(), 'grade-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'nilai.xlsx', null, null, true);
    }

    public function test_export_memuat_konteks_siswa_dan_mata_pelajaran_tanpa_id_terlihat(): void
    {
        Grade::create([
            'student_id' => $this->student->id,
            'subject_id' => $this->mathematics->id,
            'academic_year_id' => $this->year->id,
            'semester' => 1,
            'score' => 82.5,
            'recorded_by' => $this->admin->id,
        ]);

        $spreadsheet = IOFactory::load($this->exportedWorkbookPath());
        $sheet = $spreadsheet->getSheetByName('Nilai');

        $this->assertSame('2026/2027', $sheet->getCell('B2')->getValue());
        $this->assertSame('4A', $sheet->getCell('B3')->getValue());
        $this->assertSame('2026001', $sheet->getCell('B6')->getValue());
        $this->assertSame('Budi Santoso', $sheet->getCell('C6')->getValue());
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertContains(82.5, [$sheet->getCell('D6')->getValue(), $sheet->getCell('E6')->getValue()]);
        $this->assertSame(
            Worksheet::SHEETSTATE_VERYHIDDEN,
            $spreadsheet->getSheetByName('_Sistem')->getSheetState()
        );
        $spreadsheet->disconnectWorksheets();
    }

    public function test_import_memperbarui_dan_menghapus_nilai_secara_atomik(): void
    {
        Grade::create([
            'student_id' => $this->student->id,
            'subject_id' => $this->mathematics->id,
            'academic_year_id' => $this->year->id,
            'semester' => 1,
            'score' => 70,
        ]);
        Grade::create([
            'student_id' => $this->student->id,
            'subject_id' => $this->indonesian->id,
            'academic_year_id' => $this->year->id,
            'semester' => 1,
            'score' => 75,
        ]);

        $spreadsheet = IOFactory::load($this->exportedWorkbookPath());
        $sheet = $spreadsheet->getSheetByName('Nilai');
        $columns = [];
        foreach (['D', 'E'] as $column) {
            $columns[strtok((string) $sheet->getCell($column.'5')->getValue(), ' ')] = $column;
        }
        $sheet->setCellValue($columns['MTK'].'6', 91.5);
        $sheet->setCellValue($columns['BIN'].'6', null);
        $path = tempnam(sys_get_temp_dir(), 'grade-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->post('/api/grade/import', [
            ...array_map('strval', $this->context()),
            'file' => new UploadedFile($path, 'nilai.xlsx', null, null, true),
        ]);

        $response->assertOk()->assertJsonPath('data.saved', 1)->assertJsonPath('data.cleared', 1);
        $this->assertDatabaseHas('grades', [
            'student_id' => $this->student->id,
            'subject_id' => $this->mathematics->id,
            'semester' => 1,
            'score' => 91.5,
        ]);
        $this->assertDatabaseMissing('grades', [
            'student_id' => $this->student->id,
            'subject_id' => $this->indonesian->id,
            'semester' => 1,
        ]);
    }

    public function test_import_ditolak_jika_konteks_pilihan_berbeda(): void
    {
        $response = $this->post('/api/grade/import', [
            ...$this->context(2),
            'file' => $this->editedExport(['D6' => 90]),
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_satu_nilai_salah_membatalkan_seluruh_import(): void
    {
        $response = $this->post('/api/grade/import', [
            ...$this->context(),
            'file' => $this->editedExport(['D6' => 85, 'E6' => 150]),
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertDatabaseCount('grades', 0);
    }
}
