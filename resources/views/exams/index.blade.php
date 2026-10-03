@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Ujian</h1>
        <p class="mycic-page-subheading">Daftar ujian beserta status dan jadwal pelaksanaannya.</p>
    </header>

    <div id="exam-summary" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ([
            ['label' => 'Total ujian', 'note' => 'Semua entri ujian yang berhasil terdeteksi.'],
            ['label' => 'Terjadwal', 'note' => 'Sudah punya jadwal.'],
            ['label' => 'Perlu ditinjau', 'note' => 'Belum final atau masih aktif.'],
        ] as $metric)
            <div class="mycic-metric">
                <div class="mycic-metric-label">{{ $metric['label'] }}</div>
                <div class="mycic-metric-value" data-exam-metric>-</div>
                <div class="mycic-metric-note">{{ $metric['note'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-3" id="exam-list">
        <div class="mycic-empty py-8">
            <p class="mycic-empty-title text-ink-subtle">Memuat daftar ujian...</p>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const listHost = document.getElementById('exam-list');
            const heroCopy = document.getElementById('exam-hero-copy');
            const statusPill = document.getElementById('exam-status-pill');
            const metricNodes = document.querySelectorAll('[data-exam-metric]');

            const examDetailHref = (exam) => {
                const examId = String(exam.id || '').trim();

                return examId ? `/exam/${encodeURIComponent(examId)}` : '';
            };

            const renderExamCard = (exam) => {
                const href = examDetailHref(exam);
                const courseName = app.escapeHtml(exam.course_name || 'Tanpa judul');
                const schedule = app.escapeHtml(exam.schedule || 'Jadwal belum tersedia');
                const courseCode = app.escapeHtml(exam.course_code || '-');
                const status = app.escapeHtml(exam.status || 'Tanpa status');

                return `
                    <div class="mycic-card-interactive">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="mb-1 flex flex-wrap items-center gap-2 text-caption text-ink-subtle">
                                    ${app.renderBadge(courseCode, 'brand')}
                                    ${app.renderBadge(status, 'neutral')}
                                </div>
                                <h3 class="mb-1 text-subtitle font-bold text-ink">
                                    ${href
                                        ? `<a href="${href}" class="text-ink no-underline hover:text-brand">${courseName}</a>`
                                        : courseName}
                                </h3>
                                <p class="text-caption text-ink-subtle">${schedule}</p>
                            </div>
                            <div class="shrink-0">
                                ${href
                                    ? `<a href="${href}" class="${app.buttonClass('primary', { small: true })}">Detail ujian</a>`
                                    : app.renderDisabledButton('Belum tersedia')}
                            </div>
                        </div>
                    </div>
                `;
            };

            const renderExams = (payload) => {
                const exams = payload.data?.exams || [];
                const scheduledCount = exams.filter((exam) => Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-')).length;
                const reviewCount = exams.filter((exam) => !/selesai|closed|ditutup|final/i.test(String(exam.status || ''))).length;

                metricNodes[0].textContent = exams.length || '0';
                metricNodes[1].textContent = scheduledCount || '0';
                metricNodes[2].textContent = reviewCount || '0';

                if (statusPill) {
                    statusPill.textContent = exams.length ? `${exams.length} entri aktif` : 'Belum ada entri';
                }

                if (heroCopy) {
                    heroCopy.textContent = exams.length
                        ? `${exams.length} ujian berhasil dimuat. Prioritaskan entri yang belum final dan buka detail untuk melihat kesiapan unggah berkas.`
                        : 'Belum ada ujian yang muncul pada portal aktif. Coba segarkan lagi saat data akademik tersedia.';
                }

                if (!exams.length) {
                    listHost.innerHTML = app.renderEmptyState('Belum ada data ujian.', 'secondary');

                    return;
                }

                listHost.innerHTML = exams.map((exam) => renderExamCard(exam)).join('');
            };

            app.primeCachedView('/api/exam', renderExams, 'daftar ujian');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadExams = async ({ force = false } = {}) => {
                try {
                    const payload = await app.request('/api/exam', { fresh: force });
                    renderExams(payload);
                    app.markViewSynced('Daftar ujian', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    listHost.innerHTML = app.renderEmptyState('Gagal memuat daftar ujian.', 'danger');
                }
            };

            app.registerSyncHandler('exam-index', loadExams);

            await loadExams();
        });
    </script>
@endpush
