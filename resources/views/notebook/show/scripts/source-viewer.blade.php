{{--
    Notebook script, the source viewer: "Buka di sumber" on a citation and
    "Lihat isi" on a source open the source's text as the AI read it
    (GET …/sources/{id}/chunks), grouped under "Halaman N" (or "Bagian N"
    where a source has no pages, e.g. DOCX), scrolled to the cited chunk
    and highlighted. Chunk text is set as text, never as HTML. No AI call,
    no consent, no budget. On a phone, once the viewer of a citation
    closes, it switches to the Sumber tab and pulses the source's row. Part of the DOMContentLoaded handler
    opened in notebook/show.blade.php: it shares that scope (app, el,
    base, the elements, constants and state in setup).
--}}
            const SOURCE_VIEWER_CITED = ['rounded-md', 'bg-warning-soft', 'text-ink', 'ring-1', 'ring-warning/40'];

            const sourceViewerUrl = ({ sourceId, around = null, messageId = null, n = null }) => {
                const query = new URLSearchParams();

                if (Number.isInteger(around) && around >= 0) {
                    query.set('around', String(around));
                } else if (Number.isInteger(messageId) && Number.isInteger(n)) {
                    // A citation saved before positions were kept: the server finds it from its excerpt.
                    query.set('message', String(messageId));
                    query.set('n', String(n));
                }

                const search = query.toString();

                return `${base}/sources/${sourceId}/chunks${search ? `?${search}` : ''}`;
            };

            const sourceChunkHeading = (chunk) => (chunk.page ? `Halaman ${chunk.page}` : `Bagian ${Number(chunk.position) + 1}`);

            const SOURCE_VIEWER_MISSING = 'Bagian yang dirujuk tidak ditemukan lagi di sumber ini; sumbernya mungkin sudah dibaca ulang.';

            // `missing`: a citation whose chunk is no longer in the source, said on screen as well as to screen readers.
            const sourceViewerBody = (data, cited, { missing = false } = {}) => {
                const body = document.createElement('div');
                body.className = 'flex flex-col gap-3';

                const hint = document.createElement('p');
                hint.className = 'text-xs text-ink-subtle';
                hint.textContent = 'Ini teks yang dibaca AI dari sumber, bukan tampilan berkas aslinya.';
                body.append(hint);

                if (missing) {
                    const notice = document.createElement('p');
                    notice.className = 'text-xs text-warning';
                    notice.dataset.sourceViewerMissing = '';
                    notice.textContent = SOURCE_VIEWER_MISSING;
                    body.append(notice);
                }

                const chunks = Array.isArray(data?.chunks) ? data.chunks : [];
                const pager = (label, around) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = app.ui.button.ghostSm;
                    button.dataset.sourceViewerAround = String(around);
                    button.textContent = label;

                    return button;
                };

                if (!chunks.length) {
                    const empty = document.createElement('p');
                    empty.className = 'text-sm text-ink-muted';
                    empty.textContent = 'Sumber ini belum punya teks yang bisa ditampilkan.';
                    body.append(empty);

                    return body;
                }

                if (data.has_before) {
                    body.append(pager('Muat bagian sebelumnya', Math.max(0, Number(chunks[0].position) - 25)));
                }

                let lastHeading = null;
                let group = null;

                chunks.forEach((chunk) => {
                    const heading = sourceChunkHeading(chunk);

                    if (heading !== lastHeading || !chunk.page) {
                        lastHeading = heading;
                        group = document.createElement('section');
                        group.className = 'flex flex-col gap-1.5';

                        const title = document.createElement('h3');
                        title.className = 'text-xs font-medium text-ink-subtle';
                        title.textContent = heading;
                        group.append(title);
                        body.append(group);
                    }

                    const text = document.createElement('p');
                    text.className = 'whitespace-pre-line px-1.5 py-1 text-sm leading-relaxed text-ink-muted';
                    text.dataset.sourceChunk = String(chunk.position);
                    text.textContent = String(chunk.content ?? '');

                    if (cited !== null && Number(chunk.position) === cited) {
                        text.classList.remove('text-ink-muted');
                        text.classList.add(...SOURCE_VIEWER_CITED);
                        text.dataset.sourceChunkCited = '';
                        text.tabIndex = -1;
                    }

                    group.append(text);
                });

                if (data.has_after) {
                    body.append(pager('Muat bagian berikutnya', Number(chunks[chunks.length - 1].position) + 16));
                }

                return body;
            };

            // Run when the viewer closes (the Sumber tab is hidden behind the sheet while it is open): the row
            // pulses where the student can see it and takes focus, since the citation button they came from is now hidden.
            const pulseSourceRow = (sourceId) => {
                if (window.matchMedia('(min-width: 80rem)').matches) {
                    return;
                }

                setTab('sources');
                const row = sourcesHost.querySelector(`[data-source-id="${sourceId}"]`);

                if (!row) {
                    return;
                }

                row.tabIndex = -1;
                row.scrollIntoView({ block: 'center' });
                row.focus({ preventScroll: true });
                row.classList.add('animate-pulse', 'bg-surface-tint');
                window.setTimeout(() => row.classList.remove('animate-pulse', 'bg-surface-tint'), 2400);
            };

            // Fills the open sheet's body; the pagers load into the same sheet, keeping the cited chunk's highlight.
            const loadSourceViewer = async (bodyHost, { sourceId, around = null, messageId = null, n = null, fromCitation = false, cited = null }) => {
                const loading = document.createElement('p');
                loading.className = 'text-sm text-ink-muted';
                loading.textContent = 'Memuat isi sumber…';
                bodyHost.replaceChildren(loading);

                try {
                    const payload = await app.request(sourceViewerUrl({ sourceId, around, messageId, n }));
                    const data = payload?.data || {};
                    const highlight = fromCitation ? (Number.isInteger(data.around) ? data.around : null) : cited;

                    if (!loading.isConnected) {
                        return;
                    }

                    bodyHost.replaceChildren(sourceViewerBody(data, highlight, { missing: fromCitation && highlight === null }));
                    bodyHost.querySelectorAll('[data-source-viewer-around]').forEach((button) => button.addEventListener('click', () => {
                        loadSourceViewer(bodyHost, { sourceId, around: Number(button.dataset.sourceViewerAround), cited: highlight });
                    }));

                    const target = bodyHost.querySelector('[data-source-chunk-cited]');

                    if (fromCitation && target) {
                        target.scrollIntoView({ block: 'center' });
                        target.focus({ preventScroll: true });
                    } else if (fromCitation) {
                        app.announce(SOURCE_VIEWER_MISSING);
                    }
                } catch (error) {
                    if (loading.isConnected) {
                        loading.textContent = app.describeError(error, 'Gagal memuat isi sumber.');
                    }
                }
            };

            // `fromCitation` highlights the chunk the server says was cited (`around`), and on a phone pulses the source's row once the sheet closes.
            const openSourceViewer = async ({ sourceId, around = null, messageId = null, n = null, fromCitation = false }) => {
                const known = state.sources.find((source) => source.id === sourceId);
                const placeholder = document.createElement('div');
                app.openSheet({
                    title: known?.title || 'Sumber',
                    pretitle: 'Teks yang dibaca AI',
                    body: placeholder,
                    onClose: fromCitation ? () => pulseSourceRow(sourceId) : null,
                });
                const bodyHost = placeholder.parentElement;

                if (!bodyHost) {
                    return;
                }

                await loadSourceViewer(bodyHost, { sourceId, around, messageId, n, fromCitation });
            };
