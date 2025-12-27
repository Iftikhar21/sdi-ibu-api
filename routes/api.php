<?php

use App\Http\Controllers\AdminRegistrationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardAdminController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ManageUserController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\StudentRegistrationController;
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
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    
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
        Route::get('/{id}', [AdminRegistrationController::class, 'show']);
        Route::put('/{id}/status', [AdminRegistrationController::class, 'updateStatus']);
    });

    Route::prefix('admin/dashboard')->group(function () {
        Route::get('/', [DashboardAdminController::class, 'index']);
        Route::get('/quick-stats', [DashboardAdminController::class, 'quickStats']);
    });
});