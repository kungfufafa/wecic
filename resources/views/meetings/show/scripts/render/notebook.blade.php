{{--
    Pertemuan script, the Notebook card's slot: adding this meeting to the
    course notebook, then a link to it. Part of render() opened in
    meetings/show/scripts/render.blade.php: it shares that scope (payload,
    detail and the meeting, attendance and assignment state derived there)
    and the handler's (app, the helpers and the action slots).
--}}
                renderSlot('notebook', notebookSectionMarkup, null, (slot) => {
                    const addNotebookButton = slot.querySelector('#meeting-add-notebook');
                    addNotebookButton?.addEventListener('click', () => {
                        app.withSubmitLoading(addNotebookButton, async () => {
                            try {
                                const result = await app.request('/api/notebooks/meetings', {
                                    method: 'POST',
                                    json: { meeting_id: String(meetingId), courses_id: String(coursesId) },
                                    timeout: 120000,
                                });
                                const notebook = result?.data?.notebook;
                                const queued = Number(result?.data?.meeting?.queued_files || 0);

                                app.showNotice(`${result?.message || 'Pertemuan masuk ke notebook.'}${queued ? ` ${queued} berkas diarsipkan di background.` : ''}`, 'success');

                                if (notebook?.id) {
                                    addNotebookButton.outerHTML = `<a href="/notebook/${notebook.id}" class="${app.buttonClass('primary')} mycic-btn-block">Buka di Notebook</a>`;
                                }
                            } catch (error) {
                                app.showNotice(`Belum bisa dimasukkan ke notebook. ${app.describeError(error)}`, 'error');
                            }
                        });
                    });
                });

