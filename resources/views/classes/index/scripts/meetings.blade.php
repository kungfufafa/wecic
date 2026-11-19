{{--
    Jadwal & Kelas script, a class's meeting list, opened from its card or
    from the modal (the class detail endpoint). Part of the DOMContentLoaded
    handler opened in classes/index.blade.php: it shares that scope (app,
    the elements, constants and state in setup).
--}}
            // A loading or empty note inside a card or the modal: quieter than the dashed empty box.
            const renderMeetingPanelNote = (message) => `<div class="${app.ui.divider} pt-3"><p class="text-xs text-ink-subtle">${app.escapeHtml(message)}</p></div>`;

            const renderMeetingLinks = (panel, payload) => {
                const materials = payload.class_detail?.course_materials || [];

                if (!materials.length) {
                    panel.innerHTML = renderMeetingPanelNote('Belum ada pertemuan yang tersedia.');
                    return;
                }

                panel.innerHTML = `
                    <div class="${app.ui.divider} pt-3">
                        <div class="mb-2 text-xs font-medium text-ink-subtle">Daftar Pertemuan</div>
                        <div class="flex flex-wrap gap-2">
                            ${materials.map((material, index) => {
                                const meetingUrl = app.buildMeetingUrlFromHref(material.join_link?.href);
                                const labelNumber = material.meeting_number || index + 1;

                                if (!meetingUrl) {
                                    return `
                                        <span class="${app.buttonClass('secondary', { small: true })} pointer-events-none tabular-nums opacity-50" aria-disabled="true" title="Detail meeting belum tersedia">
                                            P${app.escapeHtml(labelNumber)}
                                        </span>
                                    `;
                                }

                                return `
                                    <a href="${meetingUrl}" class="${app.buttonClass('secondary', { small: true })} tabular-nums" title="Buka Pertemuan ${app.escapeHtml(labelNumber)}">
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

