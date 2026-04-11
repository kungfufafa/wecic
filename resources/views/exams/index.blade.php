@extends('layouts.app')

@section('title', 'Ujian')
@section('page_id', 'exam-index')

@section('content')
    <section class="grid gap-6">
        <div class="app-panel">
            <div class="flex flex-col gap-4">
                <div>
                    <p class="section-label">Ujian</p>
                    <h1 data-display class="mt-4 text-3xl font-semibold text-[color:var(--ink)] sm:text-4xl">Daftar ujian aktif</h1>
                    <p class="mt-3 text-sm leading-7 text-[color:var(--ink-soft)]">Pantau status unggah dan buka detail ujian untuk setiap mata kuliah.</p>
                </div>
            </div>
        </div>

        <div id="exam-mobile-list" class="grid gap-4 md:hidden">
            <div class="empty-shell">Memuat daftar ujian.</div>
        </div>

        <div class="app-panel hidden md:block">
            <div class="overflow-x-auto">
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
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;

            const tableBody = document.getElementById('exam-table');
            const mobileList = document.getElementById('exam-mobile-list');

            const renderExamCards = (exams) => exams.map((exam) => `
                <article class="app-panel">
                    <div class="flex flex-col gap-4">
                        <div>
                            <p class="section-label">${app.escapeHtml(exam.course_code || '-')}</p>
                            <h2 data-display class="mt-3 text-xl font-semibold text-[color:var(--ink)]">${app.escapeHtml(exam.course_name || '-')}</h2>
                        </div>
                        ${app.renderKeyValueList([
                            { label: 'Jadwal', value: exam.schedule },
                            { label: 'Status', value: exam.status },
                        ])}
                        <a href="/exam/${app.escapeHtml(exam.id)}" class="secondary-button self-start">Detail ujian</a>
                    </div>
                </article>
            `).join('');

            const renderExams = (payload) => {
                const exams = payload.data?.exams || [];

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
                        <td>${app.escapeHtml(exam.status || '-')}</td>
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
