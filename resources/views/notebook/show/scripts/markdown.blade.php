{{--
    Notebook script, formatting an answer: citation buttons, the safe
    Markdown subset, plain text, and the citation panel. Part of the
    DOMContentLoaded handler opened in notebook/show.blade.php: it shares
    that scope (app, el, base, the elements, constants and state in setup).
--}}
            const citationButton = (number) => `<button type="button" class="mx-0.5 inline-flex h-5 min-w-5 cursor-pointer items-center justify-center rounded-full bg-surface-tint px-1 align-text-top font-mono text-[11px] font-medium text-brand-text transition-colors duration-150 hover:bg-brand hover:text-brand-ink focus-visible:outline-2 focus-visible:outline-brand" data-cite="${number}" aria-label="Rujukan ${number}">${number}</button>`;

            // A run of citations ([3], [2][5]) right after a word or a bracket is code such as arr[0], as on the server.
            const formatProse = (text) => app.escapeHtml(text)
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*])\*(?!\s)(.+?)\*(?!\*)/g, '$1<em>$2</em>')
                .replace(/(^|[^\p{L}\p{N}_\]])((?:\[\d{1,4}\])+)/gu, (match, before, run) => before + run.replace(/\[(\d{1,4})\]/g, (citation, number) => citationButton(number)));

            // Safe Markdown subset: everything is escaped first; code spans keep their text as written.
            const inline = (text) => String(text ?? '').split(/(`[^`]+`)/).map((part, index) => (index % 2
                ? `<code class="rounded bg-surface px-1">${app.escapeHtml(part.slice(1, -1))}</code>`
                : formatProse(part))).join('');

            const renderMarkdown = (text) => {
                const blocks = [];
                let list = null;
                let fence = null;

                const flushList = () => {
                    if (list) {
                        blocks.push(`<${list.tag} class="${list.tag === 'ul' ? 'list-disc' : 'list-decimal'} flex flex-col gap-1 ps-5">${list.items.join('')}</${list.tag}>`);
                        list = null;
                    }
                };

                const flushFence = () => {
                    blocks.push(`<pre class="overflow-x-auto rounded-lg bg-surface p-2 text-caption"><code>${app.escapeHtml(fence.join('\n'))}</code></pre>`);
                    fence = null;
                };

                String(text || '').split(/\n/).forEach((rawLine) => {
                    if (/^\s*```/.test(rawLine)) {
                        if (fence) {
                            flushFence();
                        } else {
                            flushList();
                            fence = [];
                        }

                        return;
                    }

                    if (fence) {
                        fence.push(rawLine);

                        return;
                    }

                    const line = rawLine.trimEnd();
                    const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
                    const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
                    const heading = line.match(/^(#{1,4})\s+(.*)$/);
                    const checkbox = line.match(/^\s*[-*]\s+\[[ xX]\]\s+(.*)$/);

                    if (checkbox) {
                        if (!list || list.tag !== 'ul') {
                            flushList();
                            list = { tag: 'ul', items: [] };
                        }

                        list.items.push(`<li class="list-none -ms-5"><label class="flex items-start gap-2"><input type="checkbox" class="mt-1"><span>${inline(checkbox[1])}</span></label></li>`);

                        return;
                    }

                    if (bullet || numbered) {
                        const tag = bullet ? 'ul' : 'ol';

                        if (!list || list.tag !== tag) {
                            flushList();
                            list = { tag, items: [] };
                        }

                        list.items.push(`<li>${inline((bullet || numbered)[1])}</li>`);

                        return;
                    }

                    flushList();

                    if (heading) {
                        blocks.push(`<h4 class="mt-1 text-sm font-medium leading-snug text-ink">${inline(heading[2])}</h4>`);
                    } else if (line.trim() !== '') {
                        blocks.push(`<p>${inline(line)}</p>`);
                    }
                });

                if (fence) {
                    flushFence();
                }

                flushList();

                return `<div class="flex flex-col gap-2 text-sm leading-relaxed text-ink">${blocks.join('')}</div>`;
            };

            const plainText = (text) => String(text || '')
                .replace(/(^|[^\p{L}\p{N}_\]])(?:\[\d{1,4}\])+/gu, '$1')
                .replace(/[*#`]/g, '')
                .replace(/\s+/g, ' ')
                .trim();

            const citationPanel = (message) => `<div class="hidden rounded-[var(--radius-control)] border border-border-light bg-surface p-3 text-caption text-ink-muted" data-cite-panel="${message.id}"></div>`;

