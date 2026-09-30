<?php

use App\Http\Controllers\AboutSchoolController;
use App\Http\Controllers\AcademicDashboardController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminRegistrationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\ClassroomPlacementController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardAdminController;
use App\Http\Controllers\EducationValueController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\GalleryCategoryController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\GradeSpreadsheetController;
use App\Http\Controllers\GraduationController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LegalityController;
use App\Http\Controllers\ManageUserController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\OrganizationStructureController;
use App\Http\Controllers\PrincipalController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\RegistrationInformationController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentRegistrationController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectSpreadsheetController;
use App\Http\Controllers\TeacherAccountController;
use App\Http\Controllers\TeacherAssignmentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeacherSpreadsheetController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViewController;
use App\Http\Controllers\VisionMisionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::get('/home', [ViewController::class, 'getHomeData']);
Route::get('/sejarah', [ViewController::class, 'getSejarah']);
Route::get('/visi-misi', [ViewController::class, 'getVisiMisi']);
Route::get('/program-list', [ViewController::class, 'getProgram']);
Route::get('/program-detail/{slug}', [ViewController::class, 'getProgramDetail']);
Route::get('/berita-list', [ViewController::class, 'getBeritaList']);
Route::get('/berita', [ViewController::class, 'getBerita']);
Route::get('/berita-detail/{slug}', [ViewController::class, 'getBeritaDetail']);
Route::get('/kontak', [ViewController::class, 'getKontak']);
Route::get('/faq-list', [ViewController::class, 'getFaq']);
Route::get('/gallery-list', [ViewController::class, 'getGallery']);
Route::get('/gallery-detail/{id}', [ViewController::class, 'getGalleryDetail']);
Route::get('/gallery-categories', [ViewController::class, 'getGalleryCategories']);
Route::get('/profil/tentang-sekolah', [ViewController::class, 'getAboutSchool']);
Route::get('/profil/nilai-pendidikan', [ViewController::class, 'getEducationValues']);
Route::get('/profil/kepala-sekolah', [ViewController::class, 'getPrincipals']);
Route::get('/profil/guru', [ViewController::class, 'getTeachers']);
Route::get('/profil/struktur-organisasi', [ViewController::class, 'getOrganizationStructures']);
Route::get('/profil/legalitas', [ViewController::class, 'getLegalities']);
Route::get('/profil/lulusan', [ViewController::class, 'getGraduates']);
Route::get('/kegiatan-list', [ViewController::class, 'getActivities']);
Route::get('/registration-information', [RegistrationInformationController::class, 'publicShow']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('registrations')->group(function () {
        Route::get('/', [StudentRegistrationController::class, 'index']);
        Route::get('/count', [StudentRegistrationController::class, 'count']);
        Route::post('/', [StudentRegistrationController::class, 'store']);
        Route::get('/{id}', [StudentRegistrationController::class, 'show']);
    });

    Route::prefix('user')->group(function () {
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::put('/profile', [UserController::class, 'updateProfile']);
    });

    // Ganti password sendiri (dipakai juga saat guru wajib ganti di login pertama)
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Profil guru yang sedang login beserta penugasannya
    Route::get('/guru/profile', [TeacherAccountController::class, 'me']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {

    Route::get('/admin/registration-information', [RegistrationInformationController::class, 'adminShow']);
    Route::put('/admin/registration-information', [RegistrationInformationController::class, 'update']);

    Route::prefix('admin')->group(function () {
        Route::get('/profile', [AdminController::class, 'getProfile']);
        Route::put('/profile', [AdminController::class, 'updateProfile']);
    });

    Route::prefix('news')->group(function () {
        Route::get('/', [NewsController::class, 'index']);
        Route::get('/{id}', [NewsController::class, 'show']);
        Route::post('/create', [NewsController::class, 'store']);
        Route::put('/{id}/update', [NewsController::class, 'update']);
        Route::delete('/{id}/delete', [NewsController::class, 'destroy']);
    });

    Route::prefix('history')->group(function () {
        Route::get('/', [HistoryController::class, 'index']);
        Route::get('/{id}', [HistoryController::class, 'show']);
        Route::post('/create', [HistoryController::class, 'store']);
        Route::put('/{id}/update', [HistoryController::class, 'update']);
        Route::delete('/{id}/delete', [HistoryController::class, 'destroy']);
    });

    Route::prefix('vision-mision')->group(function () {
        Route::get('/', [VisionMisionController::class, 'index']);
        Route::get('/{id}', [VisionMisionController::class, 'show']);
        Route::post('/create', [VisionMisionController::class, 'store']);
        Route::put('/{id}/update', [VisionMisionController::class, 'update']);
        Route::delete('/{id}/delete', [VisionMisionController::class, 'destroy']);
    });

    Route::prefix('program')->group(function () {
        Route::get('/', [ProgramController::class, 'index']);
        Route::get('/{id}', [ProgramController::class, 'show']);
        Route::post('/create', [ProgramController::class, 'store']);
        Route::put('/{id}/update', [ProgramController::class, 'update']);
        Route::delete('/{id}/delete', [ProgramController::class, 'destroy']);
    });

    Route::prefix('contact')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/{id}', [ContactController::class, 'show']);
        Route::post('/create', [ContactController::class, 'store']);
        Route::post('/{id}/update', [ContactController::class, 'update']);
        Route::delete('/{id}/delete', [ContactController::class, 'destroy']);
    });

    Route::prefix('faq')->group(function () {
        Route::get('/', [FaqController::class, 'index']);
        Route::get('/{id}', [FaqController::class, 'show']);
        Route::post('/create', [FaqController::class, 'store']);
        Route::put('/{id}/update', [FaqController::class, 'update']);
        Route::delete('/{id}/delete', [FaqController::class, 'destroy']);
    });

    Route::prefix('gallery')->group(function () {
        Route::get('/', [GalleryController::class, 'index']);
        Route::get('/{id}', [GalleryController::class, 'show']);
        Route::post('/create', [GalleryController::class, 'store']);
        Route::put('/{id}/update', [GalleryController::class, 'update']);
        Route::delete('/{id}/delete', [GalleryController::class, 'destroy']);
    });

    Route::prefix('gallery-category')->group(function () {
        Route::get('/', [GalleryCategoryController::class, 'index']);
        Route::post('/reorder', [GalleryCategoryController::class, 'reorder']);
        Route::get('/{id}', [GalleryCategoryController::class, 'show']);
        Route::post('/create', [GalleryCategoryController::class, 'store']);
        Route::put('/{id}/update', [GalleryCategoryController::class, 'update']);
        Route::delete('/{id}/delete', [GalleryCategoryController::class, 'destroy']);
    });

    Route::prefix('about-school')->group(function () {
        Route::get('/', [AboutSchoolController::class, 'index']);
        Route::get('/{id}', [AboutSchoolController::class, 'show']);
        Route::post('/create', [AboutSchoolController::class, 'store']);
        Route::put('/{id}/update', [AboutSchoolController::class, 'update']);
        Route::delete('/{id}/delete', [AboutSchoolController::class, 'destroy']);
    });

    Route::prefix('activity')->group(function () {
        Route::get('/', [ActivityController::class, 'index']);
        Route::get('/{id}', [ActivityController::class, 'show']);
        Route::post('/create', [ActivityController::class, 'store']);
        Route::put('/{id}/update', [ActivityController::class, 'update']);
        Route::delete('/{id}/delete', [ActivityController::class, 'destroy']);
    });

    // Master Data
    Route::prefix('academic-year')->group(function () {
        Route::get('/', [AcademicYearController::class, 'index']);
        Route::get('/{id}', [AcademicYearController::class, 'show']);
        Route::post('/create', [AcademicYearController::class, 'store']);
        Route::post('/{id}/copy-classrooms', [AcademicYearController::class, 'copyClassrooms']);
        Route::put('/{id}/update', [AcademicYearController::class, 'update']);
        Route::delete('/{id}/delete', [AcademicYearController::class, 'destroy']);
    });

    Route::prefix('classroom')->group(function () {
        Route::get('/', [ClassroomController::class, 'index']);
        Route::get('/export', [ClassroomController::class, 'export']);
        Route::get('/template', [ClassroomController::class, 'template']);
        Route::post('/import', [ClassroomController::class, 'import']);
        Route::get('/{id}/students', [ClassroomController::class, 'students']);
        Route::get('/{id}', [ClassroomController::class, 'show']);
        Route::post('/create', [ClassroomController::class, 'store']);
        Route::put('/{id}/update', [ClassroomController::class, 'update']);
        Route::delete('/{id}/delete', [ClassroomController::class, 'destroy']);
    });

    Route::prefix('student')->group(function () {
        Route::get('/', [StudentController::class, 'index']);
        Route::get('/{id}', [StudentController::class, 'show']);
        Route::put('/{id}/update', [StudentController::class, 'update']);
    });

    Route::prefix('admin/classroom-placements')->group(function () {
        Route::get('/', [ClassroomPlacementController::class, 'index']);
        Route::get('/export', [ClassroomPlacementController::class, 'export']);
        Route::get('/template', [ClassroomPlacementController::class, 'template']);
        Route::post('/import', [ClassroomPlacementController::class, 'import']);
        Route::post('/academic-year', [ClassroomPlacementController::class, 'assignAcademicYear']);
    });

    Route::prefix('admin/graduations')->group(function () {
        Route::get('/', [GraduationController::class, 'index']);
        Route::get('/graduates', [GraduationController::class, 'graduates']);
        Route::post('/', [GraduationController::class, 'store']);
        Route::delete('/{studentId}', [GraduationController::class, 'cancel']);
    });

    Route::prefix('admin/promotions')->group(function () {
        Route::get('/', [PromotionController::class, 'index']);
        Route::post('/', [PromotionController::class, 'store']);
    });

    Route::prefix('education-value')->group(function () {
        Route::get('/', [EducationValueController::class, 'index']);
        Route::get('/{id}', [EducationValueController::class, 'show']);
        Route::post('/create', [EducationValueController::class, 'store']);
        Route::put('/{id}/update', [EducationValueController::class, 'update']);
        Route::delete('/{id}/delete', [EducationValueController::class, 'destroy']);
    });

    Route::prefix('principal')->group(function () {
        Route::get('/', [PrincipalController::class, 'index']);
        Route::get('/{id}', [PrincipalController::class, 'show']);
        Route::post('/create', [PrincipalController::class, 'store']);
        Route::put('/{id}/update', [PrincipalController::class, 'update']);
        Route::delete('/{id}/delete', [PrincipalController::class, 'destroy']);
    });

    Route::prefix('teacher')->group(function () {
        Route::get('/', [TeacherController::class, 'index']);
        Route::get('/export', [TeacherSpreadsheetController::class, 'export']);
        Route::get('/template', [TeacherSpreadsheetController::class, 'template']);
        Route::post('/import', [TeacherSpreadsheetController::class, 'import']);
        Route::get('/{id}', [TeacherController::class, 'show']);
        Route::post('/create', [TeacherController::class, 'store']);
        Route::put('/{id}/update', [TeacherController::class, 'update']);
        Route::delete('/{id}/delete', [TeacherController::class, 'destroy']);
        Route::post('/{id}/account', [TeacherAccountController::class, 'store']);
        Route::post('/{id}/reset-password', [TeacherAccountController::class, 'resetPassword']);
    });

    Route::prefix('legality')->group(function () {
        Route::get('/', [LegalityController::class, 'index']);
        Route::get('/{id}', [LegalityController::class, 'show']);
        Route::post('/create', [LegalityController::class, 'store']);
        Route::put('/{id}/update', [LegalityController::class, 'update']);
        Route::delete('/{id}/delete', [LegalityController::class, 'destroy']);
    });

    Route::prefix('organization-structure')->group(function () {
        Route::get('/', [OrganizationStructureController::class, 'index']);
        Route::get('/{id}', [OrganizationStructureController::class, 'show']);
        Route::post('/create', [OrganizationStructureController::class, 'store']);
        Route::put('/{id}/update', [OrganizationStructureController::class, 'update']);
        Route::delete('/{id}/delete', [OrganizationStructureController::class, 'destroy']);
    });

    // Akademik dasar: master mata pelajaran + input nilai
    Route::prefix('subject')->group(function () {
        Route::get('/', [SubjectController::class, 'index']);
        Route::get('/export', [SubjectSpreadsheetController::class, 'export']);
        Route::get('/template', [SubjectSpreadsheetController::class, 'template']);
        Route::post('/import', [SubjectSpreadsheetController::class, 'import']);
        Route::get('/{id}', [SubjectController::class, 'show']);
        Route::post('/create', [SubjectController::class, 'store']);
        Route::put('/{id}/update', [SubjectController::class, 'update']);
        Route::delete('/{id}/delete', [SubjectController::class, 'destroy']);
    });

    // Penulisan jadwal tetap khusus admin; pembacaan jadwal dibuka untuk guru
    Route::prefix('schedule')->group(function () {
        Route::post('/create', [ScheduleController::class, 'store']);
        Route::put('/{id}/update', [ScheduleController::class, 'update']);
        Route::delete('/{id}/delete', [ScheduleController::class, 'destroy']);
    });

    Route::prefix('academic-dashboard')->group(function () {
        Route::get('/', [AcademicDashboardController::class, 'index']);
    });

    Route::prefix('teacher-assignment')->group(function () {
        Route::get('/', [TeacherAssignmentController::class, 'index']);
        Route::post('/homeroom', [TeacherAssignmentController::class, 'storeHomeroom']);
        Route::post('/teaching', [TeacherAssignmentController::class, 'storeTeaching']);
    });

    Route::prefix('manage-user')->group(function () {
        Route::get('/roles', [ManageUserController::class, 'showRoles']);
        Route::get('/admin', [ManageUserController::class, 'showAdmin']);
        Route::get('/pengguna', [ManageUserController::class, 'showUser']);
        Route::post('/{id}/reset-password', [ManageUserController::class, 'resetPassword']);

        Route::get('/', [ManageUserController::class, 'index']);
        Route::get('/{id}', [ManageUserController::class, 'show']);
        Route::post('/create', [ManageUserController::class, 'store']);
        Route::put('/{id}/update', [ManageUserController::class, 'update']);
        Route::delete('/{id}/delete', [ManageUserController::class, 'destroy']);
    });

    Route::prefix('admin/registrations')->group(function () {
        Route::get('/', [AdminRegistrationController::class, 'index']);
        Route::get('/statistics', [AdminRegistrationController::class, 'statistics']);
        Route::get('/export', [AdminRegistrationController::class, 'export']);
        Route::get('/{id}/documents/download', [AdminRegistrationController::class, 'downloadDocuments']);
        Route::post('/{id}/classroom', [AdminRegistrationController::class, 'assignClassroom']);
        Route::post('/{id}/student', [AdminRegistrationController::class, 'createStudent']);
        Route::put('/{id}/academic-year', [AdminRegistrationController::class, 'setAcademicYear']);
        Route::get('/{id}', [AdminRegistrationController::class, 'show']);
        Route::put('/{id}/status', [AdminRegistrationController::class, 'updateStatus']);
        Route::delete('/{id}', [AdminRegistrationController::class, 'destroy']);
    });

    Route::prefix('admin/dashboard')->group(function () {
        Route::get('/', [DashboardAdminController::class, 'index']);
        Route::get('/school-summary', [DashboardAdminController::class, 'schoolSummary']);
        Route::get('/quick-stats', [DashboardAdminController::class, 'quickStats']);
    });
});

/*
 * Area guru: admin dan guru.
 *
 * Guru hanya boleh menyentuh data yang ditugaskan kepadanya — pembatasannya
 * ada di masing-masing controller (lihat App\Support\TeacherScope), sehingga
 * guru tidak bisa membuka kelas lain dengan mengubah parameter di URL.
 */
Route::middleware(['auth:sanctum', 'role:admin,guru'])->group(function () {
    Route::get('/grade', [GradeController::class, 'index']);
    Route::post('/grade', [GradeController::class, 'store']);
    Route::get('/grade/export', [GradeSpreadsheetController::class, 'export']);
    Route::post('/grade/import', [GradeSpreadsheetController::class, 'import']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/recap', [AttendanceController::class, 'recap']);
    Route::post('/attendance', [AttendanceController::class, 'store']);

    Route::get('/report-card', [ReportCardController::class, 'index']);
    Route::get('/schedule', [ScheduleController::class, 'index']);
});
