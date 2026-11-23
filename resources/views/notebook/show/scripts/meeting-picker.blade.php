{{--
    Notebook script, the sheet that adds meetings of the class as sources,
    or syncs them all in the background. Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            const openMeetingPicker = async () => {
                const render = (data) => {
                    const meetings = Array.isArray(data?.meetings) ? data.meetings : [];
                    const classUrl = state.notebook?.class_url;
                    const syncButton = classUrl ? `<button type="button" class="${app.ui.button.secondary}" data-meeting-sync data-online-required>Sinkronkan semua pertemuan</button>` : '';
                    let body;

                    if (!meetings.length) {
                        body = `
                            <div class="flex flex-col gap-3">
                                ${app.renderEmptyState(data?.available === false ? 'Daftar pertemuan belum tersimpan.' : 'Belum ada pertemuan di halaman kelas.', 'secondary', data?.available === false ? 'Buka halaman kelas sekali agar daftar pertemuan tersimpan.' : 'Pertemuan muncul setelah dosen mengunggah materinya.')}
                                ${classUrl ? `<a href="${app.escapeHtml(classUrl)}" class="${app.ui.button.primary}">Buka halaman kelas</a>` : ''}
                                ${syncButton}
                            </div>
                        `;
                    } else {
                        body = `
                            <form class="flex flex-col gap-3" data-meeting-picker>
                                <div class="flex flex-col gap-1.5">
                                    ${meetings.map((meeting) => `
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 text-sm transition-colors hover:bg-surface/50 has-[:checked:not(:disabled)]:border-brand/40 has-[:checked:not(:disabled)]:bg-surface-tint/40 ${meeting.added ? 'opacity-70' : ''}">
                                            <input type="checkbox" class="${app.ui.checkbox} mt-0.5" value="${app.escapeHtml(meeting.meeting_id)}" data-courses-id="${app.escapeHtml(meeting.courses_id)}" ${meeting.added ? 'checked disabled' : ''}>
                                            <span class="min-w-0 flex-1">
                                                <span class="block font-medium leading-snug text-ink">${app.escapeHtml(meeting.meeting_number ? `P${meeting.meeting_number} · ` : '')}${app.escapeHtml(meeting.title)}</span>
                                                <span class="mt-0.5 block text-xs text-ink-subtle">${app.escapeHtml([meeting.date, meeting.time, meeting.archived_files ? `${meeting.archived_files} berkas di arsip` : null].filter(Boolean).join(' · '))}</span>
                                            </span>
                                            ${meeting.added ? app.renderBadge('Sudah masuk', 'success') : ''}
                                        </label>
                                    `).join('')}
                                </div>
                                <p class="text-xs text-ink-subtle">Pertemuan baru diambil dari portal (butuh online).</p>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button type="submit" class="${app.ui.button.primary}">Tambahkan yang dipilih</button>
                                    ${syncButton}
                                </div>
                            </form>
                        `;
                    }

                    const panel = app.openSheet({ title: 'Tambah pertemuan', pretitle: state.notebook?.course_name || '', body });
                    app.refreshOnlineRequiredState();
                    const picker = panel.querySelector('[data-meeting-picker]');

                    picker?.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const chosen = Array.from(picker.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)'));

                        if (!chosen.length) {
                            app.showNotice('Pilih minimal satu pertemuan.', 'warning');

                            return;
                        }

                        await app.withSubmitLoading(picker.querySelector('button[type="submit"]'), async () => {
                            let added = 0;

                            for (const input of chosen) {
                                try {
                                    await app.request('/api/notebooks/meetings', { method: 'POST', json: { meeting_id: input.value, courses_id: input.dataset.coursesId }, timeout: 120000 });
                                    added += 1;
                                    input.disabled = true;
                                } catch (error) {
                                    app.showNotice(`Pertemuan gagal dimasukkan. ${app.describeError(error)}`, 'error');
                                }
                            }

                            if (added) {
                                app.showNotice(`${added} pertemuan masuk ke notebook.`, 'success');
                                app.closeSheet();
                                await load({ quiet: true });
                                renderMessages();
                            }
                        });
                    });

                    panel.querySelector('[data-meeting-sync]')?.addEventListener('click', async (event) => {
                        if (!app.requireOnlineAction('Memasukkan semua pertemuan butuh koneksi ke portal.')) {
                            return;
                        }

                        await app.withSubmitLoading(event.currentTarget, async () => {
                            try {
                                const payload = await app.request(`${base}/meetings/sync`, { method: 'POST' });

                                app.showNotice(payload?.message || 'Pertemuan sedang dimasukkan.', 'success');
                                app.closeSheet();
                            } catch (error) {
                                app.showNotice(`Belum bisa disinkronkan. ${app.describeError(error)}`, 'error');
                            }
                        });
                    });
                };

                if (state.meetings) {
                    render(state.meetings);
                }

                try {
                    const payload = await app.request(`${base}/meetings`);

                    if (payload?.data) {
                        state.meetings = payload.data;

                        if (!document.querySelector('[data-meeting-picker]') && !document.querySelector('[data-meeting-sync]')) {
                            render(state.meetings);
                        }
                    }
                } catch (error) {
                    if (!state.meetings) {
                        app.showNotice(app.describeError(error, 'Daftar pertemuan gagal dimuat.'), 'error');
                    }
                }
            };

