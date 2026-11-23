{{--
    Notebook script, adding sources, showing a citation (with "Buka di
    sumber", see source-viewer), and asking a question. Part of the DOMContentLoaded handler opened in
    notebook/show.blade.php: it shares that scope (app, el, base, the
    elements, constants and state in setup).
--}}
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
                // The source may have been removed since; then there is nothing to open.
                const canOpen = state.sources.some((source) => source.id === Number(citation.source_id));
                panel.innerHTML = `
                    <p class="mb-1 font-medium text-ink">[${citation.n}] ${app.escapeHtml(citation.source_title)}${citation.page ? ` · hlm. ${citation.page}` : ''}</p>
                    <p class="whitespace-pre-line">${app.escapeHtml(citation.excerpt)}</p>
                    ${canOpen ? `<button type="button" class="${app.ui.button.ghostSm} mt-1 -ml-3 text-brand-text!" data-open-source="${Number(citation.source_id)}" data-open-position="${Number.isInteger(citation.position) ? citation.position : ''}" data-open-message="${messageId}" data-open-n="${Number(citation.n)}">Buka di sumber</button>` : ''}
                `;
            };

            // `sourceIds` asks about those sources only (a question from a source's guide), whatever is ticked.
            const ask = async (question, { sourceIds = null } = {}) => {
                if (state.busy || question.trim() === '' || needsAiConsent()) {
                    return;
                }

                if (!sourceIds && noContextChosen()) {
                    app.showNotice('Centang minimal satu sumber dulu, atau ketuk "Semua".', 'warning');

                    return;
                }

                state.busy = true;
                const submit = askForm.querySelector('button[type="submit"]');
                const pending = document.createElement('li');
                pending.className = 'flex flex-col gap-2';
                pending.innerHTML = `
                    <div class="ms-auto max-w-[85%] rounded-xl rounded-tr-xs bg-surface px-3 py-2 text-sm text-ink">${app.escapeHtml(question)}</div>
                    <div class="flex items-center gap-2 text-sm text-ink-subtle"><span class="${app.ui.spinner}" aria-hidden="true"></span> Membaca ${sourceIds ? sourceIds.length : contextSources().length} sumber…</div>
                `;

                if (!state.messages.some((message) => message.kind === 'chat')) {
                    messagesHost.innerHTML = '';
                }

                messagesHost.appendChild(pending);
                messagesHost.scrollTop = messagesHost.scrollHeight;
                questionInput.value = '';

                await app.withSubmitLoading(submit, async () => {
                    try {
                        const payload = await app.request(`${base}/messages`, { method: 'POST', json: { question, source_ids: sourceIds || contextSourceIds(), mode: state.askMode }, timeout: 120000 });

                        state.messages.push(payload.data.question, payload.data.answer);
                        setAiBudget(payload.data);
                        renderMessages();
                        // The list is redrawn whole, so only the new answer is read out.
                        app.announce(`Jawaban: ${plainText(payload.data.answer.content)}`);
                    } catch (error) {
                        pending.remove();
                        questionInput.value = question;
                        renderMessages();
                        noteAiStatus(error);
                        app.showNotice(describeAiError(error, 'Pertanyaan belum bisa dijawab.'), 'error');
                    }
                });

                state.busy = false;
            };

