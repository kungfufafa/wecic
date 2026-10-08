<?php

declare(strict_types=1);

use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalAuthController;
use App\Http\Controllers\API\PortalDataController;
use App\Http\Controllers\API\PortalOperationController;
use App\Http\Controllers\API\PortalSupportController;
use Illuminate\Support\Facades\Route;

// Session-aware middleware stack for campus API routes (cookie + session + CSRF).
$sessionMiddleware = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \App\Http\Middleware\VerifyCsrfTokenMiddleware::class,
];

// Proxied endpoints use the original upstream portal parameter names as primary input.
// Compatibility aliases may still be accepted in request classes for older clients.
Route::middleware($sessionMiddleware)->group(function () {
    Route::prefix('auth')->controller(PortalAuthController::class)->group(function (): void {
        Route::post('login', 'login')->middleware('throttle:portal-login');
        Route::get('session', 'checkSession');
        Route::post('session/refresh', 'refreshSession')->middleware('throttle:portal-action');
        Route::post('logout', 'logout')->middleware('throttle:portal-action');
    });

    Route::middleware('portal.session')->group(function (): void {
        Route::prefix('auth')->controller(PortalAuthController::class)->group(function (): void {
            Route::get('password/form', 'passwordForm');
            Route::post('password', 'changePassword')->middleware('throttle:portal-action');
        });

        Route::controller(PortalDataController::class)->group(function (): void {
            Route::post('refresh', 'refresh')->middleware('throttle:portal-action');
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
            Route::prefix('finance')->group(function (): void {
                Route::get('payments', 'paymentHistory');
            });
        });

        Route::prefix('assignment')->controller(PortalActionController::class)->group(function (): void {
            Route::get('pending', 'pendingAssignments');
            Route::post('', 'uploadAssignment')->middleware('throttle:portal-action');
            Route::delete('', 'deleteAssignment')->middleware('throttle:portal-action');
        });

        Route::get('operations/{operation}', [PortalOperationController::class, 'show'])->whereUuid('operation');

        Route::get('attendance/today', [PortalActionController::class, 'todayAttendanceShortcuts']);
        Route::post('attendance', [PortalActionController::class, 'markAttendance'])->middleware('throttle:portal-action');

        Route::prefix('exam')->controller(PortalActionController::class)->group(function (): void {
            Route::get('', 'listExams');
            Route::post('', 'uploadExam')->middleware('throttle:portal-action');
            Route::get('{id}', 'examDetail');
        });

        Route::get('support/download', [PortalSupportController::class, 'downloadFile']);
    });

    Route::prefix('support')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('parameter-help', 'parameterHelp');
        Route::get('validate-parameters', 'validateParameters');
    });

    Route::prefix('metadata')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('endpoints', 'endpoints');
    });
});
