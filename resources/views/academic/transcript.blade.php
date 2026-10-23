@extends('layouts.app')

@section('title', 'Transkrip & Progres Studi')
@section('page_id', 'transcript-index')

@php
    $academicLinks = [
        ['href' => route('krs.index'), 'label' => 'KRS & Perwalian', 'active' => false],
        ['href' => route('grades.index'), 'label' => 'Nilai', 'active' => false],
        ['href' => route('transcript.index'), 'label' => 'Transkrip', 'active' => true],
    ];
@endphp

@section('content')
    <nav class="mb-4" aria-label="Menu akademik">
        <ul class="inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-xl border border-border/40 bg-surface p-1">
            @foreach ($academicLinks as $link)
                <li class="shrink-0">
                    <a
                        href="{{ $link['href'] }}"
                        class="inline-flex min-h-11 items-center whitespace-nowrap sm:min-h-10 rounded-lg px-3 py-2 text-xs font-semibold transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm {{ $link['active'] ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs' : 'text-ink-muted hover:text-ink' }}"
                        @if ($link['active']) aria-current="page" @endif
                    >{{ $link['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <header class="mb-4">
        <h1 class="mycic-page-heading">Transkrip</h1>
        <p class="mycic-page-subheading">IPK, progres SKS menuju lulus, dan apa yang bisa kamu perbaiki.</p>
        <p class="mt-1 text-caption text-ink-subtle" data-transcript-updated></p>
    </header>

    <div class="flex flex-col gap-5">
        <div id="transcript-alerts" class="flex flex-col gap-3 empty:hidden"></div>

        <div id="transcript-hero">
            <div class="mycic-card" aria-busy="true">
                <div class="mycic-card-body grid gap-5 md:grid-cols-3 md:items-center">
                    <span class="sr-only">Memuat transkrip…</span>
                    <div class="flex flex-col gap-2">
                        <div class="mycic-skeleton h-3 w-10"></div>
                        <div class="mycic-skeleton h-12 w-28"></div>
                    </div>
                    <div class="flex flex-col gap-2 md:col-span-2">
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                        <div class="mycic-skeleton h-3.5 w-full"></div>
                        <div class="mycic-skeleton h-3 w-2/3"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-5 md:grid-cols-2" id="transcript-charts">
            <div id="transcript-trend" class="min-w-0">
                <div class="mycic-card" aria-busy="true"><div class="mycic-card-body flex flex-col gap-3"><div class="mycic-skeleton h-4 w-24"></div><div class="mycic-skeleton h-20 w-full"></div></div></div>
            </div>
            <div id="transcript-letters" class="min-w-0">
                <div class="mycic-card" aria-busy="true"><div class="mycic-card-body flex flex-col gap-3"><div class="mycic-skeleton h-4 w-24"></div><div class="mycic-skeleton h-3 w-full"></div><div class="mycic-skeleton h-3 w-2/3"></div></div></div>
            </div>
        </div>

        <div id="transcript-low"></div>

        <section class="mycic-card" aria-labelledby="transcript-sim-title" id="transcript-simulator">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="transcript-sim-title">Simulasi target IPK</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Berapa rata-rata IPS yang kamu butuhkan supaya IPK mencapai target.</p>
            </div>
            <form class="mycic-card-body flex flex-col gap-4" id="transcript-sim-form" novalidate>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="mycic-field">
                        <label for="transcript-sim-target" class="mycic-label">Target IPK</label>
                        <input
                            type="number"
                            id="transcript-sim-target"
                            name="target_ipk"
                            class="mycic-input tabular-nums"
                            inputmode="decimal"
                            min="0"
                            max="4"
                            step="0.01"
                            aria-describedby="transcript-sim-target-hint transcript-sim-target-error"
                            disabled
                        >
                        <p class="mycic-field-hint" id="transcript-sim-target-hint">Antara 0 dan 4, dua angka di belakang koma.</p>
                        <p class="mycic-field-error hidden" id="transcript-sim-target-error"></p>
                    </div>
                    <div class="mycic-field">
                        <label for="transcript-sim-sks" class="mycic-label">SKS yang akan ditempuh</label>
                        <input
                            type="number"
                            id="transcript-sim-sks"
                            name="next_sks"
                            class="mycic-input tabular-nums"
                            inputmode="numeric"
                            min="1"
                            max="200"
                            step="1"
                            aria-describedby="transcript-sim-sks-hint transcript-sim-sks-error"
                            disabled
                        >
                        <p class="mycic-field-hint" id="transcript-sim-sks-hint">Satu semester biasanya 18–24 SKS.</p>
                        <p class="mycic-field-error hidden" id="transcript-sim-sks-error"></p>
                    </div>
                </div>
                <div class="flex flex-col gap-2" id="transcript-sim-presets"></div>
                <div id="transcript-sim-result" aria-live="polite">
                    <p class="text-sm text-ink-subtle">Simulasi siap setelah transkrip dimuat.</p>
                </div>
                <p class="text-caption text-ink-subtle">Perkiraan dari IPK dan SKS saat ini. Perbaikan nilai MK yang diulang tidak ikut dihitung.</p>
            </form>
        </section>

        <section class="mycic-card" aria-labelledby="transcript-table-title">
            <div class="mycic-card-header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="mycic-card-title" id="transcript-table-title">Daftar nilai</h2>
                    <p class="mt-0.5 text-caption text-ink-subtle" id="transcript-table-caption">Nilai terbaik tiap mata kuliah.</p>
                </div>
                <div class="mycic-input-wrap w-full sm:w-64">
                    <label for="transcript-search" class="sr-only">Cari mata kuliah, kode, atau nilai</label>
                    <span class="mycic-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    </span>
                    <input
                        type="search"
                        id="transcript-search"
                        class="mycic-input mycic-input-with-icon text-sm"
                        placeholder="Cari mata kuliah…"
                        autocomplete="off"
                        aria-controls="transcript-table"
                        disabled
                    >
                </div>
            </div>
            <div id="transcript-table">
                <div class="mycic-card-body flex flex-col divide-y divide-border-light" aria-busy="true">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="flex flex-col gap-1.5">
                                <div class="mycic-skeleton h-4 w-40 sm:w-56"></div>
                                <div class="mycic-skeleton h-3 w-24"></div>
                            </div>
                            <div class="mycic-skeleton h-8 w-8"></div>
                        </div>
                    @endfor
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const endpoint = '/api/academic/transcript';
            const gradesUrl = @json(route('grades.index'));
            const announcementUrl = @json(route('announcement.index'));
            const hosts = {
                alerts: document.getElementById('transcript-alerts'),
                hero: document.getElementById('transcript-hero'),
                charts: document.getElementById('transcript-charts'),
                simulator: document.getElementById('transcript-simulator'),
                trend: document.getElementById('transcript-trend'),
                letters: document.getElementById('transcript-letters'),
                low: document.getElementById('transcript-low'),
                table: document.getElementById('transcript-table'),
                tableCaption: document.getElementById('transcript-table-caption'),
                search: document.getElementById('transcript-search'),
                updated: document.querySelector('[data-transcript-updated]'),
                simForm: document.getElementById('transcript-sim-form'),
                simTarget: document.getElementById('transcript-sim-target'),
                simSks: document.getElementById('transcript-sim-sks'),
                simTargetError: document.getElementById('transcript-sim-target-error'),
                simSksError: document.getElementById('transcript-sim-sks-error'),
                simPresets: document.getElementById('transcript-sim-presets'),
                simResult: document.getElementById('transcript-sim-result'),
            };
            const state = { data: null, meta: null, query: '', simReady: false };
            const esc = (value) => app.escapeHtml(value);
            const maxWeight = 4;
            const letterOrder = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'E'];
            const letterClasses = {
                success: 'bg-success-soft text-success',
                brand: 'bg-surface-tint text-brand-pressed',
                warning: 'bg-warning-soft text-warning',
                danger: 'bg-danger-soft text-danger',
                neutral: 'bg-surface text-ink-subtle',
            };

            const cachedData = (url) => {
                try {
                    return app.getCachedResponse(url)?.payload?.data ?? null;
                } catch (error) {
                    return null;
                }
            };

            const letterTone = (letter) => ({ A: 'success', B: 'brand', C: 'warning', D: 'danger', E: 'danger' }[String(letter || '').trim().toUpperCase().charAt(0)] || 'neutral');

            const formatIp = (value) => (typeof value === 'number' && Number.isFinite(value) ? value.toFixed(2) : '–');

            const letterBadge = (letter, size = 'h-9 w-9 text-sm') => `
                <span class="flex ${size} shrink-0 items-center justify-center rounded-lg font-bold ${letterClasses[letterTone(letter)]}" aria-hidden="true">${esc(letter || '–')}</span>
            `;

            // Only graded SKS weigh in the IPK: conversion SKS (part of sks_passed) carry no grade.
            const ipkTotals = () => {
                const summary = state.data?.summary || {};
                const sks = Number.isInteger(summary.sks_total)
                    ? Math.max(0, summary.sks_total)
                    : Math.max(0, (Number(summary.sks_passed) || 0) - (Number(summary.conversion_sks) || 0));
                const qualityPoints = Number(summary.quality_points_total) || 0;

                return { sks, qualityPoints, ipk: sks > 0 ? Math.round((qualityPoints / sks) * 100) / 100 : null };
            };

            const renderFreshness = () => {
                const meta = state.meta || {};
                const ago = app.formatTimeAgo(meta.synced_at || state.data?.extracted_at || meta.cached_at);
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

            const renderAlerts = () => {
                const data = state.data;
                const summary = data.summary || {};
                const alerts = [];
                const fieldLabels = { ipk: 'IPK', sks_total: 'jumlah SKS', quality_points_total: 'total mutu' };

                if (summary.source === 'computed') {
                    alerts.push(`<div class="${app.alertTone('info')}">Ringkasan resmi portal belum terbaca. Angka di halaman ini <strong class="font-semibold">dihitung MyCIC</strong> dari KHS.</div>`);
                } else if (summary.mismatch) {
                    const fields = (summary.mismatch_fields || []).map((field) => fieldLabels[field] || field).join(', ');

                    alerts.push(`
                        <div class="${app.alertTone('warning')}">
                            <div class="font-semibold">Angka resmi portal berbeda dengan hitungan MyCIC</div>
                            <p class="mt-1">Yang berbeda: ${esc(fields || 'ringkasan nilai')}. MyCIC memakai angka resmi portal. Kalau ragu, cocokkan dengan KHS di portal kampus.</p>
                        </div>
                    `);
                }

                if (data.is_partial) {
                    alerts.push(`<div class="${app.alertTone('warning')}"><span class="me-1">${app.renderBadge('Data sebagian', 'warning')}</span> Halaman nilai SIPANDAI gagal dibaca, jadi MyCIC memakai nilai dari halaman KRS.</div>`);
                }

                hosts.alerts.innerHTML = alerts.join('');
            };

            const renderHero = () => {
                const data = state.data;
                const summary = data.summary || {};
                const progress = data.progress || {};
                const target = Number(data.target_sks || progress.target_sks) || null;
                const earned = Number(progress.earned_sks ?? summary.sks_passed) || 0;
                const conversion = data.conversion || {};
                const conversionSks = Math.min(earned, Number(summary.conversion_sks) || 0);
                const remaining = Number.isInteger(data.remaining_sks) ? data.remaining_sks : (target ? Math.max(0, target - earned) : null);
                const currentLoad = data.current_load && Number(data.current_load.sks) > 0 ? data.current_load : null;
                const projected = progress.projected_semesters_remaining;
                const average = typeof progress.average_sks_per_semester === 'number' ? progress.average_sks_per_semester : null;
                const badges = [
                    summary.ipk === null || summary.ipk === undefined ? '' : app.renderBadge(summary.source === 'official' ? 'Resmi portal' : 'Dihitung MyCIC', summary.source === 'official' ? 'success' : 'info'),
                    summary.predicate_estimate ? app.renderBadge(`Perkiraan predikat: ${summary.predicate_estimate}`, 'brand') : '',
                ].filter(Boolean).join('');
                const facts = [
                    { label: 'Sisa SKS', value: remaining === null ? '–' : `${remaining}`, note: target ? `dari target ${target} SKS` : '' },
                    {
                        label: 'Perkiraan lulus',
                        value: projected === null || projected === undefined ? '–' : (projected === 0 ? 'Selesai' : `${projected} smt lagi`),
                        note: average ? `rata-rata ${Number.isInteger(average) ? average : average.toFixed(1)} SKS lulus/semester` : 'butuh minimal satu semester bernilai',
                    },
                    {
                        label: 'Sedang ditempuh',
                        value: currentLoad ? `${currentLoad.sks} SKS` : '–',
                        note: currentLoad
                            ? `${currentLoad.courses} MK di KRS semester ini${data.current_load.graded_courses > 0 ? ` · ${data.current_load.graded_courses} MK sudah dinilai` : ''}`
                            : (Number(data.current_load?.graded_courses) > 0 ? 'Semua MK di KRS ini sudah dinilai' : 'KRS semester ini belum terbaca'),
                    },
                ];
                const caption = [
                    progress.target_sks_source === 'config' ? 'Target SKS memakai standar 144 SKS karena portal belum menyebut sisa SKS.' : '',
                    conversionSks > 0 ? `Termasuk ${conversionSks} SKS konversi (${conversion.course_count} MK di KRS semester T), tanpa nilai sehingga tidak masuk IPK.` : '',
                    conversion.status === 'failed' ? 'KRS konversi (semester T) gagal dibaca, jadi sisa SKS belum dikurangi SKS konversi.' : '',
                    progress.target_sks_source !== 'config' && conversionSks === 0 && conversion.status !== 'failed' && data.excluded_semesters?.length
                        ? `Semester ${data.excluded_semesters.join(', ')} (non-reguler) tidak dihitung.`
                        : '',
                ].filter(Boolean).join(' ');

                hosts.hero.innerHTML = `
                    <section class="mycic-card" aria-labelledby="transcript-ipk-label">
                        <div class="mycic-card-body grid gap-5 md:grid-cols-3 md:items-center">
                            <div class="min-w-0">
                                <div class="mycic-metric-label" id="transcript-ipk-label">IPK</div>
                                <div class="text-5xl font-extrabold tabular-nums tracking-tight text-ink">${esc(formatIp(summary.ipk))}</div>
                                <div class="mt-1 text-caption text-ink-subtle">${esc(summary.sks_total ?? summary.sks_passed ?? 0)} SKS dinilai · mutu ${esc(summary.quality_points_total ?? 0)}</div>
                                ${badges ? `<div class="mt-2 flex flex-wrap gap-1.5">${badges}</div>` : ''}
                            </div>
                            <div class="flex min-w-0 flex-col gap-4 md:col-span-2">
                                ${target ? app.renderProgressBar({
                                    max: target,
                                    label: 'SKS menuju lulus',
                                    valueLabel: `${earned} dari ${target} SKS`,
                                    segments: [
                                        { value: earned - conversionSks, tone: 'success', label: 'Lulus', valueLabel: `${earned - conversionSks}` },
                                        ...(conversionSks > 0 ? [{ value: conversionSks, tone: 'info', label: 'Konversi', valueLabel: `${conversionSks}` }] : []),
                                        ...(currentLoad ? [{ value: currentLoad.sks, tone: 'brand', striped: true, label: 'Sedang ditempuh', valueLabel: `${currentLoad.sks}` }] : []),
                                    ],
                                    remainderLabel: 'Belum diambil',
                                    caption,
                                    size: 'lg',
                                }) : '<p class="text-sm text-ink-muted">Target SKS belum terbaca.</p>'}
                                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    ${facts.map((fact) => `
                                        <div class="min-w-0 rounded-[var(--radius-control)] bg-surface/60 px-3 py-2.5">
                                            <div class="flex items-baseline justify-between gap-2 sm:flex-col sm:items-start sm:gap-0.5">
                                                <dt class="mycic-metric-label">${esc(fact.label)}</dt>
                                                <dd class="shrink-0 text-lg font-bold tabular-nums text-ink">${esc(fact.value)}</dd>
                                            </div>
                                            ${fact.note ? `<dd class="text-caption text-ink-subtle">${esc(fact.note)}</dd>` : ''}
                                        </div>
                                    `).join('')}
                                </dl>
                            </div>
                        </div>
                    </section>
                `;
            };

            const renderTrend = () => {
                const series = (state.data.ips_series || []).filter((point) => typeof point.ips === 'number');
                const latest = state.data.latest_ips;
                const delta = typeof latest?.delta === 'number' ? latest.delta : null;
                let headline = 'Belum ada IPS yang bisa digambar.';

                if (latest) {
                    const semesterLabel = `IPS semester ${latest.semester}${latest.is_complete === false ? ' (sebagian)' : ''}`;

                    headline = delta === null
                        ? `${semesterLabel}: ${formatIp(latest.ips)}`
                        : `${semesterLabel}: ${formatIp(latest.ips)} (${delta > 0 ? 'naik' : (delta < 0 ? 'turun' : 'tetap')}${delta ? ` ${Math.abs(delta).toFixed(2)}` : ''} dari semester ${latest.previous_semester})`;
                }

                hosts.trend.innerHTML = `
                    <section class="mycic-card h-full" aria-labelledby="transcript-trend-title">
                        <div class="mycic-card-header">
                            <h2 class="mycic-card-title" id="transcript-trend-title">Tren IPS</h2>
                            <p class="mt-0.5 text-caption text-ink-subtle">${esc(headline)}</p>
                        </div>
                        <div class="mycic-card-body flex flex-col gap-4">
                            ${series.length ? app.renderSparkline(series.map((point) => ({ label: `Smt ${point.semester}`, value: point.ips })), {
                                label: 'IPS per semester',
                                min: 0,
                                max: 4,
                                valueFormat: (value) => value.toFixed(2),
                            }) : app.renderEmptyState('Belum ada semester yang dinilai.', 'secondary', 'Tren muncul setelah nilai semester pertama keluar.')}
                            ${series.length ? `
                                <ul class="flex flex-wrap gap-2" aria-label="IPS tiap semester">
                                    ${series.map((point) => `
                                        <li class="mycic-chip tabular-nums" title="${esc(point.sks)} SKS · ${esc(point.graded_count)} MK">
                                            Smt ${esc(point.semester)} · <span class="text-ink">${esc(formatIp(point.ips))}</span>${point.is_complete === false ? ' <span class="text-warning">(sebagian)</span>' : ''}${point.ips_mismatch ? ' <span class="text-warning">(beda hitungan)</span>' : ''}
                                        </li>
                                    `).join('')}
                                </ul>
                            ` : ''}
                        </div>
                    </section>
                `;
            };

            const renderLetters = () => {
                const counts = state.data.letter_counts || {};
                const letters = Object.keys(counts).sort((left, right) => {
                    const leftIndex = letterOrder.indexOf(left);
                    const rightIndex = letterOrder.indexOf(right);

                    return (leftIndex === -1 ? 99 : leftIndex) - (rightIndex === -1 ? 99 : rightIndex);
                });
                const total = letters.reduce((sum, letter) => sum + (Number(counts[letter]) || 0), 0);
                const tones = { success: 'success', brand: 'brand', warning: 'warning', danger: 'danger', neutral: 'neutral' };
                const good = letters.filter((letter) => ['A', 'B'].includes(letter.charAt(0))).reduce((sum, letter) => sum + (Number(counts[letter]) || 0), 0);

                hosts.letters.innerHTML = `
                    <section class="mycic-card h-full" aria-labelledby="transcript-letters-title">
                        <div class="mycic-card-header">
                            <h2 class="mycic-card-title" id="transcript-letters-title">Sebaran nilai</h2>
                            <p class="mt-0.5 text-caption text-ink-subtle">${total ? `${esc(good)} dari ${esc(total)} MK bernilai A atau B` : 'Jumlah MK per huruf mutu'}</p>
                        </div>
                        <div class="mycic-card-body">
                            ${total ? app.renderStackedBar(letters.map((letter) => ({
                                label: letter,
                                value: Number(counts[letter]) || 0,
                                tone: tones[letterTone(letter)],
                            })), { label: 'Sebaran nilai huruf', valueFormat: (value) => `${value} MK`, size: 'lg' }) : app.renderEmptyState('Belum ada nilai.', 'secondary')}
                        </div>
                    </section>
                `;
            };

            const renderLowGrades = () => {
                const low = state.data.low_grades || {};
                const courses = [...(low.e_courses || []), ...(low.d_courses || [])];
                const cCount = (low.low_grade_courses || []).filter((course) => String(course.letter || '').toUpperCase().startsWith('C')).length;
                const { sks, qualityPoints, ipk } = ipkTotals();
                const currentKrs = cachedData('/api/academic/krs');
                const takingNow = new Set((currentKrs?.courses || []).map((course) => String(course.code || '').toUpperCase()));

                if (!courses.length) {
                    hosts.low.innerHTML = `
                        <section class="mycic-card" aria-labelledby="transcript-low-title">
                            <div class="mycic-card-body flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-success-soft text-success" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10" /></svg>
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-sm font-bold text-ink" id="transcript-low-title">Tidak ada nilai D atau E</h2>
                                    <p class="text-caption text-ink-subtle">${cCount ? `Ada ${esc(cCount)} MK bernilai C. Mengulangnya bisa menaikkan IPK.` : 'Pertahankan! Semua mata kuliah lulus dengan nilai minimal C.'}</p>
                                </div>
                            </div>
                        </section>
                    `;

                    return;
                }

                hosts.low.innerHTML = `
                    <section class="mycic-card" aria-labelledby="transcript-low-title">
                        <div class="mycic-card-header">
                            <h2 class="mycic-card-title" id="transcript-low-title">Perlu diperbaiki</h2>
                            <p class="mt-0.5 text-caption text-ink-subtle">Nilai D dan E menurunkan IPK. Mengulang MK ini lewat KRS reguler atau Semester Pendek bisa menaikkannya.</p>
                        </div>
                        <ul class="flex flex-col px-4 md:px-5" aria-label="Mata kuliah bernilai D atau E">
                            ${courses.map((course) => {
                                const courseSks = Number(course.sks) || 0;
                                const weight = Number(course.weight) || 0;
                                const projected = sks > 0 ? Math.round(((qualityPoints + courseSks * (3 - weight)) / sks) * 100) / 100 : null;
                                const gain = projected !== null && ipk !== null ? Math.round((projected - ipk) * 100) / 100 : null;
                                const retaking = takingNow.has(String(course.code || '').toUpperCase());

                                return `
                                    <li class="mycic-list-row items-center">
                                        ${letterBadge(course.letter)}
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-ink">${esc(course.name || 'Mata kuliah')}<span class="sr-only">, nilai ${esc(course.letter)}</span></div>
                                            <div class="text-caption text-ink-subtle"><span class="font-mono">${esc(course.code || '–')}</span> · Smt ${esc(course.semester ?? '–')} · ${esc(courseSks)} SKS${course.attempt_count > 1 ? ` · sudah diambil ${esc(course.attempt_count)}×` : ''}</div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                ${retaking ? app.renderBadge('Sedang diulang semester ini', 'brand') : ''}
                                                ${projected !== null && gain > 0 ? `<span class="text-caption text-ink-muted">Kalau diulang dan dapat B, IPK jadi <span class="font-semibold tabular-nums text-success">${esc(formatIp(projected))}</span> (+${esc(gain.toFixed(2))})</span>` : ''}
                                            </div>
                                        </div>
                                    </li>
                                `;
                            }).join('')}
                        </ul>
                        <div class="flex flex-wrap items-center gap-2 border-t border-border-light px-4 py-3 md:px-5">
                            ${cCount ? `<span class="text-caption text-ink-subtle">Ada juga ${esc(cCount)} MK bernilai C.</span>` : ''}
                            <a href="${esc(announcementUrl)}" class="${app.buttonClass('secondary', { small: true })}">Cek info Semester Pendek</a>
                        </div>
                    </section>
                `;
            };

            const filteredTranscript = () => {
                const rows = Array.isArray(state.data?.transcript) ? state.data.transcript : [];
                const query = state.query.trim().toLowerCase();

                if (!query) {
                    return rows;
                }

                return rows.filter((row) => [row.code, row.name, row.letter, `smt ${row.semester}`, `semester ${row.semester}`]
                    .some((value) => String(value ?? '').toLowerCase().includes(query)));
            };

            const renderTable = () => {
                const rows = Array.isArray(state.data?.transcript) ? state.data.transcript : [];
                const shown = filteredTranscript();
                const totalSks = rows.reduce((sum, row) => sum + (Number(row.sks) || 0), 0);

                hosts.tableCaption.textContent = rows.length
                    ? `${rows.length} MK · ${totalSks} SKS · nilai terbaik bila MK diulang${state.query ? ` · ${shown.length} cocok` : ''}`
                    : 'Nilai terbaik tiap mata kuliah.';

                if (!rows.length) {
                    hosts.table.innerHTML = `<div class="mycic-card-body">${app.renderEmptyState('Belum ada nilai di transkrip.', 'secondary', 'Transkrip terisi setelah nilai semester pertama keluar.')}</div>`;

                    return;
                }

                if (!shown.length) {
                    hosts.table.innerHTML = `<div class="mycic-card-body">${app.renderEmptyState('Tidak ada mata kuliah yang cocok.', 'secondary', 'Coba kata kunci lain, misalnya kode MK atau huruf nilai.')}</div>`;

                    return;
                }

                const attempts = (row) => (row.attempt_count > 1
                    ? `<span class="mycic-badge-neutral" title="${esc((row.attempts || []).map((attempt) => `Smt ${attempt.semester}: ${attempt.letter}`).join(', '))}">diulang ${esc(row.attempt_count)}×</span>`
                    : '');

                hosts.table.innerHTML = `
                    <div class="hidden md:block">
                        <div class="mycic-table-wrap rounded-t-none border-0">
                            <table class="mycic-table">
                                <caption class="sr-only">Transkrip nilai terbaik</caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="w-16">Smt</th>
                                        <th scope="col">Kode</th>
                                        <th scope="col">Mata kuliah</th>
                                        <th scope="col" class="text-end">SKS</th>
                                        <th scope="col" class="text-center">Nilai</th>
                                        <th scope="col" class="text-end">Mutu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${shown.map((row) => `
                                        <tr>
                                            <td class="tabular-nums">${esc(row.semester ?? '–')}</td>
                                            <td class="whitespace-nowrap font-mono text-caption">${esc(row.code || '–')}</td>
                                            <td><span class="font-semibold text-ink">${esc(row.name || 'Mata kuliah')}</span> ${attempts(row)}</td>
                                            <td class="text-end tabular-nums">${esc(row.sks ?? '–')}</td>
                                            <td><div class="flex justify-center">${letterBadge(row.letter, 'h-8 w-8 text-xs')}<span class="sr-only">${esc(row.letter)}</span></div></td>
                                            <td class="text-end tabular-nums">${esc(row.quality_points ?? '–')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <ul class="flex flex-col px-4 md:hidden" aria-label="Transkrip nilai terbaik">
                        ${shown.map((row) => `
                            <li class="mycic-list-row items-center">
                                ${letterBadge(row.letter)}
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-ink">${esc(row.name || 'Mata kuliah')}<span class="sr-only">, nilai ${esc(row.letter)}</span></div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-caption text-ink-subtle">
                                        <span class="font-mono">${esc(row.code || '–')}</span><span aria-hidden="true">·</span><span>Smt ${esc(row.semester ?? '–')}</span><span aria-hidden="true">·</span><span>${esc(row.sks ?? '–')} SKS</span>
                                        ${attempts(row)}
                                    </div>
                                </div>
                                <div class="shrink-0 text-end">
                                    <div class="text-caption text-ink-subtle">Mutu</div>
                                    <div class="text-sm font-bold tabular-nums text-ink">${esc(row.quality_points ?? '–')}</div>
                                </div>
                            </li>
                        `).join('')}
                    </ul>
                `;
            };

            /**
             * Average IPS needed over `additionalSks` to reach `targetIpk`,
             * the same formula as AcademicProgressCalculator::simulateTarget.
             */
            const simulate = (targetIpk, additionalSks) => {
                const { sks, qualityPoints, ipk } = ipkTotals();
                const needed = (targetIpk * (sks + additionalSks) - qualityPoints) / additionalSks;
                const required = needed <= 0 ? 0 : Math.ceil(Number((needed * 100).toFixed(6))) / 100;

                return {
                    currentIpk: ipk,
                    required,
                    achievable: required <= maxWeight,
                    alreadyReached: ipk !== null && ipk >= targetIpk,
                };
            };

            const setFieldError = (input, errorNode, message) => {
                input.setAttribute('aria-invalid', message ? 'true' : 'false');
                errorNode.textContent = message || '';
                errorNode.classList.toggle('hidden', !message);
            };

            const renderSimPresets = () => {
                const data = state.data;
                const targets = (data.progress?.graduation_targets || []).slice().sort((left, right) => left.target_ipk - right.target_ipk);
                const currentLoad = data.current_load && Number(data.current_load.sks) > 0 ? data.current_load : null;
                const remaining = Number.isInteger(data.remaining_sks) ? data.remaining_sks : null;
                const sksPresets = [
                    currentLoad ? { label: `Semester ini · ${currentLoad.sks} SKS`, value: currentLoad.sks } : null,
                    remaining ? { label: `Sampai lulus · ${remaining} SKS`, value: remaining } : null,
                ].filter(Boolean);
                const button = (attribute, value, label, note = '') => `<button type="button" class="mycic-chip min-h-11 sm:min-h-9 transition-colors hover:border-brand/40 hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-pressed:border-brand aria-pressed:bg-surface-tint aria-pressed:text-brand-pressed" ${attribute}="${esc(value)}" aria-pressed="false">${esc(label)}${note ? ` <span class="text-success" aria-hidden="true">✓</span><span class="sr-only">, ${esc(note)}</span>` : ''}</button>`;

                hosts.simPresets.innerHTML = `
                    ${targets.length ? `
                        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Target predikat kelulusan">
                            <span class="text-caption font-semibold text-ink-subtle">Predikat:</span>
                            ${targets.map((item) => button('data-sim-target', item.target_ipk, `${item.predicate} · ${Number(item.target_ipk).toFixed(2)}`, item.already_reached ? 'sudah tercapai' : '')).join('')}
                        </div>
                    ` : ''}
                    ${sksPresets.length ? `
                        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Jumlah SKS">
                            <span class="text-caption font-semibold text-ink-subtle">SKS:</span>
                            ${sksPresets.map((item) => button('data-sim-sks', item.value, item.label)).join('')}
                        </div>
                    ` : ''}
                `;

                hosts.simPresets.querySelectorAll('[data-sim-target]').forEach((chip) => {
                    chip.addEventListener('click', () => {
                        hosts.simTarget.value = Number(chip.dataset.simTarget).toFixed(2);
                        renderSimResult();
                    });
                });

                hosts.simPresets.querySelectorAll('[data-sim-sks]').forEach((chip) => {
                    chip.addEventListener('click', () => {
                        hosts.simSks.value = chip.dataset.simSks;
                        renderSimResult();
                    });
                });
            };

            const syncPresetState = (targetIpk, additionalSks) => {
                hosts.simPresets.querySelectorAll('[data-sim-target]').forEach((chip) => {
                    chip.setAttribute('aria-pressed', Number(chip.dataset.simTarget) === targetIpk ? 'true' : 'false');
                });
                hosts.simPresets.querySelectorAll('[data-sim-sks]').forEach((chip) => {
                    chip.setAttribute('aria-pressed', Number(chip.dataset.simSks) === additionalSks ? 'true' : 'false');
                });
            };

            const renderSimResult = () => {
                if (!state.data) {
                    return;
                }

                const targetIpk = Number(String(hosts.simTarget.value).replace(',', '.'));
                const additionalSks = Number(hosts.simSks.value);
                const targetValid = hosts.simTarget.value !== '' && Number.isFinite(targetIpk) && targetIpk >= 0 && targetIpk <= maxWeight;
                const sksValid = hosts.simSks.value !== '' && Number.isInteger(additionalSks) && additionalSks >= 1 && additionalSks <= 200;

                setFieldError(hosts.simTarget, hosts.simTargetError, targetValid ? '' : 'Isi target IPK antara 0 dan 4.');
                setFieldError(hosts.simSks, hosts.simSksError, sksValid ? '' : 'Isi jumlah SKS berupa angka bulat 1–200.');
                syncPresetState(targetValid ? Math.round(targetIpk * 100) / 100 : null, sksValid ? additionalSks : null);

                if (!targetValid || !sksValid) {
                    hosts.simResult.innerHTML = '<p class="text-sm text-ink-subtle">Lengkapi target IPK dan jumlah SKS untuk melihat hasilnya.</p>';

                    return;
                }

                if (ipkTotals().sks <= 0) {
                    hosts.simResult.innerHTML = `<div class="${app.statusPanelClass('neutral')}">Simulasi butuh minimal satu nilai yang sudah keluar.</div>`;

                    return;
                }

                const result = simulate(targetIpk, additionalSks);
                const best = Math.max(...(state.data.ips_series || []).map((point) => (typeof point.ips === 'number' ? point.ips : 0)), 0);
                const target = targetIpk.toFixed(2);
                const oneSemester = additionalSks <= 24;

                if (result.required === 0) {
                    hosts.simResult.innerHTML = `
                        <div class="${app.statusPanelClass('success')}" data-sim-outcome="safe">
                            <div class="text-sm font-bold">Target aman</div>
                            <p class="mt-0.5 text-sm text-ink">IPK kamu tetap minimal ${esc(target)} berapa pun nilai di ${esc(additionalSks)} SKS berikutnya.</p>
                        </div>
                    `;

                    return;
                }

                if (!result.achievable) {
                    hosts.simResult.innerHTML = `
                        <div class="rounded-[var(--radius-control)] bg-danger-soft p-3" data-sim-outcome="unreachable">
                            <div class="text-sm font-bold text-danger">${oneSemester ? 'Tidak tercapai semester ini' : `Tidak tercapai dengan ${esc(additionalSks)} SKS`}</div>
                            <p class="mt-0.5 text-sm text-ink">Butuh rata-rata IPS ${esc(result.required.toFixed(2))}, padahal nilai maksimal 4.00. Coba hitung dengan lebih banyak SKS atau turunkan target.</p>
                        </div>
                    `;

                    return;
                }

                const tone = result.required > best && best > 0 ? 'warning' : 'brand';
                const context = best > 0
                    ? (result.required > best
                        ? `Lebih tinggi dari IPS terbaikmu sejauh ini (${best.toFixed(2)}), jadi perlu usaha ekstra.`
                        : `Masih di bawah IPS terbaikmu sejauh ini (${best.toFixed(2)}).`)
                    : '';

                hosts.simResult.innerHTML = `
                    <div class="${app.statusPanelClass(tone)} flex items-center gap-4" data-sim-outcome="reachable">
                        <div class="shrink-0 text-center">
                            <div class="text-3xl font-bold leading-none tabular-nums">${esc(result.required.toFixed(2))}</div>
                            <div class="mt-1 text-[0.65rem] font-semibold uppercase tracking-wide">IPS minimal</div>
                        </div>
                        <div class="min-w-0 text-sm text-ink">
                            <p>Rata-rata IPS yang kamu butuhkan di ${esc(additionalSks)} SKS berikutnya supaya IPK jadi ${esc(target)}.</p>
                            ${result.alreadyReached ? `<p class="mt-1 text-caption text-ink-muted">IPK kamu sekarang (${esc(formatIp(result.currentIpk))}) sudah di atas target; jaga rata-rata ini supaya tidak turun.</p>` : ''}
                            ${context ? `<p class="mt-1 text-caption text-ink-muted">${esc(context)}</p>` : ''}
                        </div>
                    </div>
                `;
            };

            const initSimulator = () => {
                const data = state.data;
                const { ipk } = ipkTotals();

                renderSimPresets();
                hosts.simTarget.disabled = false;
                hosts.simSks.disabled = false;

                if (!state.simReady) {
                    const next = (data.progress?.graduation_targets || [])
                        .filter((item) => !item.already_reached)
                        .sort((left, right) => left.target_ipk - right.target_ipk)[0];
                    const fallbackTarget = ipk === null ? 3 : Math.min(maxWeight, Math.round((ipk + 0.25) * 100) / 100);
                    const currentLoad = data.current_load && Number(data.current_load.sks) > 0 ? data.current_load.sks : null;
                    const remaining = Number.isInteger(data.remaining_sks) && data.remaining_sks > 0 ? data.remaining_sks : null;

                    hosts.simTarget.value = Number(next?.target_ipk ?? fallbackTarget).toFixed(2);
                    hosts.simSks.value = String(remaining ?? currentLoad ?? 20);
                    state.simReady = true;
                }

                renderSimResult();
            };

            hosts.simForm.addEventListener('submit', (event) => {
                event.preventDefault();
                renderSimResult();
            });
            hosts.simTarget.addEventListener('input', renderSimResult);
            hosts.simSks.addEventListener('input', renderSimResult);

            hosts.search.addEventListener('input', (event) => {
                state.query = event.target.value || '';
                renderTable();
            });

            const render = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.data = payload.data;
                state.meta = payload.meta || {};
                hosts.charts.hidden = false;
                hosts.simulator.hidden = false;
                hosts.search.disabled = false;
                renderAlerts();
                renderHero();
                renderTrend();
                renderLetters();
                renderLowGrades();
                renderTable();
                initSimulator();
                renderFreshness();
            };

            try {
                app.primeCachedView(endpoint, render, 'transkrip');
            } catch (error) {
                // A blocked storage only skips the instant view.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                if (!(state.data)) {
                    app.mountErrorState(hosts.hero, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    hosts.charts.hidden = true;
                    hosts.simulator.hidden = true;
                    hosts.table.innerHTML = `<div class="mycic-card-body">${app.renderEmptyState('Daftar nilai belum tersedia.', 'secondary')}</div>`;
                }

                return;
            }

            const loadTranscript = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    render(payload);
                    app.markViewSynced('Transkrip', payload, { force });
                } catch (error) {
                    app.setSyncStatus('Transkrip gagal dimuat.', 'error');

                    if (!state.data) {
                        app.mountErrorState(hosts.hero, 'Transkrip gagal dimuat.', () => loadTranscript({ force }), {
                            subtitle: `${app.describeError(error)} Kalau kamu belum punya nilai sama sekali, transkrip memang belum tersedia.`,
                        });
                        hosts.charts.hidden = true;
                        hosts.simulator.hidden = true;
                        hosts.table.innerHTML = `<div class="mycic-card-body">${app.renderEmptyState('Daftar nilai belum tersedia.', 'secondary')}</div>`;
                    } else {
                        app.showNotice(`Transkrip gagal diperbarui. ${app.describeError(error)}`, 'warning');
                    }
                }
            };

            app.registerSyncHandler('transcript-index', loadTranscript, { aliases: ['grades-index', 'krs-index'] });

            await loadTranscript();
        });
    </script>
@endpush
