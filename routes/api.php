<?php

declare(strict_types=1);

use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalDataController;
use App\Http\Controllers\API\PortalSupportController;
use App\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

// API routes for campus authentication (without CSRF protection)
Route::prefix('campus')->group(function () {
    Route::post('login', [WebLoginController::class, 'login']);

    // Campus data routes (with authentication middleware)
    Route::middleware(['web'])->group(function () {
        Route::get('dashboard', [PortalDataController::class, 'dashboard']);
        Route::get('profile', [PortalDataController::class, 'profile']);
        Route::get('classes', [PortalDataController::class, 'classes']);
        Route::get('class-detail', [PortalDataController::class, 'classDetail']);
        Route::get('class-detail-by-id', [PortalDataController::class, 'classDetailById']);
        Route::get('meeting-detail', [PortalDataController::class, 'meetingDetail']);
        Route::get('meeting-detail-by-id', [PortalDataController::class, 'meetingDetailById']);

        // New endpoints for different class data sources
        Route::get('sipandai-classes', [PortalDataController::class, 'sipandaiClasses']);
        Route::get('student-course', [PortalDataController::class, 'studentCourse']);
        Route::get('merged-classes', [PortalDataController::class, 'mergedClasses']);
        Route::get('classes/overview', [PortalDataController::class, 'classesOverview']);
        Route::get('sipandai-flow', [PortalDataController::class, 'sipandaiFlow']);
        Route::post('assignments/upload', [PortalActionController::class, 'uploadAssignment']);
        Route::post('attendance/mark', [PortalActionController::class, 'markAttendance']);
        Route::delete('assignments/submission', [PortalActionController::class, 'deleteAssignment']);

        // Support + metadata
        Route::get('support/session', [PortalSupportController::class, 'sessionStatus']);
        Route::get('support/parameter-help', [PortalSupportController::class, 'parameterHelp']);
        Route::get('support/validate-parameters', [PortalSupportController::class, 'validateParameters']);
        Route::get('metadata/endpoints', [PortalSupportController::class, 'endpoints']);
    });
});
