{{--
    Pertemuan script, the assignment card's slot: re-checking, the PDF
    dropzone, uploading (or queueing it offline), and deleting an upload.
    Part of render() opened in meetings/show/scripts/render.blade.php: it
    shares that scope (payload, detail and the meeting, attendance and
    assignment state derived there) and the handler's (app, the helpers and
    the action slots).
--}}
                renderSlot('assignment', assignmentSectionMarkup, { assignmentParams, assignmentDeletePayload }, (slot) => {
                    slot.querySelector('[data-assignment-recheck]')?.addEventListener('click', (event) => {
                        void app.withSubmitLoading(event.currentTarget, () => loadMeeting({ force: true }));
                    });

                    // Wire up file dropzone
                    const fileInput = slot.querySelector('#assignment-file');
                    const dropzoneEmpty = slot.querySelector('#dropzone-empty');
                    const dropzoneSelected = slot.querySelector('#dropzone-selected');
                    const selectedFileName = slot.querySelector('#selected-file-name');
                    const selectedFileSize = slot.querySelector('#selected-file-size');

                    fileInput?.addEventListener('change', () => {
                        const file = fileInput.files?.[0];
                        if (file) {
                            if (dropzoneEmpty) dropzoneEmpty.classList.add('hidden');
                            if (dropzoneSelected) dropzoneSelected.classList.remove('hidden');
                            if (selectedFileName) selectedFileName.textContent = file.name;
                            if (selectedFileSize) selectedFileSize.textContent = formatBytes(file.size);
                        } else {
                            if (dropzoneEmpty) dropzoneEmpty.classList.remove('hidden');
                            if (dropzoneSelected) dropzoneSelected.classList.add('hidden');
                        }
                    });

                    const assignmentForm = slot.querySelector('#assignment-form');
                    assignmentForm?.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.', { queueable: true })) {
                            return;
                        }

                        const file = fileInput?.files?.[0];

                        if (!file) {
                            app.showNotice('Pilih file PDF untuk tugas.', 'warning');
                            return;
                        }

                        // The server takes PDFs up to 10 MB; checking here saves a long upload that would be refused.
                        if (!/\.pdf$/i.test(file.name)) {
                            app.showNotice('File tugas harus berformat PDF.', 'warning');
                            return;
                        }

                        if (file.size > ASSIGNMENT_MAX_BYTES) {
                            app.showNotice(`Ukuran file tugas maksimal 10 MB (file ini ${formatBytes(file.size)}).`, 'warning');
                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', assignmentParams.courses_id);
                        formData.append('id', assignmentParams.id);
                        formData.append('pertemuan', assignmentParams.pertemuan);
                        formData.append('attachment', file);

                        const note = slot.querySelector('#assignment-note')?.value.trim();
                        if (note) {
                            formData.append('form_values[keterangan]', note);
                        }

                        // Submitted from "Tetap kirim tugas": POST past the closed
                        // upload window and let the portal decide.
                        if (assignmentForm.closest('[data-assignment-override]')) {
                            formData.append('allow_closed', '1');
                        }

                        await app.guardFormSubmit(assignmentForm, () => app.withSubmitLoading(assignmentForm.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                // Offline session: the file waits in the outbox, not at the portal.
                                if (app.handleQueuedActionResult(result)) {
                                    assignmentForm.reset();

                                    return;
                                }

                                const operationId = result?.data?.operation_id;
                                if (operationId) {
                                    // Keep the form locked until the portal has the file;
                                    // on completion this meeting re-renders as submitted.
                                    await app.trackOperation(operationId, 'Upload tugas');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadMeeting();
                                }
                            } catch (error) {
                                // An upload of this assignment is still running (another tab,
                                // a reload): follow it instead of sending the file again.
                                if (error.status === 409 && error.payload?.data?.operation_id) {
                                    app.showNotice(error.payload.message, 'warning');
                                    await app.trackOperation(error.payload.data.operation_id, 'Upload tugas', { announce: false });

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });

                    // Assignment Delete Listener
                    const assignmentDeleteButton = slot.querySelector('#assignment-delete');
                    assignmentDeleteButton?.addEventListener('click', async () => {
                        if (!app.requireOnlineAction('Hapus tugas butuh koneksi internet.')) {
                            return;
                        }

                        if (!window.confirm('Hapus tugas yang sudah diupload untuk meeting ini?')) {
                            return;
                        }

                        await app.withSubmitLoading(assignmentDeleteButton, async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'DELETE',
                                    json: { ...assignmentDeletePayload, courses_id: coursesId },
                                });

                                app.showNotice(result?.message || 'Tugas berhasil dihapus.', 'success');
                                app.clearCachedResponse('/api/assignment/pending');
                                await loadMeeting();
                            } catch (error) {
                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        });
                    });
                });

