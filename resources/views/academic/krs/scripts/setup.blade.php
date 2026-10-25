{{--
    KRS script, the links, the hosts and the shared state, the icons, the
    cache helpers, the period labels, and the "Diperbarui" line. Every later
    part uses them. Part of the DOMContentLoaded handler opened in
    academic/krs.blade.php: it shares that scope (app, esc, hosts, state and
    the helpers in setup).
--}}
            const app = window.portalApp;
            const portalUrl = @json(config('portal.base_url'));
            const guideUrl = @json(route('more').'#panduan');
            const classUrl = @json(route('class.index'));
            const gradesUrl = @json(route('grades.index'));
            const hosts = {
                status: document.getElementById('krs-status'),
                courses: document.getElementById('krs-courses'),
                schedule: document.getElementById('krs-schedule'),
                notes: document.getElementById('krs-notes'),
                printHeader: document.getElementById('krs-print-header'),
                dpa: document.getElementById('krs-dpa'),
                history: document.getElementById('krs-history'),
                updated: document.querySelector('[data-krs-updated]'),
                period: document.getElementById('krs-period-label'),
            };
            const state = { krs: null, krsMeta: null, advising: null, advisingMeta: null, detail: null, detailRequested: false, renderedDay: null };
            const todayKey = () => new Date().toLocaleDateString('sv-SE');
            const esc = (value) => app.escapeHtml(value);
            const icons = {
                check: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>',
                chevron: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6l-6 6" /></svg>',
                external: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>',
                print: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>',
                chat: '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /></svg>',
            };

            // Which KRS period matters for each status (same order as PortalKrsResolver).
            const relevantKinds = {
                not_submitted: ['her_registrasi', 'krs', 'perwalian_perbaikan'],
                pending_dpa: ['perwalian_perbaikan', 'krs', 'her_registrasi'],
                validated: ['perwalian_perbaikan', 'krs', 'her_registrasi'],
                unknown: ['her_registrasi', 'krs', 'perwalian_perbaikan'],
            };

            const cachedData = (url) => {
                try {
                    return app.getCachedResponse(url)?.payload?.data ?? null;
                } catch (error) {
                    return null;
                }
            };

            const primeFromCache = (url, render, label) => {
                try {
                    return app.primeCachedView(url, render, label);
                } catch (error) {
                    return false;
                }
            };

            const termLabel = (period) => {
                if (!period) {
                    return '';
                }

                const term = period.term === 'ganjil' ? 'Ganjil' : (period.term === 'genap' ? 'Genap' : '');

                return [period.academic_year ? `T.A ${period.academic_year}` : '', term].filter(Boolean).join(' · ') || period.label || '';
            };

            const shortRange = (start, end) => {
                const from = app.toDate(start);
                const to = app.toDate(end);

                if (from && to && from.getMonth() === to.getMonth() && from.getFullYear() === to.getFullYear()) {
                    return `${from.getDate()}–${app.formatIndonesianDate(end, { month: 'short' })}`;
                }

                return `${app.formatIndonesianDate(start, { month: 'short', year: false })} – ${app.formatIndonesianDate(end, { month: 'short' })}`;
            };

            const renderFreshness = () => {
                const meta = state.krsMeta || state.advisingMeta;

                if (!hosts.updated || !meta) {
                    return;
                }

                const data = state.krs || state.advising || {};
                const ago = app.formatTimeAgo(meta.synced_at || data.extracted_at || meta.cached_at);
                const parts = [ago ? `Diperbarui ${ago}` : 'Waktu pembaruan belum diketahui'];
                let warn = false;

                if (meta.status === 'failed') {
                    parts.push('gagal diperbarui, ini data terakhir');
                    warn = true;
                } else if (meta.status === 'syncing') {
                    parts.push('sedang diperbarui dari portal');
                }

                if (meta.data_origin === 'device_cache') {
                    parts.push(meta.cache_is_stale ? 'data tersimpan di perangkat, mungkin sudah lama' : 'data tersimpan di perangkat');
                    warn = warn || Boolean(meta.cache_is_stale);
                }

                hosts.updated.textContent = parts.join(' · ');
                hosts.updated.classList.toggle('text-warning', warn);
                hosts.updated.classList.toggle('text-ink-subtle', !warn);
            };

