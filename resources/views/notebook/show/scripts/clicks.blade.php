{{--
    Notebook script, the click handler shared by the panels: citations,
    suggestions, pins, Studio, deleting or retrying a source, deleting a
    message, quiz answers and flashcards. Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            document.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (!target) {
                    return;
                }

                const cite = target.closest('[data-cite]');
                const suggestion = target.closest('[data-suggestion]');
                const pin = target.closest('[data-pin]');
                const studio = target.closest('[data-studio]');
                const deleteSource = target.closest('[data-delete-source]');
                const retrySource = target.closest('[data-retry-source]');
                const deleteMessage = target.closest('[data-delete-message]');
                const quizOption = target.closest('[data-quiz-option]');
                const flashcard = target.closest('[data-flashcard]');

                if (cite) {
                    showCitation(cite);
                } else if (suggestion) {
                    ask(suggestion.dataset.suggestion);
                } else if (quizOption) {
                    const item = quizOption.closest('li');
                    const answer = Number(quizOption.dataset.quizAnswer);
                    const correct = Number(quizOption.dataset.quizOption) === answer;
                    const correctText = item.querySelector(`[data-quiz-option="${answer}"]`)?.textContent.trim() || '';
                    const verdict = correct ? 'Benar.' : `Salah. Jawaban benar: ${correctText}.`;
                    const explanation = item.querySelector('[data-quiz-explanation]');

                    // Right and wrong are said in words too, not only by colour.
                    item.querySelectorAll('[data-quiz-option]').forEach((option) => {
                        const index = Number(option.dataset.quizOption);

                        option.disabled = true;
                        option.classList.toggle('border-success!', index === answer);
                        option.classList.toggle('bg-success-soft!', index === answer);
                        option.classList.toggle('border-danger!', index !== answer && option === quizOption);

                        if (index === answer || option === quizOption) {
                            option.insertAdjacentHTML('beforeend', ` <span class="text-xs font-medium ${index === answer ? 'text-success' : 'text-danger'}">· ${index === answer ? 'Benar' : 'Pilihanmu, salah'}</span>`);
                        }
                    });

                    if (explanation) {
                        explanation.insertAdjacentHTML('afterbegin', `<span class="font-medium text-ink">${app.escapeHtml(verdict)}</span> `);
                        explanation.classList.remove('hidden');
                        // The chosen option is now disabled; keep the keyboard on the result.
                        explanation.setAttribute('tabindex', '-1');
                        explanation.focus();
                    }

                    app.announce(`${verdict} ${explanation?.textContent.replace(verdict, '').trim() || ''}`);
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
                    if (studio.dataset.studio === 'assignment_plan') {
                        openAssignmentPlanner(studio);
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
                    try {
                        await app.request(`${base}/sources/${retrySource.dataset.retrySource}/retry`, { method: 'POST' });
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                } else if (deleteMessage) {
                    if (!window.confirm('Hapus hasil ini?')) {
                        return;
                    }

                    try {
                        await app.request(`${base}/messages/${deleteMessage.dataset.deleteMessage}`, { method: 'DELETE' });
                        state.messages = state.messages.filter((message) => message.id !== Number(deleteMessage.dataset.deleteMessage));
                        renderNotes();
                    } catch (error) {
                        app.showNotice(app.describeError(error), 'error');
                    }
                }
            });

