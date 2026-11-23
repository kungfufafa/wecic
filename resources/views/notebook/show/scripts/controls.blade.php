{{--
    Notebook script, the Sumber, Chat and Studio tabs on a phone, the
    answer mode (Jawab langsung, Mode tutor, Ringkas), the question form,
    the context ticks (Semua, Kosongkan) saved on the server, and
    "+ Catatan baru" with its editor saving when it loses focus. Part of the
    DOMContentLoaded handler opened in notebook/show.blade.php: it shares
    that scope (app, el, base, the elements, constants and state in setup).
--}}
            const setTab = (tab) => {
                state.tab = tab;
                document.querySelectorAll('[data-notebook-tab]').forEach((button) => {
                    const active = button.dataset.notebookTab === tab;

                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('[data-notebook-panel]').forEach((panel) => {
                    panel.classList.toggle('max-xl:hidden', panel.dataset.notebookPanel !== tab);
                });
            };

            document.querySelectorAll('[data-notebook-tab]').forEach((button) => button.addEventListener('click', () => setTab(button.dataset.notebookTab)));
            setTab('chat');

            const setAskMode = (mode, { remember = true } = {}) => {
                state.askMode = Object.hasOwn(ASK_MODES, mode) ? mode : 'answer';
                document.querySelectorAll('[data-ask-mode]').forEach((button) => {
                    button.setAttribute('aria-pressed', button.dataset.askMode === state.askMode ? 'true' : 'false');
                });
                el('notebook-ask-mode-hint').classList.toggle('hidden', state.askMode !== 'tutor');
                questionInput.placeholder = state.askMode === 'tutor' ? 'Ceritakan bagian yang ingin kamu pahami' : 'Tanyakan dari sumber yang dicentang';

                if (remember) {
                    try {
                        window.localStorage.setItem(ASK_MODE_KEY, state.askMode);
                    } catch {
                        // Private windows may refuse storage; the mode still holds for this visit.
                    }
                }
            };

            document.querySelectorAll('[data-ask-mode]').forEach((button) => button.addEventListener('click', () => {
                setAskMode(button.dataset.askMode);

                // The starters follow the mode while the chat is empty.
                if (messagesHost.querySelector('[data-suggestion]')) {
                    renderMessages();
                }
            }));
            setAskMode(state.askMode, { remember: false });

            askForm.addEventListener('submit', (event) => {
                event.preventDefault();
                ask(questionInput.value.trim());
            });

            questionInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    askForm.requestSubmit();
                }
            });

            // The ticks are saved on the server (`context_source_ids`, null = every source), so the app and another
            // browser open with the same choice. A failed save keeps the ticks on this page and says so.
            const saveContextChoice = async () => {
                const chosen = state.excluded.size ? state.sources.map((source) => source.id).filter((id) => !state.excluded.has(id)) : null;

                state.contextChoiceKey = chosen === null ? 'all' : chosen.join(',');

                try {
                    await app.request(base, { method: 'PATCH', json: { context_source_ids: chosen } });
                } catch (error) {
                    app.showNotice(app.describeError(error, 'Pilihan sumber belum tersimpan.'), 'warning');
                }
            };

            const changeContext = (change) => {
                change();
                renderSources();
                renderStudioButtons();
                saveContextChoice();
            };

            sourcesHost.addEventListener('change', (event) => {
                const checkbox = event.target instanceof HTMLInputElement ? event.target.closest('[data-source-select]') : null;

                if (!checkbox) {
                    return;
                }

                const id = Number(checkbox.dataset.sourceSelect);

                changeContext(() => {
                    if (checkbox.checked) {
                        state.excluded.delete(id);
                    } else {
                        state.excluded.add(id);
                    }
                });
            });

            el('notebook-context-all').addEventListener('click', () => changeContext(() => state.excluded.clear()));

            el('notebook-context-none').addEventListener('click', () => changeContext(() => {
                readySources().forEach((source) => state.excluded.add(source.id));
            }));

            el('notebook-note-new').addEventListener('click', (event) => app.withSubmitLoading(event.currentTarget, () => createNote()));

            // A pause in typing saves too (NOTE_PAUSE_SAVE_MS), so a tab closed without leaving the editor loses little.
            const NOTE_PAUSE_SAVE_MS = 2500;
            const notePauseTimers = {};

            notesHost.addEventListener('input', (event) => {
                const editor = event.target instanceof HTMLTextAreaElement && event.target.matches('[data-note-editor]') ? event.target : null;

                if (editor) {
                    const messageId = Number(editor.dataset.noteEditor);

                    editNote(editor);
                    window.clearTimeout(notePauseTimers[messageId]);
                    notePauseTimers[messageId] = window.setTimeout(() => saveNote(messageId), NOTE_PAUSE_SAVE_MS);
                }
            });

            // Closing the tab, reloading or going back fires no focusout: the unsaved notes go out as keepalive requests.
            window.addEventListener('pagehide', () => flushNotes({ keepalive: true }));
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    flushNotes({ keepalive: true });
                }
            });

            // Leaving a note's editor saves it, once, when it changed (saveNote).
            notesHost.addEventListener('focusout', (event) => {
                const editor = event.target instanceof HTMLTextAreaElement && event.target.matches('[data-note-editor]') ? event.target : null;

                if (editor) {
                    saveNote(Number(editor.dataset.noteEditor));
                }
            });
