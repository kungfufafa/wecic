{{--
    Notebook script, "Cek pemahamanku": the sheet where the student writes
    their own answer, explanation or outline (openFeedback), and the
    feedback it gets back (renderFeedback), in the sheet and in Catatan.
    Feedback names what is right, what is missing and what is mistaken,
    with citations, and never a corrected answer. Entry points: "Jawab" on
    a study guide's practice questions, "Cek kerangkaku" on an assignment
    outline, "Jawab lagi" on a follow-up question; later ones call
    openFeedback(prompt, mode). Part of the DOMContentLoaded handler opened
    in notebook/show.blade.php: it shares that scope (app, el, base, the
    elements, constants and state in setup).
--}}
            const FEEDBACK_MODES = @json(\App\Services\NotebookAssistant::FEEDBACK_MODES);
            const FEEDBACK_VERDICTS = @json(\App\Services\NotebookAssistant::FEEDBACK_VERDICTS);
            const FEEDBACK_VERDICT_TONES = { tepat: 'success', sebagian: 'warning', belum_tepat: 'danger' };
            const FEEDBACK_ANSWER_MIN = 20;
            const feedbackExhausted = () => state.aiBudget?.remaining === 0;

            // The feedback check's own budget line (budgetLine() speaks of practice sets).
            const feedbackBudgetLine = () => {
                if (!state.aiBudget) {
                    return 'Cek pemahaman memakai 1 permintaan dari kuota AI harianmu.';
                }

                return feedbackExhausted()
                    ? 'Kuota AI hari ini sudah habis. Cek pemahaman bisa dipakai lagi besok.'
                    : `Sisa kuota AI hari ini: ${state.aiBudget.remaining} dari ${state.aiBudget.limit}. Cek pemahaman memakai 1.`;
            };
            const FEEDBACK_ANSWER_MAX = 6000;
            const FEEDBACK_PROMPT_MAX = 1500;

            // A study guide's "Pertanyaan latihan" items get "Jawab" (renderMarkdown's listItemAction).
            const practiceQuestionAction = (itemText, heading) => {
                const question = plainText(itemText);

                if (!/pertanyaan latihan/i.test(heading || '') || question.length < 2) {
                    return '';
                }

                return ` <button type="button" class="${app.ui.button.ghostSm} -my-1 text-brand-text!" data-feedback-prompt="${app.escapeHtml(question.slice(0, FEEDBACK_PROMPT_MAX))}" data-feedback-mode="practice_answer">Jawab</button>`;
            };

            const feedbackPoints = (title, points, render) => (points.length ? `
                <div class="flex flex-col gap-1">
                    <p class="text-xs font-medium text-ink">${title}</p>
                    <ul class="flex list-disc flex-col gap-1 ps-5 text-sm leading-relaxed text-ink-muted">${points.map((point) => `<li>${render(point)}${itemCitations(point)}</li>`).join('')}</ul>
                </div>
            ` : '');

            // The verdict in words (not only by colour), then what is right, missing and mistaken, each with its citations.
            const renderFeedback = (message) => {
                const data = message.data && typeof message.data === 'object' ? message.data : {};
                const list = (key) => (Array.isArray(data[key]) ? data[key] : []);
                const verdict = Object.hasOwn(FEEDBACK_VERDICTS, data.verdict) ? data.verdict : null;
                const mode = Object.hasOwn(FEEDBACK_MODES, message.context?.feedback_mode) ? message.context.feedback_mode : 'practice_answer';

                return `
                    <div class="flex flex-col gap-3" data-feedback="${message.id}">
                        ${message.context?.feedback_prompt ? `<p class="text-xs text-ink-subtle"><span class="font-medium text-ink-muted">${app.escapeHtml(FEEDBACK_MODES[mode])}:</span> ${app.escapeHtml(message.context.feedback_prompt)}</p>` : ''}
                        ${verdict ? `<p class="flex flex-wrap items-center gap-2 text-sm text-ink"><span class="${app.ui.badge[FEEDBACK_VERDICT_TONES[verdict]] || app.ui.badge.neutral}">${app.escapeHtml(FEEDBACK_VERDICTS[verdict])}</span></p>` : ''}
                        ${feedbackPoints('Yang sudah tepat', list('strengths'), (point) => inline(point.point || ''))}
                        ${feedbackPoints('Yang belum dibahas', list('gaps'), (point) => inline(point.point || ''))}
                        ${feedbackPoints('Yang perlu diluruskan', list('misconceptions'), (point) => `<span class="text-ink">${inline(point.claim || '')}</span>${point.correction ? ` → ${inline(point.correction)}` : ''}`)}
                        ${data.next_step ? `<p class="text-sm text-ink-muted"><span class="font-medium text-ink">Langkah berikutnya:</span> ${inline(data.next_step)}</p>` : ''}
                        ${data.follow_up_question ? `
                            <div class="flex flex-col gap-2 rounded-lg border border-border-light bg-surface/40 p-3">
                                <p class="text-sm text-ink"><span class="font-medium">Pertanyaan lanjutan:</span> ${inline(data.follow_up_question)}</p>
                                <div><button type="button" class="${app.ui.button.secondarySm}" data-feedback-prompt="${app.escapeHtml(plainText(data.follow_up_question).slice(0, FEEDBACK_PROMPT_MAX))}" data-feedback-mode="practice_answer">Jawab lagi</button></div>
                            </div>
                        ` : ''}
                    </div>
                `;
            };

            const feedbackSheetBody = (prompt, mode, sourceCount) => `
                <form class="flex flex-col gap-3" data-feedback-form novalidate>
                    <div class="rounded-lg border border-border-light bg-surface/40 p-3 text-sm text-ink">
                        <p class="text-xs font-medium text-ink-subtle">${app.escapeHtml(FEEDBACK_MODES[mode])}</p>
                        <p class="mt-1 whitespace-pre-line">${app.escapeHtml(prompt)}</p>
                    </div>
                    <p class="text-sm text-ink-muted">Kami memberi umpan balik, bukan menuliskan tugasmu. Tulis dengan kata-katamu sendiri; jawabanmu dinilai hanya dari sumber yang dicentang.</p>
                    <label class="${app.ui.field}">
                        <span class="${app.ui.label}">${mode === 'outline_check' ? 'Kerangkamu' : 'Jawabanmu'}</span>
                        <textarea class="${app.ui.textarea} min-h-40!" name="answer" minlength="${FEEDBACK_ANSWER_MIN}" maxlength="${FEEDBACK_ANSWER_MAX}" required placeholder="${mode === 'outline_check' ? 'Tempel atau tulis kerangkamu: bagian-bagian dan poin yang akan dibahas.' : 'Tulis jawabanmu sendiri, minimal 20 karakter.'}"></textarea>
                    </label>
                    <p class="text-xs text-ink-subtle">Konteks terukur: ${sourceCount} sumber. ${app.escapeHtml(feedbackBudgetLine())}</p>
                    <button type="submit" class="${app.ui.button.primary}" ${feedbackExhausted() ? 'disabled aria-disabled="true"' : ''}>Cek pemahamanku</button>
                </form>
            `;

            const showFeedbackResult = (panel, message) => {
                const host = panel.querySelector('[data-app-sheet-body]') || panel;

                host.innerHTML = `
                    <div class="flex flex-col gap-3" data-message-id="${message.id}">
                        ${renderFeedback(message)}
                        ${citationPanel(message)}
                        <p class="text-xs text-ink-subtle">Tersimpan di Catatan.</p>
                    </div>
                `;
            };

            // Reusable entry point: `prompt` is the question, concept or brief; `mode` one of FEEDBACK_MODES;
            // `sourceIds` narrows the context (the ticked sources otherwise).
            const openFeedback = (prompt, mode = 'practice_answer', { sourceIds = null } = {}) => {
                const text = String(prompt || '').trim().slice(0, FEEDBACK_PROMPT_MAX);
                const feedbackMode = Object.hasOwn(FEEDBACK_MODES, mode) ? mode : 'practice_answer';

                if (text.length < 2 || needsAiConsent()) {
                    return null;
                }

                if (!state.aiAvailable) {
                    app.showNotice('Asisten AI belum aktif.', 'warning');

                    return null;
                }

                if (!sourceIds && noContextChosen()) {
                    app.showNotice('Centang minimal satu sumber dulu, atau ketuk "Semua".', 'warning');

                    return null;
                }

                const panel = app.openSheet({
                    title: 'Cek pemahamanku',
                    pretitle: FEEDBACK_MODES[feedbackMode],
                    body: feedbackSheetBody(text, feedbackMode, sourceIds ? sourceIds.length : contextSources().length),
                });
                const form = panel.querySelector('[data-feedback-form]');

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const answer = form.elements.answer.value.trim();

                    if (answer.length < FEEDBACK_ANSWER_MIN) {
                        app.showNotice('Jawabanmu terlalu pendek untuk dinilai. Tulis minimal 20 karakter.', 'warning');
                        form.elements.answer.focus();

                        return;
                    }

                    await app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                        try {
                            const payload = await app.request(`${base}/feedback`, {
                                method: 'POST',
                                json: { prompt: text, answer, mode: feedbackMode, source_ids: sourceIds || contextSourceIds() },
                                timeout: 180000,
                            });
                            const message = payload.data.message;

                            state.messages.push(message);
                            setAiBudget(payload.data);
                            renderNotes();

                            // The shared sheet was closed or opened for something else meanwhile: leave it as it is.
                            if (!form.isConnected) {
                                app.showNotice('Umpan balik tersimpan di Catatan.', 'success');

                                return;
                            }

                            showFeedbackResult(panel, message);
                            app.announce(`Umpan balik: ${FEEDBACK_VERDICTS[message.data?.verdict] || ''}`);
                        } catch (error) {
                            noteAiStatus(error);
                            app.showNotice(describeAiError(error, 'Umpan balik belum bisa dibuat.'), 'error');
                        }
                    });
                });

                return panel;
            };

