<?php

declare(strict_types=1);

use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalAuthController;
use App\Http\Controllers\API\PortalDataController;
use App\Http\Controllers\API\PortalSupportController;
use Illuminate\Support\Facades\Route;

// Session-aware middleware stack for campus API routes (cookie + session, no CSRF).
$sessionMiddleware = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
];

// Proxied endpoints use the original upstream portal parameter names as primary input.
// Compatibility aliases may still be accepted in request classes for older clients.
Route::middleware($sessionMiddleware)->group(function () {
    Route::prefix('auth')->controller(PortalAuthController::class)->group(function (): void {
        Route::post('login', 'login');
        Route::get('session', 'checkSession');
        Route::post('session/refresh', 'refreshSession');
        Route::post('logout', 'logout');
    });

    Route::middleware('portal.session')->group(function (): void {
        Route::controller(PortalDataController::class)->group(function (): void {
            Route::get('dashboard', 'dashboard');
            Route::get('profile', 'profile');
            Route::get('class', 'mergedClasses');
            Route::get('class/{id}', 'classDetail');
            Route::get('meeting/{meeting_id}', 'meetingDetail');
            Route::prefix('lms')->group(function (): void {
                Route::get('class', 'classes');
                Route::get('flow', 'sipandaiFlow');
            });
            Route::prefix('schedule')->group(function (): void {
                Route::get('class', 'studentCourse');
            });
        });

        Route::prefix('assignment')->controller(PortalActionController::class)->group(function (): void {
            Route::get('pending', 'pendingAssignments');
            Route::post('', 'uploadAssignment');
            Route::delete('', 'deleteAssignment');
        });

        Route::get('attendance/today', [PortalActionController::class, 'todayAttendanceShortcuts']);
        Route::post('attendance', [PortalActionController::class, 'markAttendance']);

        Route::prefix('exam')->controller(PortalActionController::class)->group(function (): void {
            Route::get('', 'listExams');
            Route::post('', 'uploadExam');
            Route::get('{id}', 'examDetail');
        });
    });

    Route::prefix('support')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('parameter-help', 'parameterHelp');
        Route::get('validate-parameters', 'validateParameters');
    });

    Route::prefix('metadata')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('endpoints', 'endpoints');
    });
});
