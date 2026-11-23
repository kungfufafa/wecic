{{--
    Notebook script, the Sumber, Chat and Studio tabs on a phone, the
    answer mode (Jawab langsung, Mode tutor, Ringkas), the question form,
    and the context ticks (Semua, Kosongkan). Part of the
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

            sourcesHost.addEventListener('change', (event) => {
                const checkbox = event.target instanceof HTMLInputElement ? event.target.closest('[data-source-select]') : null;

                if (!checkbox) {
                    return;
                }

                const id = Number(checkbox.dataset.sourceSelect);

                if (checkbox.checked) {
                    state.excluded.delete(id);
                } else {
                    state.excluded.add(id);
                }

                renderSources();
                renderStudioButtons();
            });

            el('notebook-context-all').addEventListener('click', () => {
                state.excluded.clear();
                renderSources();
                renderStudioButtons();
            });

            el('notebook-context-none').addEventListener('click', () => {
                readySources().forEach((source) => state.excluded.add(source.id));
                renderSources();
                renderStudioButtons();
            });

