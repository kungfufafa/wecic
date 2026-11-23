{{--
    Notebook script, the chat: the context line under an answer, the
    assistant messages, and renderMessages(). Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
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
                    <details class="text-xs text-ink-subtle">
                        <summary class="cursor-pointer hover:text-ink-muted">Konteks: ${app.escapeHtml(parts)}${context.focus ? ' · fokus tugas' : ''}</summary>
                        <div class="mt-1 rounded-lg border border-border-light bg-surface/40 p-2.5 leading-relaxed text-ink-muted">${context.focus ? `<p class="mb-1"><span class="font-medium text-ink">Fokus:</span> ${app.escapeHtml(context.focus)}</p>` : ''}${titles || 'Tidak ada sumber.'}</div>
                    </details>
                `;
            };

            // Answers from before modes were direct answers.
            const askModeBadge = (message) => {
                const mode = Object.hasOwn(ASK_MODES, message.context?.mode) ? message.context.mode : 'answer';

                return `<span class="${mode === 'tutor' ? app.ui.badge.brand : app.ui.badge.neutral}">${app.escapeHtml(ASK_MODES[mode])}</span>`;
            };

            const renderAssistant = (message) => `
                <li class="flex flex-col gap-2" data-message-id="${message.id}">
                    <div class="rounded-xl rounded-tl-xs border border-border bg-panel p-3 sm:p-4">${renderMarkdown(message.content)}</div>
                    ${citationPanel(message)}
                    <div class="flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                        ${askModeBadge(message)}
                        ${message.citations.length ? `<span class="tabular-nums">${message.citations.length} rujukan</span>` : '<span>Tanpa rujukan</span>'}
                        <button type="button" class="${app.ui.button.ghostSm}" data-pin="${message.id}" aria-pressed="${message.pinned ? 'true' : 'false'}">${message.pinned ? 'Tersimpan di Catatan' : 'Simpan ke Catatan'}</button>
                    </div>
                    ${contextLine(message)}
                </li>
            `;

            const renderMessages = () => {
                const chat = state.messages.filter((message) => message.kind === 'chat' && (state.chatFromId === null || message.id >= state.chatFromId));

                if (!chat.length) {
                    messagesHost.innerHTML = `
                        <li class="flex flex-col gap-3">
                            <p class="text-sm text-ink-muted">Belum ada percakapan. Coba mulai dengan:</p>
                            <div class="flex flex-wrap gap-2">${(state.askMode === 'tutor' ? TUTOR_SUGGESTIONS : SUGGESTIONS).map((text) => `<button type="button" class="${app.ui.button.secondarySm} whitespace-normal! py-1.5 text-start" data-suggestion="${app.escapeHtml(text)}">${app.escapeHtml(text)}</button>`).join('')}</div>
                        </li>
                    `;

                    return;
                }

                messagesHost.innerHTML = chat.map((message) => (message.role === 'user'
                    ? `<li class="ms-auto max-w-[85%] rounded-xl rounded-tr-xs bg-surface px-3 py-2 text-sm text-ink" data-message-id="${message.id}">${app.escapeHtml(message.content)}</li>`
                    : renderAssistant(message))).join('');
                messagesHost.scrollTop = messagesHost.scrollHeight;
            };

