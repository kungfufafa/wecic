@extends('layouts.app')

@section('title', 'Notebook')
@section('page_id', 'notebook-index')
@section('hide_sync_refresh', '1')

@section('content')
    {{-- `$notebookAi` names the AI provider the server uses (NotebookAiProviders::forPages()). --}}
    @php
        $aiChatLabel = $notebookAi['processors'][0]['label'] ?? $notebookAi['label'];
        $aiModelSettings = $notebookAi['model_settings'];
        $aiModelExample = count($aiModelSettings) > 1 ? $aiModelSettings[0].' atau '.$aiModelSettings[count($aiModelSettings) - 1] : ($aiModelSettings[0] ?? '');
    @endphp
    <x-page-hero title="Notebook">
        <x-slot:actions>
            {{-- Ulasan harian; the badge counts the cards waiting today (`review_due` of /api/notebooks). --}}
            <x-button variant="secondary" size="sm" :href="route('notebook.review')" id="notebook-review-link">
                Ulasan harian
                <span class="hidden min-w-5 rounded-full bg-brand px-1.5 text-center text-xs font-semibold leading-5 text-brand-ink tabular-nums" id="notebook-review-due" aria-hidden="true"></span>
            </x-button>
            <x-button size="sm" href="#notebook-create">
                <x-icon name="plus" size="sm" />
                Notebook baru
            </x-button>
        </x-slot:actions>
    </x-page-hero>

    <div class="flex min-w-0 flex-col gap-6">
        {{-- One banner per reason the AI cannot answer (`ai_status` of /api/notebooks). --}}
        <x-alert tone="warning" icon class="hidden" data-ai-status-banner="disabled" role="status">
            Fitur AI Notebook dimatikan di server WeCIC. Pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan.
        </x-alert>
        <x-alert tone="warning" icon class="hidden" data-ai-status-banner="missing_key" role="status">
            AI server belum aktif ({{ $notebookAi['key_setting'] }} kosong). Pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan, atau pakai kunci sendiri di <a class="font-medium underline underline-offset-2" href="{{ route('notebook.ai-settings') }}">Pengaturan AI</a>.
        </x-alert>
        <x-alert tone="warning" icon class="hidden" data-ai-status-banner="invalid_key" role="status">
            @if ($notebookAi['own_settings'])
                Kunci API {{ $aiChatLabel }} milikmu ditolak. Perbarui di <a class="font-medium underline underline-offset-2" href="{{ route('notebook.ai-settings') }}">Pengaturan AI</a>.
            @else
                Fitur AI tidak tersedia karena kunci API {{ $aiChatLabel }} di server WeCIC ditolak. Pengelola perlu memperbarui {{ $notebookAi['key_setting'] }}.
            @endif
        </x-alert>
        <x-alert tone="warning" icon class="hidden" data-ai-status-banner="model_misconfigured" role="status">
            @if ($notebookAi['own_settings'])
                Pengaturan model atau alamat AI milikmu ditolak. Periksa di <a class="font-medium underline underline-offset-2" href="{{ route('notebook.ai-settings') }}">Pengaturan AI</a>.
            @else
                Fitur AI tidak tersedia karena pengaturan model AI di server WeCIC ditolak (model tidak dikenali, sudah tidak tersedia, atau pengaturannya tidak cocok), misalnya {{ $aiModelExample }}.
            @endif
        </x-alert>

        <x-section title="Mata kuliah semester ini" title-id="notebook-courses-title">
            <x-slot:count id="notebook-courses-count"></x-slot:count>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" id="notebook-courses" aria-busy="true">
                <span class="sr-only">Memuat notebook…</span>
                @for ($i = 0; $i < 3; $i++)
                    <x-card class="flex min-h-36 flex-col gap-3 p-4 sm:p-5">
                        <div class="flex min-w-0 flex-col gap-2">
                            <x-skeleton class="h-4 w-2/3" />
                            <x-skeleton class="h-3.5 w-1/2" />
                        </div>
                        <x-skeleton class="mt-auto h-3.5 w-3/4" />
                    </x-card>
                @endfor
            </div>
        </x-section>

        <x-section title="Notebook lainnya" title-id="notebook-list-title">
            <x-slot:count id="notebook-list-count"></x-slot:count>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" id="notebook-list" aria-busy="true"></div>
        </x-section>

        <section class="scroll-mt-20 rounded-xl border border-border bg-panel" id="notebook-create" aria-labelledby="notebook-create-title">
            <x-card.body class="flex flex-col gap-5 lg:flex-row lg:items-end lg:gap-8">
                <div class="min-w-0 lg:w-52 lg:shrink-0 lg:self-center">
                    <h2 class="text-sm font-semibold tracking-tight text-ink" id="notebook-create-title">Notebook baru</h2>
                </div>
                <form class="grid min-w-0 flex-1 gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end sm:gap-3" id="notebook-create-form">
                    <label class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink">Judul</span>
                        <x-input id="notebook-title" name="title" maxlength="120" required placeholder="Persiapan UTS Basis Data" />
                    </label>
                    <label class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink">Materi kelas (opsional)</span>
                        <x-select id="notebook-course" name="course_key">
                            <option value="">Kosong</option>
                        </x-select>
                    </label>
                    <x-button type="submit">Buat notebook</x-button>
                </form>
            </x-card.body>
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
            // Older responses carry only ai_available.
            const showAiStatus = (data) => {
                const status = data?.ai_status || (data?.ai_available === false ? 'missing_key' : 'ok');

                document.querySelectorAll('[data-ai-status-banner]').forEach((banner) => {
                    banner.classList.toggle('hidden', banner.dataset.aiStatusBanner !== status);
                });
            };

            // Older responses carry no review_due: the badge stays hidden.
            const showReviewDue = (due) => {
                const count = Number.isInteger(due) && due > 0 ? due : 0;
                const badge = document.getElementById('notebook-review-due');

                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.toggle('hidden', count === 0);
                document.getElementById('notebook-review-link').setAttribute('aria-label', count ? `Ulasan harian, ${count} kartu menunggu` : 'Ulasan harian');
            };

            const card = (notebook) => {
                const stats = [
                    notebook.kind === 'course' ? `${notebook.meetings_count} pertemuan` : null,
                    `${notebook.sources_count} sumber`,
                    `${notebook.messages_count} pesan`,
                    app.formatTimeAgo(notebook.updated_at),
                ].filter(Boolean).join(' · ');
                // Course code and lecturer, or the linked course of a custom notebook, as one muted line.
                const subtitle = notebook.kind === 'course'
                    ? [notebook.course_code, notebook.lecturer_name].filter(Boolean).join(' · ')
                    : (notebook.course_name || '');

                return `
                    <a href="/notebook/${notebook.id}" class="${app.ui.cardInteractive} group min-w-0" data-slot="card">
                        <span class="flex h-full min-w-0 flex-col">
                            <span class="flex min-h-28 min-w-0 flex-1 flex-col gap-3 p-4 sm:p-5">
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="line-clamp-2 text-sm font-medium leading-snug text-ink">${app.escapeHtml(notebook.title)}</span>
                                    ${subtitle ? `<span class="truncate text-xs text-ink-muted">${app.escapeHtml(subtitle)}</span>` : ''}
                                </span>
                                ${notebook.kind === 'course' && notebook.meetings_count === 0 ? `<span class="flex">${app.renderBadge('Belum ada pertemuan', 'neutral')}</span>` : ''}
                            </span>
                            <span class="flex min-w-0 items-center justify-between gap-3 border-t border-border-light px-4 py-2.5 sm:px-5">
                                <span class="truncate text-xs text-ink-subtle tabular-nums">${app.escapeHtml(stats)}</span>
                                ${app.renderIcon('arrow-right', 'sm', 'text-ink-subtle transition-transform group-hover:translate-x-0.5')}
                            </span>
                        </span>
                    </a>
                `;
            };

            const renderCounts = (notebooks) => {
                const courses = notebooks.filter((notebook) => notebook.kind === 'course');

                document.getElementById('notebook-courses-count').textContent = courses.length ? String(courses.length) : '';
                document.getElementById('notebook-list-count').textContent = notebooks.length - courses.length ? String(notebooks.length - courses.length) : '';
            };

            const renderCourses = (notebooks, enabled) => {
                coursesHost.removeAttribute('aria-busy');
                const courses = notebooks
                    .filter((notebook) => notebook.kind === 'course')
                    .sort((a, b) => String(a.course_name || a.title).localeCompare(String(b.course_name || b.title), 'id'));

                if (!courses.length) {
                    coursesHost.innerHTML = `<div class="flex flex-col gap-3 sm:col-span-2 xl:col-span-3">${enabled === false
                        ? app.renderEmptyState('Notebook mata kuliah dimatikan di server.', 'secondary', '', { icon: 'notebook' })
                        : app.renderEmptyState('Daftar kelasmu belum tersimpan.', 'secondary', 'Buka halaman Kuliah agar notebook mata kuliah muncul di sini.', { icon: 'class' })}
                        ${enabled === false ? '' : `<div class="flex justify-center"><a href="/class" class="${app.ui.button.primary}">Buka halaman Kuliah</a></div>`}
                    </div>`;

                    return;
                }

                coursesHost.innerHTML = courses.map(card).join('');
            };

            const renderList = (notebooks) => {
                listHost.removeAttribute('aria-busy');
                const custom = notebooks.filter((notebook) => notebook.kind !== 'course');

                if (!custom.length) {
                    listHost.innerHTML = `<p class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-ink-subtle sm:col-span-2 xl:col-span-3"><span class="${app.ui.emptyIcon}" aria-hidden="true">${app.renderIcon('notebook', 'lg')}</span>Belum ada notebook buatan sendiri.</p>`;

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

                    showAiStatus(payload?.data);
                    showReviewDue(payload?.data?.review_due);
                    const notebooks = Array.isArray(payload?.data?.notebooks) ? payload.data.notebooks : [];
                    renderCourses(notebooks, payload?.data?.course_notebooks);
                    renderList(notebooks);
                    renderCounts(notebooks);
                    app.setSyncStatus('Notebook tersimpan di WeCIC, tidak bergantung pada portal.', 'info');
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
