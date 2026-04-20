@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

@section('content')
    <section class="page-shell">
        <section class="page-hero">
            <div class="page-hero-shell">
                <div class="page-hero-main">
                    <p class="section-label">Pusat ujian</p>
                    <h1 data-display class="page-hero-title is-wide">Agenda ujian dan unggah berkas.</h1>
                    <p id="exam-hero-copy" class="page-hero-copy">
                        Memuat daftar ujian yang bisa dibuka dari portal kampus dan membantu Anda berpindah ke detail pengumpulan.
                    </p>
                    <div class="page-meta-row">
                        <span class="meta-pill is-accent">Ujian aktif</span>
                        <span id="exam-status-pill" class="meta-pill">Menunggu sinkron</span>
                    </div>
                </div>
                <div class="page-hero-actions">
                    <a href="/dashboard" class="ghost-button">Kembali ke dashboard</a>
                </div>
            </div>
        </section>

        <div id="exam-summary" class="dense-metric-grid">
            @foreach (['Total ujian', 'Terjadwal', 'Perlu ditinjau'] as $metric)
                <article class="dense-metric">
                    <span class="dense-metric-label">{{ $metric }}</span>
                    <strong class="dense-metric-value" data-exam-metric>-</strong>
                    <p class="dense-metric-copy">
                        @if ($metric === 'Total ujian')
                            Semua entri ujian yang berhasil terdeteksi.
                        @elseif ($metric === 'Terjadwal')
                            Entri yang sudah memiliki metadata jadwal.
                        @else
                            Ujian yang statusnya belum final atau masih aktif.
                        @endif
                    </p>
                </article>
            @endforeach
        </div>

        <section class="section-shell md:hidden">
            <div class="section-head">
                <div class="section-head-main">
                    <h2 data-display class="section-title">Daftar ujian</h2>
                    <p class="section-copy">Tampilan kartu untuk akses cepat di perangkat kecil saat berpindah antar detail ujian.</p>
                </div>
            </div>
            <div id="exam-mobile-list" class="mt-6 stack-list">
                <div class="empty-shell">Memuat daftar ujian.</div>
            </div>
        </section>

        <section class="section-shell hidden md:block">
            <div class="section-head">
                <div class="section-head-main">
                    <h2 data-display class="section-title">Daftar ujian</h2>
                    <p class="section-copy">Mode tabel memudahkan pemindaian kode mata kuliah, jadwal, dan status dalam satu layar.</p>
                </div>
            </div>
            <div class="table-shell mt-6 overflow-x-auto">
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Mata kuliah</th>
                            <th>Jadwal</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="exam-table">
                        <tr>
                            <td colspan="5" class="py-8 text-center text-sm text-[color:var(--ink-soft)]">Memuat daftar ujian.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const tableBody = document.getElementById('exam-table');
            const mobileList = document.getElementById('exam-mobile-list');
            const heroCopy = document.getElementById('exam-hero-copy');
            const statusPill = document.getElementById('exam-status-pill');
            const metricNodes = document.querySelectorAll('[data-exam-metric]');

            const renderExamCards = (exams) => exams.map((exam) => `
                <article class="stack-item">
                    <div class="stack-item-shell">
                        <div class="stack-item-main">
                            <div class="space-y-3">
                                <div class="page-meta-row">
                                    <span class="stack-item-kicker">${app.escapeHtml(exam.course_code || '-')}</span>
                                    <span class="meta-pill">${app.escapeHtml(exam.status || 'Tanpa status')}</span>
                                </div>
                                <div>
                                    <h2 data-display class="stack-item-title">${app.escapeHtml(exam.course_name || '-')}</h2>
                                    <p class="stack-item-copy">${app.escapeHtml(exam.schedule || 'Jadwal belum tersedia')}</p>
                                </div>
                            </div>
                        </div>
                        <div class="stack-item-aside">
                            <div class="surface-card-soft">
                                <p class="section-label">Aksi ujian</p>
                                <div class="surface-card-actions">
                                    <a href="/exam/${app.escapeHtml(exam.id)}" class="primary-button w-full justify-center">Detail ujian</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            `).join('');

            const renderExams = (payload) => {
                const exams = payload.data?.exams || [];
                const scheduledCount = exams.filter((exam) => Boolean(String(exam.schedule || '').trim() && exam.schedule !== '-')).length;
                const reviewCount = exams.filter((exam) => !/selesai|closed|ditutup|final/i.test(String(exam.status || ''))).length;

                metricNodes[0].textContent = exams.length || '0';
                metricNodes[1].textContent = scheduledCount || '0';
                metricNodes[2].textContent = reviewCount || '0';
                statusPill.textContent = exams.length ? `${exams.length} entri aktif` : 'Belum ada entri';
                heroCopy.textContent = exams.length
                    ? `${exams.length} ujian berhasil dimuat. Prioritaskan entri yang belum final dan buka detail untuk melihat kesiapan unggah berkas.`
                    : 'Belum ada ujian yang muncul pada portal aktif. Coba segarkan lagi saat data akademik tersedia.';

                if (!exams.length) {
                    tableBody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-sm text-[color:var(--ink-soft)]">Belum ada data ujian.</td></tr>';
                    mobileList.innerHTML = '<div class="empty-shell">Belum ada data ujian.</div>';

                    return;
                }

                mobileList.innerHTML = renderExamCards(exams);
                tableBody.innerHTML = exams.map((exam) => `
                    <tr>
                        <td>${app.escapeHtml(exam.course_code || '-')}</td>
                        <td>${app.escapeHtml(exam.course_name || '-')}</td>
                        <td>${app.escapeHtml(exam.schedule || '-')}</td>
                        <td><span class="meta-pill">${app.escapeHtml(exam.status || '-')}</span></td>
                        <td class="text-right">
                            <a href="/exam/${app.escapeHtml(exam.id)}" class="secondary-button">Detail</a>
                        </td>
                    </tr>
                `).join('');
            };

            app.primeCachedView('/api/exam', renderExams, 'daftar ujian');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadExams = async () => {
                try {
                    const payload = await app.request('/api/exam');
                    renderExams(payload);
                    app.markViewSynced('Daftar ujian');
                } catch (error) {
                    app.showNotice(error.payload?.message || error.message, 'error');
                    mobileList.innerHTML = '<div class="empty-shell">Gagal memuat daftar ujian.</div>';
                    tableBody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-sm text-[color:var(--ink-soft)]">Gagal memuat daftar ujian.</td></tr>';
                }
            };

            app.registerSyncHandler('exam-index', loadExams);

            await loadExams();
        });
    </script>
@endpush
