@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

{{--
    Ujian: the hero, a 3-column KPI strip (total, submitted, pending),
    status filter tabs with search, and the exams as rows of one list card
    linking to each detail page.
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

    <x-stat-strip class="mb-6 grid-cols-3" id="exam-summary">
        <x-metric label="Total ujian">
            <x-slot:value id="exam-metric-total"><x-skeleton class="h-7 w-10" /></x-slot:value>
            <x-slot:note id="exam-metric-total-note"></x-slot:note>
        </x-metric>
        <x-metric label="Sudah dikumpulkan">
            <x-slot:value id="exam-metric-submitted" class="text-success!"><x-skeleton class="h-7 w-10" /></x-slot:value>
            <x-slot:note id="exam-metric-submitted-note"></x-slot:note>
        </x-metric>
        <x-metric label="Perlu dikumpulkan">
            <x-slot:value id="exam-metric-pending" class="text-warning!"><x-skeleton class="h-7 w-10" /></x-slot:value>
            <x-slot:note id="exam-metric-pending-note"></x-slot:note>
        </x-metric>
    </x-stat-strip>

    {{-- Exam answers queued while offline: not at the portal yet. --}}
    <div class="mb-6 hidden" data-outbox-list data-outbox-match="action:exam_upload" data-outbox-heading="Antrean offline ujian"></div>

    <x-section title="Daftar ujian" title-id="exam-list-title" heading-class="">
        <x-slot:aside class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <x-view-tabs as="div" class="shrink-0" id="exam-filter-tabs" role="tablist">
                    <x-view-tab :active="true" data-exam-filter="all" id="exam-tab-all">Semua</x-view-tab>
                    <x-view-tab data-exam-filter="pending" id="exam-tab-pending">Perlu dikumpulkan</x-view-tab>
                    <x-view-tab data-exam-filter="submitted" id="exam-tab-submitted">Sudah dikumpulkan</x-view-tab>
                </x-view-tabs>

                <div class="relative flex w-full items-center sm:w-56 lg:w-64">
                    <span class="pointer-events-none absolute start-3 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                        <x-icon name="search" size="sm" />
                    </span>
                    <x-input
                        icon
                        type="search"
                        id="exam-search-input"
                        placeholder="Cari mata kuliah atau dosen"
                        aria-label="Cari mata kuliah, kode, atau dosen"
                        autocomplete="off"
                    />
                </div>
            </div>
        </x-slot:aside>
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
            const filterTabs = document.querySelectorAll('[data-exam-filter]');
            const searchInput = document.getElementById('exam-search-input');
            const metricTotal = document.getElementById('exam-metric-total');
            const metricTotalNote = document.getElementById('exam-metric-total-note');
            const metricSubmitted = document.getElementById('exam-metric-submitted');
            const metricSubmittedNote = document.getElementById('exam-metric-submitted-note');
            const metricPending = document.getElementById('exam-metric-pending');
            const metricPendingNote = document.getElementById('exam-metric-pending-note');

            let allExams = [];
            let activeFilter = 'all';
            let searchQuery = '';

            const examDetailHref = (exam) => {
                const examId = String(exam.id || '').trim();

                return examId ? `/exam/${encodeURIComponent(examId)}` : '';
            };

            // "Belum Upload" or "Belum dikumpulkan" are not submissions.
            const isSubmittedStatus = (status) => !/belum|tidak|not/i.test(status) && /sudah|terkirim|telah|selesai|uploaded/i.test(status);

            const classifyExamStatus = (exam) => {
                const rawStatus = String(exam.status || '').trim();
                const normalized = rawStatus && rawStatus !== '-' ? rawStatus : 'Belum ada info';

                if (isSubmittedStatus(rawStatus)) {
                    return { state: 'submitted', tone: 'success', icon: 'circle-check', label: normalized };
                }

                if (/belum\s+upload|belum\s+dikumpulkan|sedang\s+dibuka/i.test(rawStatus)) {
                    const isOpen = /sedang\s+dibuka|berlangsung/i.test(rawStatus);
                    const isOverdue = /lewat|terlambat|overdue|mendesak/i.test(rawStatus);

                    return {
                        state: 'pending',
                        tone: isOverdue ? 'danger' : (isOpen ? 'brand' : 'warning'),
                        icon: 'exam',
                        label: normalized,
                    };
                }

                return { state: 'neutral', tone: 'neutral', icon: 'clock', label: normalized };
            };

            /**
             * An exam as a row of the list card: leading status icon tile, course
             * metadata, schedule, status badge, and link to its detail page.
             */
            const renderExamCard = (exam) => {
                const href = examDetailHref(exam);
                const courseName = app.escapeHtml(exam.course_name || 'Tanpa judul');
                const hasSchedule = Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-');
                const scheduleText = app.escapeHtml(hasSchedule ? exam.schedule : 'Jadwal ujian belum diumumkan');
                const lecturerText = exam.lecturer ? app.escapeHtml(exam.lecturer) : '';
                const courseCode = app.escapeHtml(exam.course_code || '-');
                const statusInfo = classifyExamStatus(exam);
                const iconTileClass = app.ui.iconTile[statusInfo.tone] || app.ui.iconTile.neutral;
                const badgeClass = app.ui.badge[statusInfo.tone] || app.ui.badge.neutral;
                const accentClass = statusInfo.state === 'submitted'
                    ? 'border-s-[3px] border-s-success'
                    : statusInfo.state === 'pending'
                    ? (statusInfo.tone === 'danger'
                        ? 'border-s-[3px] border-s-danger'
                        : (statusInfo.tone === 'brand' ? 'border-s-[3px] border-s-brand' : 'border-s-[3px] border-s-warning'))
                    : '';

                const body = `
                    <span class="${iconTileClass} mt-0.5 size-10 rounded-lg" aria-hidden="true">
                        ${app.renderIcon(statusInfo.icon, 'sm')}
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-subtle">
                                <span class="font-mono font-medium text-ink-muted">${courseCode}</span>
                                ${lecturerText ? `
                                    <span class="inline-flex min-w-0 items-center gap-1">
                                        ${app.renderIcon('user', 'sm', 'size-3.5! text-ink-subtle')}
                                        <span class="truncate">${lecturerText}</span>
                                    </span>
                                ` : ''}
                            </div>
                            <p class="mt-1 text-sm font-semibold leading-snug text-ink">${courseName}</p>
                            <p class="mt-1 flex items-center gap-1.5 text-xs ${hasSchedule ? 'text-ink-muted' : 'text-ink-subtle'}">
                                ${app.renderIcon('calendar', 'sm', 'size-3.5! text-ink-subtle')}
                                <span class="truncate">${scheduleText}</span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 sm:justify-end">
                            <span class="${badgeClass}">${app.escapeHtml(statusInfo.label)}</span>
                        </div>
                    </div>
                `;

                return href
                    ? `<li><a href="${href}" class="${app.ui.listRowFlushInteractive} ${accentClass}">${body}${app.renderIcon('chevron-right', 'sm', app.ui.listRowChevron)}</a></li>`
                    : `<li class="${app.ui.listRowFlush} ${accentClass}">${body}</li>`;
            };

            const renderFilteredExams = () => {
                if (!allExams.length) {
                    listHost.innerHTML = app.renderEmptyState('Belum ada ujian.', 'secondary', '', { icon: 'exam' });

                    return;
                }

                const query = searchQuery.trim().toLowerCase();
                const filtered = allExams.filter((exam) => {
                    const statusInfo = classifyExamStatus(exam);

                    if (activeFilter === 'submitted' && statusInfo.state !== 'submitted') {
                        return false;
                    }

                    if (activeFilter === 'pending' && statusInfo.state !== 'pending') {
                        return false;
                    }

                    if (!query) {
                        return true;
                    }

                    return [exam.course_name, exam.course_code, exam.lecturer, exam.schedule]
                        .some((field) => String(field || '').toLowerCase().includes(query));
                });

                if (!filtered.length) {
                    const emptyMessage = query
                        ? `Tidak ada ujian yang cocok dengan "${searchQuery.trim()}".`
                        : activeFilter === 'submitted'
                        ? 'Belum ada ujian yang dikumpulkan.'
                        : 'Tidak ada ujian yang perlu dikumpulkan.';

                    listHost.innerHTML = app.renderEmptyState(emptyMessage, 'secondary', '', { icon: query ? 'search' : 'exam' });

                    return;
                }

                listHost.innerHTML = `
                    <div class="${app.ui.card} overflow-hidden" data-slot="card">
                        <ul class="${app.ui.list}">${filtered.map(renderExamCard).join('')}</ul>
                    </div>
                `;
            };

            const renderExams = (payload) => {
                allExams = payload.data?.exams || [];

                const submittedCount = allExams.filter((exam) => classifyExamStatus(exam).state === 'submitted').length;
                const pendingCount = allExams.filter((exam) => classifyExamStatus(exam).state === 'pending').length;
                const neutralCount = Math.max(0, allExams.length - submittedCount - pendingCount);

                metricTotal.textContent = String(allExams.length);
                metricTotalNote.textContent = neutralCount > 0 ? `${neutralCount} belum terjadwal` : 'Semua ujian aktif';

                metricSubmitted.textContent = String(submittedCount);
                metricSubmitted.classList.toggle('text-success!', submittedCount > 0);
                metricSubmittedNote.textContent = allExams.length
                    ? `${Math.round((submittedCount / allExams.length) * 100)}% selesai`
                    : '';

                metricPending.textContent = String(pendingCount);
                metricPending.classList.toggle('text-warning!', pendingCount > 0);
                metricPendingNote.textContent = pendingCount > 0 ? 'Menunggu upload' : 'Tidak ada tanggungan';

                renderFilteredExams();
            };

            filterTabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activeFilter = tab.dataset.examFilter || 'all';
                    filterTabs.forEach((candidate) => {
                        candidate.setAttribute('aria-selected', candidate === tab ? 'true' : 'false');
                    });
                    renderFilteredExams();
                });
            });

            if (searchInput) {
                searchInput.addEventListener('input', (event) => {
                    searchQuery = event.target.value;
                    renderFilteredExams();
                });
            }

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
