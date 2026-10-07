@extends('layouts.app')

@section('title', 'Jadwal & Kelas Perkuliahan')
@section('page_id', 'class-index')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Jadwal & Kelas</h1>
    </header>

    <div id="class-summary" class="mb-5 grid grid-cols-3 gap-3">
        <div class="mycic-metric">
            <div class="mycic-metric-label">Total kelas</div>
            <div class="mycic-metric-value" data-summary-value><div class="mycic-skeleton h-6 w-12"></div></div>
        </div>
        <div class="mycic-metric">
            <div class="mycic-metric-label">Semester</div>
            <div class="mycic-metric-value" data-summary-value><div class="mycic-skeleton h-6 w-16"></div></div>
        </div>
        <div class="mycic-metric">
            <div class="mycic-metric-label">Hari aktif</div>
            <div class="mycic-metric-value" id="summary-active-days"><div class="mycic-skeleton h-6 w-16"></div></div>
            <div class="mycic-metric-note" id="summary-total-hours"></div>
        </div>
    </div>

    <div id="day-summary-strip" class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <!-- Rendered by JS -->
    </div>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex shrink-0 items-center gap-1 p-1 bg-surface rounded-xl border border-border/40 overflow-x-auto max-w-full" id="view-mode-tabs" role="tablist">
            <button type="button" class="mycic-view-tab inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 text-xs sm:text-sm font-bold rounded-lg transition-all bg-panel text-brand shadow-xs border border-border/40" data-view-target="blocking" role="tab" aria-selected="true" aria-controls="view-blocking" id="tab-blocking">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>
                <span>Grid</span>
            </button>
            <button type="button" class="mycic-view-tab inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 text-xs sm:text-sm font-semibold rounded-lg transition-all text-ink-muted hover:text-ink" data-view-target="timeline" role="tab" aria-selected="false" aria-controls="view-timeline" id="tab-timeline">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 15" /></svg>
                <span>Timeline</span>
            </button>
            <button type="button" class="mycic-view-tab inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 text-xs sm:text-sm font-semibold rounded-lg transition-all text-ink-muted hover:text-ink" data-view-target="list" role="tab" aria-selected="false" aria-controls="view-list" id="tab-list">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></svg>
                <span>Daftar</span>
            </button>
        </div>

        <div class="mycic-input-wrap w-full sm:w-64">
            <span class="mycic-input-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
            </span>
            <input
                type="search"
                id="class-search-input"
                class="mycic-input-with-icon h-9 text-xs"
                placeholder="Cari matkul, dosen, ruang..."
                autocomplete="off"
            >
        </div>
    </div>

    <div id="view-blocking" class="view-panel mb-6" role="tabpanel" aria-labelledby="tab-blocking">
        <div class="mycic-card overflow-hidden">
            <div id="blocking-grid-container" class="overflow-x-auto p-2 sm:p-4">
                <div class="mycic-empty py-12">
                    <p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- View Container 2: Timeline & Jam Kosong -->
    <div id="view-timeline" class="view-panel mb-6 hidden" role="tabpanel" aria-labelledby="tab-timeline">
        <div id="timeline-container" class="flex flex-col gap-4">
            <div class="mycic-empty py-12">
                <p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p>
            </div>
        </div>
    </div>

    <!-- View Container 3: Daftar Kelas Asli (List View) -->
    <div id="view-list" class="view-panel mb-6 hidden" role="tabpanel" aria-labelledby="tab-list">
        <div class="mb-4 flex flex-col gap-3" id="class-list">
            <div class="mycic-empty py-8">
                <p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p>
            </div>
        </div>
    </div>

    <!-- Unscheduled / Flexible Classes Section (if any classes lack fixed day/time) -->
    <div id="unscheduled-classes-container" class="mb-6 hidden">
        <!-- Rendered if unscheduled classes exist -->
    </div>

    <!-- Interactive Class Detail Modal -->
    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" id="class-detail-modal" role="dialog" aria-modal="true">
        <div class="mycic-modal-backdrop" id="class-modal-backdrop"></div>
        <div class="mycic-modal-panel max-w-lg">
            <div class="mycic-modal-header">
                <div class="min-w-0 flex-1 pr-2">
                    <div class="mb-1 flex items-center gap-2">
                        <span class="rounded bg-brand/10 px-2 py-0.5 text-xs font-bold text-brand dark:bg-brand/20" id="modal-class-code">-</span>
                        <span class="rounded bg-surface px-2 py-0.5 text-xs font-medium text-ink-subtle" id="modal-class-day">-</span>
                    </div>
                    <h2 class="text-subtitle font-bold text-ink leading-snug" id="modal-class-title">-</h2>
                </div>
                <button type="button" class="mycic-modal-close" id="class-modal-close" aria-label="Tutup">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="mycic-modal-body flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg border border-border bg-surface/50 p-3">
                        <div class="text-caption font-semibold uppercase text-ink-subtle">Waktu Kuliah</div>
                        <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-time">-</div>
                        <div class="text-xs text-ink-subtle mt-0.5" id="modal-class-duration">-</div>
                    </div>
                    <div class="rounded-lg border border-border bg-surface/50 p-3">
                        <div class="text-caption font-semibold uppercase text-ink-subtle">Ruangan & Kelas</div>
                        <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-room">-</div>
                        <div class="text-xs text-ink-subtle mt-0.5" id="modal-class-group">-</div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface/50 p-3">
                    <div class="text-caption font-semibold uppercase text-ink-subtle">Dosen Pengampu</div>
                    <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-lecturer">-</div>
                    <div class="text-xs text-ink-subtle mt-1.5 flex flex-col gap-1" id="modal-class-contacts"></div>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-border-light">
                    <a href="#" class="mycic-btn-primary-sm flex-1 text-center justify-center" id="modal-btn-open-class">Buka Ruang Kelas</a>
                    <button type="button" class="mycic-btn-secondary-sm" id="modal-btn-toggle-meeting">Lihat pertemuan</button>
                </div>

                <div class="hidden" id="modal-meeting-panel"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const endpoint = '/api/class';
            const summaryCards = document.querySelectorAll('[data-summary-value]');
            const activeDaysMetric = document.getElementById('summary-active-days');
            const totalHoursMetric = document.getElementById('summary-total-hours');
            const dayStripHost = document.getElementById('day-summary-strip');
            const searchInput = document.getElementById('class-search-input');
            const blockingGridHost = document.getElementById('blocking-grid-container');
            const timelineHost = document.getElementById('timeline-container');
            const listHost = document.getElementById('class-list');
            const unscheduledHost = document.getElementById('unscheduled-classes-container');
            const viewTabs = document.querySelectorAll('[data-view-target]');
            const viewPanels = {
                blocking: document.getElementById('view-blocking'),
                timeline: document.getElementById('view-timeline'),
                list: document.getElementById('view-list'),
            };

            // Modal elements
            const modalEl = document.getElementById('class-detail-modal');
            const modalCloseBtn = document.getElementById('class-modal-close');
            const modalBackdrop = document.getElementById('class-modal-backdrop');
            const modalCode = document.getElementById('modal-class-code');
            const modalDay = document.getElementById('modal-class-day');
            const modalTitle = document.getElementById('modal-class-title');
            const modalTime = document.getElementById('modal-class-time');
            const modalDuration = document.getElementById('modal-class-duration');
            const modalRoom = document.getElementById('modal-class-room');
            const modalGroup = document.getElementById('modal-class-group');
            const modalLecturer = document.getElementById('modal-class-lecturer');
            const modalContacts = document.getElementById('modal-class-contacts');
            const modalOpenBtn = document.getElementById('modal-btn-open-class');
            const modalMeetingBtn = document.getElementById('modal-btn-toggle-meeting');
            const modalMeetingPanel = document.getElementById('modal-meeting-panel');

            const DAYS_LIST = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const INDO_DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const TODAY_DAY = INDO_DAYS[new Date().getDay()];

            let currentViewMode = localStorage.getItem('mycic-class-view-mode') || 'blocking';
            let selectedDayFilter = 'all';
            let currentSearchQuery = '';
            let cachedClassesData = [];
            let cachedPayload = null;
            let currentModalDetailApi = null;

            const COURSE_PALETTES = [
                {
                    bg: 'bg-blue-500/10 dark:bg-blue-500/20',
                    border: 'border-blue-500/30 dark:border-blue-400/30',
                    accent: 'border-l-blue-600 dark:border-l-blue-400',
                    badge: 'bg-blue-600 text-white dark:bg-blue-500 dark:text-white',
                    timeBadge: 'bg-blue-500/15 text-blue-800 dark:text-blue-200',
                    dot: 'bg-blue-600 dark:bg-blue-400',
                    ring: 'hover:ring-blue-500/30',
                },
                {
                    bg: 'bg-emerald-500/10 dark:bg-emerald-500/20',
                    border: 'border-emerald-500/30 dark:border-emerald-400/30',
                    accent: 'border-l-emerald-600 dark:border-l-emerald-400',
                    badge: 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-white',
                    timeBadge: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200',
                    dot: 'bg-emerald-600 dark:bg-emerald-400',
                    ring: 'hover:ring-emerald-500/30',
                },
                {
                    bg: 'bg-violet-500/10 dark:bg-violet-500/20',
                    border: 'border-violet-500/30 dark:border-violet-400/30',
                    accent: 'border-l-violet-600 dark:border-l-violet-400',
                    badge: 'bg-violet-600 text-white dark:bg-violet-500 dark:text-white',
                    timeBadge: 'bg-violet-500/15 text-violet-800 dark:text-violet-200',
                    dot: 'bg-violet-600 dark:bg-violet-400',
                    ring: 'hover:ring-violet-500/30',
                },
                {
                    bg: 'bg-amber-500/10 dark:bg-amber-500/20',
                    border: 'border-amber-500/30 dark:border-amber-400/30',
                    accent: 'border-l-amber-600 dark:border-l-amber-400',
                    badge: 'bg-amber-600 text-white dark:bg-amber-500 dark:text-white',
                    timeBadge: 'bg-amber-500/15 text-amber-900 dark:text-amber-200',
                    dot: 'bg-amber-600 dark:bg-amber-400',
                    ring: 'hover:ring-amber-500/30',
                },
                {
                    bg: 'bg-rose-500/10 dark:bg-rose-500/20',
                    border: 'border-rose-500/30 dark:border-rose-400/30',
                    accent: 'border-l-rose-600 dark:border-l-rose-400',
                    badge: 'bg-rose-600 text-white dark:bg-rose-500 dark:text-white',
                    timeBadge: 'bg-rose-500/15 text-rose-800 dark:text-rose-200',
                    dot: 'bg-rose-600 dark:bg-rose-400',
                    ring: 'hover:ring-rose-500/30',
                },
                {
                    bg: 'bg-teal-500/10 dark:bg-teal-500/20',
                    border: 'border-teal-500/30 dark:border-teal-400/30',
                    accent: 'border-l-teal-600 dark:border-l-teal-400',
                    badge: 'bg-teal-600 text-white dark:bg-teal-500 dark:text-white',
                    timeBadge: 'bg-teal-500/15 text-teal-800 dark:text-teal-200',
                    dot: 'bg-teal-600 dark:bg-teal-400',
                    ring: 'hover:ring-teal-500/30',
                },
                {
                    bg: 'bg-indigo-500/10 dark:bg-indigo-500/20',
                    border: 'border-indigo-500/30 dark:border-indigo-400/30',
                    accent: 'border-l-indigo-600 dark:border-l-indigo-400',
                    badge: 'bg-indigo-600 text-white dark:bg-indigo-500 dark:text-white',
                    timeBadge: 'bg-indigo-500/15 text-indigo-800 dark:text-indigo-200',
                    dot: 'bg-indigo-600 dark:bg-indigo-400',
                    ring: 'hover:ring-indigo-500/30',
                },
            ];

            const cleanText = (str) => {
                if (!str) return '';
                return String(str)
                    .replace(/&nbsp;/gi, ' ')
                    .replace(/&amp;/gi, '&')
                    .replace(/&quot;/gi, '"')
                    .replace(/&#039;/gi, "'")
                    .replace(/&lt;/gi, '<')
                    .replace(/&gt;/gi, '>')
                    .replace(/\s+/g, ' ')
                    .trim();
            };

            const normalizeDay = (rawDay) => {
                if (!rawDay) return null;
                const clean = String(rawDay).trim().toLowerCase();
                if (clean.includes('senin') || clean.includes('monday')) return 'Senin';
                if (clean.includes('selasa') || clean.includes('tuesday')) return 'Selasa';
                if (clean.includes('rabu') || clean.includes('wednesday')) return 'Rabu';
                if (clean.includes('kamis') || clean.includes('thursday')) return 'Kamis';
                if (clean.includes('jumat') || clean.includes("jum'at") || clean.includes('friday')) return 'Jumat';
                if (clean.includes('sabtu') || clean.includes('saturday')) return 'Sabtu';
                if (clean.includes('minggu') || clean.includes('ahad') || clean.includes('sunday')) return 'Minggu';
                return null;
            };

            const parseTimeToMinutes = (str) => {
                if (!str) return null;
                const match = String(str).match(/(\d{1,2}):(\d{2})/);
                if (!match) return null;
                return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
            };

            const formatMinutesToTime = (minutes) => {
                const h = Math.floor(minutes / 60).toString().padStart(2, '0');
                const m = (minutes % 60).toString().padStart(2, '0');
                return `${h}:${m}`;
            };

            const formatDuration = (minutes) => {
                const h = Math.floor(minutes / 60);
                const m = minutes % 60;
                if (h > 0 && m > 0) return `${h} jam ${m} menit`;
                if (h > 0) return `${h} jam`;
                return `${m} menit`;
            };

            const getCoursePalette = (code, index) => {
                if (!code) return COURSE_PALETTES[index % COURSE_PALETTES.length];
                let hash = 0;
                for (let i = 0; i < code.length; i++) {
                    hash = (hash << 5) - hash + code.charCodeAt(i);
                    hash |= 0;
                }
                return COURSE_PALETTES[Math.abs(hash) % COURSE_PALETTES.length];
            };

            const parseClassItem = (klass, index) => {
                const params = klass.join_link?.params || {};
                const courseInfo = klass.course_info || {};
                const lecturerInfo = klass.lecturer_info || {};
                const schedule = klass.schedule || {};
                const classRoomInfo = klass.class_room_info || {};

                const rawSchedule = schedule.raw || schedule.formatted_time || schedule.time_range || schedule.time || '';
                const day = normalizeDay(schedule.day || rawSchedule);

                let startMin = parseTimeToMinutes(schedule.start_time);
                let endMin = parseTimeToMinutes(schedule.end_time);

                if (startMin === null || endMin === null) {
                    const timeMatch = (schedule.time_range || schedule.formatted_time || schedule.time || rawSchedule).match(/(\d{1,2}:\d{2})(?::\d{2})?\s*(?:-|sd|s\/d|sampai)\s*(\d{1,2}:\d{2})(?::\d{2})?/i);
                    if (timeMatch) {
                        if (startMin === null) startMin = parseTimeToMinutes(timeMatch[1]);
                        if (endMin === null) endMin = parseTimeToMinutes(timeMatch[2]);
                    }
                }

                if (startMin !== null && endMin === null) {
                    endMin = startMin + 90;
                }

                const startTimeFormatted = startMin !== null ? formatMinutesToTime(startMin) : '-';
                const endTimeFormatted = endMin !== null ? formatMinutesToTime(endMin) : '-';
                const durationMinutes = startMin !== null && endMin !== null ? Math.max(endMin - startMin, 30) : 90;
                const formattedTime = schedule.formatted_time || (startMin !== null && endMin !== null ? `${startTimeFormatted} - ${endTimeFormatted}` : rawSchedule || '-');

                const classUrl = app.buildClassUrl(params.id, params.dosen_id, params.course_id);
                const detailApiUrl = params.id && params.dosen_id && params.course_id
                    ? `/api/class/${params.id}?dosen_id=${params.dosen_id}&course_id=${params.course_id}`
                    : null;

                const code = cleanText(courseInfo.code || '');
                const rawName = cleanText(courseInfo.name || 'Tanpa judul');
                const roomClean = cleanText(classRoomInfo.room || courseInfo.room || '');
                const classGroupClean = cleanText(classRoomInfo.class_name || courseInfo.class_name || '');
                const lecturerClean = cleanText(lecturerInfo.name || 'Dosen tidak tertera');

                const palette = getCoursePalette(code || rawName || `class-${index}`, index);

                return {
                    id: index,
                    courseCode: code,
                    courseName: rawName,
                    lecturerName: lecturerClean,
                    lecturerContact: lecturerInfo.contact || null,
                    lecturerEmail: lecturerInfo.email || null,
                    roomName: roomClean || 'Ruang belum ditentukan',
                    className: classGroupClean && classGroupClean !== '-' ? classGroupClean : 'Kelas Reguler',
                    day,
                    startMin,
                    endMin,
                    startTimeFormatted,
                    endTimeFormatted,
                    durationMinutes,
                    formattedTime,
                    classUrl,
                    detailApiUrl,
                    palette,
                    original: klass,
                };
            };

            const processDayGroups = (parsedClasses) => {
                const dayMap = {};
                DAYS_LIST.forEach((d) => {
                    dayMap[d] = {
                        day: d,
                        classes: [],
                        gaps: [],
                        totalMinutes: 0,
                    };
                });

                const unscheduled = [];

                parsedClasses.forEach((c) => {
                    if (c.day && dayMap[c.day]) {
                        dayMap[c.day].classes.push(c);
                    } else {
                        unscheduled.push(c);
                    }
                });

                DAYS_LIST.forEach((d) => {
                    const group = dayMap[d];
                    group.classes.sort((a, b) => {
                        const aStart = a.startMin !== null ? a.startMin : 9999;
                        const bStart = b.startMin !== null ? b.startMin : 9999;
                        return aStart - bStart;
                    });

                    let totalDuration = 0;
                    group.classes.forEach((c) => {
                        if (c.startMin !== null && c.endMin !== null) {
                            totalDuration += Math.max(c.endMin - c.startMin, 0);
                        }
                    });
                    group.totalMinutes = totalDuration;

                    // Compute gaps between classes
                    const gaps = [];
                    for (let i = 0; i < group.classes.length - 1; i++) {
                        const cur = group.classes[i];
                        const next = group.classes[i + 1];

                        if (cur.endMin !== null && next.startMin !== null && next.startMin > cur.endMin) {
                            const gapDuration = next.startMin - cur.endMin;
                            if (gapDuration >= 15) {
                                gaps.push({
                                    startMin: cur.endMin,
                                    endMin: next.startMin,
                                    startTime: formatMinutesToTime(cur.endMin),
                                    endTime: formatMinutesToTime(next.startMin),
                                    durationMinutes: gapDuration,
                                    formattedDuration: formatDuration(gapDuration),
                                    beforeClass: cur.courseName,
                                    afterClass: next.courseName,
                                });
                            }
                        }
                    }
                    group.gaps = gaps;
                });

                return { dayMap, unscheduled };
            };

            const renderSummary = (payload, parsedClasses, dayMap) => {
                const semester = payload.semester_info?.semester || payload.semester_info?.label || payload.academic_summary?.semester || '-';

                summaryCards[0].textContent = String(parsedClasses.length || '0');
                summaryCards[1].textContent = semester;

                let activeDaysCount = 0;
                let totalWeeklyMinutes = 0;

                DAYS_LIST.forEach((d) => {
                    if (dayMap[d].classes.length > 0) {
                        activeDaysCount++;
                        totalWeeklyMinutes += dayMap[d].totalMinutes;
                    }
                });

                if (activeDaysMetric) {
                    activeDaysMetric.textContent = String(activeDaysCount);
                }
                if (totalHoursMetric) {
                    const totalHours = (totalWeeklyMinutes / 60).toFixed(1).replace('.0', '');
                    totalHoursMetric.textContent = `${totalHours} jam / minggu`;
                }
            };

            const renderDaySummaryStrip = (dayMap) => {
                dayStripHost.innerHTML = DAYS_LIST.map((d) => {
                    const group = dayMap[d];
                    const isToday = d === TODAY_DAY;
                    const count = group.classes.length;
                    const hasClasses = count > 0;
                    const isSelected = selectedDayFilter === d;
                    const gapNote = group.gaps.length > 0
                        ? `<div class="text-[10px] mt-1 text-amber-700 dark:text-amber-400 font-medium">${group.gaps.length} jeda</div>`
                        : '';

                    return `
                        <div class="rounded-lg border p-3 transition-all duration-150 cursor-pointer ${
                            isSelected
                                ? 'border-brand bg-brand/5 shadow-xs ring-2 ring-brand/20'
                                : isToday
                                ? 'border-brand/40 bg-surface/70'
                                : 'border-border bg-panel hover:border-brand/30 hover:bg-surface/40'
                        }" data-day-trigger="${d}" title="${isSelected ? 'Tampilkan semua hari' : `Filter ${d}`}">
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <span class="text-xs font-bold text-ink">${d}</span>
                                ${isToday ? '<span class="rounded bg-brand px-1.5 py-0.5 text-[10px] font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                            </div>
                            <div class="text-sm font-extrabold ${hasClasses ? 'text-brand' : 'text-ink-subtle'}">
                                ${hasClasses ? `${count} kelas` : 'Libur'}
                            </div>
                            ${gapNote}
                        </div>
                    `;
                }).join('');

                dayStripHost.querySelectorAll('[data-day-trigger]').forEach((el) => {
                    el.addEventListener('click', () => {
                        const day = el.dataset.dayTrigger;
                        setDayFilter(selectedDayFilter === day ? 'all' : day);
                    });
                });
            };

            // Layout overlapping classes side by side within each day column
            const calculateClassColumnLayout = (classes) => {
                const sorted = [...classes].sort((a, b) => {
                    if (a.startMin !== b.startMin) return (a.startMin ?? 0) - (b.startMin ?? 0);
                    return (b.durationMinutes ?? 0) - (a.durationMinutes ?? 0);
                });

                const clusters = [];
                let currentCluster = [];
                let clusterEnd = -1;

                sorted.forEach((c) => {
                    if (c.startMin === null) return;
                    if (currentCluster.length === 0) {
                        currentCluster.push(c);
                        clusterEnd = c.endMin ?? (c.startMin + 90);
                    } else if (c.startMin < clusterEnd) {
                        currentCluster.push(c);
                        clusterEnd = Math.max(clusterEnd, c.endMin ?? (c.startMin + 90));
                    } else {
                        clusters.push(currentCluster);
                        currentCluster = [c];
                        clusterEnd = c.endMin ?? (c.startMin + 90);
                    }
                });
                if (currentCluster.length > 0) {
                    clusters.push(currentCluster);
                }

                const layoutMap = new Map();
                clusters.forEach((cluster) => {
                    const columns = [];
                    cluster.forEach((c) => {
                        let placed = false;
                        for (let i = 0; i < columns.length; i++) {
                            if (c.startMin >= columns[i]) {
                                columns[i] = c.endMin ?? (c.startMin + 90);
                                layoutMap.set(c.id, { colIndex: i, totalCols: 1 });
                                placed = true;
                                break;
                            }
                        }
                        if (!placed) {
                            columns.push(c.endMin ?? (c.startMin + 90));
                            layoutMap.set(c.id, { colIndex: columns.length - 1, totalCols: 1 });
                        }
                    });
                    const numCols = columns.length;
                    cluster.forEach((c) => {
                        const entry = layoutMap.get(c.id);
                        if (entry) entry.totalCols = numCols;
                    });
                });

                return layoutMap;
            };

            const renderBlockingGrid = (dayMap) => {
                const daysToRender = selectedDayFilter === 'all'
                    ? DAYS_LIST
                    : DAYS_LIST.filter((d) => d === selectedDayFilter);

                let minHour = 7;
                let maxHour = 18;

                DAYS_LIST.forEach((d) => {
                    dayMap[d].classes.forEach((c) => {
                        if (c.startMin !== null) {
                            const startH = Math.floor(c.startMin / 60);
                            if (startH < minHour) minHour = startH;
                        }
                        if (c.endMin !== null) {
                            const endH = Math.ceil(c.endMin / 60);
                            if (endH > maxHour) maxHour = endH;
                        }
                    });
                });

                const totalHours = maxHour - minHour;
                const totalGridMinutes = totalHours * 60;
                const hourHeightPx = 68;
                const totalGridHeightPx = totalHours * hourHeightPx;

                const hoursArray = [];
                for (let h = minHour; h <= maxHour; h++) {
                    hoursArray.push(h);
                }

                // Grid template columns inline style ensures zero dependency on dynamic Tailwind purging
                const gridColsStyle = daysToRender.length === 1
                    ? 'grid-template-columns: 64px 1fr;'
                    : `grid-template-columns: 64px repeat(${daysToRender.length}, minmax(140px, 1fr));`;

                const containerWidthClass = daysToRender.length === 1 ? 'min-w-full' : 'min-w-[700px] md:min-w-full';

                // Current time calculation for live time indicator
                const now = new Date();
                const nowMinutesFromMidnight = now.getHours() * 60 + now.getMinutes();
                const isWithinGridHours = nowMinutesFromMidnight >= (minHour * 60) && nowMinutesFromMidnight <= (maxHour * 60);
                const nowTopPct = isWithinGridHours
                    ? ((nowMinutesFromMidnight - (minHour * 60)) / totalGridMinutes) * 100
                    : null;
                const nowFormattedTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

                const searchLower = currentSearchQuery.trim().toLowerCase();

                let html = `
                    <div class="${containerWidthClass}">
                        <!-- Header Row -->
                        <div class="grid border-b border-border bg-surface/90 sticky top-0 z-30 backdrop-blur-sm rounded-t-lg" style="${gridColsStyle}">
                            <div class="p-2.5 text-center text-xs font-bold uppercase text-ink-subtle border-r border-border flex items-center justify-center sticky left-0 z-40 bg-surface/95 backdrop-blur-sm">
                                Jam
                            </div>
                            ${daysToRender.map((d) => {
                                const group = dayMap[d];
                                const isToday = d === TODAY_DAY;
                                const count = group.classes.length;

                                return `
                                    <div class="p-2.5 text-center border-r border-border last:border-r-0 ${
                                        isToday ? 'bg-brand/10 font-bold' : ''
                                    }">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <span class="text-xs sm:text-sm font-bold text-ink">${d}</span>
                                            ${isToday ? '<span class="rounded bg-brand px-1 py-0.5 text-[9px] font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                                        </div>
                                        <div class="text-[11px] text-ink-subtle mt-0.5">
                                            ${count > 0 ? `${count} Kelas` : '<span class="text-ink-subtle/80">Libur</span>'}
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>

                        <!-- Grid Body -->
                        <div class="grid relative" style="height: ${totalGridHeightPx}px; ${gridColsStyle}">
                            <!-- Time Axis (Sticky on left for effortless mobile horizontal scrolling) -->
                            <div class="relative border-r border-border bg-surface/85 backdrop-blur-sm select-none sticky left-0 z-20">
                                ${hoursArray.map((h, i) => {
                                    return `
                                        <div class="absolute left-0 right-0 text-center text-[11px] font-medium text-ink-subtle pt-1 border-b border-border/40" style="top: ${i * hourHeightPx}px; height: ${hourHeightPx}px;">
                                            ${String(h).padStart(2, '0')}:00
                                        </div>
                                    `;
                                }).join('')}
                            </div>

                            <!-- Day Columns -->
                            ${daysToRender.map((d) => {
                                const group = dayMap[d];
                                const isToday = d === TODAY_DAY;
                                const layoutMap = calculateClassColumnLayout(group.classes);

                                return `
                                    <div class="relative border-r border-border last:border-r-0 ${
                                        isToday ? 'bg-brand/[0.02]' : ''
                                    }">
                                        <!-- Background Hour Lines -->
                                        ${hoursArray.map((_, i) => {
                                            if (i === hoursArray.length - 1) return '';
                                            return `
                                                <div class="absolute inset-x-0 border-b border-border/40 pointer-events-none" style="top: ${i * hourHeightPx}px; height: ${hourHeightPx}px;">
                                                    <div class="w-full border-b border-dashed border-border/20" style="margin-top: ${hourHeightPx / 2}px;"></div>
                                                </div>
                                            `;
                                        }).join('')}

                                        ${group.classes.length === 0 ? `
                                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <div class="text-xs font-medium text-ink-subtle">Libur</div>
                                            </div>
                                        ` : ''}

                                        <!-- Live Current Time Indicator Line (on Today's Column) -->
                                        ${isToday && nowTopPct !== null ? `
                                            <div class="absolute left-0 right-0 z-30 pointer-events-none flex items-center" style="top: ${nowTopPct}%;">
                                                <div class="h-2.5 w-2.5 -ml-1 rounded-full bg-red-600 ring-4 ring-red-500/20 shadow-xs"></div>
                                                <div class="flex-1 border-b-2 border-red-500 dark:border-red-400"></div>
                                                <span class="rounded bg-red-600 px-1 py-0.5 text-[9px] font-bold text-white shadow-xs">
                                                    ${nowFormattedTime}
                                                </span>
                                            </div>
                                        ` : ''}

                                        <!-- Gap Blocks (Jam Kosong) -->
                                        ${group.gaps.map((gap) => {
                                            const gapTopPct = ((gap.startMin - (minHour * 60)) / totalGridMinutes) * 100;
                                            const gapHeightPct = (gap.durationMinutes / totalGridMinutes) * 100;

                                            return `
                                                <div class="absolute left-1 right-1 rounded-md border border-dashed border-amber-500/50 bg-amber-500/10 dark:bg-amber-500/15 p-1 flex items-center justify-center text-center transition-all duration-150 hover:bg-amber-500/20 z-10 overflow-hidden"
                                                     style="top: ${gapTopPct}%; height: ${gapHeightPct}%; min-height: 24px;"
                                                     title="${gap.startTime} – ${gap.endTime}">
                                                    <span class="text-[11px] font-bold text-amber-900 dark:text-amber-200 truncate">${gap.formattedDuration}</span>
                                                </div>
                                            `;
                                        }).join('')}

                                        <!-- Class Blocks (Side-by-side if overlapping) -->
                                        ${group.classes.map((c) => {
                                            if (c.startMin === null) return '';
                                            const topPct = ((c.startMin - (minHour * 60)) / totalGridMinutes) * 100;
                                            const heightPct = Math.max(((c.durationMinutes) / totalGridMinutes) * 100, 4.5);
                                            const p = c.palette;

                                            const layout = layoutMap.get(c.id) || { colIndex: 0, totalCols: 1 };
                                            const widthPct = 100 / layout.totalCols;
                                            const leftPct = layout.colIndex * widthPct;

                                            const matchesSearch = !searchLower || (
                                                c.courseName.toLowerCase().includes(searchLower) ||
                                                c.courseCode.toLowerCase().includes(searchLower) ||
                                                c.lecturerName.toLowerCase().includes(searchLower) ||
                                                c.roomName.toLowerCase().includes(searchLower)
                                            );

                                            return `
                                                <div class="absolute rounded-lg border ${p.border} ${p.accent} border-l-4 ${p.bg} p-2 cursor-pointer transition-all duration-150 hover:shadow-md hover:scale-[1.01] hover:z-20 flex flex-col justify-between overflow-hidden shadow-xs group ${
                                                    matchesSearch ? 'opacity-100 ring-1 ring-border/20' : 'opacity-30 hover:opacity-100'
                                                }"
                                                     style="top: ${topPct}%; height: ${heightPct}%; left: calc(${leftPct}% + 4px); width: calc(${widthPct}% - 8px); min-height: 52px;"
                                                     data-class-modal-trigger="${c.id}">
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex items-center justify-between gap-1 mb-0.5">
                                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-extrabold ${p.timeBadge}">
                                                                ${app.escapeHtml(c.formattedTime)}
                                                            </span>
                                                            ${c.courseCode ? `<span class="text-[9px] font-semibold text-ink-subtle uppercase truncate">${app.escapeHtml(c.courseCode)}</span>` : ''}
                                                        </div>
                                                        <div class="text-xs font-bold text-ink truncate leading-tight group-hover:text-brand" title="${app.escapeHtml(c.courseName)}">
                                                            ${app.escapeHtml(c.courseName)}
                                                        </div>
                                                    </div>
                                                    <div class="mt-1 flex items-center justify-between gap-1 text-[10px] text-ink-subtle truncate pt-1 border-t border-black/5 dark:border-white/5">
                                                        <span class="truncate font-medium">${app.escapeHtml(c.roomName)}</span>
                                                        <span class="truncate hidden sm:inline">${app.escapeHtml((c.lecturerName || '').split(',')[0])}</span>
                                                    </div>
                                                </div>
                                            `;
                                        }).join('')}
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;

                blockingGridHost.innerHTML = html;
                bindModalTriggers();
            };

            const renderTimeline = (dayMap) => {
                const daysToRender = selectedDayFilter === 'all'
                    ? DAYS_LIST
                    : DAYS_LIST.filter((d) => d === selectedDayFilter);

                const searchLower = currentSearchQuery.trim().toLowerCase();

                timelineHost.innerHTML = daysToRender.map((d) => {
                    const group = dayMap[d];
                    const isToday = d === TODAY_DAY;
                    const classesToRender = searchLower
                        ? group.classes.filter((c) =>
                            c.courseName.toLowerCase().includes(searchLower) ||
                            c.courseCode.toLowerCase().includes(searchLower) ||
                            c.lecturerName.toLowerCase().includes(searchLower) ||
                            c.roomName.toLowerCase().includes(searchLower)
                        )
                        : group.classes;

                    const count = classesToRender.length;

                    return `
                        <div class="mycic-card overflow-hidden">
                            <!-- Day Header -->
                            <div class="flex items-center justify-between border-b border-border bg-surface/60 px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-subtitle font-bold text-ink">${d}</h3>
                                    ${isToday ? '<span class="rounded bg-brand px-2 py-0.5 text-xs font-bold text-white uppercase tracking-tight">Hari Ini</span>' : ''}
                                    <span class="rounded-full bg-surface-tint px-2.5 py-0.5 text-xs font-semibold text-ink-muted">
                                        ${count > 0 ? `${count} kelas` : 'Libur'}
                                    </span>
                                </div>
                                ${count > 0 ? `<div class="text-caption text-ink-subtle">${formatDuration(group.totalMinutes)}</div>` : ''}
                            </div>

                            <div class="p-4 flex flex-col gap-3">
                                ${count === 0 ? `
                                    <div class="rounded-lg border border-dashed border-border p-4 text-center text-sm text-ink-subtle">
                                        ${searchLower ? 'Tidak ada hasil.' : 'Libur'}
                                    </div>
                                ` : ''}

                                ${classesToRender.map((c, index) => {
                                    const p = c.palette;
                                    const nextClass = classesToRender[index + 1];
                                    let gapHtml = '';

                                    if (nextClass && c.endMin !== null && nextClass.startMin !== null && nextClass.startMin > c.endMin) {
                                        const gapDur = nextClass.startMin - c.endMin;
                                        if (gapDur >= 15) {
                                            gapHtml = `
                                                <div class="my-1 rounded-lg border border-dashed border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-950 dark:text-amber-100">
                                                    ☕ ${formatDuration(gapDur)} · ${formatMinutesToTime(c.endMin)}–${formatMinutesToTime(nextClass.startMin)}
                                                </div>
                                            `;
                                        }
                                    }

                                    return `
                                        <div class="rounded-lg border ${p.border} ${p.accent} border-l-4 ${p.bg} p-3.5 transition-all">
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 mb-1 text-caption">
                                                        <span class="rounded px-2 py-0.5 text-xs font-bold ${p.timeBadge}">
                                                            ${app.escapeHtml(c.formattedTime)}
                                                        </span>
                                                        ${c.courseCode ? `<span class="rounded bg-black/5 dark:bg-white/10 px-1.5 py-0.5 text-xs font-semibold text-ink-subtle uppercase">${app.escapeHtml(c.courseCode)}</span>` : ''}
                                                        <span class="text-ink-subtle">&middot; ${formatDuration(c.durationMinutes)}</span>
                                                    </div>
                                                    <h4 class="text-subtitle font-bold text-ink">
                                                        ${c.classUrl ? `<a href="${c.classUrl}" class="hover:text-brand">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                                    </h4>
                                                    <div class="mt-2 flex flex-wrap gap-3 text-caption text-ink-subtle">
                                                        <span>${app.escapeHtml(c.roomName)}</span>
                                                        <span>${app.escapeHtml(c.className)}</span>
                                                        <span>${app.escapeHtml(c.lecturerName)}</span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2 shrink-0 mt-2 sm:mt-0">
                                                    ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('primary', { small: true })}">Buka kelas</a>` : ''}
                                                    ${c.detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${c.detailApiUrl}">Lihat pertemuan</button>` : ''}
                                                </div>
                                            </div>
                                            <div class="mt-3 hidden" data-meeting-panel>
                                                ${app.renderEmptyState('Memuat daftar pertemuan.', 'secondary')}
                                            </div>
                                        </div>
                                        ${gapHtml}
                                    `;
                                }).join('')}

                            </div>
                        </div>
                    `;
                }).join('');

                bindMeetingPanels(timelineHost);
            };

            const renderCard = (c) => {
                return `
                    <div class="mycic-card-interactive" data-class-card>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="mb-1 flex items-center gap-2 text-caption text-ink-subtle">
                                    ${app.renderBadge(c.courseCode || '-', 'brand')}
                                    <span>${app.escapeHtml(c.lecturerName)}</span>
                                </div>
                                <h3 class="mb-2 text-subtitle font-bold text-ink">
                                    ${c.classUrl ? `<a href="${c.classUrl}" class="text-ink no-underline hover:text-brand">${app.escapeHtml(c.courseName)}</a>` : app.escapeHtml(c.courseName)}
                                </h3>
                                <div class="flex flex-wrap gap-3 text-caption text-ink-subtle">
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M11 15h1" /><path d="M12 15v3" /></svg>
                                        <span class="${app.textToneClass('brand')}">${app.escapeHtml(c.day || '-')}</span> &middot; ${app.escapeHtml(c.formattedTime)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 19l18 0" /><path d="M5 6m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" /></svg>
                                        ${app.escapeHtml(c.className)}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7l6 -3l6 3l6 -3l0 13l-6 3l-6 -3l-6 3l0 -13" /><path d="M9 4l0 13" /><path d="M15 7l0 13" /></svg>
                                        ${app.escapeHtml(c.roomName)}
                                    </span>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-row gap-2 md:flex-col">
                                ${c.classUrl ? `<a href="${c.classUrl}" class="${app.buttonClass('primary', { small: true })}">Buka kelas</a>` : app.renderDisabledButton('Tidak tersedia')}
                                ${c.detailApiUrl ? `<button type="button" class="${app.buttonClass('secondary', { small: true })}" data-meeting-toggle data-detail-api="${c.detailApiUrl}">Lihat pertemuan</button>` : ''}
                            </div>
                        </div>
                        <div class="mt-3 hidden" data-meeting-panel>
                            ${app.renderEmptyState('Memuat daftar pertemuan.', 'secondary')}
                        </div>
                    </div>
                `;
            };

            const renderCardList = (classes, selectedDay, searchQuery = '') => {
                let filtered = classes;
                if (selectedDay !== 'all') {
                    filtered = filtered.filter((c) => c.day === selectedDay);
                }

                if (searchQuery) {
                    const q = searchQuery.toLowerCase();
                    filtered = filtered.filter((c) =>
                        c.courseName.toLowerCase().includes(q) ||
                        c.courseCode.toLowerCase().includes(q) ||
                        c.lecturerName.toLowerCase().includes(q) ||
                        c.roomName.toLowerCase().includes(q)
                    );
                }

                if (!filtered.length) {
                    const msg = searchQuery
                        ? `Tidak ada kelas yang cocok dengan "${app.escapeHtml(searchQuery)}".`
                        : selectedDay !== 'all'
                        ? `Tidak ada kelas pada hari ${selectedDay}.`
                        : 'Belum ada kelas pada sumber ini.';
                    listHost.innerHTML = app.renderEmptyState(msg, 'secondary');
                    return;
                }

                // Chronological sorting: Senin -> Sabtu, then by startMin
                const dayOrder = { 'Senin': 1, 'Selasa': 2, 'Rabu': 3, 'Kamis': 4, 'Jumat': 5, 'Sabtu': 6, 'Minggu': 7 };
                const sorted = [...filtered].sort((a, b) => {
                    const dA = dayOrder[a.day] || 99;
                    const dB = dayOrder[b.day] || 99;
                    if (dA !== dB) return dA - dB;
                    return (a.startMin ?? 9999) - (b.startMin ?? 9999);
                });

                listHost.innerHTML = sorted.map(renderCard).join('');
                bindMeetingPanels(listHost);
            };

            const renderUnscheduledClasses = (unscheduled) => {
                if (!unscheduled || !unscheduled.length) {
                    unscheduledHost.classList.add('hidden');
                    unscheduledHost.innerHTML = '';
                    return;
                }

                unscheduledHost.classList.remove('hidden');
                unscheduledHost.innerHTML = `
                    <div class="mycic-card p-4">
                        <h3 class="text-subtitle font-bold text-ink mb-3">Tanpa jadwal tetap (${unscheduled.length})</h3>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            ${unscheduled.map(renderCard).join('')}
                        </div>
                    </div>
                `;
                bindMeetingPanels(unscheduledHost);
            };

            const renderMeetingLinks = (panel, payload) => {
                const materials = payload.class_detail?.course_materials || [];

                if (!materials.length) {
                    panel.innerHTML = app.renderEmptyState('Belum ada pertemuan yang tersedia.', 'secondary');
                    return;
                }

                panel.innerHTML = `
                    <div class="mycic-divider pt-3">
                        <div class="mb-2 text-caption font-medium text-ink-subtle">Daftar Pertemuan</div>
                        <div class="flex flex-wrap gap-2">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const labelNumber = material.meeting_number || index + 1;

                                if (!meetingUrl) {
                                    return `
                                        <span class="${app.buttonClass('secondary', { small: true })} pointer-events-none cursor-help opacity-50" title="Detail meeting belum tersedia">
                                            P${app.escapeHtml(labelNumber)}
                                        </span>
                                    `;
                                }

                                return `
                                    <a href="${meetingUrl}" class="${app.buttonClass('ghost', { small: true })}" title="Buka Pertemuan ${app.escapeHtml(labelNumber)}">
                                        P${app.escapeHtml(labelNumber)}
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const bindMeetingPanels = (root = document) => {
                root.querySelectorAll('[data-meeting-toggle]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const parent = button.closest('[data-class-card]') || button.closest('.rounded-lg');
                        const panel = parent?.querySelector('[data-meeting-panel]');

                        if (!panel) return;

                        const alreadyOpen = !panel.classList.contains('hidden');
                        if (alreadyOpen) {
                            panel.classList.add('hidden');
                            button.textContent = 'Lihat pertemuan';
                            return;
                        }

                        panel.classList.remove('hidden');
                        panel.innerHTML = app.renderEmptyState('Memuat daftar pertemuan.', 'secondary');
                        button.textContent = 'Tutup pertemuan';

                        const detailUrl = button.dataset.detailApi;
                        const cachedDetail = app.getCachedResponse(detailUrl);

                        if (cachedDetail) {
                            renderMeetingLinks(panel, cachedDetail.payload);
                        }

                        try {
                            const payload = await app.request(detailUrl);
                            renderMeetingLinks(panel, payload);
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                            panel.innerHTML = app.renderEmptyState('Gagal memuat daftar pertemuan.', 'danger');
                            button.textContent = 'Lihat pertemuan';
                        }
                    });
                });
            };

            const openModal = () => {
                modalEl.classList.remove('hidden');
                modalEl.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            };

            const closeModal = () => {
                modalEl.classList.add('hidden');
                modalEl.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
                if (modalMeetingPanel) {
                    modalMeetingPanel.classList.add('hidden');
                    modalMeetingPanel.innerHTML = '';
                }
                if (modalMeetingBtn) {
                    modalMeetingBtn.textContent = 'Lihat pertemuan';
                }
            };

            const bindModalTriggers = () => {
                document.querySelectorAll('[data-class-modal-trigger]').forEach((el) => {
                    el.addEventListener('click', () => {
                        const classId = parseInt(el.dataset.classModalTrigger, 10);
                        const c = cachedClassesData[classId];
                        if (!c) return;

                        modalCode.textContent = c.courseCode || 'KELAS';
                        modalDay.textContent = c.day || '-';
                        modalTitle.textContent = c.courseName;
                        modalTime.textContent = c.formattedTime;
                        modalDuration.textContent = `Durasi: ${formatDuration(c.durationMinutes)}`;
                        modalRoom.textContent = c.roomName;
                        modalGroup.textContent = `Kelas: ${c.className}`;
                        modalLecturer.textContent = c.lecturerName;

                        let contactsHtml = '';
                        if (c.lecturerContact) {
                            contactsHtml += `<span>📞 Telepon/WA: ${app.escapeHtml(c.lecturerContact)}</span>`;
                        }
                        if (c.lecturerEmail && c.lecturerEmail !== '-') {
                            contactsHtml += `<span>✉️ Email: ${app.escapeHtml(c.lecturerEmail)}</span>`;
                        }
                        if (!contactsHtml) {
                            contactsHtml = '<span class="text-ink-subtle">Kontak dosen tidak tersedia</span>';
                        }
                        modalContacts.innerHTML = contactsHtml;

                        if (c.classUrl) {
                            modalOpenBtn.href = c.classUrl;
                            modalOpenBtn.classList.remove('hidden');
                        } else {
                            modalOpenBtn.classList.add('hidden');
                        }

                        currentModalDetailApi = c.detailApiUrl;
                        if (currentModalDetailApi) {
                            modalMeetingBtn.classList.remove('hidden');
                            modalMeetingBtn.textContent = 'Lihat pertemuan';
                            modalMeetingPanel.classList.add('hidden');
                            modalMeetingPanel.innerHTML = '';
                        } else {
                            modalMeetingBtn.classList.add('hidden');
                            modalMeetingPanel.classList.add('hidden');
                        }

                        openModal();
                    });
                });
            };

            if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
            if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !modalEl.classList.contains('hidden')) {
                    closeModal();
                }
            });

            if (modalMeetingBtn) {
                modalMeetingBtn.addEventListener('click', async () => {
                    if (!currentModalDetailApi) return;
                    const alreadyOpen = !modalMeetingPanel.classList.contains('hidden');
                    if (alreadyOpen) {
                        modalMeetingPanel.classList.add('hidden');
                        modalMeetingBtn.textContent = 'Lihat pertemuan';
                        return;
                    }

                    modalMeetingPanel.classList.remove('hidden');
                    modalMeetingPanel.innerHTML = app.renderEmptyState('Memuat daftar pertemuan.', 'secondary');
                    modalMeetingBtn.textContent = 'Tutup pertemuan';

                    const cachedDetail = app.getCachedResponse(currentModalDetailApi);
                    if (cachedDetail) {
                        renderMeetingLinks(modalMeetingPanel, cachedDetail.payload);
                    }

                    try {
                        const payload = await app.request(currentModalDetailApi);
                        renderMeetingLinks(modalMeetingPanel, payload);
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                        modalMeetingPanel.innerHTML = app.renderEmptyState('Gagal memuat daftar pertemuan.', 'danger');
                        modalMeetingBtn.textContent = 'Lihat pertemuan';
                    }
                });
            }

            const switchView = (targetView) => {
                currentViewMode = targetView;
                localStorage.setItem('mycic-class-view-mode', targetView);

                viewTabs.forEach((tab) => {
                    const isCurrent = tab.dataset.viewTarget === targetView;
                    tab.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
                    if (isCurrent) {
                        tab.className = 'mycic-view-tab inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 text-xs sm:text-sm font-bold rounded-lg transition-all bg-panel text-brand shadow-xs border border-border/40';
                    } else {
                        tab.className = 'mycic-view-tab inline-flex items-center gap-1.5 whitespace-nowrap px-3 py-1.5 text-xs sm:text-sm font-semibold rounded-lg transition-all text-ink-muted hover:text-ink';
                    }
                });

                Object.keys(viewPanels).forEach((k) => {
                    if (k === targetView) {
                        viewPanels[k].classList.remove('hidden');
                    } else {
                        viewPanels[k].classList.add('hidden');
                    }
                });
            };

            viewTabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    switchView(tab.dataset.viewTarget);
                });
            });

            switchView(currentViewMode);

            const setDayFilter = (day) => {
                selectedDayFilter = day;
                const { dayMap, unscheduled } = processDayGroups(cachedClassesData);
                renderDaySummaryStrip(dayMap);
                renderBlockingGrid(dayMap);
                renderTimeline(dayMap);
                renderCardList(cachedClassesData, selectedDayFilter, currentSearchQuery);
                renderUnscheduledClasses(unscheduled);
            };

            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    currentSearchQuery = e.target.value;
                    const { dayMap, unscheduled } = processDayGroups(cachedClassesData);
                    renderBlockingGrid(dayMap);
                    renderTimeline(dayMap);
                    renderCardList(cachedClassesData, selectedDayFilter, currentSearchQuery);
                    renderUnscheduledClasses(unscheduled);
                });
            }

            const renderSource = (payload) => {
                cachedPayload = payload;
                const rawClasses = payload.classes || [];
                const parsedClasses = rawClasses.map(parseClassItem);
                cachedClassesData = parsedClasses;

                const { dayMap, unscheduled } = processDayGroups(parsedClasses);

                renderSummary(payload, parsedClasses, dayMap);
                renderDaySummaryStrip(dayMap);
                renderBlockingGrid(dayMap);
                renderTimeline(dayMap);
                renderCardList(parsedClasses, selectedDayFilter, currentSearchQuery);
                renderUnscheduledClasses(unscheduled);
                switchView(currentViewMode);
            };

            app.primeCachedView(endpoint, renderSource, 'daftar kelas utama');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const loadSource = async ({ force = false } = {}) => {
                const usedCachedPayload = app.primeCachedView(endpoint, renderSource, 'daftar kelas utama');

                if (!usedCachedPayload) {
                    blockingGridHost.innerHTML = '<div class="mycic-empty py-12"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                    timelineHost.innerHTML = '<div class="mycic-empty py-12"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                    listHost.innerHTML = '<div class="mycic-empty py-8"><p class="mycic-empty-title text-ink-subtle">Memuat jadwal...</p></div>';
                }

                try {
                    const payload = await app.request(endpoint, { fresh: force });
                    renderSource(payload);
                    app.markViewSynced('Daftar kelas utama', payload, { force });
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    blockingGridHost.innerHTML = app.renderEmptyState('Gagal memuat jadwal kelas.', 'danger');
                    timelineHost.innerHTML = app.renderEmptyState('Gagal memuat jadwal kelas.', 'danger');
                    listHost.innerHTML = app.renderEmptyState('Gagal memuat daftar kelas.', 'danger');
                }
            };

            app.registerSyncHandler('class-index', loadSource);

            await loadSource();
        });
    </script>
@endpush
