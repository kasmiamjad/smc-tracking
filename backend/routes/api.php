<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ConsoleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TechnicianAuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---- Public -----------------------------------------------------------
    Route::post('auth/register', [TechnicianAuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/login-pin', [TechnicianAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/login', [ConsoleAuthController::class, 'login'])->middleware('throttle:login');
    Route::get('departments', [DepartmentController::class, 'index']); // needed by the registration screen

    Route::post('auth/refresh', [SessionController::class, 'refresh'])
        ->middleware(['auth:sanctum', 'abilities:refresh', 'active'])
        ->name('auth.refresh');

    // ---- Authenticated (access token) -------------------------------------
    Route::middleware(['auth:sanctum', 'abilities:access', 'active', 'throttle:api'])->group(function () {

        Route::get('auth/me', [SessionController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
        Route::post('auth/change-pin', [SessionController::class, 'changePin'])->name('auth.change-pin');

        Route::get('users/{user}/documents', [DocumentController::class, 'index']);
        Route::post('users/{user}/documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        // ---- Console: admins and dispatchers (read) ------------------------
        Route::middleware('role:ADMIN,DISPATCHER')->prefix('admin')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/pending', [UserController::class, 'pending']);
            Route::get('users/{user}', [UserController::class, 'show']);
        });

        // ---- Console: admins only (write) ----------------------------------
        Route::middleware('role:ADMIN')->group(function () {
            Route::prefix('admin')->group(function () {
                Route::post('users', [UserController::class, 'store']);
                Route::patch('users/{user}', [UserController::class, 'update']);
                Route::patch('users/{user}/approval', [UserController::class, 'approval']);
                Route::patch('users/{user}/block', [UserController::class, 'block']);
                Route::post('users/{user}/reset-pin', [UserController::class, 'resetPin']);
                Route::post('users/{user}/unlock', [UserController::class, 'unlock']);
                Route::patch('documents/{document}/verification', [DocumentController::class, 'verify']);
            });

            Route::post('departments', [DepartmentController::class, 'store']);
            Route::put('departments/{department}', [DepartmentController::class, 'update']);
            Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);
        });
    });
});
