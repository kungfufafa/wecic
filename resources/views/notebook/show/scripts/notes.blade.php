{{--
    Notebook script, the Studio outputs in Catatan (quiz, flashcards,
    glossary, mind map) and the Studio buttons. Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            const renderQuiz = (message) => {
                const questions = Array.isArray(message.data) ? message.data : [];

                return `<ol class="flex flex-col gap-4" data-quiz="${message.id}">${questions.map((question, index) => `
                    <li class="flex flex-col gap-2">
                        <p class="text-sm font-medium leading-snug text-ink">${index + 1}. ${inline(question.question || '')}</p>
                        <div class="flex flex-col gap-1.5">
                            ${(question.options || []).map((option, optionIndex) => `
                                <button type="button" class="cursor-pointer rounded-[var(--radius-control)] border border-border px-3 py-2 text-start text-sm text-ink transition-colors duration-150 ease-[var(--ease-snappy)] hover:border-brand/50 hover:bg-surface/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-quiz-option="${optionIndex}" data-quiz-answer="${Number(question.answer_index)}">${inline(option)}</button>
                            `).join('')}
                        </div>
                        <p class="hidden text-caption text-ink-muted" data-quiz-explanation>${inline(question.explanation || '')}</p>
                    </li>
                `).join('')}</ol>`;
            };

            const renderFlashcards = (message) => {
                const cards = Array.isArray(message.data) ? message.data : [];

                return `<div class="grid grid-cols-1 gap-2">${cards.map((card) => `
                    <button type="button" class="min-h-20 cursor-pointer rounded-[var(--radius-card)] border border-border bg-surface p-3 text-start text-sm transition-colors duration-150 ease-[var(--ease-snappy)] hover:border-ink-subtle/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-flashcard aria-pressed="false">
                        <span class="block font-medium leading-snug text-ink" data-flashcard-front>${inline(card.front || '')}</span>
                        <span class="hidden text-ink-muted" data-flashcard-back>${inline(card.back || '')}</span>
                        <span class="mt-1 block text-caption text-ink-subtle" data-flashcard-hint>Ketuk untuk membalik</span>
                    </button>
                `).join('')}</div>`;
            };

            const renderGlossary = (message) => {
                const terms = Array.isArray(message.data) ? message.data : [];

                return `<dl class="flex flex-col gap-2 text-sm">${terms.map((term) => `
                    <div><dt class="font-medium text-ink">${inline(term.term || '')}</dt><dd class="text-ink-muted">${inline(term.definition || '')}${(term.citations || []).map((n) => inline(`[${Number(n)}]`)).join('')}</dd></div>
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
                            <summary class="cursor-pointer text-sm font-medium text-ink">${title}</summary>
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

            // Studio outputs live only here, so they are listed even if unpinned elsewhere.
            const renderNotes = () => {
                const notes = state.messages.filter((message) => message.pinned || message.kind !== 'chat').slice().reverse();

                if (!notes.length) {
                    notesHost.innerHTML = '<p class="text-sm text-ink-subtle">Hasil studio dan jawaban yang kamu simpan muncul di sini.</p>';

                    return;
                }

                notesHost.innerHTML = notes.map((message) => `
                    <details class="rounded-[var(--radius-control)] border border-border bg-panel" data-message-id="${message.id}" ${message.id === state.openNoteId ? 'open' : ''}>
                        <summary class="flex cursor-pointer items-center justify-between gap-2 p-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(message.kind === 'chat' ? 'Jawaban tersimpan' : (state.studioKinds[message.kind] || message.kind))}</span>
                                <span class="block text-caption text-ink-subtle">${app.escapeHtml([app.formatTimeAgo(message.created_at), message.context?.focus ? message.context.focus : null].filter(Boolean).join(' · '))}</span>
                            </span>
                        </summary>
                        <div class="flex flex-col gap-3 border-t border-border-light p-3">
                            ${renderNoteBody(message)}
                            ${citationPanel(message)}
                            ${contextLine(message)}
                            <div class="flex flex-wrap gap-2">
                                ${message.kind === 'chat'
                                    ? `<button type="button" class="mycic-btn-ghost-sm" data-pin="${message.id}" aria-pressed="true">Lepas dari Catatan</button>`
                                    : `<button type="button" class="mycic-btn-ghost-sm text-danger hover:bg-danger-soft hover:text-danger" data-delete-message="${message.id}">Hapus</button>`}
                            </div>
                        </div>
                    </details>
                `).join('');
            };

            const renderStudioButtons = () => {
                const disabled = !state.aiAvailable || !contextSources().length;

                studioButtons.innerHTML = Object.entries(state.studioKinds).map(([kind, label]) => `
                    <button type="button" class="${kind === 'assignment_plan' ? 'mycic-btn-primary-sm col-span-2' : 'mycic-btn-secondary-sm'}" data-studio="${kind}" ${disabled ? 'disabled aria-disabled="true"' : ''}>${app.escapeHtml(label)}</button>
                `).join('');
            };

