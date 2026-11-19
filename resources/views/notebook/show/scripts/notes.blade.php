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
                                <button type="button" class="min-h-11 cursor-pointer rounded-lg border border-border bg-panel px-3 py-2 text-start text-sm text-ink transition-colors duration-150 ease-[var(--ease-snappy)] hover:border-brand/50 hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 disabled:cursor-default disabled:hover:border-border disabled:hover:bg-panel sm:min-h-9" data-quiz-option="${optionIndex}" data-quiz-answer="${Number(question.answer_index)}">${inline(option)}</button>
                            `).join('')}
                        </div>
                        <p class="hidden text-xs leading-relaxed text-ink-muted focus:outline-none" data-quiz-explanation>${inline(question.explanation || '')}</p>
                    </li>
                `).join('')}</ol>`;
            };

            const renderFlashcards = (message) => {
                const cards = Array.isArray(message.data) ? message.data : [];

                return `<div class="grid grid-cols-1 gap-2">${cards.map((card) => `
                    <button type="button" class="min-h-20 cursor-pointer rounded-lg border border-border bg-surface/40 p-3 text-start text-sm transition-colors duration-150 ease-[var(--ease-snappy)] hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-pressed:border-brand/40 aria-pressed:bg-surface-tint/40" data-flashcard aria-pressed="false">
                        <span class="block font-medium leading-snug text-ink" data-flashcard-front>${inline(card.front || '')}</span>
                        <span class="hidden text-ink-muted" data-flashcard-back>${inline(card.back || '')}</span>
                        <span class="mt-1 block text-xs text-ink-subtle" data-flashcard-hint>Ketuk untuk membalik</span>
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
                            <ul class="ms-2 mt-1 flex flex-col gap-1 border-s border-border ps-3">${children.map((child) => renderMindMapNode(child, depth + 1)).join('')}</ul>
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
                    notesHost.innerHTML = '<p class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-ink-subtle">Hasil studio dan jawaban yang kamu simpan muncul di sini.</p>';

                    return;
                }

                notesHost.innerHTML = notes.map((message) => `
                    <details class="group border-t border-border-light first:border-t-0" data-message-id="${message.id}" ${message.id === state.openNoteId ? 'open' : ''}>
                        <summary class="-mx-2 flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-lg px-2 py-2.5 transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium leading-snug text-ink">${app.escapeHtml(message.kind === 'chat' ? 'Jawaban tersimpan' : (state.studioKinds[message.kind] || message.kind))}</span>
                                <span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml([app.formatTimeAgo(message.created_at), message.context?.focus ? message.context.focus : null].filter(Boolean).join(' · '))}</span>
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                        </summary>
                        <div class="flex flex-col gap-3 pb-3 pt-1">
                            ${renderNoteBody(message)}
                            ${citationPanel(message)}
                            ${contextLine(message)}
                            <div class="flex flex-wrap gap-2">
                                ${message.kind === 'chat'
                                    ? `<button type="button" class="${app.ui.button.ghostSm}" data-pin="${message.id}" aria-pressed="true">Lepas dari Catatan</button>`
                                    : `<button type="button" class="${app.ui.button.ghostSm} text-danger! hover:bg-danger-soft!" data-delete-message="${message.id}">Hapus</button>`}
                            </div>
                        </div>
                    </details>
                `).join('');
            };

            const renderStudioButtons = () => {
                const disabled = !state.aiAvailable || !contextSources().length;

                studioButtons.innerHTML = Object.entries(state.studioKinds).map(([kind, label]) => `
                    <button type="button" class="${kind === 'assignment_plan' ? app.ui.button.primarySm : app.ui.button.secondarySm} min-w-0 whitespace-normal! py-1.5 leading-tight ${kind === 'assignment_plan' ? 'col-span-2' : ''}" data-studio="${kind}" ${disabled ? 'disabled aria-disabled="true"' : ''}>${app.escapeHtml(label)}</button>
                `).join('');
            };

