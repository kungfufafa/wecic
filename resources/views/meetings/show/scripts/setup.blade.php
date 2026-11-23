{{--
    Pertemuan script, the meeting and course ids, the constants,
    the not-found state, and the endpoint (a link without
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

            const renderNotFound = (message, subtitle = 'Buka halaman Kelas lalu pilih pertemuan dari daftar materi.') => {
                document.getElementById('meeting-hero').innerHTML = `
                    <header class="${app.ui.pageHero}" data-slot="page-hero">
                        <div class="min-w-0">
                            <h1 class="${app.ui.pageHeroTitle}">Pertemuan tidak ditemukan</h1>
                            <p class="${app.ui.pageHeroSubtitle}">${app.escapeHtml(message)}</p>
                        </div>
                    </header>
                `;
                document.getElementById('meeting-content').innerHTML = app.renderEmptyState(
                    'Tidak ada materi untuk link ini.',
                    'secondary',
                    subtitle,
                    { icon: 'presentation' }
                );
                document.getElementById('meeting-actions').innerHTML = `
                    <div class="${app.ui.card}" data-slot="card"><div class="${app.ui.cardBody}"><a href="/class" class="${app.buttonClass('primary')} w-full">Ke daftar kelas</a></div></div>
                `;
            };

            // A link without the course (an old notification, a hand-typed URL)
            // cannot be opened on the portal: say so instead of a blank page.
            if (!coursesId) {
                renderNotFound('Link pertemuan ini tidak lengkap, jadi pertemuannya tidak bisa dibuka.');

                return;
            }

            const endpoint = `/api/meeting/${meetingId}?courses_id=${coursesId}`;

