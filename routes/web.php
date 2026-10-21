<?php

declare(strict_types=1);

use App\Services\PortalSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (PortalSessionManager $sessionManager) {
    $sessionToken = session('campus_session_token');

    if (! $sessionToken) {
        return redirect('/login');
    }

    try {
        if ($sessionManager->validateSession($sessionToken)) {
            return redirect('/dashboard');
        }
    } catch (\Throwable $e) {
        // Fall through to login and clear stale session state.
    }

    session()->forget(['campus_session_token', 'campus_user_info']);

    return redirect('/login');
});

Route::get('/login', function (PortalSessionManager $sessionManager) {
    $sessionToken = session('campus_session_token');

    if (! $sessionToken) {
        return view('auth.login');
    }

    try {
        if ($sessionManager->validateSession($sessionToken)) {
            return redirect('/dashboard');
        }
    } catch (\Throwable $e) {
        // Render login below after clearing stale session state.
    }

    session()->forget(['campus_session_token', 'campus_user_info']);

    return view('auth.login');
})->name('login');

Route::middleware('portal.web')->group(function (): void {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/profile', 'profile')->name('profile');
    Route::view('/finance', 'finance.index')->name('finance.index');
    Route::view('/password', 'auth.password')->name('password.edit');
    Route::view('/class', 'classes.index')->name('class.index');
    Route::get('/class/{id}', function (Request $request, string $id) {
        return view('classes.show', [
            'id' => $id,
            'dosenId' => $request->query('dosen_id'),
            'courseId' => $request->query('course_id'),
        ]);
    })->name('class.show');

    Route::get('/meeting/{meeting_id}', function (Request $request, string $meeting_id) {
        return view('meetings.show', [
            'meetingId' => $meeting_id,
            'coursesId' => $request->query('courses_id', $request->query('course_id')),
        ]);
    })->name('meeting.show');

    Route::view('/exam', 'exams.index')->name('exam.index');
    Route::get('/exam/{id}', function (string $id) {
        return view('exams.show', ['id' => $id]);
    })->name('exam.show');

    Route::view('/krs', 'academic.krs')->name('krs.index');
    Route::get('/krs/{id}', function (string $id) {
        return view('academic.krs-show', ['id' => $id]);
    })->whereNumber('id')->name('krs.show');
    Route::view('/grades', 'academic.grades')->name('grades.index');
    Route::view('/transcript', 'academic.transcript')->name('transcript.index');

    Route::view('/announcement', 'announcements.index')->name('announcement.index');
    Route::get('/announcement/{id}', function (string $id) {
        return view('announcements.show', ['id' => $id]);
    })->where('id', '[0-9a-f]{40}')->name('announcement.show');

    Route::view('/skkm', 'skkm.index')->name('skkm.index');
    Route::view('/more', 'more')->name('more');
});
