@extends('layouts.app')

@section('title', 'Notebook')
@section('page_id', 'notebook-show')
@section('hide_sync_refresh', '1')

@section('content')
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('notebook.index') }}" class="mycic-link text-sm">Notebook</a>
            <h1 class="mycic-page-heading break-words" id="notebook-title">Memuat…</h1>
            <p class="mycic-page-subheading" id="notebook-meta"></p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="#" class="mycic-btn-ghost-sm hidden" id="notebook-class-link">Buka kelas</a>
            <button type="button" class="mycic-btn-ghost-sm" id="notebook-rename">Ganti judul</button>
            <button type="button" class="mycic-btn-ghost-sm text-danger" id="notebook-delete">Hapus</button>
        </div>
    </header>

    <div class="mycic-alert-warning mb-4 hidden" id="notebook-ai-off" role="status">
        Fitur AI belum diaktifkan di server MyCIC (GEMINI_API_KEY kosong). Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio aktif setelah AI dinyalakan.
    </div>

    <div class="mb-3 inline-flex items-center gap-1 rounded-xl border border-border/40 bg-surface p-1 lg:hidden" role="tablist" aria-label="Bagian notebook" id="notebook-tabs">
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="sources">Sumber</button>
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="chat">Chat</button>
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="studio">Studio</button>
    </div>

    <div class="grid min-w-0 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)_20rem]">
        <section class="mycic-card min-w-0 self-start" aria-labelledby="notebook-sources-title" data-notebook-panel="sources">
            <div class="mycic-card-header flex items-center justify-between gap-2">
                <h2 class="mycic-card-title" id="notebook-sources-title">Sumber</h2>
                <span class="text-caption text-ink-subtle" id="notebook-sources-count"></span>
            </div>
            <div class="mycic-card-body flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-2">
                    <button type="button" class="mycic-btn-primary justify-start hidden" id="source-add-meeting">+ Tambah pertemuan</button>
                    <button type="button" class="mycic-btn-secondary-sm justify-start" id="source-add-archive">+ Dari arsip materi</button>
                    <label class="mycic-btn-secondary-sm cursor-pointer justify-start">
                        <span>+ Unggah berkas</span>
                        <input type="file" class="sr-only" id="source-upload" accept=".pdf,.docx,.pptx,.txt,.md,.png,.jpg,.jpeg">
                    </label>
                    <button type="button" class="mycic-btn-secondary-sm justify-start" id="source-add-text">+ Tulis catatan</button>
                </div>
                <div class="hidden flex-wrap items-center justify-between gap-2 rounded-lg border border-border/40 bg-surface px-2.5 py-1.5 text-caption" id="notebook-context-bar">
                    <span class="text-ink-muted" id="notebook-context-summary"></span>
                    <span class="flex gap-2">
                        <button type="button" class="font-semibold text-brand-pressed hover:underline" id="notebook-context-all">Semua</button>
                        <button type="button" class="font-semibold text-brand-pressed hover:underline" id="notebook-context-none">Kosongkan</button>
                    </span>
                </div>
                <ul class="flex flex-col gap-2" id="notebook-sources" aria-busy="true">
                    <li class="mycic-skeleton h-12 w-full"></li>
                </ul>
            </div>
        </section>

        <section class="mycic-card flex min-h-[32rem] min-w-0 flex-col" aria-labelledby="notebook-chat-title" data-notebook-panel="chat">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="notebook-chat-title">Chat</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Jawaban hanya dari sumber yang dicentang. Ketuk nomor rujukan untuk melihat kutipannya.</p>
            </div>
            <div class="mycic-card-body flex min-h-0 flex-1 flex-col gap-3">
                <ol class="flex max-h-[60vh] min-h-48 flex-1 flex-col gap-4 overflow-y-auto pe-1" id="notebook-messages" aria-live="polite"></ol>
                <form class="flex flex-col gap-2" id="notebook-ask-form">
                    <label class="sr-only" for="notebook-question">Pertanyaan</label>
                    <textarea class="mycic-input min-h-20 resize-y" id="notebook-question" maxlength="2000" placeholder="Tanyakan sesuatu tentang sumbermu…" required></textarea>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-caption text-ink-subtle" id="notebook-ask-context">Enter untuk kirim, Shift+Enter untuk baris baru</span>
                        <button type="submit" class="mycic-btn-primary">Kirim</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="mycic-card min-w-0 self-start" aria-labelledby="notebook-studio-title" data-notebook-panel="studio">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="notebook-studio-title">Studio</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Bahan belajar mandiri dan kerangka tugas dari sumber yang dicentang, tersimpan otomatis di Catatan.</p>
            </div>
            <div class="mycic-card-body flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-2" id="notebook-studio-buttons"></div>
                <div class="flex flex-col gap-3">
                    <h3 class="mycic-section-title">Catatan</h3>
                    <div class="flex flex-col gap-3" id="notebook-notes"></div>
                </div>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const notebookId = @json($notebookId);
            const base = `/api/notebooks/${notebookId}`;
            const el = (id) => document.getElementById(id);
            const sourcesHost = el('notebook-sources');
            const messagesHost = el('notebook-messages');
            const notesHost = el('notebook-notes');
            const studioButtons = el('notebook-studio-buttons');
            const askForm = el('notebook-ask-form');
            const questionInput = el('notebook-question');

            const SOURCE_STATUS = {
                pending: { label: 'Menunggu', tone: 'neutral' },
                processing: { label: 'Dibaca…', tone: 'info' },
                ready: { label: 'Siap', tone: 'success' },
                failed: { label: 'Gagal', tone: 'danger' },
            };
            const SOURCE_KIND = { meeting: 'Catatan pertemuan', archived_file: 'Arsip materi', upload: 'Unggahan', text: 'Catatan' };
            const SUGGESTIONS = [
                'Ringkas inti materi ini dalam 5 poin.',
                'Konsep apa yang paling penting untuk ujian?',
                'Jelaskan bagian tersulit dengan contoh sederhana.',
                'Tugas apa saja yang ada di pertemuan-pertemuan ini dan kapan tenggatnya?',
            ];

            const state = {
                notebook: null,
                sources: [],
                messages: [],
                meetings: null,
                archivedCourses: [],
                studioKinds: {},
                aiAvailable: true,
                busy: false,
                pollTimer: null,
                tab: 'chat',
                // Sources left out of the context (NotebookLM-style source ticks); empty = everything.
                excluded: new Set(),
            };

            // A bare 502/504 means the web server gave up before the model answered.
            const describeAiError = (error, fallback) => (error?.status === 504 || error?.status === 502
                ? 'Model AI tidak menjawab dalam batas waktu server (sedang sibuk). Tunggu sebentar lalu coba lagi.'
                : app.describeError(error, fallback));

            const formatChars = (chars) => (chars >= 1000 ? `${(chars / 1000).toFixed(chars >= 10000 ? 0 : 1).replace('.', ',')} rb` : String(chars)) + ' karakter';

            const readySources = () => state.sources.filter((source) => source.status === 'ready');
            const contextSources = () => readySources().filter((source) => !state.excluded.has(source.id));
            const contextSourceIds = () => (state.excluded.size ? contextSources().map((source) => source.id) : []);
            const isCourseNotebook = () => state.notebook?.kind === 'course';

            // Safe Markdown subset: everything is escaped first.
            const inline = (text) => app.escapeHtml(text)
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*])\*(?!\s)(.+?)\*(?!\*)/g, '$1<em>$2</em>')
                .replace(/`([^`]+)`/g, '<code class="rounded bg-surface px-1">$1</code>')
                .replace(/\[(\d{1,4})\]/g, '<button type="button" class="mx-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-surface-tint px-1 align-text-top text-[11px] font-bold text-brand-pressed hover:bg-brand hover:text-white focus-visible:outline-2 focus-visible:outline-brand" data-cite="$1" aria-label="Rujukan $1">$1</button>');

            const renderMarkdown = (text) => {
                const blocks = [];
                let list = null;

                const flushList = () => {
                    if (list) {
                        blocks.push(`<${list.tag} class="${list.tag === 'ul' ? 'list-disc' : 'list-decimal'} flex flex-col gap-1 ps-5">${list.items.join('')}</${list.tag}>`);
                        list = null;
                    }
                };

                String(text || '').split(/\n/).forEach((rawLine) => {
                    const line = rawLine.trimEnd();
                    const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
                    const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
                    const heading = line.match(/^(#{1,4})\s+(.*)$/);
                    const checkbox = line.match(/^\s*[-*]\s+\[[ xX]\]\s+(.*)$/);

                    if (checkbox) {
                        if (!list || list.tag !== 'ul') {
                            flushList();
                            list = { tag: 'ul', items: [] };
                        }

                        list.items.push(`<li class="list-none -ms-5"><label class="flex items-start gap-2"><input type="checkbox" class="mt-1"><span>${inline(checkbox[1])}</span></label></li>`);

                        return;
                    }

                    if (bullet || numbered) {
                        const tag = bullet ? 'ul' : 'ol';

                        if (!list || list.tag !== tag) {
                            flushList();
                            list = { tag, items: [] };
                        }

                        list.items.push(`<li>${inline((bullet || numbered)[1])}</li>`);

                        return;
                    }

                    flushList();

                    if (heading) {
                        blocks.push(`<h4 class="mt-1 text-sm font-bold text-ink">${inline(heading[2])}</h4>`);
                    } else if (line.trim() !== '') {
                        blocks.push(`<p>${inline(line)}</p>`);
                    }
                });

                flushList();

                return `<div class="flex flex-col gap-2 text-sm leading-relaxed text-ink">${blocks.join('')}</div>`;
            };

            const citationPanel = (message) => `<div class="hidden rounded-lg border border-border/40 bg-surface p-3 text-caption text-ink-muted" data-cite-panel="${message.id}"></div>`;

            // "Konteks: 3 sumber · 12 kutipan · 9,8 rb karakter": what the model actually read.
            const contextLine = (message) => {
                const context = message.context;

                if (!context || !Number.isFinite(Number(context.excerpts))) {
                    return '';
                }

                const parts = [
                    `${context.sources?.length ?? context.source_ids?.length ?? 0} dari ${context.total_sources ?? '?'} sumber`,
                    `${context.excerpts} kutipan`,
                    formatChars(Number(context.chars || 0)),
                    context.partial ? 'sebagian teks' : null,
                ].filter(Boolean).join(' · ');
                const titles = (context.sources || []).map((source) => app.escapeHtml(source.meeting_number ? `P${source.meeting_number} · ${source.title}` : source.title)).join('<br>');

                return `
                    <details class="text-caption text-ink-subtle">
                        <summary class="cursor-pointer">Konteks: ${app.escapeHtml(parts)}${context.focus ? ' · fokus tugas' : ''}</summary>
                        <div class="mt-1 rounded-lg border border-border/40 bg-surface p-2 text-ink-muted">${context.focus ? `<p class="mb-1"><span class="font-semibold">Fokus:</span> ${app.escapeHtml(context.focus)}</p>` : ''}${titles || 'Tidak ada sumber.'}</div>
                    </details>
                `;
            };

            const renderAssistant = (message) => `
                <li class="flex flex-col gap-2" data-message-id="${message.id}">
                    <div class="rounded-2xl rounded-tl-sm border border-border/40 bg-panel p-3">${renderMarkdown(message.content)}</div>
                    ${citationPanel(message)}
                    <div class="flex flex-wrap items-center gap-2 text-caption text-ink-subtle">
                        ${message.citations.length ? `<span>${message.citations.length} rujukan</span>` : '<span>Tanpa rujukan</span>'}
                        <button type="button" class="mycic-btn-ghost-sm" data-pin="${message.id}" aria-pressed="${message.pinned ? 'true' : 'false'}">${message.pinned ? 'Tersimpan di Catatan' : 'Simpan ke Catatan'}</button>
                    </div>
                    ${contextLine(message)}
                </li>
            `;

            const renderMessages = () => {
                const chat = state.messages.filter((message) => message.kind === 'chat');

                if (!chat.length) {
                    messagesHost.innerHTML = `
                        <li class="flex flex-col gap-3">
                            <p class="text-sm text-ink-muted">Belum ada percakapan. Coba mulai dengan:</p>
                            <div class="flex flex-wrap gap-2">${SUGGESTIONS.map((text) => `<button type="button" class="mycic-btn-secondary-sm" data-suggestion="${app.escapeHtml(text)}">${app.escapeHtml(text)}</button>`).join('')}</div>
                        </li>
                    `;

                    return;
                }

                messagesHost.innerHTML = chat.map((message) => (message.role === 'user'
                    ? `<li class="ms-auto max-w-[85%] rounded-2xl rounded-tr-sm bg-brand px-3 py-2 text-sm text-white" data-message-id="${message.id}">${app.escapeHtml(message.content)}</li>`
                    : renderAssistant(message))).join('');
                messagesHost.scrollTop = messagesHost.scrollHeight;
            };

            const renderQuiz = (message) => {
                const questions = Array.isArray(message.data) ? message.data : [];

                return `<ol class="flex flex-col gap-4" data-quiz="${message.id}">${questions.map((question, index) => `
                    <li class="flex flex-col gap-2">
                        <p class="text-sm font-semibold text-ink">${index + 1}. ${inline(question.question || '')}</p>
                        <div class="flex flex-col gap-1.5">
                            ${(question.options || []).map((option, optionIndex) => `
                                <button type="button" class="rounded-lg border border-border/50 px-3 py-2 text-start text-sm hover:border-brand/50 focus-visible:outline-2 focus-visible:outline-brand" data-quiz-option="${optionIndex}" data-quiz-answer="${Number(question.answer_index)}">${inline(option)}</button>
                            `).join('')}
                        </div>
                        <p class="hidden text-caption text-ink-muted" data-quiz-explanation>${inline(question.explanation || '')}</p>
                    </li>
                `).join('')}</ol>`;
            };

            const renderFlashcards = (message) => {
                const cards = Array.isArray(message.data) ? message.data : [];

                return `<div class="grid grid-cols-1 gap-2">${cards.map((card) => `
                    <button type="button" class="min-h-20 rounded-xl border border-border/50 bg-surface p-3 text-start text-sm focus-visible:outline-2 focus-visible:outline-brand" data-flashcard aria-pressed="false">
                        <span class="block font-semibold text-ink" data-flashcard-front>${inline(card.front || '')}</span>
                        <span class="hidden text-ink-muted" data-flashcard-back>${inline(card.back || '')}</span>
                        <span class="mt-1 block text-caption text-ink-subtle" data-flashcard-hint>Ketuk untuk membalik</span>
                    </button>
                `).join('')}</div>`;
            };

            const renderGlossary = (message) => {
                const terms = Array.isArray(message.data) ? message.data : [];

                return `<dl class="flex flex-col gap-2 text-sm">${terms.map((term) => `
                    <div><dt class="font-bold text-ink">${inline(term.term || '')}</dt><dd class="text-ink-muted">${inline(term.definition || '')}${(term.citations || []).map((n) => inline(`[${Number(n)}]`)).join('')}</dd></div>
                `).join('')}</dl>`;
            };

            const renderMindMapNode = (node, depth = 0) => {
                const children = Array.isArray(node?.children) ? node.children : [];
                const title = inline(node?.title || '');

                if (!children.length) {
                    return `<li class="text-sm text-ink-muted">${title}</li>`;
                }

                return `
                    <li>
                        <details ${depth < 1 ? 'open' : ''} class="group">
                            <summary class="cursor-pointer text-sm ${depth === 0 ? 'font-bold text-ink' : 'font-semibold text-ink'}">${title}</summary>
                            <ul class="ms-2 mt-1 flex flex-col gap-1 border-s border-border/60 ps-3">${children.map((child) => renderMindMapNode(child, depth + 1)).join('')}</ul>
                        </details>
                    </li>
                `;
            };

            const renderNoteBody = (message) => {
                switch (message.kind) {
                    case 'quiz':
                        return renderQuiz(message);
                    case 'flashcards':
                        return renderFlashcards(message);
                    case 'glossary':
                        return renderGlossary(message);
                    case 'mind_map':
                        return `<ul class="flex flex-col gap-1">${renderMindMapNode(message.data || {})}</ul>`;
                    default:
                        return renderMarkdown(message.content);
                }
            };

            const renderNotes = () => {
                const notes = state.messages.filter((message) => message.pinned).slice().reverse();

                if (!notes.length) {
                    notesHost.innerHTML = '<p class="text-sm text-ink-subtle">Hasil studio dan jawaban yang kamu simpan muncul di sini.</p>';

                    return;
                }

                notesHost.innerHTML = notes.map((message) => `
                    <details class="rounded-xl border border-border/40 bg-panel" data-message-id="${message.id}" ${message.id === state.openNoteId ? 'open' : ''}>
                        <summary class="flex cursor-pointer items-center justify-between gap-2 p-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-ink">${app.escapeHtml(message.kind === 'chat' ? 'Jawaban tersimpan' : (state.studioKinds[message.kind] || message.kind))}</span>
                                <span class="block text-caption text-ink-subtle">${app.escapeHtml([app.formatTimeAgo(message.created_at), message.context?.focus ? message.context.focus : null].filter(Boolean).join(' · '))}</span>
                            </span>
                        </summary>
                        <div class="flex flex-col gap-3 border-t border-border/40 p-3">
                            ${renderNoteBody(message)}
                            ${citationPanel(message)}
                            ${contextLine(message)}
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="mycic-btn-ghost-sm" data-pin="${message.id}" aria-pressed="true">Lepas dari Catatan</button>
                                ${message.kind !== 'chat' ? `<button type="button" class="mycic-btn-ghost-sm text-danger" data-delete-message="${message.id}">Hapus</button>` : ''}
                            </div>
                        </div>
                    </details>
                `).join('');
            };

            const renderStudioButtons = () => {
                const disabled = !state.aiAvailable || !contextSources().length;

                studioButtons.innerHTML = Object.entries(state.studioKinds).map(([kind, label]) => `
                    <button type="button" class="${kind === 'assignment_plan' ? 'mycic-btn-primary col-span-2' : 'mycic-btn-secondary-sm'}" data-studio="${kind}" ${disabled ? 'disabled aria-disabled="true"' : ''}>${app.escapeHtml(label)}</button>
                `).join('');
            };

            const renderContextBar = () => {
                const ready = readySources();
                const chosen = contextSources();
                const bar = el('notebook-context-bar');

                bar.classList.toggle('hidden', ready.length < 2);
                bar.classList.toggle('flex', ready.length >= 2);
                el('notebook-context-summary').textContent = `Konteks: ${chosen.length} dari ${ready.length} sumber siap`;
                el('notebook-ask-context').textContent = ready.length >= 2 && chosen.length < ready.length
                    ? `Hanya ${chosen.length} sumber yang dicentang dipakai`
                    : 'Enter untuk kirim, Shift+Enter untuk baris baru';
            };

            const sourceGroup = (source) => (source.meeting_number ? `Pertemuan ${source.meeting_number}` : (source.meeting_id ? 'Pertemuan' : 'Sumber lain'));

            const renderSources = () => {
                sourcesHost.removeAttribute('aria-busy');
                el('notebook-sources-count').textContent = `${state.sources.length}`;
                el('source-add-meeting').classList.toggle('hidden', !isCourseNotebook());

                if (!state.sources.length) {
                    sourcesHost.innerHTML = `<li class="text-sm text-ink-subtle">${isCourseNotebook()
                        ? 'Belum ada pertemuan. Buka pertemuan di menu Kuliah (masuk otomatis), atau pilih dari daftar lewat Tambah pertemuan.'
                        : 'Belum ada sumber. Tambahkan materi dari arsip, unggah PDF/PPTX/DOCX, atau tulis catatan.'}</li>`;
                    renderContextBar();

                    return;
                }

                let lastGroup = null;
                const hasMeetings = state.sources.some((source) => source.meeting_id);

                sourcesHost.innerHTML = state.sources.map((source) => {
                    const status = SOURCE_STATUS[source.status] || SOURCE_STATUS.pending;
                    const details = [SOURCE_KIND[source.kind] || source.kind, source.page_count ? `${source.page_count} hlm.` : '', source.truncated ? 'sebagian' : ''].filter(Boolean).join(' · ');
                    const group = sourceGroup(source);
                    const header = hasMeetings && group !== lastGroup ? `<li class="mt-1 text-caption font-bold uppercase tracking-wide text-ink-subtle">${app.escapeHtml(group)}</li>` : '';
                    lastGroup = group;
                    const ready = source.status === 'ready';
                    const checked = ready && !state.excluded.has(source.id);

                    return `${header}
                        <li class="rounded-xl border ${checked ? 'border-border/40' : 'border-dashed border-border/40 opacity-75'} p-3" data-source-id="${source.id}">
                            <div class="flex items-start justify-between gap-2">
                                <label class="flex min-w-0 items-start gap-2">
                                    <input type="checkbox" class="mt-1 shrink-0" data-source-select="${source.id}" ${checked ? 'checked' : ''} ${ready ? '' : 'disabled'} aria-label="Pakai sebagai konteks">
                                    <span class="min-w-0">
                                        <span class="block break-words text-sm font-semibold text-ink">${app.escapeHtml(source.title)}</span>
                                        <span class="block text-caption text-ink-subtle">${app.escapeHtml(details)}</span>
                                    </span>
                                </label>
                                ${app.renderBadge(status.label, status.tone)}
                            </div>
                            ${source.error ? `<p class="mt-2 text-caption text-danger">${app.escapeHtml(source.error)}</p>` : ''}
                            ${source.summary ? `<details class="mt-2"><summary class="cursor-pointer text-caption font-semibold text-brand-pressed">Ringkasan sumber</summary><p class="mt-1 whitespace-pre-line text-caption text-ink-muted">${app.escapeHtml(source.summary)}</p></details>` : ''}
                            <div class="mt-2 flex gap-2">
                                ${source.status === 'failed' && source.kind !== 'text' && source.kind !== 'meeting' ? `<button type="button" class="mycic-btn-ghost-sm" data-retry-source="${source.id}">Baca ulang</button>` : ''}
                                <button type="button" class="mycic-btn-ghost-sm text-danger" data-delete-source="${source.id}">Hapus</button>
                            </div>
                        </li>
                    `;
                }).join('');
                renderContextBar();
            };

            const renderHeader = () => {
                const notebook = state.notebook || {};
                const meetingsCount = Number(notebook.meetings_count || 0);

                el('notebook-title').textContent = notebook.title || 'Notebook';
                el('notebook-meta').textContent = [
                    notebook.kind === 'course' ? 'Notebook mata kuliah' : notebook.course_name,
                    notebook.course_code,
                    notebook.lecturer_name,
                    meetingsCount ? `${meetingsCount} pertemuan` : null,
                    `${state.sources.length} sumber`,
                ].filter(Boolean).join(' · ');
                document.title = `${notebook.title || 'Notebook'} · Notebook`;
                el('notebook-ai-off').classList.toggle('hidden', state.aiAvailable);
                el('notebook-delete').textContent = notebook.kind === 'course' ? 'Kosongkan' : 'Hapus';

                const classLink = el('notebook-class-link');
                classLink.classList.toggle('hidden', !notebook.class_url);
                classLink.href = notebook.class_url || '#';
            };

            const renderAll = () => {
                renderHeader();
                renderSources();
                renderMessages();
                renderNotes();
                renderStudioButtons();
            };

            const schedulePoll = () => {
                window.clearTimeout(state.pollTimer);

                if (state.sources.some((source) => source.status === 'pending' || source.status === 'processing')) {
                    state.pollTimer = window.setTimeout(() => load({ quiet: true }), 4000);
                }
            };

            const receive = (data) => {
                state.notebook = data.notebook;
                state.sources = Array.isArray(data.sources) ? data.sources : [];
                state.messages = Array.isArray(data.messages) ? data.messages : [];
                state.meetings = data.meetings && typeof data.meetings === 'object' ? data.meetings : null;
                state.archivedCourses = Array.isArray(data.archived_courses) ? data.archived_courses : [];
                state.studioKinds = data.studio_kinds || {};
                state.aiAvailable = data.ai_available !== false;

                const known = new Set(state.sources.map((source) => source.id));
                state.excluded.forEach((id) => {
                    if (!known.has(id)) {
                        state.excluded.delete(id);
                    }
                });
            };

            const load = async ({ quiet = false } = {}) => {
                try {
                    const payload = await app.request(base);

                    if (!payload?.data) {
                        return;
                    }

                    receive(payload.data);

                    if (quiet) {
                        renderHeader();
                        renderSources();
                        renderStudioButtons();
                    } else {
                        renderAll();
                    }

                    app.setSyncStatus('Notebook tersimpan di MyCIC.', 'info');
                    schedulePoll();
                } catch (error) {
                    if (error.status === 404) {
                        app.storeNotice('Notebook tidak ditemukan.', 'warning');
                        window.location.href = '/notebook';

                        return;
                    }

                    if (!quiet) {
                        sourcesHost.removeAttribute('aria-busy');
                        app.mountErrorState(messagesHost, 'Notebook gagal dimuat.', () => load(), { subtitle: app.describeError(error) });
                    }
                }
            };

            const addSources = async (body) => {
                const payload = await app.request(`${base}/sources`, { method: 'POST', ...body, timeout: 120000 });

                app.showNotice(payload?.message || 'Sumber ditambahkan.', 'success');
                await load({ quiet: true });
                renderMessages();
            };

            const findCitation = (messageId, number) => {
                const message = state.messages.find((item) => item.id === messageId);

                return message?.citations.find((citation) => citation.n === number) || null;
            };

            const showCitation = (button) => {
                const holder = button.closest('[data-message-id]');
                const messageId = Number(holder?.dataset.messageId);
                const panel = holder?.querySelector(`[data-cite-panel="${messageId}"]`);
                const citation = findCitation(messageId, Number(button.dataset.cite));

                if (!panel) {
                    return;
                }

                if (!citation) {
                    panel.classList.remove('hidden');
                    panel.textContent = 'Kutipan ini tidak ditemukan.';

                    return;
                }

                const alreadyShown = panel.dataset.shown === String(citation.n) && !panel.classList.contains('hidden');
                panel.classList.toggle('hidden', alreadyShown);
                panel.dataset.shown = String(citation.n);
                panel.innerHTML = `
                    <p class="mb-1 font-bold text-ink">[${citation.n}] ${app.escapeHtml(citation.source_title)}${citation.page ? ` · hlm. ${citation.page}` : ''}</p>
                    <p class="whitespace-pre-line">${app.escapeHtml(citation.excerpt)}</p>
                `;
            };

            const ask = async (question) => {
                if (state.busy || question.trim() === '') {
                    return;
                }

                state.busy = true;
                const submit = askForm.querySelector('button[type="submit"]');
                const pending = document.createElement('li');
                pending.className = 'flex flex-col gap-2';
                pending.innerHTML = `
                    <div class="ms-auto max-w-[85%] rounded-2xl rounded-tr-sm bg-brand px-3 py-2 text-sm text-white">${app.escapeHtml(question)}</div>
                    <div class="flex items-center gap-2 text-sm text-ink-subtle"><span class="mycic-spinner" aria-hidden="true"></span> Membaca ${contextSources().length} sumber…</div>
                `;

                if (!state.messages.some((message) => message.kind === 'chat')) {
                    messagesHost.innerHTML = '';
                }

                messagesHost.appendChild(pending);
                messagesHost.scrollTop = messagesHost.scrollHeight;
                questionInput.value = '';

                await app.withSubmitLoading(submit, async () => {
                    try {
                        const payload = await app.request(`${base}/messages`, { method: 'POST', json: { question, source_ids: contextSourceIds() }, timeout: 120000 });

                        state.messages.push(payload.data.question, payload.data.answer);
                        renderMessages();
                    } catch (error) {
                        pending.remove();
                        questionInput.value = question;
                        renderMessages();
                        app.showNotice(describeAiError(error, 'Pertanyaan belum bisa dijawab.'), 'error');
                    }
                });

                state.busy = false;
            };

            const runStudio = async (button, kind, focus = null) => {
                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`${base}/studio`, { method: 'POST', json: { kind, source_ids: contextSourceIds(), focus }, timeout: 180000 });

                        state.messages.push(payload.data.message);
                        state.openNoteId = payload.data.message.id;
                        renderNotes();
                        setTab('studio');
                        app.showNotice(payload.message || 'Hasil dibuat.', 'success');
                    } catch (error) {
                        app.showNotice(describeAiError(error, 'Hasil belum bisa dibuat.'), 'error');
                    }
                });
            };

            // The assignment outline asks what the task is, so the outline fits the brief and the chosen sources.
            const openAssignmentPlanner = (button) => {
                const meetingsWithTasks = state.sources.filter((source) => source.kind === 'meeting' && source.status === 'ready');
                const chosen = contextSources();
                const panel = app.openSheet({
                    title: 'Kerangka tugas',
                    pretitle: `${chosen.length} sumber dicentang`,
                    body: `
                        <form class="flex flex-col gap-3" data-assignment-plan>
                            <p class="text-sm text-ink-muted">Tulis instruksi tugas dari dosen (atau tempel dari portal). Kerangka, poin per bagian, konsep kunci bersitasi, langkah kerja, dan checklist disusun hanya dari sumber yang dicentang, agar kamu menulis sendiri dengan cepat dan rapi.</p>
                            <label class="mycic-field">
                                <span class="mycic-label">Instruksi / fokus tugas</span>
                                <textarea class="mycic-input min-h-32" name="focus" maxlength="1500" placeholder="Contoh: Makalah 5 halaman tentang normalisasi basis data, sertakan contoh kasus, dikumpulkan pertemuan 7."></textarea>
                            </label>
                            ${meetingsWithTasks.length ? `
                                <div class="flex flex-wrap gap-2">
                                    ${meetingsWithTasks.map((source) => `<button type="button" class="mycic-btn-secondary-sm" data-focus-meeting="${source.id}">Tugas ${app.escapeHtml(source.meeting_number ? `pertemuan ${source.meeting_number}` : source.title)}</button>`).join('')}
                                </div>
                            ` : ''}
                            <p class="text-caption text-ink-subtle">Konteks terukur: ${chosen.length} sumber${chosen.length !== readySources().length ? ` (dari ${readySources().length} yang siap)` : ''}. Ubah centang di panel Sumber bila perlu.</p>
                            <button type="submit" class="mycic-btn-primary">Susun kerangka</button>
                        </form>
                    `,
                });
                const form = panel.querySelector('[data-assignment-plan]');

                form.querySelectorAll('[data-focus-meeting]').forEach((chip) => {
                    chip.addEventListener('click', () => {
                        const source = state.sources.find((item) => item.id === Number(chip.dataset.focusMeeting));
                        const prefix = source?.meeting_number ? `Tugas pertemuan ${source.meeting_number}` : `Tugas ${source?.title || ''}`;

                        form.focus.value = form.focus.value.trim() ? form.focus.value : `${prefix}: `;
                        form.focus.focus();
                    });
                });

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    const focus = form.focus.value.trim() || null;

                    app.closeSheet();
                    runStudio(button, 'assignment_plan', focus);
                });
            };

            const openMeetingPicker = async () => {
                const render = (data) => {
                    const meetings = Array.isArray(data?.meetings) ? data.meetings : [];
                    const classUrl = state.notebook?.class_url;
                    const syncButton = classUrl ? '<button type="button" class="mycic-btn-secondary" data-meeting-sync data-online-required>Masukkan semua pertemuan (background)</button>' : '';
                    let body;

                    if (!meetings.length) {
                        body = `
                            <div class="flex flex-col gap-3">
                                ${app.renderEmptyState(data?.available === false ? 'Daftar pertemuan belum tersimpan.' : 'Belum ada pertemuan di halaman kelas.', 'secondary', data?.available === false ? 'Buka halaman kelas ini sekali agar daftar pertemuannya tersimpan, atau buka pertemuan lalu pilih "Masukkan ke Notebook".' : 'Pertemuan muncul setelah dosen mengunggah materinya.')}
                                ${classUrl ? `<a href="${app.escapeHtml(classUrl)}" class="mycic-btn-primary">Buka halaman kelas</a>` : ''}
                                ${syncButton}
                            </div>
                        `;
                    } else {
                        body = `
                            <form class="flex flex-col gap-3" data-meeting-picker>
                                <div class="flex flex-col gap-1.5">
                                    ${meetings.map((meeting) => `
                                        <label class="flex items-start gap-2 rounded-lg border border-border/40 p-2 text-sm ${meeting.added ? 'opacity-70' : ''}">
                                            <input type="checkbox" class="mt-1" value="${app.escapeHtml(meeting.meeting_id)}" data-courses-id="${app.escapeHtml(meeting.courses_id)}" ${meeting.added ? 'checked disabled' : ''}>
                                            <span class="min-w-0 flex-1">
                                                <span class="block font-semibold text-ink">${app.escapeHtml(meeting.meeting_number ? `P${meeting.meeting_number} · ` : '')}${app.escapeHtml(meeting.title)}</span>
                                                <span class="block text-caption text-ink-subtle">${app.escapeHtml([meeting.date, meeting.time, meeting.archived_files ? `${meeting.archived_files} berkas di arsip` : null].filter(Boolean).join(' · '))}</span>
                                            </span>
                                            ${meeting.added ? app.renderBadge('Sudah masuk', 'success') : ''}
                                        </label>
                                    `).join('')}
                                </div>
                                <p class="text-caption text-ink-subtle">Pertemuan yang belum pernah dibuka diambil dari portal (butuh online). Berkasnya dibaca di background.</p>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button type="submit" class="mycic-btn-primary">Tambahkan yang dipilih</button>
                                    ${syncButton}
                                </div>
                            </form>
                        `;
                    }

                    const panel = app.openSheet({ title: 'Tambah pertemuan', pretitle: state.notebook?.course_name || '', body });
                    app.refreshOnlineRequiredState();
                    const picker = panel.querySelector('[data-meeting-picker]');

                    picker?.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const chosen = Array.from(picker.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)'));

                        if (!chosen.length) {
                            app.showNotice('Pilih minimal satu pertemuan.', 'warning');

                            return;
                        }

                        await app.withSubmitLoading(picker.querySelector('button[type="submit"]'), async () => {
                            let added = 0;

                            for (const input of chosen) {
                                try {
                                    await app.request('/api/notebooks/meetings', { method: 'POST', json: { meeting_id: input.value, courses_id: input.dataset.coursesId }, timeout: 120000 });
                                    added += 1;
                                    input.disabled = true;
                                } catch (error) {
                                    app.showNotice(`Pertemuan gagal dimasukkan. ${app.describeError(error)}`, 'error');
                                }
                            }

                            if (added) {
                                app.showNotice(`${added} pertemuan masuk ke notebook.`, 'success');
                                app.closeSheet();
                                await load({ quiet: true });
                                renderMessages();
                            }
                        });
                    });

                    panel.querySelector('[data-meeting-sync]')?.addEventListener('click', async (event) => {
                        if (!app.requireOnlineAction('Memasukkan semua pertemuan butuh koneksi ke portal.')) {
                            return;
                        }

                        await app.withSubmitLoading(event.currentTarget, async () => {
                            try {
                                const payload = await app.request(`${base}/meetings/sync`, { method: 'POST' });

                                app.showNotice(payload?.message || 'Pertemuan sedang dimasukkan.', 'success');
                                app.closeSheet();
                            } catch (error) {
                                app.showNotice(`Belum bisa disinkronkan. ${app.describeError(error)}`, 'error');
                            }
                        });
                    });
                };

                if (state.meetings) {
                    render(state.meetings);
                }

                try {
                    const payload = await app.request(`${base}/meetings`);

                    if (payload?.data) {
                        state.meetings = payload.data;

                        if (!document.querySelector('[data-meeting-picker]') && !document.querySelector('[data-meeting-sync]')) {
                            render(state.meetings);
                        }
                    }
                } catch (error) {
                    if (!state.meetings) {
                        app.showNotice(app.describeError(error, 'Daftar pertemuan gagal dimuat.'), 'error');
                    }
                }
            };

            const setTab = (tab) => {
                state.tab = tab;
                document.querySelectorAll('[data-notebook-tab]').forEach((button) => {
                    const active = button.dataset.notebookTab === tab;

                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                    button.className = `inline-flex min-h-11 items-center rounded-lg px-3 text-sm focus-visible:outline-2 focus-visible:outline-brand ${active ? 'border border-border/40 bg-panel font-bold text-brand shadow-xs' : 'border border-transparent font-semibold text-ink-muted'}`;
                });
                document.querySelectorAll('[data-notebook-panel]').forEach((panel) => {
                    panel.classList.toggle('max-lg:hidden', panel.dataset.notebookPanel !== tab);
                });
            };

            document.querySelectorAll('[data-notebook-tab]').forEach((button) => button.addEventListener('click', () => setTab(button.dataset.notebookTab)));
            setTab('chat');

            askForm.addEventListener('submit', (event) => {
                event.preventDefault();
                ask(questionInput.value.trim());
            });

            questionInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    askForm.requestSubmit();
                }
            });

            sourcesHost.addEventListener('change', (event) => {
                const checkbox = event.target instanceof HTMLInputElement ? event.target.closest('[data-source-select]') : null;

                if (!checkbox) {
                    return;
                }

                const id = Number(checkbox.dataset.sourceSelect);

                if (checkbox.checked) {
                    state.excluded.delete(id);
                } else {
                    state.excluded.add(id);
                }

                renderSources();
                renderStudioButtons();
            });

            el('notebook-context-all').addEventListener('click', () => {
                state.excluded.clear();
                renderSources();
                renderStudioButtons();
            });

            el('notebook-context-none').addEventListener('click', () => {
                readySources().forEach((source) => state.excluded.add(source.id));
                renderSources();
                renderStudioButtons();
            });

            document.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (!target) {
                    return;
                }

                const cite = target.closest('[data-cite]');
                const suggestion = target.closest('[data-suggestion]');
                const pin = target.closest('[data-pin]');
                const studio = target.closest('[data-studio]');
                const deleteSource = target.closest('[data-delete-source]');
                const retrySource = target.closest('[data-retry-source]');
                const deleteMessage = target.closest('[data-delete-message]');
                const quizOption = target.closest('[data-quiz-option]');
                const flashcard = target.closest('[data-flashcard]');

                if (cite) {
                    showCitation(cite);
                } else if (suggestion) {
                    ask(suggestion.dataset.suggestion);
                } else if (quizOption) {
                    const item = quizOption.closest('li');
                    const answer = Number(quizOption.dataset.quizAnswer);

                    item.querySelectorAll('[data-quiz-option]').forEach((option) => {
                        const index = Number(option.dataset.quizOption);

                        option.disabled = true;
                        option.classList.toggle('border-success', index === answer);
                        option.classList.toggle('bg-success-soft', index === answer);
                        option.classList.toggle('border-danger', index !== answer && option === quizOption);
                    });
                    item.querySelector('[data-quiz-explanation]')?.classList.remove('hidden');
                } else if (flashcard) {
                    const flipped = flashcard.getAttribute('aria-pressed') !== 'true';

                    flashcard.setAttribute('aria-pressed', flipped ? 'true' : 'false');
                    flashcard.querySelector('[data-flashcard-front]').classList.toggle('hidden', flipped);
                    flashcard.querySelector('[data-flashcard-back]').classList.toggle('hidden', !flipped);
                    flashcard.querySelector('[data-flashcard-hint]').textContent = flipped ? 'Ketuk untuk melihat pertanyaan' : 'Ketuk untuk membalik';
                } else if (pin) {
                    const messageId = Number(pin.dataset.pin);
                    const message = state.messages.find((item) => item.id === messageId);

                    try {
                        const payload = await app.request(`${base}/messages/${messageId}`, { method: 'PATCH', json: { pinned: !message?.pinned } });

                        Object.assign(message, payload.data.message);
                        renderMessages();
                        renderNotes();
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (studio && !studio.disabled) {
                    if (studio.dataset.studio === 'assignment_plan') {
                        openAssignmentPlanner(studio);
                    } else {
                        await runStudio(studio, studio.dataset.studio);
                    }
                } else if (deleteSource) {
                    if (!window.confirm('Hapus sumber ini dari notebook?')) {
                        return;
                    }

                    try {
                        await app.request(`${base}/sources/${deleteSource.dataset.deleteSource}`, { method: 'DELETE' });
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (retrySource) {
                    try {
                        await app.request(`${base}/sources/${retrySource.dataset.retrySource}/retry`, { method: 'POST' });
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (deleteMessage) {
                    if (!window.confirm('Hapus hasil ini?')) {
                        return;
                    }

                    try {
                        await app.request(`${base}/messages/${deleteMessage.dataset.deleteMessage}`, { method: 'DELETE' });
                        state.messages = state.messages.filter((message) => message.id !== Number(deleteMessage.dataset.deleteMessage));
                        renderNotes();
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                }
            });

            el('source-add-meeting').addEventListener('click', () => openMeetingPicker());

            el('source-add-archive').addEventListener('click', () => {
                const used = new Set(state.sources.map((source) => source.archived_file_id).filter(Boolean));
                const courses = state.archivedCourses;
                const body = courses.length
                    ? `
                        <form class="flex flex-col gap-4" data-archive-picker>
                            ${courses.map((course) => `
                                <fieldset class="flex flex-col gap-1">
                                    <legend class="mb-1 text-sm font-bold text-ink">${app.escapeHtml(course.course_name || 'Mata kuliah')}</legend>
                                    ${course.files.map((file) => `
                                        <label class="flex items-start gap-2 text-sm ${used.has(file.id) ? 'opacity-60' : ''}">
                                            <input type="checkbox" class="mt-1" value="${file.id}" ${used.has(file.id) ? 'checked disabled' : ''}>
                                            <span>${app.escapeHtml([file.meeting_number ? `P${file.meeting_number}` : '', file.kind_label, file.title || file.filename].filter(Boolean).join(' · '))}</span>
                                        </label>
                                    `).join('')}
                                </fieldset>
                            `).join('')}
                            <button type="submit" class="mycic-btn-primary">Tambahkan</button>
                        </form>
                    `
                    : app.renderEmptyState('Arsip materi masih kosong.', 'secondary', 'Buka pertemuan di halaman Kuliah atau arsipkan materi kelas di menu Arsip.');
                const panel = app.openSheet({ title: 'Tambah dari arsip materi', body });
                const picker = panel.querySelector('[data-archive-picker]');

                picker?.addEventListener('submit', (event) => {
                    event.preventDefault();

                    const ids = Array.from(picker.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)')).map((input) => Number(input.value));

                    if (!ids.length) {
                        app.showNotice('Pilih minimal satu berkas.', 'warning');

                        return;
                    }

                    app.withSubmitLoading(picker.querySelector('button[type="submit"]'), async () => {
                        try {
                            await addSources({ json: { type: 'archived_file', archived_file_ids: ids } });
                            app.closeSheet();
                        } catch (error) {
                            app.showNotice(`Sumber gagal ditambahkan. ${app.describeError(error)}`, 'error');
                        }
                    });
                });
            });

            el('source-upload').addEventListener('change', async (event) => {
                const file = event.target.files?.[0];

                if (!file) {
                    return;
                }

                const formData = new FormData();
                formData.append('type', 'upload');
                formData.append('file', file);

                try {
                    app.showNotice(`Mengunggah ${file.name}…`, 'info');
                    await addSources({ formData });
                } catch (error) {
                    app.showNotice(`Berkas gagal diunggah. ${app.describeError(error)}`, 'error');
                } finally {
                    event.target.value = '';
                }
            });

            el('source-add-text').addEventListener('click', () => {
                const panel = app.openSheet({
                    title: 'Tulis catatan',
                    body: `
                        <form class="flex flex-col gap-3" data-text-source>
                            <label class="mycic-field"><span class="mycic-label">Judul</span><input class="mycic-input" name="title" maxlength="120" required></label>
                            <label class="mycic-field"><span class="mycic-label">Isi</span><textarea class="mycic-input min-h-48" name="content" required placeholder="Tempel catatan kuliah, ringkasan, atau teks materi."></textarea></label>
                            <button type="submit" class="mycic-btn-primary">Simpan sebagai sumber</button>
                        </form>
                    `,
                });
                const textForm = panel.querySelector('[data-text-source]');

                textForm.addEventListener('submit', (event) => {
                    event.preventDefault();

                    app.withSubmitLoading(textForm.querySelector('button[type="submit"]'), async () => {
                        try {
                            await addSources({ json: { type: 'text', title: textForm.title.value, content: textForm.content.value } });
                            app.closeSheet();
                        } catch (error) {
                            app.showNotice(`Catatan gagal disimpan. ${app.describeError(error)}`, 'error');
                        }
                    });
                });
            });

            el('notebook-rename').addEventListener('click', async () => {
                const title = window.prompt('Judul baru notebook', state.notebook?.title || '');

                if (!title || title.trim() === '' || title.trim() === state.notebook?.title) {
                    return;
                }

                try {
                    const payload = await app.request(base, { method: 'PATCH', json: { title: title.trim() } });

                    state.notebook = payload.data.notebook;
                    renderHeader();
                } catch (error) {
                    app.showNotice(app.describeError(error), 'error');
                }
            });

            el('notebook-delete').addEventListener('click', async () => {
                const course = isCourseNotebook();
                const question = course
                    ? 'Kosongkan notebook mata kuliah ini? Semua pertemuan, sumber, percakapan, dan catatannya dihapus; notebooknya tetap ada. Berkas arsip materi tidak ikut terhapus.'
                    : 'Hapus notebook ini beserta percakapan dan unggahannya? Berkas arsip materi tidak ikut terhapus.';

                if (!window.confirm(question)) {
                    return;
                }

                try {
                    const payload = await app.request(base, { method: 'DELETE' });

                    if (payload?.data?.emptied) {
                        state.excluded.clear();
                        app.showNotice(payload.message || 'Notebook dikosongkan.', 'success');
                        await load();

                        return;
                    }

                    app.storeNotice('Notebook dihapus.', 'success');
                    window.location.href = '/notebook';
                } catch (error) {
                    app.showNotice(app.describeError(error), 'error');
                }
            });

            // The background meeting sync announces itself with this key.
            app.registerSyncHandler('notebook-show', async () => {
                await load({ quiet: true });
                renderMessages();
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            await load();
        });
    </script>
@endpush
