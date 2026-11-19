{{--
    Notebook script, adding sources, showing a citation, and asking a
    question. Part of the DOMContentLoaded handler opened in
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
                panel.innerHTML = `
                    <p class="mb-1 font-medium text-ink">[${citation.n}] ${app.escapeHtml(citation.source_title)}${citation.page ? ` · hlm. ${citation.page}` : ''}</p>
                    <p class="whitespace-pre-line">${app.escapeHtml(citation.excerpt)}</p>
                `;
            };

            const ask = async (question) => {
                if (state.busy || question.trim() === '') {
                    return;
                }

                if (noContextChosen()) {
                    app.showNotice('Centang minimal satu sumber dulu, atau ketuk "Semua".', 'warning');

                    return;
                }

                state.busy = true;
                const submit = askForm.querySelector('button[type="submit"]');
                const pending = document.createElement('li');
                pending.className = 'flex flex-col gap-2';
                pending.innerHTML = `
                    <div class="ms-auto max-w-[85%] rounded-[var(--radius-card)] rounded-tr-xs bg-brand px-3 py-2 text-sm text-white">${app.escapeHtml(question)}</div>
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

