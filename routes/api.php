<?php

declare(strict_types=1);

use App\Http\Controllers\API\NotebookController;
use App\Http\Controllers\API\PortalAcademicController;
use App\Http\Controllers\API\PortalActionCenterController;
use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalAnnouncementController;
use App\Http\Controllers\API\PortalArchiveController;
use App\Http\Controllers\API\PortalAuthController;
use App\Http\Controllers\API\PortalDataController;
use App\Http\Controllers\API\PortalLibraryController;
use App\Http\Controllers\API\PortalNotificationController;
use App\Http\Controllers\API\PortalOperationController;
use App\Http\Controllers\API\PortalSupportController;
use App\Http\Controllers\API\PortalThesisController;
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
        // An offline session (portal unreachable at login) only reads the archive.
        Route::prefix('auth')->middleware('portal.online')->controller(PortalAuthController::class)->group(function (): void {
            Route::get('password/form', 'passwordForm');
            Route::post('password', 'changePassword')->middleware('throttle:portal-action');
        });

        Route::controller(PortalDataController::class)->group(function (): void {
            Route::post('refresh', 'refresh')->middleware(['portal.online', 'throttle:portal-action']);
            Route::get('dashboard', 'dashboard');
            Route::get('profile', 'profile');
            Route::get('class', 'mergedClasses');
            Route::get('class/{id}', 'classDetail');
            Route::get('meeting/{meeting_id}', 'meetingDetail');
            Route::prefix('lms')->group(function (): void {
                Route::get('class', 'classes');
                Route::get('flow', 'sipandaiFlow')->middleware('portal.online');
            });
            Route::prefix('schedule')->group(function (): void {
                Route::get('class', 'studentCourse');
            });
            Route::prefix('finance')->group(function (): void {
                Route::get('payments', 'paymentHistory');
                Route::get('summary', 'financeSummary');
            });
            Route::get('portal/context', 'portalContext');
            Route::get('skkm', 'skkm');
        });

        Route::get('action-items', [PortalActionCenterController::class, 'index']);

        Route::prefix('notifications')->controller(PortalNotificationController::class)->group(function (): void {
            Route::get('', 'index');
            Route::get('unread-count', 'unreadCount');
            // Local bookkeeping only: must not spend the portal-action budget meant for attendance and uploads.
            Route::post('read', 'markRead');
        });

        Route::prefix('academic')->controller(PortalAcademicController::class)->group(function (): void {
            Route::get('krs', 'krs');
            Route::get('advising', 'advising');
            // Registered after any future `krs/print/official` route; only numeric ids match.
            Route::get('krs/{id}', 'krsDetail')->whereNumber('id');
            Route::get('grades', 'grades');
            Route::get('transcript', 'transcript');
        });

        Route::prefix('thesis')->controller(PortalThesisController::class)->group(function (): void {
            Route::get('', 'index');
            Route::get('readiness', 'readiness');
        });

        Route::prefix('library')->controller(PortalLibraryController::class)->group(function (): void {
            Route::get('', 'index');
            Route::get('theses', 'theses');
        });

        Route::prefix('announcement')->controller(PortalAnnouncementController::class)->group(function (): void {
            Route::get('', 'index');
            Route::get('{id}', 'show')->where('id', '[0-9a-f]{40}');
        });

        Route::prefix('assignment')->controller(PortalActionController::class)->group(function (): void {
            Route::get('pending', 'pendingAssignments');
            Route::post('', 'uploadAssignment')->middleware(['portal.online', 'throttle:portal-action']);
            Route::delete('', 'deleteAssignment')->middleware(['portal.online', 'throttle:portal-action']);
        });

        Route::get('operations/{operation}', [PortalOperationController::class, 'show'])->whereUuid('operation');

        Route::get('attendance/today', [PortalActionController::class, 'todayAttendanceShortcuts']);
        Route::post('attendance', [PortalActionController::class, 'markAttendance'])->middleware(['portal.online', 'throttle:portal-action']);

        Route::prefix('exam')->controller(PortalActionController::class)->group(function (): void {
            Route::get('', 'listExams');
            Route::post('', 'uploadExam')->middleware(['portal.online', 'throttle:portal-action']);
            Route::get('{id}', 'examDetail');
        });

        Route::get('support/download', [PortalSupportController::class, 'downloadFile'])->middleware('portal.online');

        Route::prefix('archive')->controller(PortalArchiveController::class)->group(function (): void {
            Route::get('', 'index');
            Route::delete('', 'destroy')->middleware('throttle:portal-action');
            Route::get('files', 'files');
            Route::post('files/sync', 'archiveClass')->middleware(['portal.online', 'throttle:portal-action']);
            Route::get('files/{file}', 'file')->whereNumber('file');
            Route::get('versions/{version}', 'show')->whereNumber('version');
            Route::get('versions/{version}/export', 'export')->whereNumber('version');
            // Local bookkeeping only, like marking notifications read.
            Route::post('versions/{version}/accept', 'accept')->whereNumber('version');
            Route::post('versions/{version}/reject', 'reject')->whereNumber('version');
            Route::get('{dataset}', 'versions')->where('dataset', '[a-z_]+');
        });

        // The notebook reads stored portal data, so it also works in offline mode
        // (adding a meeting that was never opened is the one read that needs the portal).
        Route::prefix('notebooks')->controller(NotebookController::class)->group(function (): void {
            Route::get('', 'index');
            Route::post('', 'store');
            Route::post('course', 'storeCourse');
            Route::post('meetings', 'addMeeting');
            Route::get('{notebook}', 'show')->whereNumber('notebook');
            Route::get('{notebook}/meetings', 'meetings')->whereNumber('notebook');
            Route::post('{notebook}/meetings/sync', 'syncMeetings')->whereNumber('notebook')->middleware(['portal.online', 'throttle:portal-action']);
            Route::patch('{notebook}', 'update')->whereNumber('notebook');
            Route::delete('{notebook}', 'destroy')->whereNumber('notebook');
            Route::post('{notebook}/sources', 'storeSource')->whereNumber('notebook');
            Route::post('{notebook}/sources/{source}/retry', 'retrySource')->whereNumber(['notebook', 'source']);
            Route::delete('{notebook}/sources/{source}', 'destroySource')->whereNumber(['notebook', 'source']);
            Route::post('{notebook}/messages', 'ask')->whereNumber('notebook')->middleware('throttle:notebook-ai');
            Route::post('{notebook}/studio', 'studio')->whereNumber('notebook')->middleware('throttle:notebook-ai');
            Route::patch('{notebook}/messages/{message}', 'updateMessage')->whereNumber(['notebook', 'message']);
            Route::delete('{notebook}/messages/{message}', 'destroyMessage')->whereNumber(['notebook', 'message']);
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
