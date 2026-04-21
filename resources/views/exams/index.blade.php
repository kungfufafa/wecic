@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

@section('content')
    <div id="exam-summary" class="row row-cards mb-4">
        @foreach ([
            ['label' => 'Total ujian', 'note' => 'Semua entri ujian yang berhasil terdeteksi.'],
            ['label' => 'Terjadwal', 'note' => 'Sudah punya jadwal.'],
            ['label' => 'Perlu ditinjau', 'note' => 'Belum final atau masih aktif.'],
        ] as $metric)
            <div class="col-sm-6 col-xl-4">
                <div class="card card-sm h-100">
                    <div class="card-body">
                        <div class="text-secondary text-uppercase small mb-1">{{ $metric['label'] }}</div>
                        <div class="h1 mb-1" data-exam-metric>-</div>
                        <div class="text-secondary small">{{ $metric['note'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card d-md-none mb-4">
        <div class="card-header">
            <h3 class="card-title">Daftar ujian</h3>
        </div>
        <div class="card-body">
            <div id="exam-mobile-list">
                {!! '<div class="alert alert-secondary mb-0">Memuat daftar ujian.</div>' !!}
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-header">
            <h3 class="card-title">Daftar ujian</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Mata kuliah</th>
                        <th>Jadwal</th>
                        <th>Status</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody id="exam-table">
                    <tr>
                        <td colspan="5" class="text-secondary">Memuat daftar ujian.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
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
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                            <div class="me-auto">
                                <div class="h3 mb-1">${app.escapeHtml(exam.course_name || '-')}</div>
                                <div class="text-secondary">${app.escapeHtml(exam.schedule || 'Jadwal belum tersedia')}</div>
                            </div>
                            ${app.renderBadge(exam.status || 'Tanpa status', 'secondary')}
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            ${app.renderBadge(exam.course_code || '-', 'info')}
                            <a href="/exam/${app.escapeHtml(exam.id)}" class="btn btn-primary">Detail ujian</a>
                        </div>
                    </div>
                </div>
            `).join('');

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
                    tableBody.innerHTML = '<tr><td colspan="5" class="text-secondary">Belum ada data ujian.</td></tr>';
                    mobileList.innerHTML = app.renderEmptyState('Belum ada data ujian.', 'secondary');

                    return;
                }

                mobileList.innerHTML = renderExamCards(exams);
                tableBody.innerHTML = exams.map((exam) => `
                    <tr>
                        <td>${app.escapeHtml(exam.course_code || '-')}</td>
                        <td>${app.escapeHtml(exam.course_name || '-')}</td>
                        <td>${app.escapeHtml(exam.schedule || '-')}</td>
                        <td>${app.renderBadge(exam.status || '-', 'secondary')}</td>
                        <td>
                            <a href="/exam/${app.escapeHtml(exam.id)}" class="btn btn-outline-secondary btn-sm">Detail</a>
                        </td>
                    </tr>
                `).join('');
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
                    mobileList.innerHTML = app.renderEmptyState('Gagal memuat daftar ujian.', 'danger');
                    tableBody.innerHTML = '<tr><td colspan="5" class="text-danger">Gagal memuat daftar ujian.</td></tr>';
                }
            };

            app.registerSyncHandler('exam-index', loadExams);

            await loadExams();
        });
    </script>
@endpush
