{{--
    Pertemuan script, the meeting and course ids, the constants, the
    "Kembali" link, the not-found state, and the endpoint (a link without
    the course stops here). Every later part uses them. Part of the
    DOMContentLoaded handler opened in meetings/show.blade.php: it shares
    that scope (app, meetingId, coursesId, endpoint and the constants in
    setup).
--}}
            const app = window.portalApp;

            const meetingId = @json($meetingId);
            const coursesId = @json($coursesId);

            const ASSIGNMENT_MAX_BYTES = 10 * 1024 * 1024;
            const MONTHS = {
                januari: 1, februari: 2, maret: 3, april: 4, mei: 5, juni: 6, juli: 7, agustus: 8, september: 9, oktober: 10, november: 11, desember: 12,
                january: 1, february: 2, march: 3, may: 5, june: 6, july: 7, august: 8, october: 10, december: 12,
            };

            /**
             * "Kembali" returns to the MyCIC page the student came from (class page,
             * dashboard), else to this meeting's class, else to the class list.
             */
            const backHref = (classUrl = null) => {
                try {
                    const referrer = document.referrer ? new URL(document.referrer) : null;

                    if (referrer
                        && referrer.origin === window.location.origin
                        && !referrer.pathname.startsWith('/meeting/')
                        && !['/', '/login'].includes(referrer.pathname)) {
                        return `${referrer.pathname}${referrer.search}`;
                    }
                } catch (error) {
                    // An unreadable referrer falls back to the class page.
                }

                return classUrl || '/class';
            };

            const renderNotFound = (message, subtitle = 'Buka halaman Kelas lalu pilih pertemuan dari daftar materi.') => {
                document.getElementById('meeting-hero').innerHTML = `
                    <div class="mycic-hero">
                        <div class="flex flex-col gap-3">
                            <div><a href="${app.escapeHtml(backHref())}" class="mycic-hero-pill">Kembali</a></div>
                            <h1 class="text-xl font-extrabold tracking-tight text-white sm:text-2xl">Pertemuan tidak ditemukan</h1>
                            <p class="text-sm text-white/80">${app.escapeHtml(message)}</p>
                        </div>
                    </div>
                `;
                document.getElementById('meeting-content').innerHTML = app.renderEmptyState(
                    'Tidak ada materi untuk link ini.',
                    'secondary',
                    subtitle
                );
                document.getElementById('meeting-actions').innerHTML = `
                    <div class="mycic-card"><div class="mycic-card-body"><a href="/class" class="${app.buttonClass('primary')} mycic-btn-block">Ke daftar kelas</a></div></div>
                `;
            };

            // A link without the course (an old notification, a hand-typed URL)
            // cannot be opened on the portal: say so instead of a blank page.
            if (!coursesId) {
                renderNotFound('Link pertemuan ini tidak lengkap, jadi pertemuannya tidak bisa dibuka.');

                return;
            }

            const endpoint = `/api/meeting/${meetingId}?courses_id=${coursesId}`;

