{{--
    Notebook script, the source list, grouped by meeting, with its context
    ticks and the context bar. Part of the DOMContentLoaded handler opened
    in notebook/show.blade.php: it shares that scope (app, el, base, the
    elements, constants and state in setup).
--}}
            const renderContextBar = () => {
                const ready = readySources();
                const chosen = contextSources();
                const bar = el('notebook-context-bar');

                bar.classList.toggle('hidden', ready.length < 2);
                bar.classList.toggle('flex', ready.length >= 2);
                el('notebook-context-summary').textContent = `Konteks: ${chosen.length} dari ${ready.length} sumber siap`;
                el('notebook-ask-context').textContent = noContextChosen()
                    ? 'Centang minimal satu sumber untuk bertanya'
                    : (ready.length >= 2 && chosen.length < ready.length
                        ? `Hanya ${chosen.length} sumber yang dicentang dipakai`
                        : 'Enter kirim · Shift+Enter baris baru');

                const submit = askForm.querySelector('button[type="submit"]');

                if (!state.busy) {
                    submit.disabled = noContextChosen();
                }
            };

            // Topic badges and understanding questions from the source's guide; a question asks about this source only.
            const sourceGuide = (source) => {
                const topics = (Array.isArray(source.topics) ? source.topics : []).filter((topic) => typeof topic === 'string' && topic !== '');
                const questions = (Array.isArray(source.suggested_questions) ? source.suggested_questions : []).filter((text) => typeof text === 'string' && text !== '');
                const canAsk = source.status === 'ready';

                return `
                    ${topics.length ? `<ul class="mt-2 flex flex-wrap gap-1" aria-label="Topik sumber">${topics.map((topic) => `<li class="${app.ui.badge.neutral}">${app.escapeHtml(topic)}</li>`).join('')}</ul>` : ''}
                    ${questions.length && canAsk ? `<div class="mt-2 flex flex-col items-start gap-1"><p class="text-xs text-ink-subtle">Tanyakan tentang sumber ini:</p>${questions.map((text) => `<button type="button" class="${app.ui.button.secondarySm} whitespace-normal! py-1.5 text-start" data-suggestion="${app.escapeHtml(text)}" data-suggestion-source="${Number(source.id)}">${app.escapeHtml(text)}</button>`).join('')}</div>` : ''}
                `;
            };

            const sourceGroup = (source) => (source.meeting_number ? `Pertemuan ${source.meeting_number}` : (source.meeting_id ? 'Pertemuan' : 'Sumber lain'));

            const renderSources = () => {
                sourcesHost.removeAttribute('aria-busy');
                el('notebook-sources-count').textContent = `${state.sources.length}`;
                el('source-add-meeting').hidden = !isCourseNotebook();

                if (!state.sources.length) {
                    sourcesHost.innerHTML = `<li class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-ink-subtle">${isCourseNotebook()
                        ? 'Belum ada pertemuan. Buka di Kuliah atau klik Tambah pertemuan.'
                        : 'Belum ada sumber. Tambah dari arsip, berkas, atau teks.'}</li>`;
                    renderContextBar();

                    return;
                }

                let lastGroup = null;
                const hasMeetings = state.sources.some((source) => source.meeting_id);

                sourcesHost.innerHTML = state.sources.map((source) => {
                    const details = [SOURCE_KIND[source.kind] || source.kind, source.page_count ? `${source.page_count} hlm.` : '', source.truncated ? 'sebagian' : ''].filter(Boolean).join(' · ');
                    const group = sourceGroup(source);
                    const header = hasMeetings && group !== lastGroup ? `<li class="pb-1 pt-4 text-xs font-medium text-ink-subtle first:pt-0" data-source-group>${app.escapeHtml(group)}</li>` : '';
                    lastGroup = group;
                    const ready = source.status === 'ready';
                    const checked = ready && !state.excluded.has(source.id);
                    const status = source.stalled ? SOURCE_STATUS.stalled : (SOURCE_STATUS[source.status] || SOURCE_STATUS.pending);
                    const canRetry = (source.status === 'failed' || source.stalled) && source.kind !== 'text' && source.kind !== 'meeting';
                    // Waiting for tomorrow's reading budget: the student may spend their own reads on it now,
                    // unless it waits for the AI itself to work again.
                    const canReadNow = source.waiting && Boolean(source.error) && state.aiAvailable;
                    const stalledHint = canRetry
                        ? 'Pembacaannya terhenti. Ketuk Baca ulang untuk mencoba lagi.'
                        : 'Pembacaannya terhenti. Hapus sumber ini, lalu tambahkan lagi.';

                    return `${header}
                        <li class="border-t border-border-light py-3 first:border-t-0 first:pt-0 last:pb-0 [[data-source-group]+&]:border-t-0 [[data-source-group]+&]:pt-1 ${checked ? '' : 'opacity-75'}" data-source-id="${source.id}">
                            <div class="flex items-start justify-between gap-2">
                                <label class="flex min-w-0 cursor-pointer items-start gap-2">
                                    <input type="checkbox" class="mt-0.5 size-4 shrink-0 cursor-pointer rounded border-border accent-brand" data-source-select="${source.id}" ${checked ? 'checked' : ''} ${ready ? '' : 'disabled'} aria-label="Pakai ${app.escapeHtml(source.title)} sebagai konteks">
                                    <span class="min-w-0">
                                        <span class="block break-words text-sm font-medium leading-snug text-ink">${app.escapeHtml(source.title)}</span>
                                        <span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml(details)}</span>
                                    </span>
                                </label>
                                ${ready && !source.stalled ? '' : app.renderBadge(status.label, status.tone)}
                            </div>
                            ${source.error ? `<p class="mt-2 text-xs ${source.status === 'failed' ? 'text-danger' : 'text-ink-muted'}">${app.escapeHtml(source.error)}</p>` : ''}
                            ${source.stalled ? `<p class="mt-2 text-xs text-ink-muted">${stalledHint}</p>` : ''}
                            ${source.waiting && !source.error ? '<p class="mt-2 text-xs text-ink-muted">Menunggu giliran dibaca.</p>' : ''}
                            ${source.summary ? `<details class="mt-2"><summary class="cursor-pointer text-xs font-medium text-brand-text">Ringkasan sumber</summary><p class="mt-1 whitespace-pre-line text-xs leading-relaxed text-ink-muted">${app.escapeHtml(source.summary)}</p>${sourceGuide(source)}</details>` : ''}
                            <div class="mt-2 flex gap-1">
                                ${ready ? `<button type="button" class="${app.ui.button.ghostSm}" data-view-source="${source.id}" aria-label="Lihat isi ${app.escapeHtml(source.title)}">Lihat isi</button>` : ''}
                                ${canRetry || canReadNow ? `<button type="button" class="${app.ui.button.ghostSm}" data-retry-source="${source.id}">${canReadNow ? 'Baca sekarang' : 'Baca ulang'}</button>` : ''}
                                <button type="button" class="${app.ui.button.ghostSm} text-danger! hover:bg-danger-soft!" data-delete-source="${source.id}">Hapus</button>
                            </div>
                        </li>
                    `;
                }).join('');
                renderContextBar();
            };

