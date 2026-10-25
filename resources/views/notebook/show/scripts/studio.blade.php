{{--
    Notebook script, running a Studio tool, and the assignment outline sheet
    that asks what the task is first. Part of the DOMContentLoaded handler
    opened in notebook/show.blade.php: it shares that scope (app, el, base,
    the elements, constants and state in setup).
--}}
            const runStudio = async (button, kind, focus = null) => {
                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`${base}/studio`, { method: 'POST', json: { kind, source_ids: contextSourceIds(), focus }, timeout: 180000 });

                        state.messages.push(payload.data.message);
                        state.openNoteId = payload.data.message.id;
                        renderNotes();
                        setTab('studio');
                        app.showNotice(payload.message || 'Hasil dibuat.', 'success');
                    } catch (error) {
                        noteAiStatus(error);
                        app.showNotice(describeAiError(error, 'Hasil belum bisa dibuat.'), 'error');
                    }
                });
            };

            // The assignment outline asks what the task is, so the outline fits the brief and the chosen sources.
            const openAssignmentPlanner = (button) => {
                const meetingsWithTasks = state.sources.filter((source) => source.kind === 'meeting' && source.status === 'ready');
                const chosen = contextSources();
                const panel = app.openSheet({
                    title: 'Kerangka tugas',
                    pretitle: `${chosen.length} sumber dicentang`,
                    body: `
                        <form class="flex flex-col gap-3" data-assignment-plan>
                            <p class="text-sm text-ink-muted">Tulis instruksi tugas dari dosen (atau tempel dari portal). Kerangka, poin per bagian, konsep kunci bersitasi, langkah kerja, dan checklist disusun hanya dari sumber yang dicentang, agar kamu menulis sendiri dengan cepat dan rapi.</p>
                            <label class="mycic-field">
                                <span class="mycic-label">Instruksi / fokus tugas</span>
                                <textarea class="mycic-input min-h-32" name="focus" maxlength="1500" placeholder="Contoh: Makalah 5 halaman tentang normalisasi basis data, sertakan contoh kasus, dikumpulkan pertemuan 7."></textarea>
                            </label>
                            ${meetingsWithTasks.length ? `
                                <div class="flex flex-wrap gap-2">
                                    ${meetingsWithTasks.map((source) => `<button type="button" class="mycic-btn-secondary-sm" data-focus-meeting="${source.id}">Tugas ${app.escapeHtml(source.meeting_number ? `pertemuan ${source.meeting_number}` : source.title)}</button>`).join('')}
                                </div>
                            ` : ''}
                            <p class="text-caption text-ink-subtle">Konteks terukur: ${chosen.length} sumber${chosen.length !== readySources().length ? ` (dari ${readySources().length} yang siap)` : ''}. Ubah centang di panel Sumber bila perlu.</p>
                            <button type="submit" class="mycic-btn-primary">Susun kerangka</button>
                        </form>
                    `,
                });
                const form = panel.querySelector('[data-assignment-plan]');

                form.querySelectorAll('[data-focus-meeting]').forEach((chip) => {
                    chip.addEventListener('click', () => {
                        const source = state.sources.find((item) => item.id === Number(chip.dataset.focusMeeting));
                        const prefix = source?.meeting_number ? `Tugas pertemuan ${source.meeting_number}` : `Tugas ${source?.title || ''}`;

                        form.focus.value = form.focus.value.trim() ? form.focus.value : `${prefix}: `;
                        form.focus.focus();
                    });
                });

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    const focus = form.focus.value.trim() || null;

                    app.closeSheet();
                    runStudio(button, 'assignment_plan', focus);
                });
            };

