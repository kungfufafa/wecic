<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The time-based reminders (PortalNotificationCenter inputs) built from the
 * action center and today's classes. Shared by the reminder scan of an open
 * session (ScanPortalNotificationsJob) and the one for a student whose app
 * is closed (PortalAwayReminders): a reminder has the same dedupe key
 * whichever found it, so it is never sent twice.
 *
 * @phpstan-type Reminder array{type: string, dataset: string|null, record_key: string, dedupe_key: string, title: string, body: string|null, url: string, urgency: string}
 */
class PortalReminderBuilder
{
    private const REMINDER_SEVERITIES = ['critical', 'warning'];

    /**
     * Action center items with a due date that turned warning or critical:
     * at most one reminder per item, due date and severity.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<Reminder>
     */
    public function deadlineReminders(array $items): array
    {
        $reminders = [];

        foreach ($items as $item) {
            $dueAt = $item['due_at'] ?? null;
            $severity = $item['severity'] ?? null;

            if (! is_string($dueAt) || $dueAt === '' || ! in_array($severity, self::REMINDER_SEVERITIES, true)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');

            $reminders[] = [
                'type' => 'deadline_reminder',
                'dataset' => is_string($item['source'] ?? null) ? $item['source'] : null,
                'record_key' => $id,
                'dedupe_key' => hash('sha256', implode("\0", ['reminder', $id, $dueAt, $severity])),
                'title' => (string) ($item['title'] ?? 'Tenggat mendekat'),
                'body' => is_string($item['description'] ?? null) ? $item['description'] : null,
                'url' => ($item['cta_external'] ?? false) === true ? '/dashboard' : $this->openableUrl((string) ($item['cta_url'] ?? '')),
                'urgency' => $severity,
            ];
        }

        return $reminders;
    }

    /**
     * Classes in session now whose attendance has not been sent yet (today's
     * attendance shortcuts, read from the portal).
     *
     * @param  list<array<string, mixed>>  $classes
     * @return list<Reminder>
     */
    public function attendanceReminders(array $classes): array
    {
        $reminders = [];

        foreach ($classes as $class) {
            $meetingId = (string) ($class['meeting_id'] ?? '');

            if ($meetingId === ''
                || empty($class['attendance_payload'])
                || ($class['active_now'] ?? false) !== true
                || ($class['attendance_status']['already_marked'] ?? false) === true) {
                continue;
            }

            $courseName = trim((string) ($class['course_name'] ?? ''));
            $coursesId = (string) ($class['attendance_payload']['courseid'] ?? '');

            $reminders[] = [
                'type' => 'attendance_open',
                'dataset' => null,
                'record_key' => $meetingId,
                'dedupe_key' => hash('sha256', implode("\0", ['attendance', $meetingId])),
                'title' => $courseName !== '' ? "Absen {$courseName} sekarang" : 'Absen kelas yang sedang berlangsung',
                'body' => 'Kelas sedang berlangsung dan absensimu belum tercatat. Kirim dari MyCIC.',
                'url' => $this->openableUrl('/meeting/'.rawurlencode($meetingId).($coursesId !== '' ? '?courses_id='.rawurlencode($coursesId) : '')),
                'urgency' => 'warning',
            ];
        }

        return $reminders;
    }

    /**
     * Classes of today's schedule in session now (`today_classes` of a
     * dashboard snapshot whose `active_now` was decided at this moment).
     * Without the portal it is not known whether attendance is open or
     * already sent, so this only says the class started: one reminder per
     * class and day.
     *
     * @param  list<mixed>  $todayClasses
     * @return list<Reminder>
     */
    public function classStartedReminders(array $todayClasses, string $date): array
    {
        $reminders = [];

        foreach ($todayClasses as $class) {
            if (! is_array($class) || ($class['active_now'] ?? false) !== true) {
                continue;
            }

            $courseName = trim((string) ($class['course_name'] ?? ''));
            $startTime = (string) ($class['schedule']['start_time'] ?? '');
            $classKey = implode('|', [$class['class_id'] ?? '', $class['course_id'] ?? '', $courseName, $startTime]);
            $room = trim((string) ($class['room'] ?? ''));

            $reminders[] = [
                'type' => 'class_started',
                'dataset' => null,
                'record_key' => mb_substr($date.'|'.$classKey, 0, 255),
                'dedupe_key' => hash('sha256', implode("\0", ['class_started', $date, $classKey])),
                'title' => $courseName !== '' ? "Kelas {$courseName} sudah dimulai" : 'Kelasmu sudah dimulai',
                'body' => ($room !== '' ? "Ruang {$room}. " : '').'Buka MyCIC untuk cek dan kirim absensimu.',
                'url' => '/dashboard',
                'urgency' => 'info',
            ];
        }

        return $reminders;
    }

    /**
     * A meeting page cannot open without its `courses_id`; such a link falls
     * back to the dashboard, which lists the same class and deadline.
     */
    private function openableUrl(string $url): string
    {
        if ($url === '') {
            return '/dashboard';
        }

        if (str_starts_with($url, '/meeting/')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (trim((string) ($query['courses_id'] ?? '')) === '') {
                return '/dashboard';
            }
        }

        return $url;
    }
}
