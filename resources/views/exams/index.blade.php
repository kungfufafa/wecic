@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

{{--
    Ujian: the hero and the exams as rows of one list card, each a link to
    its detail page; the heading counts them (and how many are submitted).
--}}
@section('content')
    <x-page-hero title="Ujian">
        <x-slot:actions>
            <x-button :href="route('class.index')" variant="secondary" size="sm">
                <x-icon name="class" size="sm" />
                Jadwal kelas
            </x-button>
        </x-slot:actions>
    </x-page-hero>

    {{-- Exam answers queued while offline: not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-match="action:exam_upload" data-outbox-heading="Antrean offline ujian"></div>

    <x-section title="Daftar ujian" title-id="exam-list-title">
        <x-slot:count id="exam-count" class="hidden">0</x-slot:count>
        <div class="flex flex-col gap-3" id="exam-list">
            <x-empty-state title="Memuat ujian..." title-class="font-normal! text-ink-subtle!" />
        </div>
    </x-section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const listHost = document.getElementById('exam-list');
            const countNode = document.getElementById('exam-count');

            const examDetailHref = (exam) => {
                const examId = String(exam.id || '').trim();

                return examId ? `/exam/${encodeURIComponent(examId)}` : '';
            };

            /**
             * An exam as a row of the list card: a link to its detail page when it
             * has an id, with the status (a badge only once submitted) and the
             * course code on the right.
             */
            const renderExamCard = (exam, submitted = false) => {
                const href = examDetailHref(exam);
                const courseName = app.escapeHtml(exam.course_name || 'Tanpa judul');
                const hasSchedule = Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-');
                const schedule = app.escapeHtml([exam.lecturer, hasSchedule ? exam.schedule : 'Jadwal ujian belum diumumkan'].filter(Boolean).join(' · '));
                const courseCode = app.escapeHtml(exam.course_code || '-');
                const rawStatus = String(exam.status || '').trim();
                const status = app.escapeHtml(rawStatus && rawStatus !== '-' ? rawStatus : 'Belum ada info');
                const body = `
                    <div class="${app.ui.listRowBody}">
                        <p class="text-sm font-medium leading-snug text-ink">${courseName}</p>
                        <p class="${app.ui.listRowSubtitle}">${schedule}</p>
                    </div>
                    <div class="${app.ui.listRowTrailing}">
                        ${submitted ? `<span class="${app.ui.badge.success}">${status}</span>` : `<span class="text-ink-muted">${status}</span>`}
                        <span class="font-mono">${courseCode}</span>
                    </div>
                `;

                return href
                    ? `<li><a href="${href}" class="${app.ui.listRowFlushInteractive}">${body}${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}</a></li>`
                    : `<li class="${app.ui.listRowFlush}">${body}</li>`;
            };

            const renderExams = (payload) => {
                const exams = payload.data?.exams || [];
                // "Belum Upload" or "Belum dikumpulkan" are not submissions.
                const isSubmittedStatus = (status) => !/belum|tidak|not/i.test(status) && /sudah|terkirim|telah|selesai|uploaded/i.test(status);
                const submittedCount = exams.filter((exam) => isSubmittedStatus(String(exam.status || ''))).length;

                countNode.textContent = submittedCount ? `${exams.length} · ${submittedCount} terkirim` : String(exams.length);
                countNode.classList.toggle('hidden', !exams.length);

                if (!exams.length) {
                    listHost.innerHTML = app.renderEmptyState('Belum ada ujian.', 'secondary', '', { icon: 'exam' });

                    return;
                }

                listHost.innerHTML = `
                    <div class="${app.ui.card} overflow-hidden" data-slot="card">
                        <ul class="${app.ui.list}">${exams.map((exam) => renderExamCard(exam, isSubmittedStatus(String(exam.status || '')))).join('')}</ul>
                    </div>
                `;
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
