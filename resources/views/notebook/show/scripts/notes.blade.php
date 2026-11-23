{{--
    Notebook script, the Studio outputs in Catatan (quiz and flashcard
    practice, glossary, mind map, Simpan ke ulasan), the student's own notes (editor,
    preview, Salin, Unduh .md, Jadikan sumber) and the Studio buttons. Practice progress
    lives in state.practice, so redrawing Catatan keeps it. Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            // Practice lives in state.practice[message.id], so a redraw of Catatan keeps it (renderNotes()).
            const quizPractice = (messageId) => (state.practice[messageId] ??= { choices: [], flagged: [], reviewing: false });

            // Items marked "Soal ini salah?" leave the score; the rest count once all of them are answered.
            const quizScore = (questions, practice) => {
                const counted = questions.map((question, index) => index).filter((index) => !practice.flagged.includes(index));
                const answered = counted.filter((index) => Number.isInteger(practice.choices[index]));
                const missed = counted.filter((index) => practice.choices[index] !== Number(questions[index].answer_index));

                return {
                    total: counted.length,
                    answered: answered.length,
                    score: counted.length - missed.length,
                    missed,
                    done: counted.length > 0 && answered.length === counted.length,
                };
            };

            // Numbers the item's own text already shows as [n] are not drawn a second time.
            const itemCitations = (item, text = '') => {
                const shown = new Set([...String(text).matchAll(/\[(\d+)\]/g)].map((match) => Number(match[1])));

                return [...new Set((Array.isArray(item?.citations) ? item.citations : []).map(Number))]
                    .filter((n) => Number.isInteger(n) && !shown.has(n))
                    .map((n) => citationButton(n))
                    .join('');
            };

            // Making a practice set spends one AI request: what is left today is said before the button.
            const budgetLine = () => {
                if (!state.aiBudget) {
                    return 'Membuat latihan memakai 1 permintaan dari kuota AI harianmu.';
                }

                return state.aiBudget.remaining > 0
                    ? `Sisa kuota AI hari ini: ${state.aiBudget.remaining} dari ${state.aiBudget.limit}. Membuat latihan memakai 1.`
                    : 'Kuota AI hari ini sudah habis. Ulangi dan tinjau tetap bisa dipakai; buat latihan baru besok.';
            };

            const practiceFromButton = (messageId, missed) => {
                const unavailable = !state.aiAvailable || state.aiBudget?.remaining === 0;

                return `
                    <button type="button" class="${app.ui.button.primarySm}" data-practice-from="${messageId}" data-practice-missed="${missed.join(',')}" ${unavailable ? 'disabled aria-disabled="true"' : ''}>Buat latihan dari yang salah</button>
                `;
            };

            // Ulasan harian: no AI and no budget. A quiz saves the questions missed in its last saved attempt
            // (context.practice); flashcards save every card, or with `missed` the ones still to learn.
            const reviewImportButton = (messageId, label, missed = false) => `
                <button type="button" class="${app.ui.button.secondarySm}" data-review-import="${messageId}" ${missed ? 'data-review-missed' : ''}>${label}</button>
            `;

            // The server reads the attempt saved on the output, so the save of the attempt just finished (savePractice)
            // lands first; one that failed is tried once more, and nothing is imported while it is not saved.
            const saveToReview = async (button) => {
                const messageId = Number(button.dataset.reviewImport);

                await app.withSubmitLoading(button, async () => {
                    const pending = state.practiceSaves[messageId];

                    if (pending && !(await pending)) {
                        const message = state.messages.find((item) => item.id === messageId);

                        if (!message || !(await savePractice(message))) {
                            return;
                        }
                    }

                    try {
                        const payload = await app.request(`${base}/messages/${messageId}/review-cards`, {
                            method: 'POST',
                            json: button.hasAttribute('data-review-missed') ? { only_missed: true } : {},
                        });

                        app.showNotice(`${payload?.message || 'Disimpan ke Ulasan harian.'} Ulas di Notebook › Ulasan harian.`, 'success');
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Kartu gagal disimpan ke ulasan.'), 'error');
                    }
                });
            };

            const renderQuizItem = (question, index, practice) => {
                const answer = Number(question.answer_index);
                const choice = practice.choices[index];
                const answered = Number.isInteger(choice);
                const flagged = practice.flagged.includes(index);
                const correct = answered && choice === answer;
                const options = (question.options || []).map((option, optionIndex) => {
                    const mark = !answered || flagged ? '' : optionIndex === answer
                        ? ' <span class="text-xs font-medium text-success">· Benar</span>'
                        : optionIndex === choice ? ' <span class="text-xs font-medium text-danger">· Pilihanmu, salah</span>' : '';
                    const tone = !answered || flagged ? '' : optionIndex === answer
                        ? 'border-success! bg-success-soft!'
                        : optionIndex === choice ? 'border-danger!' : '';

                    return `<button type="button" class="min-h-11 cursor-pointer rounded-lg border border-border bg-panel px-3 py-2 text-start text-sm text-ink transition-colors duration-150 ease-[var(--ease-snappy)] hover:border-brand/50 hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 disabled:cursor-default disabled:hover:border-border disabled:hover:bg-panel sm:min-h-9 ${tone}" data-quiz-option="${optionIndex}" ${answered || flagged ? 'disabled' : ''}>${inline(option)}${mark}</button>`;
                }).join('');

                return `
                    <li class="flex flex-col gap-2 ${flagged ? 'opacity-70' : ''}" data-quiz-item="${index}">
                        <p class="text-sm font-medium leading-snug text-ink">${index + 1}. ${inline(question.question || '')}</p>
                        ${question.topic ? `<p class="text-xs text-ink-subtle">Topik: ${app.escapeHtml(question.topic)}</p>` : ''}
                        <div class="flex flex-col gap-1.5">${options}</div>
                        ${answered && !flagged ? `
                            <p class="text-xs leading-relaxed text-ink-muted focus:outline-none" data-quiz-explanation="${index}" tabindex="-1"><span class="font-medium text-ink">${correct ? 'Benar.' : `Salah. Jawaban benar: ${app.escapeHtml(String(question.options?.[answer] ?? ''))}.`}</span> ${inline(question.explanation || '')}${itemCitations(question, question.explanation)}</p>
                        ` : ''}
                        ${flagged ? '<p class="text-xs text-ink-subtle">Ditandai salah, tidak dihitung dalam skor.</p>' : ''}
                        <div><button type="button" class="${app.ui.button.ghostSm} -ms-3" data-quiz-flag="${index}" aria-pressed="${flagged ? 'true' : 'false'}">${flagged ? 'Batalkan tanda salah' : 'Soal ini salah?'}</button></div>
                    </li>
                `;
            };

            const renderQuiz = (message) => {
                const questions = Array.isArray(message.data) ? message.data : [];
                const practice = quizPractice(message.id);
                const result = quizScore(questions, practice);
                // Reviewing lists the missed items and the ones marked wrong, so a mark can still be undone;
                // with none of either left it falls back to every item.
                const reviewed = questions.map((question, index) => index).filter((index) => result.missed.includes(index) || practice.flagged.includes(index));
                const reviewing = practice.reviewing && result.done && reviewed.length > 0;
                const shown = reviewing ? reviewed : questions.map((question, index) => index);
                const last = message.context?.practice;
                const flaggedCount = practice.flagged.length;

                return `
                    <div class="flex flex-col gap-4" data-quiz="${message.id}">
                        <p class="text-xs text-ink-subtle">${result.done ? '' : `Terjawab ${result.answered}/${result.total}${last && !result.answered ? ` · Skor terakhir ${Number(last.score)}/${Number(last.total)}` : ''}`}</p>
                        <ol class="flex flex-col gap-4">${shown.map((index) => renderQuizItem(questions[index], index, practice)).join('')}</ol>
                        ${result.done ? `
                            <div class="flex flex-col gap-2 rounded-lg border border-border-light bg-surface/40 p-3 focus:outline-none" data-quiz-score tabindex="-1">
                                <p class="text-base font-semibold text-ink tabular-nums">Skor ${result.score}/${result.total}</p>
                                <p class="text-xs text-ink-muted">${result.missed.length ? `${result.missed.length} soal belum tepat.` : 'Semua jawaban benar.'}${flaggedCount ? ` ${flaggedCount} soal ditandai salah dan tidak dihitung.` : ''}</p>
                                ${result.missed.length ? `<p class="text-xs text-ink-subtle" data-budget-line>${app.escapeHtml(budgetLine())}</p>` : ''}
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="${app.ui.button.secondarySm}" data-quiz-retry="${message.id}">Ulangi</button>
                                    ${result.missed.length || reviewing ? `
                                        <button type="button" class="${app.ui.button.secondarySm}" data-quiz-review="${message.id}" aria-pressed="${reviewing ? 'true' : 'false'}">${reviewing ? 'Tampilkan semua soal' : 'Tinjau yang salah'}</button>
                                    ` : ''}
                                    ${result.missed.length ? practiceFromButton(message.id, result.missed) : ''}
                                    ${result.missed.length ? reviewImportButton(message.id, 'Simpan yang salah ke ulasan', true) : ''}
                                </div>
                            </div>
                        ` : ''}
                    </div>
                `;
            };

            const shuffled = (items) => {
                const copy = items.slice();

                for (let index = copy.length - 1; index > 0; index--) {
                    const swap = Math.floor(Math.random() * (index + 1));
                    [copy[index], copy[swap]] = [copy[swap], copy[index]];
                }

                return copy;
            };

            const flashcardPractice = (message) => {
                const count = Array.isArray(message.data) ? message.data.length : 0;

                return (state.practice[message.id] ??= {
                    mode: 'deck',
                    order: Array.from({ length: count }, (item, index) => index),
                    position: 0,
                    flipped: false,
                    known: [],
                    again: [],
                });
            };

            // Every card not marked "Sudah hafal" is still to learn, a skipped one ("Berikutnya") included.
            const flashToLearn = (deck, count) => Array.from({ length: count }, (item, index) => index).filter((index) => !deck.known.includes(index));

            const renderFlashcardGrid = (cards) => `<div class="grid grid-cols-1 gap-2">${cards.map((card) => `
                <button type="button" class="min-h-20 cursor-pointer rounded-lg border border-border bg-surface/40 p-3 text-start text-sm transition-colors duration-150 ease-[var(--ease-snappy)] hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-pressed:border-brand/40 aria-pressed:bg-surface-tint/40" data-flashcard aria-pressed="false">
                    <span class="block font-medium leading-snug text-ink" data-flashcard-front>${inline(card.front || '')}</span>
                    <span class="hidden text-ink-muted" data-flashcard-back>${inline(card.back || '')}</span>
                    <span class="mt-1 block text-xs text-ink-subtle" data-flashcard-hint>Ketuk untuk membalik</span>
                </button>
            `).join('')}</div>`;

            const renderFlashcardDeck = (message, cards, deck) => {
                if (deck.position >= deck.order.length) {
                    const toLearn = flashToLearn(deck, cards.length);

                    return `
                        <div class="flex flex-col gap-2 rounded-lg border border-border-light bg-surface/40 p-3 focus:outline-none" data-flash-summary tabindex="-1">
                            <p class="text-base font-semibold text-ink tabular-nums">Hafal ${deck.known.length}/${cards.length}</p>
                            <p class="text-xs text-ink-muted">${toLearn.length ? `${toLearn.length} kartu perlu diulang.` : 'Semua kartu sudah hafal.'}</p>
                            ${toLearn.length ? `<p class="text-xs text-ink-subtle" data-budget-line>${app.escapeHtml(budgetLine())}</p>` : ''}
                            <div class="flex flex-wrap gap-2">
                                ${toLearn.length ? `<button type="button" class="${app.ui.button.secondarySm}" data-flash-repeat="${message.id}">Ulangi yang belum hafal</button>` : ''}
                                <button type="button" class="${app.ui.button.secondarySm}" data-flash-restart="${message.id}">Mulai lagi</button>
                                ${toLearn.length ? practiceFromButton(message.id, toLearn) : ''}
                                ${toLearn.length ? reviewImportButton(message.id, 'Simpan yang belum hafal ke ulasan', true) : ''}
                            </div>
                        </div>
                    `;
                }

                const index = deck.order[deck.position];
                const card = cards[index] || {};

                return `
                    <div class="flex flex-col gap-2">
                        <p class="text-xs text-ink-subtle tabular-nums" data-flash-progress>Kartu ${deck.position + 1}/${deck.order.length}${deck.known.length ? ` · ${deck.known.length} hafal` : ''}</p>
                        <button type="button" class="flex min-h-36 cursor-pointer flex-col justify-center gap-2 rounded-xl border border-border bg-surface/40 p-4 text-start transition-colors duration-150 ease-[var(--ease-snappy)] hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-pressed:border-brand/40 aria-pressed:bg-surface-tint/40" data-flash-flip="${message.id}" aria-pressed="${deck.flipped ? 'true' : 'false'}" aria-label="${deck.flipped ? 'Jawaban kartu. Ketuk untuk melihat pertanyaan' : 'Pertanyaan kartu. Ketuk untuk membalik'}">
                            <span class="text-xs font-medium text-ink-subtle">${deck.flipped ? 'Jawaban' : 'Pertanyaan'}</span>
                            <span class="text-sm leading-relaxed ${deck.flipped ? 'text-ink-muted' : 'font-medium text-ink'}">${inline(deck.flipped ? card.back || '' : card.front || '')}</span>
                        </button>
                        ${deck.flipped && itemCitations(card) ? `<p class="text-xs text-ink-subtle">Rujukan ${itemCitations(card)}</p>` : ''}
                        ${deck.flipped ? `
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" class="${app.ui.button.secondarySm}" data-flash-again="${message.id}">Ulangi</button>
                                <button type="button" class="${app.ui.button.primarySm}" data-flash-known="${message.id}">Sudah hafal</button>
                            </div>
                        ` : ''}
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="${app.ui.button.ghostSm}" data-flash-prev="${message.id}" ${deck.position === 0 ? 'disabled aria-disabled="true"' : ''}>Sebelumnya</button>
                            <button type="button" class="${app.ui.button.ghostSm}" data-flash-next="${message.id}">Berikutnya</button>
                            <button type="button" class="${app.ui.button.ghostSm}" data-flash-shuffle="${message.id}">Acak</button>
                        </div>
                    </div>
                `;
            };

            // One card at a time by default; the grid of every card stays as the other view.
            const renderFlashcards = (message) => {
                const cards = Array.isArray(message.data) ? message.data : [];
                const deck = flashcardPractice(message);

                return `
                    <div class="flex flex-col gap-3" data-flashcards="${message.id}">
                        <div class="flex gap-1 rounded-lg border border-border-light bg-surface/40 p-1" role="group" aria-label="Tampilan flashcard">
                            ${[['deck', 'Satu kartu'], ['grid', 'Semua kartu']].map(([mode, label]) => `<button type="button" class="min-h-11 flex-1 cursor-pointer rounded-md px-2 text-[13px] font-medium text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-pressed:bg-panel aria-pressed:text-ink aria-pressed:shadow-xs sm:min-h-8 dark:aria-pressed:shadow-none" data-flash-mode="${mode}" aria-pressed="${deck.mode === mode ? 'true' : 'false'}">${label}</button>`).join('')}
                        </div>
                        ${deck.mode === 'grid' ? renderFlashcardGrid(cards) : renderFlashcardDeck(message, cards, deck)}
                    </div>
                `;
            };

            // Redraw one output's practice in place (its Catatan entry stays open), then move focus to `focusSelector`.
            const renderPractice = (messageId, focusSelector = null) => {
                const message = state.messages.find((item) => item.id === messageId);
                const host = notesHost.querySelector(`[data-practice-host="${messageId}"]`);

                if (!message || !host) {
                    return;
                }

                host.innerHTML = renderNoteBody(message);

                const focusTarget = focusSelector ? host.querySelector(focusSelector) : null;

                focusTarget?.focus();
            };

            // The attempt is kept on the output (context.practice) without AI or budget; a quiz sends its choices
            // and the server counts the score. A failed save does not interrupt the practice. The save is kept in
            // state.practiceSaves (resolving to whether it worked) so Simpan ke ulasan waits for it.
            const savePractice = (message) => {
                const questions = Array.isArray(message.data) ? message.data : [];
                const practice = state.practice[message.id];
                const body = message.kind === 'quiz'
                    ? { choices: questions.map((question, index) => (Number.isInteger(practice.choices[index]) ? practice.choices[index] : null)), flagged: practice.flagged }
                    : { score: practice.known.length, total: questions.length, missed: flashToLearn(practice, questions.length) };
                const previous = state.practiceSaves[message.id];
                const saving = (async () => {
                    // Attempts are saved in the order they were made.
                    await previous;

                    try {
                        const payload = await app.request(`${base}/messages/${message.id}`, { method: 'PATCH', json: { practice: body } });

                        message.context = payload.data.message.context;

                        return true;
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Hasil latihan belum tersimpan.'), 'warning');

                        return false;
                    }
                })();

                state.practiceSaves[message.id] = saving;

                return saving;
            };

            const renderGlossary = (message) => {
                const terms = Array.isArray(message.data) ? message.data : [];

                return `<dl class="flex flex-col gap-2 text-sm">${terms.map((term) => `
                    <div><dt class="font-medium text-ink">${inline(term.term || '')}</dt><dd class="text-ink-muted">${inline(term.definition || '')}${(term.citations || []).map((n) => inline(`[${Number(n)}]`)).join('')}</dd></div>
                `).join('')}</dl>`;
            };

            // A mind map node by its path of child indexes ('' is the root, '0.2' the root's first child's third).
            const mindMapNodeAt = (root, path) => {
                let node = root && typeof root === 'object' ? root : null;
                let parent = null;

                for (const index of String(path || '').split('.').filter((part) => part !== '')) {
                    const children = Array.isArray(node?.children) ? node.children : [];

                    parent = node;
                    node = children[Number(index)] && typeof children[Number(index)] === 'object' ? children[Number(index)] : null;
                }

                return node ? { title: String(node.title || '').trim(), parent: parent ? String(parent.title || '').trim() : null } : null;
            };

            // Each title is a button that opens the node's actions (toggleMindMapActions); the branches stay nested <details>.
            const renderMindMapNode = (node, depth = 0, path = '') => {
                const children = Array.isArray(node?.children) ? node.children : [];
                const title = inline(node?.title || '');

                return `
                    <li class="flex flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-x-1" data-mindmap-row>
                            <button type="button" class="-mx-1.5 min-h-11 cursor-pointer rounded-md px-1.5 text-start text-sm transition-colors hover:bg-surface/60 hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-expanded:bg-surface-tint/40 aria-expanded:text-ink sm:min-h-8 ${depth < 1 || children.length ? 'font-medium text-ink' : 'text-ink-muted'}" data-mindmap-node="${path}" aria-expanded="false">${title}</button>
                            ${itemCitations(node)}
                        </div>
                        ${children.length ? `
                            <details ${depth < 1 ? 'open' : ''}>
                                <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-md text-xs text-ink-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:min-h-7 [&::-webkit-details-marker]:hidden">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 shrink-0 -rotate-90 transition-transform [details[open]>summary>&]:rotate-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                                    ${children.length} sub-topik<span class="sr-only"> ${app.escapeHtml(String(node?.title || ''))}</span>
                                </summary>
                                <ul class="ms-2 mt-1 flex flex-col gap-1 border-s border-border ps-3">${children.map((child, index) => renderMindMapNode(child, depth + 1, path === '' ? String(index) : `${path}.${index}`)).join('')}</ul>
                            </details>
                        ` : ''}
                    </li>
                `;
            };

            // A node's actions use the sources the map was made from (those still ready), else the ticked ones.
            const mindMapSourceIds = (message) => {
                const ids = (Array.isArray(message?.context?.source_ids) ? message.context.source_ids : [])
                    .map(Number)
                    .filter((id) => state.sources.some((source) => source.id === id && source.status === 'ready'));

                return ids.length ? ids : null;
            };

            const mindMapBudgetLine = () => (state.aiBudget
                ? (state.aiBudget.remaining > 0
                    ? `Sisa kuota AI hari ini: ${state.aiBudget.remaining} dari ${state.aiBudget.limit}. Tiap pilihan memakai 1 permintaan.`
                    : 'Kuota AI hari ini sudah habis. Coba lagi besok.')
                : 'Tiap pilihan memakai 1 permintaan dari kuota AI harianmu.');

            const mindMapActionsBody = (node, id) => {
                const unavailable = !state.aiAvailable || state.aiBudget?.remaining === 0;
                const action = (name, label, hint) => `
                    <button type="button" class="${app.ui.button.secondarySm} justify-start! text-start" data-mindmap-action="${name}" ${unavailable ? 'disabled aria-disabled="true"' : ''}>
                        <span class="flex flex-col"><span>${label}</span><span class="text-xs font-normal text-ink-subtle">${hint}</span></span>
                    </button>
                `;

                return `
                    <div class="my-1 flex flex-col gap-2 rounded-lg border border-border bg-panel p-3 shadow-xs dark:shadow-none" id="${id}" role="group" aria-label="Pilihan untuk ${app.escapeHtml(node.title)}" data-mindmap-actions>
                        ${action('explain', 'Jelaskan', 'Jawaban bersumber di Chat')}
                        ${action('practice', 'Latihan', 'Kuis singkat tentang simpul ini')}
                        ${action('feedback', 'Jelaskan dengan kata-katamu', 'Tulis sendiri, lalu cek pemahamanmu')}
                        <p class="text-xs text-ink-subtle">${app.escapeHtml(mindMapBudgetLine())}</p>
                    </div>
                `;
            };

            // One node's actions open under its title at a time; the same title closes them again.
            const toggleMindMapActions = (button) => {
                const holder = button.closest('[data-message-id]');
                const message = state.messages.find((item) => item.id === Number(holder?.dataset.messageId));
                const node = mindMapNodeAt(message?.data, button.dataset.mindmapNode);
                const wasOpen = button.getAttribute('aria-expanded') === 'true';

                holder?.querySelectorAll('[data-mindmap-actions]').forEach((panel) => panel.remove());
                holder?.querySelectorAll('[data-mindmap-node][aria-expanded="true"]').forEach((other) => {
                    other.setAttribute('aria-expanded', 'false');
                    other.removeAttribute('aria-controls');
                });

                if (wasOpen || !node || !node.title) {
                    return;
                }

                const id = `mindmap-${message.id}-${button.dataset.mindmapNode.replaceAll('.', '-') || 'root'}`;

                button.closest('[data-mindmap-row]').insertAdjacentHTML('afterend', mindMapActionsBody(node, id));
                button.setAttribute('aria-expanded', 'true');
                button.setAttribute('aria-controls', id);
            };

            // Jelaskan asks in Chat; Latihan opens the quiz options with the node as its focus; the third opens
            // "Cek pemahamanku" (explain_concept). Each spends one daily request when it runs.
            const runMindMapAction = async (actionButton) => {
                const holder = actionButton.closest('[data-message-id]');
                const message = state.messages.find((item) => item.id === Number(holder?.dataset.messageId));
                const nodeButton = holder?.querySelector('[data-mindmap-node][aria-expanded="true"]');
                const node = mindMapNodeAt(message?.data, nodeButton?.dataset.mindmapNode);

                if (!node || !node.title) {
                    return;
                }

                const sourceIds = mindMapSourceIds(message);
                const inContext = node.parent ? ` dalam konteks "${node.parent}"` : '';

                switch (actionButton.dataset.mindmapAction) {
                    case 'explain':
                        setTab('chat');
                        await ask(`Jelaskan "${node.title}"${inContext} berdasarkan sumber.`, { sourceIds });
                        break;
                    case 'practice':
                        openStudioOptions(actionButton, 'quiz', { focus: `${node.title}${node.parent ? ` (dalam konteks ${node.parent})` : ''}`.slice(0, 1500), sourceIds });
                        break;
                    case 'feedback':
                        openFeedback(`${node.title}${node.parent ? ` (dalam konteks ${node.parent})` : ''}`, 'explain_concept', { sourceIds });
                        break;
                }
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
                        return `<ul class="flex flex-col gap-1" data-mindmap="${message.id}">${renderMindMapNode(message.data || {})}</ul>`;
                    case 'feedback':
                        return renderFeedback(message);
                    case 'note':
                        return renderNoteEditor(message);
                    case 'study_guide':
                        return renderMarkdown(message.content, { listItemAction: practiceQuestionAction });
                    default:
                        return renderMarkdown(message.content);
                }
            };

            const noteLabel = (message) => {
                if (message.kind === 'chat') {
                    return 'Jawaban tersimpan';
                }

                if (message.kind === 'note') {
                    return noteTitle(message);
                }

                return message.kind === 'feedback' ? 'Cek pemahaman' : (state.studioKinds[message.kind] || message.kind);
            };

            // "Cek kerangkaku": the student's own outline for this brief gets feedback (openFeedback, outline_check).
            const outlineCheckButton = (message) => `<button type="button" class="${app.ui.button.secondarySm}" data-feedback-prompt="${app.escapeHtml(String(message.context?.focus || 'Kerangka tugas dari Catatan').slice(0, 1500))}" data-feedback-mode="outline_check">Cek kerangkaku</button>`;

            // The student's own note: its first line without Markdown marks, else "Catatan baru".
            const noteTitle = (message) => {
                const line = String(message?.content || '').split(/\n/)
                    .map((text) => text.replace(/^\s{0,3}(?:#{1,6}\s+|[-*+]\s+|\d+[.)]\s+|>\s*)/, '').replace(/[*_`]+/g, '').trim())
                    .find((text) => text !== '');

                return line ? (line.length > 80 ? `${line.slice(0, 79)}…` : line) : 'Catatan baru';
            };

            // The source a note became, while it is still in the notebook.
            const noteSource = (message) => {
                const id = Number(message?.context?.note_source?.id);

                return id ? state.sources.find((source) => source.id === id) || null : null;
            };

            // A textarea (saved when it loses focus, saveNote) above a live preview through the same safe Markdown as answers.
            const renderNoteEditor = (message) => `
                <div class="flex flex-col gap-2" data-note="${message.id}">
                    <label class="sr-only" for="note-editor-${message.id}">Isi catatan</label>
                    <textarea class="${app.ui.textarea} min-h-32 resize-y" id="note-editor-${message.id}" data-note-editor="${message.id}" maxlength="${NOTE_MAX_CHARS}" placeholder="Tulis dengan kata-katamu sendiri. Markdown didukung: # judul, - daftar, **tebal**.">${app.escapeHtml(message.content || '')}</textarea>
                    <p class="text-xs text-ink-subtle" data-note-status="${message.id}" aria-live="polite">${state.noteSaved[message.id] === (message.content || '') ? 'Tersimpan' : 'Belum tersimpan'}</p>
                    <div class="rounded-lg border border-border-light bg-surface/40 p-3" aria-label="Pratinjau catatan">
                        <p class="mb-1 text-xs font-medium text-ink-subtle">Pratinjau</p>
                        <div class="text-sm" data-note-preview="${message.id}">${String(message.content || '').trim() ? renderMarkdown(message.content) : '<p class="text-ink-subtle">Catatan masih kosong.</p>'}</div>
                    </div>
                </div>
            `;

            const noteActions = (message) => {
                const source = noteSource(message);
                const full = state.sourceLimit !== null && state.sources.length >= state.sourceLimit;

                return `
                    <button type="button" class="${app.ui.button.secondarySm}" data-note-copy="${message.id}">Salin</button>
                    <button type="button" class="${app.ui.button.secondarySm}" data-note-download="${message.id}">Unduh .md</button>
                    ${source
                        ? `<span class="inline-flex min-h-11 items-center text-xs text-ink-subtle sm:min-h-8">Sudah jadi sumber: ${app.escapeHtml(source.title)}</span>`
                        : `<button type="button" class="${app.ui.button.secondarySm}" data-note-source="${message.id}" ${full ? 'disabled aria-disabled="true" title="Notebook ini sudah mencapai batas jumlah sumber."' : ''}>Jadikan sumber</button>`}
                `;
            };

            // Typing updates the note in state (so a redraw keeps it) and its preview; nothing is sent yet.
            const editNote = (textarea) => {
                const messageId = Number(textarea.dataset.noteEditor);
                const message = state.messages.find((item) => item.id === messageId);

                if (!message) {
                    return;
                }

                message.content = textarea.value;
                state.noteDrafts[messageId] = textarea.value;

                const holder = textarea.closest('[data-note]');
                const preview = holder?.querySelector('[data-note-preview]');
                const status = holder?.querySelector('[data-note-status]');

                if (preview) {
                    preview.innerHTML = message.content.trim() ? renderMarkdown(message.content) : '<p class="text-ink-subtle">Catatan masih kosong.</p>';
                }

                if (status) {
                    status.textContent = state.noteSaved[messageId] === message.content ? 'Tersimpan' : 'Belum tersimpan';
                }
            };

            // The note as the page has it now: state.messages is replaced by every load, so it is looked up each time.
            const currentNote = (messageId) => state.messages.find((item) => item.id === messageId && item.kind === 'note');

            // One PATCH when the text differs from what the server has; a failed save says so and is tried on the next blur.
            // `keepalive` (leaving the page) sends at once, without waiting for an earlier save, so the browser still delivers it.
            const saveNote = async (messageId, { keepalive = false } = {}) => {
                // A save still on its way finishes first (leaving the editor for "Jadikan sumber" saves, then converts).
                if (!keepalive) {
                    await state.noteSaving[messageId];
                }

                const message = currentNote(messageId);
                const content = String(message?.content ?? '');

                if (!message || state.noteSaved[messageId] === content) {
                    return true;
                }

                const previous = state.noteSaved[messageId];
                // Looked up each time: Catatan may be redrawn while the save runs.
                const setStatus = (text) => {
                    const status = notesHost.querySelector(`[data-note-status="${messageId}"]`);

                    if (status) {
                        status.textContent = text;
                    }
                };

                state.noteSaved[messageId] = content;
                setStatus('Menyimpan…');

                const request = app.request(`${base}/messages/${messageId}`, { method: 'PATCH', json: { content }, ...(keepalive ? { keepalive: true } : {}) });

                state.noteSaving[messageId] = request.catch(() => null);

                try {
                    await request;

                    // A load while the save ran took the server's older copy; the server has this text now.
                    state.noteSaved[messageId] = content;

                    if (state.noteDrafts[messageId] === content) {
                        delete state.noteDrafts[messageId];
                    }

                    setStatus(String(currentNote(messageId)?.content ?? '') === content ? 'Tersimpan' : 'Belum tersimpan');

                    const summary = notesHost.querySelector(`[data-message-id="${messageId}"] [data-note-title]`);

                    if (summary) {
                        summary.textContent = noteTitle(currentNote(messageId) || message);
                    }

                    return true;
                } catch (error) {
                    if (state.noteSaved[messageId] === content) {
                        state.noteSaved[messageId] = previous;
                    }

                    setStatus('Belum tersimpan');
                    app.showNotice(app.describeError(error, 'Catatan belum tersimpan.'), 'warning');

                    return false;
                }
            };

            // Every note with text the server does not have yet (leaving the page, or a pause while typing).
            const unsavedNoteIds = () => state.messages
                .filter((message) => message.kind === 'note' && String(message.content ?? '') !== (state.noteSaved[message.id] ?? ''))
                .map((message) => message.id);

            const flushNotes = ({ keepalive = false } = {}) => Promise.all(unsavedNoteIds().map((messageId) => saveNote(messageId, { keepalive })));

            // "+ Catatan baru" (empty) or "Salin ke catatan" from an answer (`fromMessageId`): no AI, no consent.
            const createNote = async ({ fromMessageId = null } = {}) => {
                try {
                    const payload = await app.request(`${base}/notes`, { method: 'POST', json: fromMessageId ? { from_message_id: fromMessageId } : { content: '' } });
                    const note = payload.data.message;

                    state.messages.push(note);
                    state.noteSaved[note.id] = note.content || '';
                    state.openNoteIds.add(note.id);
                    renderNotes();

                    if (fromMessageId) {
                        app.showNotice('Disalin ke Catatan.', 'success');
                    } else {
                        notesHost.querySelector(`[data-note-editor="${note.id}"]`)?.focus();
                    }

                    return note;
                } catch (error) {
                    app.showNotice(app.describeError(error, 'Catatan gagal dibuat.'), 'error');

                    return null;
                }
            };

            // Unduh .md: the note as a Markdown file, made in the browser.
            const downloadNote = (message) => {
                const name = noteTitle(message).replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '').toLowerCase().slice(0, 60) || 'catatan';
                const url = URL.createObjectURL(new Blob([String(message.content || '')], { type: 'text/markdown;charset=utf-8' }));
                const link = document.createElement('a');

                link.href = url;
                link.download = `${name}.md`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            };

            // Jadikan sumber: saved first, then a pasted-text source (the source limit and the reading budget apply as for pasted text).
            const noteToSource = async (button) => {
                const messageId = Number(button.dataset.noteSource);
                const message = state.messages.find((item) => item.id === messageId);

                if (!message || !(await saveNote(messageId))) {
                    return;
                }

                if (!String(message.content || '').trim()) {
                    app.showNotice('Catatan ini masih kosong.', 'warning');

                    return;
                }

                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`${base}/notes/${messageId}/source`, { method: 'POST' });

                        Object.assign(message, payload.data.message);
                        state.sources = [...state.sources.filter((source) => source.id !== payload.data.source.id), payload.data.source];
                        renderHeader();
                        renderSources();
                        renderNotes();
                        renderStudioButtons();
                        app.showNotice(payload.message || 'Catatan menjadi sumber.', 'success');
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Catatan gagal dijadikan sumber.'), 'error');
                    }
                });
            };

            // Studio outputs live only here, so they are listed even if unpinned elsewhere.
            const renderNotes = () => {
                const notes = state.messages.filter((message) => message.pinned || message.kind !== 'chat').slice().reverse();

                if (!notes.length) {
                    notesHost.innerHTML = '<p class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-ink-subtle">Catatanmu sendiri, hasil studio, dan jawaban yang kamu simpan muncul di sini.</p>';

                    return;
                }

                notesHost.innerHTML = notes.map((message) => `
                    <details class="group border-t border-border-light first:border-t-0" data-message-id="${message.id}" ${state.openNoteIds.has(message.id) ? 'open' : ''}>
                        <summary class="-mx-2 flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-lg px-2 py-2.5 transition-colors hover:bg-surface/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0">
                                <span class="block break-words text-sm font-medium leading-snug text-ink" ${message.kind === 'note' ? 'data-note-title' : ''}>${app.escapeHtml(noteLabel(message))}</span>
                                <span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml([message.kind === 'note' ? 'Catatan saya' : null, app.formatTimeAgo(message.created_at), message.context?.practice ? `Skor terakhir ${Number(message.context.practice.score)}/${Number(message.context.practice.total)}` : null, message.context?.focus ? message.context.focus : null].filter(Boolean).join(' · '))}</span>
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0 text-ink-subtle transition-transform group-open:rotate-180" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                        </summary>
                        <div class="flex flex-col gap-3 pb-3 pt-1">
                            <div data-practice-host="${message.id}">${renderNoteBody(message)}</div>
                            ${citationPanel(message)}
                            ${contextLine(message)}
                            <div class="flex flex-wrap gap-2">
                                ${message.kind === 'assignment_plan' ? outlineCheckButton(message) : ''}
                                ${message.kind === 'note' ? noteActions(message) : ''}
                                ${message.kind === 'flashcards' && Array.isArray(message.data) && message.data.length ? reviewImportButton(message.id, 'Simpan ke ulasan') : ''}
                                ${message.kind === 'chat'
                                    ? `<button type="button" class="${app.ui.button.ghostSm}" data-pin="${message.id}" aria-pressed="true">Lepas dari Catatan</button>`
                                    : `<button type="button" class="${app.ui.button.ghostSm} text-danger! hover:bg-danger-soft!" data-delete-message="${message.id}" ${message.kind === 'note' ? 'data-delete-note' : ''}>Hapus</button>`}
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

