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
                const chat = state.messages.filter((message) => message.kind === 'chat' && (state.chatFromId === null || message.id >= state.chatFromId));

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

