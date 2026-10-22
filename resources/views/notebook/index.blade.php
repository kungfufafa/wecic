@extends('layouts.app')

@section('title', 'Notebook')
@section('page_id', 'notebook-index')
@section('hide_sync_refresh', '1')

@section('content')
    <header class="mb-4">
        <h1 class="mycic-page-heading">Notebook</h1>
        <p class="mycic-page-subheading">Setiap mata kuliah yang kamu ambil punya notebook sendiri. Pertemuan yang kamu buka masuk sendiri ke notebooknya, sisanya bisa ditambahkan kapan saja. Lalu tanya apa saja, buat bahan belajar, atau susun kerangka tugas. Jawabannya hanya dari sumbermu, lengkap dengan rujukan ke halaman materinya.</p>
    </header>

    <div class="flex min-w-0 flex-col gap-6">
        <div class="mycic-alert-warning mb-0 hidden" id="notebook-ai-off" role="status">
            Fitur AI belum diaktifkan di server MyCIC (GEMINI_API_KEY kosong). Kamu tetap bisa memasukkan pertemuan dan sumber teks, DOCX, dan PPTX; tanya jawab aktif setelah AI dinyalakan.
        </div>

        <section aria-labelledby="notebook-courses-title">
            <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
                <h2 class="mycic-section-title" id="notebook-courses-title">Mata kuliah semester ini</h2>
                <span class="text-caption text-ink-subtle">Dibuat otomatis dari daftar kelasmu</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" id="notebook-courses" aria-busy="true">
                <span class="sr-only">Memuat notebook…</span>
                @for ($i = 0; $i < 3; $i++)
                    <div class="mycic-card p-4">
                        <div class="mycic-skeleton h-5 w-2/3"></div>
                        <div class="mycic-skeleton mt-2 h-3.5 w-1/2"></div>
                    </div>
                @endfor
            </div>
        </section>

        <section aria-labelledby="notebook-list-title">
            <h2 class="mycic-section-title mb-2" id="notebook-list-title">Notebook lainnya</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" id="notebook-list" aria-busy="true"></div>
        </section>

        <section class="mycic-card" aria-labelledby="notebook-create-title">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="notebook-create-title">Notebook baru</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Untuk topik lintas mata kuliah, persiapan ujian, atau kumpulan materi pilihanmu sendiri.</p>
            </div>
            <div class="mycic-card-body">
                <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" id="notebook-create-form">
                    <label class="mycic-field">
                        <span class="mycic-label">Judul</span>
                        <input class="mycic-input" id="notebook-title" name="title" maxlength="120" required placeholder="Persiapan UTS Basis Data">
                    </label>
                    <label class="mycic-field">
                        <span class="mycic-label">Isi otomatis dengan materi (opsional)</span>
                        <select class="mycic-input" id="notebook-course" name="course_key">
                            <option value="">Tanpa materi, tambah sendiri nanti</option>
                        </select>
                    </label>
                    <button type="submit" class="mycic-btn-primary">Buat notebook</button>
                </form>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const coursesHost = document.getElementById('notebook-courses');
            const listHost = document.getElementById('notebook-list');
            const form = document.getElementById('notebook-create-form');
            const titleInput = document.getElementById('notebook-title');
            const courseSelect = document.getElementById('notebook-course');
            const aiOff = document.getElementById('notebook-ai-off');

            const card = (notebook) => {
                const stats = [
                    notebook.kind === 'course' ? `${notebook.meetings_count} pertemuan` : null,
                    `${notebook.sources_count} sumber`,
                    `${notebook.messages_count} pesan`,
                    app.formatTimeAgo(notebook.updated_at),
                ].filter(Boolean).join(' · ');
                const badges = notebook.kind === 'course'
                    ? [notebook.course_code ? app.renderBadge(notebook.course_code, 'brand') : '', notebook.meetings_count === 0 ? app.renderBadge('Belum ada pertemuan', 'neutral') : ''].filter(Boolean).join(' ')
                    : (notebook.course_name ? app.renderBadge(notebook.course_name, 'brand') : '');

                return `
                    <a href="/notebook/${notebook.id}" class="mycic-card flex min-h-28 min-w-0 flex-col gap-2 p-4 transition-colors hover:border-brand/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        <span class="line-clamp-2 text-base font-bold text-ink">${app.escapeHtml(notebook.title)}</span>
                        ${badges ? `<span class="flex flex-wrap gap-1">${badges}</span>` : ''}
                        ${notebook.kind === 'course' && notebook.lecturer_name ? `<span class="text-caption text-ink-muted">${app.escapeHtml(notebook.lecturer_name)}</span>` : ''}
                        <span class="mt-auto text-caption text-ink-subtle">${app.escapeHtml(stats)}</span>
                    </a>
                `;
            };

            const renderCourses = (notebooks, enabled) => {
                coursesHost.removeAttribute('aria-busy');
                const courses = notebooks
                    .filter((notebook) => notebook.kind === 'course')
                    .sort((a, b) => String(a.course_name || a.title).localeCompare(String(b.course_name || b.title), 'id'));

                if (!courses.length) {
                    coursesHost.innerHTML = `<div class="sm:col-span-2 lg:col-span-3">${enabled === false
                        ? app.renderEmptyState('Notebook mata kuliah dimatikan di server.', 'secondary')
                        : app.renderEmptyState('Daftar kelasmu belum tersimpan.', 'secondary', 'Buka halaman Kuliah sekali, lalu notebook setiap mata kuliah muncul di sini.')}
                        ${enabled === false ? '' : `<a href="/class" class="mycic-btn-primary mt-3">Buka halaman Kuliah</a>`}
                    </div>`;

                    return;
                }

                coursesHost.innerHTML = courses.map(card).join('');
            };

            const renderList = (notebooks) => {
                listHost.removeAttribute('aria-busy');
                const custom = notebooks.filter((notebook) => notebook.kind !== 'course');

                if (!custom.length) {
                    listHost.innerHTML = `<p class="text-sm text-ink-subtle sm:col-span-2 lg:col-span-3">Belum ada notebook buatan sendiri. Buat satu di bawah bila perlu.</p>`;

                    return;
                }

                listHost.innerHTML = custom.map(card).join('');
            };

            const loadCourses = async () => {
                try {
                    const payload = await app.request('/api/archive/files');
                    const courses = Array.isArray(payload?.data?.courses) ? payload.data.courses : [];

                    courseSelect.insertAdjacentHTML('beforeend', courses
                        .filter((course) => course.course_key)
                        .map((course) => `<option value="${app.escapeHtml(course.course_key)}">${app.escapeHtml(`${course.course_name || 'Mata kuliah'} (${course.files.length} berkas)`)}</option>`)
                        .join(''));
                } catch (error) {
                    // Creating an empty notebook still works.
                }
            };

            const loadNotebooks = async () => {
                try {
                    const payload = await app.request('/api/notebooks');

                    if (!payload) {
                        return;
                    }

                    aiOff.classList.toggle('hidden', payload?.data?.ai_available !== false);
                    const notebooks = Array.isArray(payload?.data?.notebooks) ? payload.data.notebooks : [];
                    renderCourses(notebooks, payload?.data?.course_notebooks);
                    renderList(notebooks);
                    app.setSyncStatus('Notebook tersimpan di MyCIC, tidak bergantung pada portal.', 'info');
                } catch (error) {
                    coursesHost.removeAttribute('aria-busy');
                    listHost.removeAttribute('aria-busy');
                    listHost.innerHTML = '';
                    app.mountErrorState(coursesHost, 'Notebook gagal dimuat.', () => loadNotebooks(), { subtitle: app.describeError(error) });
                }
            };

            courseSelect.addEventListener('change', () => {
                if (courseSelect.value && titleInput.value.trim() === '') {
                    titleInput.value = `Belajar ${courseSelect.selectedOptions[0]?.textContent.replace(/\s*\(\d+ berkas\)$/, '') || ''}`.trim();
                }
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();

                const button = form.querySelector('button[type="submit"]');

                app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request('/api/notebooks', {
                            method: 'POST',
                            json: { title: titleInput.value.trim(), course_key: courseSelect.value || null },
                        });
                        const id = payload?.data?.notebook?.id;

                        if (id) {
                            window.location.href = `/notebook/${id}`;
                        }
                    } catch (error) {
                        app.showNotice(`Notebook gagal dibuat. ${app.describeError(error)}`, 'error');
                    }
                });
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            await Promise.all([loadNotebooks(), loadCourses()]);
        });
    </script>
@endpush
