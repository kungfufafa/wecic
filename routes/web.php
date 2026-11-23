<?php

declare(strict_types=1);

use App\Services\PortalAnnouncementIds;
use App\Services\PortalDataArchive;
use App\Services\PortalDataOwner;
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
    } catch (\Throwable) {
        // A failed check is not an ended session: the dashboard answers it
        // with a retry page (EnsurePortalWebSession).
        return redirect('/dashboard');
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
    } catch (\Throwable) {
        // A failed check is not an ended session: the form stays usable,
        // but the device keeps its offline copies.
        return view('auth.login', ['sessionUnchecked' => true]);
    }

    session()->forget(['campus_session_token', 'campus_user_info']);

    return view('auth.login');
})->name('login');

Route::view('/privasi', 'legal.privacy')->name('legal.privacy');
Route::view('/bantuan', 'legal.support')->name('legal.support');
Route::view('/hapus-data', 'legal.data-deletion')->name('legal.data-deletion');

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
    /**
     * One announcement. An old link (a wording-based id from before stable
     * ids, kept in notifications) redirects to the id the announcement goes
     * by now, known from the feed and the archive; the student's
     * notifications with that link follow it.
     */
    Route::get('/announcement/{id}', function (Request $request, PortalAnnouncementIds $announcementIds, string $id) {
        $sessionToken = (string) session('campus_session_token');
        $currentId = $announcementIds->currentId($sessionToken, $id);

        if ($currentId !== null) {
            $announcementIds->follow($sessionToken, $id, $currentId);
            $query = $request->getQueryString();

            return redirect()->to(route('announcement.show', ['id' => $currentId]).($query !== null ? '?'.$query : ''));
        }

        return view('announcements.show', ['id' => $id]);
    })->where('id', '[0-9a-f]{40}')->name('announcement.show');

    Route::view('/skkm', 'skkm.index')->name('skkm.index');
    Route::view('/thesis', 'thesis.index')->name('thesis.index');
    Route::view('/library', 'library.index')->name('library.index');
    Route::view('/library/theses', 'library.theses')->name('library.theses');
    Route::view('/more', 'more')->name('more');
    Route::view('/account/delete', 'account.delete')->name('account.delete');
    Route::view('/notifications', 'notifications.index')->name('notifications.index');

    Route::view('/notebook', 'notebook.index')->name('notebook.index');
    Route::view('/notebook/ulasan', 'notebook.review')->name('notebook.review');
    Route::get('/notebook/{id}', fn (string $id) => view('notebook.show', ['notebookId' => (int) $id]))
        ->whereNumber('id')
        ->name('notebook.show');

    Route::view('/archive', 'archive.index')->name('archive.index');
    Route::get('/archive/versions/{version}/print', function (PortalDataArchive $archive, PortalDataOwner $owner, string $version) {
        $archived = $archive->findForOwner($owner->key((string) session('campus_session_token')), (int) $version);

        abort_if($archived === null, 404);

        $baseline = $archive->baseline($archived);

        return view('archive.print', [
            'version' => $archived,
            'label' => $archive->label($archived->dataset),
            'baseline' => $baseline,
            'diff' => $baseline !== null
                ? $archive->compare($baseline->payload, $archived->payload, 2000, $archived->dataset)
                : ['changes' => [], 'total' => 0, 'truncated' => false],
            'entries' => $archive->compare([], $archived->payload, null),
        ]);
    })->whereNumber('version')->name('archive.print');
    /**
     * The history of one dataset. A link to one version without its class,
     * meeting or KRS (`params_hash`), such as an older notification, opens
     * the one that version belongs to.
     */
    Route::get('/archive/{dataset}', function (Request $request, PortalDataArchive $archive, PortalDataOwner $owner, string $dataset) {
        abort_unless($archive->archives($dataset), 404);

        $paramsHash = $request->query('params_hash');
        $versionId = $request->query('version');

        if (! is_string($paramsHash) && is_string($versionId) && ctype_digit($versionId)) {
            $linked = $archive->findForOwner($owner->key((string) session('campus_session_token')), (int) $versionId);
            $paramsHash = $linked?->dataset === $dataset ? $linked->params_hash : null;
        }

        return view('archive.show', [
            'dataset' => $dataset,
            'label' => $archive->label($dataset),
            'paramsHash' => is_string($paramsHash) && preg_match('/^[0-9a-f]{32}$/', $paramsHash) === 1 ? $paramsHash : null,
        ]);
    })->where('dataset', '[a-z_]+')->name('archive.show');
});
