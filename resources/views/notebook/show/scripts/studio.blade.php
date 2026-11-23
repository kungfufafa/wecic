{{--
    Notebook script, running a Studio tool, and the options sheet some tools
    open first: what the assignment is, or how many quiz questions or
    flashcards and how hard. Part of the DOMContentLoaded handler
    opened in notebook/show.blade.php: it shares that scope (app, el, base,
    the elements, constants and state in setup).
--}}
            // Tools that ask first (openStudioOptions); quiz and flashcards take a count and a difficulty.
            const STUDIO_OPTION_KINDS = ['assignment_plan', 'quiz', 'flashcards'];
            const STUDIO_DIFFICULTIES = @json(\App\Services\NotebookAssistant::DIFFICULTIES);
            const STUDIO_COUNTS = {
                quiz: { min: @json(\App\Services\NotebookAssistant::MIN_PRACTICE_ITEMS), max: @json(\App\Services\NotebookAssistant::maxPracticeItems('quiz')), initial: 10, unit: 'soal' },
                flashcards: { min: @json(\App\Services\NotebookAssistant::MIN_PRACTICE_ITEMS), max: @json(\App\Services\NotebookAssistant::maxPracticeItems('flashcards')), initial: 15, unit: 'kartu' },
            };

            // `extra` carries a quiz or flashcards' `options`, or the `practice` a new quiz is made from.
            const runStudio = async (button, kind, focus = null, extra = {}) => {
                if (needsAiConsent()) {
                    return;
                }

                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`${base}/studio`, { method: 'POST', json: { kind, source_ids: contextSourceIds(), focus, ...extra }, timeout: 180000 });

                        state.messages.push(payload.data.message);
                        setAiBudget(payload.data);
                        state.openNoteIds.add(payload.data.message.id);
                        renderNotes();
                        setTab('studio');
                        app.showNotice(payload.message || 'Hasil dibuat.', 'success');
                    } catch (error) {
                        noteAiStatus(error);
                        app.showNotice(describeAiError(error, 'Hasil belum bisa dibuat.'), 'error');
                    }
                });
            };

            const quizOptionsBody = (kind, chosen) => {
                const counts = STUDIO_COUNTS[kind];
                const steps = [];

                for (let count = counts.min; count <= counts.max; count += 5) {
                    steps.push(count);
                }

                return `
                    <form class="flex flex-col gap-4" data-studio-options>
                        <p class="text-sm text-ink-muted">${kind === 'quiz' ? 'Kuis latihan untuk menguji pemahamanmu, dengan penjelasan dan rujukan sumber di tiap soal.' : 'Flashcard untuk menghafal istilah dan konsep penting, satu kartu setiap kali.'} Disusun hanya dari sumber yang dicentang.</p>
                        <fieldset class="flex flex-col gap-2">
                            <legend class="${app.ui.label} mb-2">Jumlah ${counts.unit}</legend>
                            <div class="flex flex-wrap gap-2">
                                ${steps.map((count) => `
                                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-border bg-panel px-3 text-sm text-ink has-checked:border-brand has-checked:bg-surface-tint/40 has-focus-visible:ring-2 has-focus-visible:ring-brand/40 sm:min-h-9">
                                        <input type="radio" class="accent-brand" name="count" value="${count}" ${count === counts.initial ? 'checked' : ''}> ${count}
                                    </label>
                                `).join('')}
                            </div>
                        </fieldset>
                        <fieldset class="flex flex-col gap-2">
                            <legend class="${app.ui.label} mb-2">Tingkat kesulitan</legend>
                            <div class="flex flex-wrap gap-2">
                                ${Object.entries(STUDIO_DIFFICULTIES).map(([difficulty, label]) => `
                                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-border bg-panel px-3 text-sm text-ink has-checked:border-brand has-checked:bg-surface-tint/40 has-focus-visible:ring-2 has-focus-visible:ring-brand/40 sm:min-h-9">
                                        <input type="radio" class="accent-brand" name="difficulty" value="${difficulty}" ${difficulty === 'campuran' ? 'checked' : ''}> ${app.escapeHtml(label)}
                                    </label>
                                `).join('')}
                            </div>
                        </fieldset>
                        <p class="text-xs text-ink-subtle">Konteks terukur: ${chosen.length} sumber${chosen.length !== readySources().length ? ` (dari ${readySources().length} yang siap)` : ''}. ${app.escapeHtml(budgetLine().replace('Membuat latihan', 'Membuat ini'))}</p>
                        <button type="submit" class="${app.ui.button.primary}">Buat ${app.escapeHtml((state.studioKinds[kind] || kind).toLowerCase())}</button>
                    </form>
                `;
            };

            const assignmentPlanBody = (chosen) => {
                const meetingsWithTasks = state.sources.filter((source) => source.kind === 'meeting' && source.status === 'ready');

                return `
                    <form class="flex flex-col gap-3" data-studio-options>
                        <p class="text-sm text-ink-muted">Tulis instruksi tugas dari dosen (atau tempel dari portal). Kerangka, poin per bagian, konsep kunci bersitasi, langkah kerja, dan checklist disusun hanya dari sumber yang dicentang, agar kamu menulis sendiri dengan cepat dan rapi.</p>
                        <label class="${app.ui.field}">
                            <span class="${app.ui.label}">Instruksi / fokus tugas</span>
                            <textarea class="${app.ui.textarea} min-h-32!" name="focus" maxlength="1500" placeholder="Contoh: Makalah 5 halaman tentang normalisasi basis data, sertakan contoh kasus, dikumpulkan pertemuan 7."></textarea>
                        </label>
                        ${meetingsWithTasks.length ? `
                            <div class="flex flex-wrap gap-2">
                                ${meetingsWithTasks.map((source) => `<button type="button" class="${app.ui.button.secondarySm}" data-focus-meeting="${source.id}">Tugas ${app.escapeHtml(source.meeting_number ? `pertemuan ${source.meeting_number}` : source.title)}</button>`).join('')}
                            </div>
                        ` : ''}
                        <p class="text-xs text-ink-subtle">Konteks terukur: ${chosen.length} sumber${chosen.length !== readySources().length ? ` (dari ${readySources().length} yang siap)` : ''}. Ubah centang di panel Sumber bila perlu.</p>
                        <button type="submit" class="${app.ui.button.primary}">Susun kerangka</button>
                    </form>
                `;
            };

            // The assignment outline asks what the task is, so the outline fits the brief and the chosen sources;
            // a quiz or flashcards ask how many and how hard.
            const openStudioOptions = (button, kind) => {
                if (needsAiConsent()) {
                    return;
                }

                const chosen = contextSources();
                const panel = app.openSheet({
                    title: state.studioKinds[kind] || 'Studio',
                    pretitle: `${chosen.length} sumber dicentang`,
                    body: kind === 'assignment_plan' ? assignmentPlanBody(chosen) : quizOptionsBody(kind, chosen),
                });
                const form = panel.querySelector('[data-studio-options]');

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
                    app.closeSheet();

                    if (kind === 'assignment_plan') {
                        runStudio(button, 'assignment_plan', form.focus.value.trim() || null);

                        return;
                    }

                    runStudio(button, kind, null, {
                        options: { count: Number(form.elements.count.value), difficulty: form.elements.difficulty.value },
                    });
                });
            };
