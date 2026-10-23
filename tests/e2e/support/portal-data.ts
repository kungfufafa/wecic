import { todayInJakarta, type ApiMock } from './fixtures';

/**
 * Answers shaped like the app's API (PortalDataController and friends): a
 * signed-in student with one class and one exam, nothing pending.
 */
export const student = { name: 'Mahasiswa E2E', nim: 'e2e-mahasiswa' };

export type TodayClass = {
    course_name: string;
    course_code?: string;
    time?: string;
    room?: string;
    attendance: { actionable: boolean; state: string; meeting_url?: string };
};

export function success(fields: Record<string, unknown>): Record<string, unknown> {
    return {
        status: 'success',
        message: 'OK',
        ...fields,
        meta: { timestamp: new Date().toISOString(), campus_session_valid: true },
    };
}

/**
 * GET /api/dashboard with today's schedule (`classes_summary`), dated today
 * where the browser runs, as PortalDataController::dashboard serves it.
 */
export function dashboard(todayClasses: TodayClass[]): Record<string, unknown> {
    const day = new Intl.DateTimeFormat('id-ID', { weekday: 'long', timeZone: 'Asia/Jakarta' }).format(new Date());

    return success({
        data: {
            user_info: student,
            classes_summary: {
                date: todayInJakarta(),
                day: day.charAt(0).toUpperCase() + day.slice(1),
                total_classes: 1,
                today_classes: todayClasses,
            },
            pending_sources: [],
        },
    });
}

/**
 * Everything the signed-in pages the specs open read on load (the layout's
 * badges, the dashboard, the class and exam lists, the offline outbox).
 * `todayClasses` is today's schedule on the dashboard.
 */
export function answerSignedInPages(api: ApiMock, { todayClasses = [] as TodayClass[] } = {}): ApiMock {
    return api
        .answer('GET', '/api/auth/session', { json: success({ data: { user_info: student, offline: false, offline_reconnect: null } }) })
        .answer('GET', '/api/notifications/unread-count', { json: success({ data: { unread_count: 0 } }) })
        .answer('GET', '/api/action-items', { json: success({ data: { items: [] } }) })
        .answer('GET', '/api/announcement', { json: success({ data: { items: [] } }) })
        .answer('GET', '/api/assignment/pending', { json: success({ data: { assignments: [] } }) })
        .answer('GET', '/api/dashboard', { json: dashboard(todayClasses) })
        .answer('GET', '/api/outbox', { json: success({ data: { items: [], queued_count: 0, offline: false, enabled: true } }) })
        .answer('GET', '/api/class', {
            json: success({
                user_info: student,
                classes: [{
                    course_info: { code: 'IF301', name: 'Pemrograman Web' },
                    lecturer_info: { name: 'Dosen E2E' },
                    schedule: { day: 'Senin', start_time: '08:00', end_time: '09:40' },
                    class_room_info: { room: 'Lab 1' },
                    join_link: { params: { id: '4242', dosen_id: '7', course_id: '4242' } },
                }],
                semester_info: {},
                data_sources: [],
            }),
        })
        .answer('GET', '/api/exam', {
            json: success({
                data: {
                    exams: [{ course_code: 'IF301', course_name: 'Pemrograman Web', lecturer: 'Dosen E2E', schedule: '-', status: 'Belum Upload' }],
                },
            }),
        });
}
