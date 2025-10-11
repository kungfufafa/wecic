<?php

declare(strict_types=1);

use App\Http\Controllers\API\CampusDataController;
use App\Http\Controllers\API\DebugController;
use App\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

// API routes for campus authentication (without CSRF protection)
Route::prefix('campus')->group(function () {
    Route::post('login', [WebLoginController::class, 'login']);

    // Campus data routes (with authentication middleware)
    Route::middleware(['web'])->group(function () {
        Route::get('dashboard', [CampusDataController::class, 'dashboard']);
        Route::get('profile', [CampusDataController::class, 'profile']);
        Route::get('classes', [CampusDataController::class, 'classes']);
        Route::get('class-detail', [CampusDataController::class, 'classDetail']);
        Route::get('class-detail-by-id', [CampusDataController::class, 'classDetailById']);
        Route::get('meeting-detail', [CampusDataController::class, 'meetingDetail']);
        Route::get('meeting-detail-by-id', [CampusDataController::class, 'meetingDetailById']);

        // New endpoints for different class data sources
        Route::get('sipandai-classes', [CampusDataController::class, 'sipandaiClasses']);
        Route::get('student-course', [CampusDataController::class, 'studentCourse']);
        Route::get('merged-classes', [CampusDataController::class, 'mergedClasses']);
    });
});

// Debug routes for testing and development
Route::prefix('debug')->middleware(['web'])->group(function () {
    Route::get('dashboard', [DebugController::class, 'testDashboard']);
    Route::get('profile', [DebugController::class, 'testProfile']);
    Route::get('classes', [DebugController::class, 'testClasses']);
    Route::get('class-detail', [DebugController::class, 'testClassDetail']);
    Route::get('session-status', [DebugController::class, 'sessionStatus']);
    Route::get('parameter-help', [DebugController::class, 'parameterHelp']);
    Route::get('test-validation', [DebugController::class, 'testParameterValidation']);
});
