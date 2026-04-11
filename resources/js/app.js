class PortalApp {
    constructor() {
        this.campusBase = document.documentElement.dataset.campusBase || window.location.origin;
        this.noticeTimeout = null;
    }

    init() {
        this.restoreNotice();
        this.bindLogout();

        if (document.body.dataset.auth === 'guest') {
            return;
        }

        if (document.body.dataset.auth === 'required') {
            this.ensureAuthenticated();
        }
    }

    async request(url, options = {}) {
        const config = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(options.headers || {}),
            },
        };

        if (options.json !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(options.json);
        }

        if (options.formData instanceof FormData) {
            config.body = options.formData;
        }

        const response = await fetch(url, config);
        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json')
            ? await response.json()
            : null;

        if (response.status === 401 && options.redirectOnAuth !== false && document.body.dataset.auth === 'required') {
            this.storeNotice(payload?.message || 'Sesi kampus sudah berakhir.', 'warning');
            window.location.href = '/login';

            return null;
        }

        if (!response.ok) {
            const error = new Error(payload?.message || `Request failed with status ${response.status}.`);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    }

    async ensureAuthenticated() {
        try {
            const payload = await this.request('/api/auth/session');

            if (!payload) {
                return null;
            }

            this.hydrateUser(payload);

            return payload;
        } catch (error) {
            this.showNotice(error.payload?.message || error.message, 'error');

            return null;
        }
    }

    hydrateUser(payload) {
        const userInfo = payload?.data?.user_info || {};
        const userName = userInfo.name || userInfo.nama || userInfo.nama_lengkap || 'Portal Mahasiswa';
        const userIdentity = userInfo.student_id || userInfo.nim || userInfo.username || '';

        document.querySelectorAll('[data-user-name]').forEach((element) => {
            element.textContent = userName;
        });

        document.querySelectorAll('[data-user-identity]').forEach((element) => {
            element.textContent = userIdentity;
            element.classList.toggle('hidden', userIdentity === '');
        });

        document.querySelectorAll('[data-session-state]').forEach((element) => {
            element.textContent = 'Aktif';
        });
    }

    bindLogout() {
        document.querySelectorAll('[data-logout]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();

                button.setAttribute('disabled', 'disabled');

                try {
                    const payload = await this.request('/api/auth/logout', {
                        method: 'POST',
                        json: {},
                        redirectOnAuth: false,
                    });

                    this.storeNotice(payload?.message || 'Logout berhasil.', 'success');
                } catch (error) {
                    this.storeNotice(error.payload?.message || 'Logout diproses lokal.', 'warning');
                }

                window.location.href = '/login';
            });
        });
    }

    storeNotice(message, type = 'info') {
        sessionStorage.setItem('portal-notice', JSON.stringify({ message, type }));
    }

    restoreNotice() {
        const rawNotice = sessionStorage.getItem('portal-notice');

        if (!rawNotice) {
            return;
        }

        sessionStorage.removeItem('portal-notice');

        try {
            const notice = JSON.parse(rawNotice);
            this.showNotice(notice.message, notice.type);
        } catch (error) {
            this.showNotice(rawNotice, 'info');
        }
    }

    showNotice(message, type = 'info') {
        const host = document.getElementById('notice-host');

        if (!host) {
            return;
        }

        const toneMap = {
            success: 'var(--notice-success)',
            error: 'var(--notice-error)',
            warning: 'var(--notice-warning)',
            info: 'var(--notice-info)',
        };

        host.innerHTML = '';

        const notice = document.createElement('div');
        notice.className = 'notice-shell';
        notice.style.setProperty('--notice-accent', toneMap[type] || toneMap.info);
        notice.innerHTML = `
            <div class="notice-copy">${this.escapeHtml(message)}</div>
            <button type="button" class="notice-close" aria-label="Tutup notifikasi">Tutup</button>
        `;

        host.appendChild(notice);
        notice.querySelector('.notice-close')?.addEventListener('click', () => {
            host.innerHTML = '';
        });

        window.clearTimeout(this.noticeTimeout);
        this.noticeTimeout = window.setTimeout(() => {
            host.innerHTML = '';
        }, 4200);
    }

    absoluteCampusUrl(path) {
        if (!path) {
            return null;
        }

        const rawPath = String(path).trim();

        if (rawPath === '') {
            return null;
        }

        let normalizedPath = rawPath;

        try {
            const parsed = new URL(rawPath);

            if (parsed.origin === this.campusBase && /^\/files\//i.test(parsed.pathname)) {
                parsed.pathname = `/portal${parsed.pathname}`;

                return parsed.toString();
            }

            return parsed.toString();
        } catch (error) {
            // Continue normalizing relative paths below.
        }

        if (/^(?:(?:\.\.\/)+|\.\/)?files\//i.test(normalizedPath)) {
            normalizedPath = `/portal/${normalizedPath.replace(/^(?:(?:\.\.\/)+|\.\/)?/i, '')}`;
        } else if (/^\/files\//i.test(normalizedPath)) {
            normalizedPath = `/portal${normalizedPath}`;
        }

        try {
            return new URL(normalizedPath, this.campusBase).toString();
        } catch (error) {
            return normalizedPath;
        }
    }

    buildMeetingUrlFromHref(href) {
        if (!href) {
            return null;
        }

        const query = href.includes('?') ? href.split('?')[1] : href;
        const parameters = new URLSearchParams(query);
        const meetingId = parameters.get('id');
        const coursesId = parameters.get('courses_id') || parameters.get('course_id');

        if (!meetingId || !coursesId) {
            return null;
        }

        return `/meeting/${meetingId}?courses_id=${coursesId}`;
    }

    buildClassUrl(id, dosenId, courseId) {
        if (!id) {
            return null;
        }

        const query = new URLSearchParams();

        if (dosenId) {
            query.set('dosen_id', dosenId);
        }

        if (courseId) {
            query.set('course_id', courseId);
        }

        const suffix = query.toString();

        return `/class/${id}${suffix ? `?${suffix}` : ''}`;
    }

    parseQueryString(value) {
        if (!value) {
            return new URLSearchParams();
        }

        const trimmed = String(value).trim();

        if (trimmed === '') {
            return new URLSearchParams();
        }

        try {
            const url = new URL(trimmed, this.campusBase);

            return url.searchParams;
        } catch (error) {
            const query = trimmed.includes('?')
                ? trimmed.split('?').slice(1).join('?')
                : trimmed.replace(/^[?#&]/, '');

            return new URLSearchParams(query);
        }
    }

    extractAssignmentShortcut(value) {
        const params = this.parseQueryString(value);

        return {
            courses_id: params.get('courses_id') || '',
            id: params.get('id') || '',
            pertemuan: params.get('pertemuan') || '',
        };
    }

    extractAttendanceShortcut(value) {
        const params = this.parseQueryString(value);

        return {
            courseid: params.get('courseid') || params.get('course_id') || params.get('courses_id') || '',
            modulesid: params.get('modulesid') || params.get('id') || '',
            pertemuan: params.get('pertemuan') || '',
        };
    }

    formatDate(value) {
        if (!value) {
            return '-';
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(date);
    }

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    renderKeyValueList(entries = []) {
        const rows = entries
            .filter((entry) => entry.value !== undefined && entry.value !== null && entry.value !== '')
            .map((entry) => `
                <div class="data-row">
                    <dt>${this.escapeHtml(entry.label)}</dt>
                    <dd>${this.escapeHtml(entry.value)}</dd>
                </div>
            `)
            .join('');

        return rows ? `<dl class="data-grid">${rows}</dl>` : '<div class="empty-shell">Tidak ada data.</div>';
    }
}

window.portalApp = new PortalApp();

document.addEventListener('DOMContentLoaded', () => {
    window.portalApp.init();
});
