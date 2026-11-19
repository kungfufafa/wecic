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
                        : 'Enter untuk kirim, Shift+Enter untuk baris baru');

                const submit = askForm.querySelector('button[type="submit"]');

                if (!state.busy) {
                    submit.disabled = noContextChosen();
                }
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
                    const details = [SOURCE_KIND[source.kind] || source.kind, source.page_count ? `${source.page_count} hlm.` : '', source.truncated ? 'sebagian' : ''].filter(Boolean).join(' · ');
                    const group = sourceGroup(source);
                    const header = hasMeetings && group !== lastGroup ? `<li class="mt-1 text-caption font-medium text-ink-subtle">${app.escapeHtml(group)}</li>` : '';
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
                        <li class="rounded-[var(--radius-control)] border ${checked ? 'border-border' : 'border-dashed border-border opacity-75'} p-3" data-source-id="${source.id}">
                            <div class="flex items-start justify-between gap-2">
                                <label class="flex min-w-0 cursor-pointer items-start gap-2">
                                    <input type="checkbox" class="mt-1 shrink-0 cursor-pointer accent-brand" data-source-select="${source.id}" ${checked ? 'checked' : ''} ${ready ? '' : 'disabled'} aria-label="Pakai ${app.escapeHtml(source.title)} sebagai konteks">
                                    <span class="min-w-0">
                                        <span class="block break-words text-sm font-medium leading-snug text-ink">${app.escapeHtml(source.title)}</span>
                                        <span class="block text-caption text-ink-subtle">${app.escapeHtml(details)}</span>
                                    </span>
                                </label>
                                ${app.renderBadge(status.label, status.tone)}
                            </div>
                            ${source.error ? `<p class="mt-2 text-caption ${source.status === 'failed' ? 'text-danger' : 'text-ink-muted'}">${app.escapeHtml(source.error)}</p>` : ''}
                            ${source.stalled ? `<p class="mt-2 text-caption text-ink-muted">${stalledHint}</p>` : ''}
                            ${source.waiting && !source.error ? '<p class="mt-2 text-caption text-ink-muted">Menunggu giliran dibaca. Beberapa berkas dibaca sekaligus; sisanya menyusul otomatis.</p>' : ''}
                            ${source.summary ? `<details class="mt-2"><summary class="cursor-pointer text-caption font-medium text-brand-text">Ringkasan sumber</summary><p class="mt-1 whitespace-pre-line text-caption text-ink-muted">${app.escapeHtml(source.summary)}</p></details>` : ''}
                            <div class="mt-2 flex gap-2">
                                ${canRetry || canReadNow ? `<button type="button" class="mycic-btn-ghost-sm" data-retry-source="${source.id}">${canReadNow ? 'Baca sekarang' : 'Baca ulang'}</button>` : ''}
                                <button type="button" class="mycic-btn-ghost-sm text-danger hover:bg-danger-soft hover:text-danger" data-delete-source="${source.id}">Hapus</button>
                            </div>
                        </li>
                    `;
                }).join('');
                renderContextBar();
            };

