{{--
    Jadwal & Kelas script, a class's meeting list, opened from its card or
    from the modal (the class detail endpoint). Part of the DOMContentLoaded
    handler opened in classes/index.blade.php: it shares that scope (app,
    the elements, constants and state in setup).
--}}
            // A loading or empty note inside a card or the modal: quieter than the dashed empty box.
            const renderMeetingPanelNote = (message) => `<div class="${app.ui.divider} pt-3"><p class="text-xs text-ink-subtle">${app.escapeHtml(message)}</p></div>`;

            const cleanTopicPreview = (raw) => String(raw || '')
                .replace(/^(?:Materi\s+Pertemuan\s+\d+\s*)?(?:Pokok\s+Bahasan\s*:\s*)?/i, '')
                .replace(/\s+/g, ' ')
                .trim();

            const renderMeetingLinks = (panel, payload) => {
                const materials = payload.class_detail?.course_materials || [];

                if (!materials.length) {
                    panel.innerHTML = renderMeetingPanelNote('Belum ada pertemuan yang tersedia.');
                    return;
                }

                panel.innerHTML = `
                    <div class="${app.ui.divider} pt-3">
                        <div class="mb-2 text-xs font-medium text-ink-subtle">Daftar Pertemuan (${materials.length})</div>
                        <div class="flex flex-wrap gap-2">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const labelNumber = material.meeting_number || index + 1;
                                const topic = cleanTopicPreview(material.title);
                                const hoverTitle = topic
                                    ? `P${labelNumber}: ${topic}`
                                    : `Buka Pertemuan ${labelNumber}`;

                                if (!meetingUrl) {
                                    return `
                                        <span class="inline-flex min-h-11 select-none items-center gap-1.5 rounded-full border border-border-light bg-surface/60 px-3 text-xs font-medium text-ink-subtle tabular-nums opacity-60 sm:min-h-8" aria-disabled="true" title="Detail meeting belum tersedia">
                                            <span class="size-1.5 rounded-full bg-ink-subtle/50" aria-hidden="true"></span>
                                            P${app.escapeHtml(labelNumber)}
                                        </span>
                                    `;
                                }

                                return `
                                    <a href="${meetingUrl}" class="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-border bg-panel px-3 text-xs font-medium text-ink transition-colors hover:border-brand/40 hover:bg-surface-tint hover:text-brand-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 tabular-nums sm:min-h-8" title="${app.escapeHtml(hoverTitle)}">
                                        <span class="size-1.5 rounded-full bg-brand" aria-hidden="true"></span>
                                        P${app.escapeHtml(labelNumber)}
                                    </a>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            };

            const bindMeetingPanels = (root = document) => {
                root.querySelectorAll('[data-meeting-toggle]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const parent = button.closest('[data-class-card]') || button.closest('.rounded-lg');
                        const panel = parent?.querySelector('[data-meeting-panel]');

                        if (!panel) return;

                        const alreadyOpen = !panel.classList.contains('hidden');
                        if (alreadyOpen) {
                            panel.classList.add('hidden');
                            button.textContent = 'Lihat pertemuan';
                            return;
                        }

                        panel.classList.remove('hidden');
                        panel.innerHTML = renderMeetingPanelNote('Memuat daftar pertemuan.');
                        button.textContent = 'Tutup pertemuan';

                        const detailUrl = button.dataset.detailApi;
                        const cachedDetail = app.getCachedResponse(detailUrl);

                        if (cachedDetail) {
                            renderMeetingLinks(panel, cachedDetail.payload);
                        }

                        try {
                            const payload = await app.request(detailUrl);
                            renderMeetingLinks(panel, payload);
                        } catch (error) {
                            app.showNotice(error.payload?.message || error.message, 'error');
                            panel.innerHTML = app.renderEmptyState('Gagal memuat daftar pertemuan.', 'danger');
                            button.textContent = 'Lihat pertemuan';
                        }
                    });
                });
            };

