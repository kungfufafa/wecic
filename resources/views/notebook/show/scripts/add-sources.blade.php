{{--
    Notebook script, the buttons that add a source: a meeting, an archived
    file, an upload, or a written note. Part of the DOMContentLoaded handler
    opened in notebook/show.blade.php: it shares that scope (app, el, base,
    the elements, constants and state in setup).
--}}
            el('source-add-meeting').addEventListener('click', () => openMeetingPicker());

            el('source-add-archive').addEventListener('click', () => {
                const used = new Set(state.sources.map((source) => source.archived_file_id).filter(Boolean));
                const courses = state.archivedCourses;
                const room = state.sourceLimit === null ? null : Math.max(0, state.sourceLimit - state.sources.length);
                const body = courses.length
                    ? `
                        <form class="flex flex-col gap-4" data-archive-picker>
                            ${room === null ? '' : `<p class="text-xs text-ink-subtle">Notebook ini bisa menambah ${room} sumber lagi (batas ${state.sourceLimit}).</p>`}
                            ${courses.map((course) => `
                                <fieldset class="flex flex-col gap-1">
                                    <legend class="mb-2 text-sm font-medium leading-snug text-ink">${app.escapeHtml(course.course_name || 'Mata kuliah')}</legend>
                                    ${course.files.map((file) => `
                                        <label class="flex min-h-11 cursor-pointer items-start gap-2.5 rounded-md px-1 py-1.5 text-sm sm:min-h-9 sm:py-1 text-ink transition-colors hover:bg-surface/50 ${used.has(file.id) ? 'opacity-60' : ''}">
                                            <input type="checkbox" class="${app.ui.checkbox} mt-0.5" value="${file.id}" ${used.has(file.id) ? 'checked disabled' : ''}>
                                            <span>${app.escapeHtml([file.meeting_number ? `P${file.meeting_number}` : '', file.kind_label, file.title || file.filename].filter(Boolean).join(' · '))}</span>
                                        </label>
                                    `).join('')}
                                </fieldset>
                            `).join('')}
                            <button type="submit" class="${app.ui.button.primary}">Tambahkan</button>
                        </form>
                    `
                    : app.renderEmptyState('Arsip materi masih kosong.', 'secondary', 'Buka pertemuan di halaman Kuliah atau arsipkan materi kelas di menu Arsip.');
                const panel = app.openSheet({ title: 'Tambah dari arsip materi', body });
                const picker = panel.querySelector('[data-archive-picker]');

                picker?.addEventListener('submit', (event) => {
                    event.preventDefault();

                    const ids = Array.from(picker.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)')).map((input) => Number(input.value));

                    if (!ids.length) {
                        app.showNotice('Pilih minimal satu berkas.', 'warning');

                        return;
                    }

                    if (room !== null && ids.length > room) {
                        app.showNotice(`Notebook ini hanya bisa menambah ${room} sumber lagi. Kurangi pilihanmu.`, 'warning');

                        return;
                    }

                    app.withSubmitLoading(picker.querySelector('button[type="submit"]'), async () => {
                        try {
                            await addSources({ json: { type: 'archived_file', archived_file_ids: ids } });
                            app.closeSheet();
                        } catch (error) {
                            app.showNotice(`Sumber gagal ditambahkan. ${app.describeError(error)}`, 'error');
                        }
                    });
                });
            });

            el('source-upload').addEventListener('change', async (event) => {
                const file = event.target.files?.[0];

                if (!file) {
                    return;
                }

                const formData = new FormData();
                formData.append('type', 'upload');
                formData.append('file', file);

                try {
                    app.showNotice(`Mengunggah ${file.name}…`, 'info');
                    await addSources({ formData });
                } catch (error) {
                    app.showNotice(`Berkas gagal diunggah. ${app.describeError(error)}`, 'error');
                } finally {
                    event.target.value = '';
                }
            });

            el('source-add-text').addEventListener('click', () => {
                const panel = app.openSheet({
                    title: 'Tulis catatan',
                    body: `
                        <form class="flex flex-col gap-3" data-text-source>
                            <label class="${app.ui.field}"><span class="${app.ui.label}">Judul</span><input class="${app.ui.input}" name="title" maxlength="120" required></label>
                            <label class="${app.ui.field}"><span class="${app.ui.label}">Isi</span><textarea class="${app.ui.textarea} min-h-48!" name="content" required placeholder="Tempel catatan kuliah, ringkasan, atau teks materi."></textarea></label>
                            <button type="submit" class="${app.ui.button.primary}">Simpan sebagai sumber</button>
                        </form>
                    `,
                });
                const textForm = panel.querySelector('[data-text-source]');

                textForm.addEventListener('submit', (event) => {
                    event.preventDefault();

                    app.withSubmitLoading(textForm.querySelector('button[type="submit"]'), async () => {
                        try {
                            await addSources({ json: { type: 'text', title: textForm.title.value, content: textForm.content.value } });
                            app.closeSheet();
                        } catch (error) {
                            app.showNotice(`Catatan gagal disimpan. ${app.describeError(error)}`, 'error');
                        }
                    });
                });
            });

