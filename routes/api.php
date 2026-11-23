<?php

declare(strict_types=1);

use App\Http\Controllers\API\NotebookAiConsentController;
use App\Http\Controllers\API\NotebookController;
use App\Http\Controllers\API\PortalAcademicController;
use App\Http\Controllers\API\PortalAccountController;
use App\Http\Controllers\API\PortalActionCenterController;
use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalAnnouncementController;
use App\Http\Controllers\API\PortalArchiveController;
use App\Http\Controllers\API\PortalAuthController;
use App\Http\Controllers\API\PortalDataController;
use App\Http\Controllers\API\PortalDeviceController;
use App\Http\Controllers\API\PortalLibraryController;
use App\Http\Controllers\API\PortalNotificationController;
use App\Http\Controllers\API\PortalOperationController;
use App\Http\Controllers\API\PortalOutboxController;
use App\Http\Controllers\API\PortalSupportController;
use App\Http\Controllers\API\PortalThesisController;
use Illuminate\Support\Facades\Route;

// Session-aware middleware stack for campus API routes: the web app's cookie
// session (with CSRF), or the session a mobile app's bearer token names
// (StartPortalSession, no cookie and no CSRF).
$sessionMiddleware = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \App\Http\Middleware\StartPortalSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \App\Http\Middleware\VerifyCsrfTokenMiddleware::class,
];

// The mobile app's login: always in token mode, so a cookie the request
// carries is never read or replaced.
Route::post('auth/token', [PortalAuthController::class, 'issueToken'])
    ->middleware([
        \App\Http\Middleware\StartPortalSession::class.':token',
        \App\Http\Middleware\VerifyCsrfTokenMiddleware::class,
        'throttle:portal-login',
    ]);

// Proxied endpoints use the original upstream portal parameter names as primary input.
// Compatibility aliases may still be accepted in request classes for older clients.
Route::middleware($sessionMiddleware)->group(function () {
    Route::prefix('auth')->controller(PortalAuthController::class)->group(function (): void {
        Route::post('login', 'login')->middleware('throttle:portal-login');
        Route::get('session', 'checkSession');
        Route::post('session/refresh', 'refreshSession')->middleware('throttle:portal-action');
        // Never throttled: a logout refused for spent action budget would leave the session open.
        Route::post('logout', 'logout');
    });

    // Echo's authEndpoint for API clients: signs the session's own channel only (routes/channels.php).
    Route::post('broadcasting/auth', [PortalDeviceController::class, 'authorizeChannel'])
        ->middleware([\App\Http\Middleware\ResolvePortalBroadcastUser::class, 'throttle:portal-channel-auth']);

    Route::middleware('portal.session')->group(function (): void {
        // An offline session (portal unreachable at login) reads the archive
        // and queues attendance and uploads in the outbox; these need the portal.
        Route::prefix('auth')->middleware('portal.online')->controller(PortalAuthController::class)->group(function (): void {
            Route::get('password/form', 'passwordForm');
            Route::post('password', 'changePassword')->middleware('throttle:portal-action');
        });

        Route::controller(PortalDataController::class)->group(function (): void {
            Route::post('refresh', 'refresh')->middleware(['portal.online', 'throttle:portal-action']);
            Route::get('dashboard', 'dashboard');
            Route::get('profile', 'profile');
            Route::get('class', 'mergedClasses');
            // Reads that make the portal build a page for any id (or ?fresh=1) are throttled.
            Route::get('class/{id}', 'classDetail')->middleware('throttle:portal-read');
            Route::get('meeting/{meeting_id}', 'meetingDetail')->middleware('throttle:portal-read');
            Route::prefix('lms')->group(function (): void {
                Route::get('class', 'classes');
                Route::get('flow', 'sipandaiFlow')->middleware(['portal.online', 'throttle:portal-read']);
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

        // Local bookkeeping only: the app installs that get notifications as push.
        Route::prefix('devices')->controller(PortalDeviceController::class)->group(function (): void {
            Route::post('push-token', 'registerPushToken');
            Route::delete('push-token', 'forgetPushToken');
        });

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

        // Uploads and attendance are deadline-bound: an offline session queues
        // them in the outbox instead of being refused (see PortalOutbox).
        Route::prefix('assignment')->controller(PortalActionController::class)->group(function (): void {
            Route::get('pending', 'pendingAssignments');
            Route::post('', 'uploadAssignment')->middleware('throttle:portal-action');
            Route::delete('', 'deleteAssignment')->middleware(['portal.online', 'throttle:portal-action']);
        });

        Route::get('operations/{operation}', [PortalOperationController::class, 'show'])->whereUuid('operation');

        Route::get('attendance/today', [PortalActionController::class, 'todayAttendanceShortcuts']);
        Route::post('attendance', [PortalActionController::class, 'markAttendance'])->middleware('throttle:portal-action');

        Route::prefix('exam')->controller(PortalActionController::class)->group(function (): void {
            Route::get('', 'listExams');
            Route::post('', 'uploadExam')->middleware('throttle:portal-action');
            Route::get('{id}', 'examDetail');
        });

        // Local bookkeeping only: the queue is read from, and cancelled in, MyCIC.
        Route::prefix('outbox')->controller(PortalOutboxController::class)->group(function (): void {
            Route::get('', 'index');
            Route::delete('{item}', 'destroy')->whereNumber('item');
        });

        // Deletes everything WeCIC keeps for the student and ends all their sessions;
        // works in offline mode too.
        Route::delete('account', [PortalAccountController::class, 'destroy'])->middleware('throttle:portal-action');

        Route::prefix('archive')->controller(PortalArchiveController::class)->group(function (): void {
            Route::get('', 'index');
            Route::delete('', 'destroy')->middleware('throttle:portal-action');
            Route::get('background-sync', 'backgroundSyncStatus');
            // Switches the stored credentials on (password checked, see the controller) or off.
            Route::patch('background-sync', 'backgroundSync')->middleware('throttle:portal-action');
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

        // Consent to send Notebook content to the AI provider: the AI parts of the
        // notebook refuse with `ai_consent_required` until it is given.
        Route::prefix('ai-consent')->controller(NotebookAiConsentController::class)->group(function (): void {
            Route::get('', 'show');
            Route::post('', 'store')->middleware('throttle:portal-action');
            Route::delete('', 'destroy')->middleware('throttle:portal-action');
        });

        // The notebook reads stored portal data, so it also works in offline mode
        // (adding a meeting that was never opened is the one read that needs the portal).
        Route::prefix('notebooks')->controller(NotebookController::class)->group(function (): void {
            Route::get('', 'index');
            Route::post('', 'store');
            Route::post('course', 'storeCourse');
            Route::post('meetings', 'addMeeting')->middleware('throttle:notebook-sources');
            Route::get('{notebook}', 'show')->whereNumber('notebook');
            Route::get('{notebook}/meetings', 'meetings')->whereNumber('notebook');
            Route::post('{notebook}/meetings/sync', 'syncMeetings')->whereNumber('notebook')->middleware(['portal.online', 'throttle:portal-action']);
            Route::patch('{notebook}', 'update')->whereNumber('notebook');
            Route::delete('{notebook}', 'destroy')->whereNumber('notebook');
            Route::post('{notebook}/sources', 'storeSource')->whereNumber('notebook')->middleware('throttle:notebook-sources');
            Route::post('{notebook}/sources/{source}/retry', 'retrySource')->whereNumber(['notebook', 'source'])->middleware('throttle:notebook-sources');
            Route::get('{notebook}/sources/{source}/chunks', 'sourceChunks')->whereNumber(['notebook', 'source'])->middleware('throttle:notebook-sources');
            Route::delete('{notebook}/sources/{source}', 'destroySource')->whereNumber(['notebook', 'source']);
            Route::post('{notebook}/messages', 'ask')->whereNumber('notebook')->middleware('throttle:notebook-ai');
            Route::post('{notebook}/studio', 'studio')->whereNumber('notebook')->middleware('throttle:notebook-ai');
            Route::patch('{notebook}/messages/{message}', 'updateMessage')->whereNumber(['notebook', 'message']);
            Route::delete('{notebook}/messages/{message}', 'destroyMessage')->whereNumber(['notebook', 'message']);
        });
    });

    // Opened as a page (a new tab), not fetched: without a session or in
    // offline mode a navigation gets the login page or a short error page
    // instead of JSON ('page' mode of both middleware).
    Route::get('support/download', [PortalSupportController::class, 'downloadFile'])
        ->middleware(['portal.session:page', 'portal.online:page']);

    Route::prefix('support')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('parameter-help', 'parameterHelp');
        Route::get('validate-parameters', 'validateParameters');
    });

    Route::prefix('metadata')->controller(PortalSupportController::class)->group(function (): void {
        Route::get('endpoints', 'endpoints');
    });
});
