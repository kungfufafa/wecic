@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Ujian</h1>
    </header>

    <div id="exam-summary" class="mb-5 grid grid-cols-3 gap-3">
        @foreach (['Total', 'Jadwal', 'Terkirim'] as $label)
            <div class="mycic-metric">
                <div class="mycic-metric-label">{{ $label }}</div>
                <div class="mycic-metric-value" data-exam-metric>-</div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-3" id="exam-list">
        <div class="mycic-empty py-8">
            <p class="mycic-empty-title text-ink-subtle">Memuat ujian...</p>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const listHost = document.getElementById('exam-list');
            const metricNodes = document.querySelectorAll('[data-exam-metric]');

            const examDetailHref = (exam) => {
                const examId = String(exam.id || '').trim();

                return examId ? `/exam/${encodeURIComponent(examId)}` : '';
            };

            const renderExamCard = (exam) => {
                const href = examDetailHref(exam);
                const courseName = app.escapeHtml(exam.course_name || 'Tanpa judul');
                const hasSchedule = Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-');
                const schedule = app.escapeHtml([exam.lecturer, hasSchedule ? exam.schedule : 'Jadwal ujian belum diumumkan'].filter(Boolean).join(' · '));
                const courseCode = app.escapeHtml(exam.course_code || '-');
                const rawStatus = String(exam.status || '').trim();
                const status = app.escapeHtml(rawStatus && rawStatus !== '-' ? rawStatus : 'Belum ada info');

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
                                    ? `<a href="${href}" class="${app.buttonClass('primary', { small: true })}">Detail</a>`
                                    : app.renderDisabledButton('—')}
                            </div>
                        </div>
                    </div>
                `;
            };

            const renderExams = (payload) => {
                const exams = payload.data?.exams || [];
                const scheduledCount = exams.filter((exam) => Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-')).length;
                // "Belum Upload" or "Belum dikumpulkan" are not submissions.
                const isSubmittedStatus = (status) => !/belum|tidak|not/i.test(status) && /sudah|terkirim|telah|selesai|uploaded/i.test(status);
                const submittedCount = exams.filter((exam) => isSubmittedStatus(String(exam.status || ''))).length;

                metricNodes[0].textContent = exams.length || '0';
                metricNodes[1].textContent = String(scheduledCount);
                metricNodes[2].textContent = String(submittedCount);

                if (!exams.length) {
                    listHost.innerHTML = app.renderEmptyState('Belum ada ujian.', 'secondary');

                    return;
                }

                listHost.innerHTML = exams.map((exam) => renderExamCard(exam)).join('');
            };

            app.primeCachedView('/api/exam', renderExams, 'daftar ujian');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadExams = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/exam', { fresh: force, background });
                    renderExams(payload);
                    app.markViewSynced('Daftar ujian', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Daftar ujian gagal dimuat.', 'error');
                    app.mountErrorState(listHost, 'Daftar ujian gagal dimuat.', () => loadExams({ force }), { subtitle: app.describeError(error) });
                }
            };

            app.registerSyncHandler('exam-index', loadExams);

            await loadExams();
        });
    </script>
@endpush
