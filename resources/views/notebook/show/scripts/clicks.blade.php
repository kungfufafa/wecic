{{--
    Notebook script, the click handler shared by the panels: citations
    (and opening them in the source viewer), viewing a source,
    suggestions, "Cek pemahamanku" (Jawab, Cek kerangkaku, Jawab lagi), pins, Studio, deleting or retrying a source, deleting a
    message, quiz practice (answers, "Soal ini salah?", Ulangi, Tinjau yang
    salah, Buat latihan dari yang salah), flashcards (deck and grid) and the
    mind map's nodes (their actions: Jelaskan, Latihan, Jelaskan dengan
    kata-katamu). Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            document.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (!target) {
                    return;
                }

                const cite = target.closest('[data-cite]');
                const openSource = target.closest('[data-open-source]');
                const viewSource = target.closest('[data-view-source]');
                const suggestion = target.closest('[data-suggestion]');
                const feedback = target.closest('[data-feedback-prompt]');
                const pin = target.closest('[data-pin]');
                const studio = target.closest('[data-studio]');
                const deleteSource = target.closest('[data-delete-source]');
                const retrySource = target.closest('[data-retry-source]');
                const deleteMessage = target.closest('[data-delete-message]');
                const quizOption = target.closest('[data-quiz-option]');
                const quizFlag = target.closest('[data-quiz-flag]');
                const quizRetry = target.closest('[data-quiz-retry]');
                const quizReview = target.closest('[data-quiz-review]');
                const practiceFrom = target.closest('[data-practice-from]');
                const flash = target.closest('[data-flash-mode], [data-flash-flip], [data-flash-prev], [data-flash-next], [data-flash-shuffle], [data-flash-known], [data-flash-again], [data-flash-repeat], [data-flash-restart]');
                const flashcard = target.closest('[data-flashcard]');
                const mindMapNode = target.closest('[data-mindmap-node]');
                const mindMapAction = target.closest('[data-mindmap-action]');

                if (cite) {
                    showCitation(cite);
                } else if (mindMapNode) {
                    toggleMindMapActions(mindMapNode);
                } else if (mindMapAction && !mindMapAction.disabled) {
                    await runMindMapAction(mindMapAction);
                } else if (openSource) {
                    const position = openSource.dataset.openPosition;

                    await openSourceViewer({
                        sourceId: Number(openSource.dataset.openSource),
                        around: position === '' || position === undefined ? null : Number(position),
                        messageId: Number(openSource.dataset.openMessage),
                        n: Number(openSource.dataset.openN),
                        fromCitation: true,
                    });
                } else if (viewSource) {
                    await openSourceViewer({ sourceId: Number(viewSource.dataset.viewSource) });
                } else if (feedback) {
                    openFeedback(feedback.dataset.feedbackPrompt, feedback.dataset.feedbackMode);
                } else if (suggestion) {
                    // A question from a source's guide asks that source alone; the chat shows the answer.
                    const sourceId = Number(suggestion.dataset.suggestionSource);

                    if (sourceId) {
                        setTab('chat');
                    }

                    ask(suggestion.dataset.suggestion, sourceId ? { sourceIds: [sourceId] } : {});
                } else if (quizOption && !quizOption.disabled) {
                    const messageId = Number(quizOption.closest('[data-quiz]').dataset.quiz);
                    const index = Number(quizOption.closest('[data-quiz-item]').dataset.quizItem);
                    const message = state.messages.find((item) => item.id === messageId);
                    const questions = Array.isArray(message?.data) ? message.data : [];
                    const practice = quizPractice(messageId);

                    practice.choices[index] = Number(quizOption.dataset.quizOption);

                    const result = quizScore(questions, practice);
                    const question = questions[index] || {};
                    const answer = Number(question.answer_index);
                    // Right and wrong are said in words too, not only by colour.
                    const verdict = practice.choices[index] === answer ? 'Benar.' : `Salah. Jawaban benar: ${question.options?.[answer] ?? ''}.`;

                    // The chosen option is now disabled; keep the keyboard on the result (or the score after the last answer).
                    renderPractice(messageId, result.done ? '[data-quiz-score]' : `[data-quiz-explanation="${index}"]`);
                    app.announce(`${verdict} ${question.explanation || ''}${result.done ? ` Skor ${result.score} dari ${result.total}.` : ''}`);

                    if (result.done) {
                        await savePractice(message);
                    }
                } else if (quizFlag) {
                    const messageId = Number(quizFlag.closest('[data-quiz]').dataset.quiz);
                    const index = Number(quizFlag.dataset.quizFlag);
                    const message = state.messages.find((item) => item.id === messageId);
                    const practice = quizPractice(messageId);
                    const wasDone = quizScore(message?.data || [], practice).done;

                    practice.flagged = practice.flagged.includes(index)
                        ? practice.flagged.filter((item) => item !== index)
                        : [...practice.flagged, index];
                    renderPractice(messageId, `[data-quiz-flag="${index}"]`);
                    app.announce(practice.flagged.includes(index) ? 'Soal ditandai salah dan tidak dihitung dalam skor.' : 'Tanda salah dibatalkan.');

                    if (wasDone || quizScore(message?.data || [], practice).done) {
                        await savePractice(message);
                    }
                } else if (quizRetry) {
                    const messageId = Number(quizRetry.dataset.quizRetry);

                    // Again from the start, on this device only: no AI and no budget.
                    state.practice[messageId] = { choices: [], flagged: quizPractice(messageId).flagged, reviewing: false };
                    renderPractice(messageId, '[data-quiz-option]:not([disabled])');
                } else if (quizReview) {
                    const messageId = Number(quizReview.dataset.quizReview);
                    const practice = quizPractice(messageId);

                    practice.reviewing = !practice.reviewing;
                    renderPractice(messageId, '[data-quiz-review]');
                } else if (practiceFrom && !practiceFrom.disabled) {
                    const missed = practiceFrom.dataset.practiceMissed.split(',').map(Number).filter(Number.isInteger);

                    await runStudio(practiceFrom, 'quiz', null, {
                        practice: { message_id: Number(practiceFrom.dataset.practiceFrom), missed },
                        options: { count: Math.min(15, Math.max(5, missed.length)) },
                    });
                } else if (flash) {
                    const messageId = Number(flash.closest('[data-flashcards]').dataset.flashcards);
                    const message = state.messages.find((item) => item.id === messageId);
                    const deck = flashcardPractice(message);
                    const current = deck.order[deck.position];
                    const grade = (list, other) => {
                        deck[list] = [...new Set([...deck[list], current])];
                        deck[other] = deck[other].filter((index) => index !== current);
                        deck.position += 1;
                        deck.flipped = false;
                    };
                    let focus = '[data-flash-flip]';

                    if (flash.dataset.flashMode) {
                        deck.mode = flash.dataset.flashMode;
                        focus = `[data-flash-mode="${deck.mode}"]`;
                    } else if (flash.dataset.flashFlip) {
                        deck.flipped = !deck.flipped;
                    } else if (flash.dataset.flashPrev) {
                        deck.position = Math.max(0, deck.position - 1);
                        deck.flipped = false;
                    } else if (flash.dataset.flashNext) {
                        deck.position = Math.min(deck.order.length - 1, deck.position + 1);
                        deck.flipped = false;
                    } else if (flash.dataset.flashShuffle) {
                        deck.order = shuffled(deck.order);
                        deck.position = 0;
                        deck.flipped = false;
                        app.announce('Kartu diacak.');
                    } else if (flash.dataset.flashKnown) {
                        grade('known', 'again');
                    } else if (flash.dataset.flashAgain) {
                        grade('again', 'known');
                    } else if (flash.dataset.flashRepeat) {
                        deck.order = flashToLearn(deck, (message.data || []).length);
                        deck.position = 0;
                        deck.flipped = false;
                    } else if (flash.dataset.flashRestart) {
                        state.practice[messageId] = null;
                        delete state.practice[messageId];
                        flashcardPractice(message);
                    }

                    const finished = deck.position >= deck.order.length && (flash.dataset.flashKnown || flash.dataset.flashAgain);

                    renderPractice(messageId, finished ? '[data-flash-summary]' : focus);

                    if (finished) {
                        app.announce(`Selesai. Hafal ${deck.known.length} dari ${(message.data || []).length} kartu.`);
                        await savePractice(message);
                    }
                } else if (flashcard) {
                    const flipped = flashcard.getAttribute('aria-pressed') !== 'true';

                    flashcard.setAttribute('aria-pressed', flipped ? 'true' : 'false');
                    flashcard.querySelector('[data-flashcard-front]').classList.toggle('hidden', flipped);
                    flashcard.querySelector('[data-flashcard-back]').classList.toggle('hidden', !flipped);
                    flashcard.querySelector('[data-flashcard-hint]').textContent = flipped ? 'Ketuk untuk melihat pertanyaan' : 'Ketuk untuk membalik';
                } else if (pin) {
                    const messageId = Number(pin.dataset.pin);
                    const message = state.messages.find((item) => item.id === messageId);

                    try {
                        const payload = await app.request(`${base}/messages/${messageId}`, { method: 'PATCH', json: { pinned: !message?.pinned } });

                        Object.assign(message, payload.data.message);
                        renderMessages();
                        renderNotes();
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (studio && !studio.disabled) {
                    if (STUDIO_OPTION_KINDS.includes(studio.dataset.studio)) {
                        openStudioOptions(studio, studio.dataset.studio);
                    } else {
                        await runStudio(studio, studio.dataset.studio);
                    }
                } else if (deleteSource) {
                    if (!window.confirm('Hapus sumber ini dari notebook?')) {
                        return;
                    }

                    try {
                        await app.request(`${base}/sources/${deleteSource.dataset.deleteSource}`, { method: 'DELETE' });
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (retrySource) {
                    if (needsAiConsent()) {
                        return;
                    }

                    try {
                        await app.request(`${base}/sources/${retrySource.dataset.retrySource}/retry`, { method: 'POST' });
                        await load({ quiet: true });
                    } catch (error) {
                        noteAiStatus(error);
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (deleteMessage) {
                    if (!window.confirm('Hapus hasil ini?')) {
                        return;
                    }

                    try {
                        await app.request(`${base}/messages/${deleteMessage.dataset.deleteMessage}`, { method: 'DELETE' });
                        state.messages = state.messages.filter((message) => message.id !== Number(deleteMessage.dataset.deleteMessage));
                        state.openNoteIds.delete(Number(deleteMessage.dataset.deleteMessage));
                        renderNotes();
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                }
            });

            // Escape closes a mind map node's actions and returns to its title.
            notesHost.addEventListener('keydown', (event) => {
                const panel = event.key === 'Escape' && event.target instanceof Element ? event.target.closest('[data-mindmap-actions]') : null;
                const node = panel ? notesHost.querySelector(`[aria-controls="${panel.id}"]`) : null;

                if (node) {
                    toggleMindMapActions(node);
                    node.focus();
                }
            });

            // The Catatan entries the student opened stay open when the list is redrawn (pin, delete, a new output).
            notesHost.addEventListener('toggle', (event) => {
                const entry = event.target instanceof HTMLDetailsElement && event.target.matches('[data-message-id]') ? event.target : null;

                if (entry) {
                    state.openNoteIds[entry.open ? 'add' : 'delete'](Number(entry.dataset.messageId));
                }
            }, true);

