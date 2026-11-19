{{--
    Beranda script, the "Tugas" list of pending assignments. Part of the
    DOMContentLoaded handler opened in dashboard.blade.php: it shares that
    scope (app, esc, the constants and state in setup).
--}}
            // ---- Tugas ---------------------------------------------------------------------------

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const pendingAssignments = payload?.data?.assignments || [];
                latestAssignments = pendingAssignments;
                latestAssignmentsPayload = payload;

                if (!hasChanged('assignments', { pendingAssignments, day: todayIso() })) {
                    return;
                }

                assignmentHost.removeAttribute('aria-busy');

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = app.renderEmptyState('Tidak ada tugas.', 'secondary');

                    return;
                }

                const describeDeadline = (assignment) => {
                    if (!assignment.deadline_date) {
                        return { label: assignment.deadline || null, tone: 'brand' };
                    }

                    const daysLeft = app.daysUntil(assignment.deadline_date);

                    if (daysLeft === null) {
                        return { label: assignment.deadline || null, tone: 'brand' };
                    }
                    if (daysLeft < 0) {
                        return { label: `Lewat ${Math.abs(daysLeft)} hari · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 0) {
                        return { label: `Hari ini · ${assignment.deadline}`, tone: 'danger' };
                    }
                    if (daysLeft === 1) {
                        return { label: `Besok · ${assignment.deadline}`, tone: 'warning' };
                    }
                    if (daysLeft <= 3) {
                        return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'warning' };
                    }

                    return { label: `${daysLeft} hari lagi · ${assignment.deadline}`, tone: 'brand' };
                };

                const hiddenCount = Math.max(0, pendingAssignments.length - ASSIGNMENT_PREVIEW_LIMIT);

                assignmentHost.innerHTML = `
                    <div class="flex flex-col gap-3" id="dashboard-assignment-list">
                    ${pendingAssignments.map((assignment, index) => {
                        const deadline = describeDeadline(assignment);
                        const statusColor = deadline.tone;
                        const isExtra = index >= ASSIGNMENT_PREVIEW_LIMIT;

                        return `
                        <div class="mycic-card overflow-hidden${isExtra && !assignmentsExpanded ? ' hidden' : ''}"${isExtra ? ' data-assignment-extra' : ''}>
                            <div class="mycic-card-body">
                                <div class="mb-3 flex flex-wrap items-start gap-2">
                                    <div class="me-auto min-w-0">
                                        <div class="mb-1 font-mono text-caption font-medium text-ink-subtle">${esc([assignment.course_code, assignment.lecturer_name].filter(Boolean).join(' · ') || '')}</div>
                                        <h3 class="mycic-card-title mb-1">${esc(assignment.course_name || 'Tugas')}</h3>
                                        ${assignment.material_title && assignment.material_title !== assignment.course_name ? `
                                            <p class="mb-0 text-sm text-ink-muted">${esc(assignment.material_title)}</p>
                                        ` : ''}
                                    </div>
                                    ${deadline.label ? `
                                        <div class="font-mono text-caption font-medium tabular-nums ${textTone(statusColor)}">${esc(deadline.label)}</div>
                                    ` : ''}
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    ${assignment.assignment_file_url
                                        ? `<a href="${esc(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lampiran</a>`
                                        : '<span></span>'}
                                    <a href="${esc(assignment.meeting_url)}" class="${app.buttonClass('secondary', { small: true })}">Detail</a>
                                </div>
                            </div>

                            <div class="border-t border-border-light bg-surface/40 px-4 py-3 md:px-5">
                                ${assignment.can_submit_directly ? `
                                    <form class="flex flex-col gap-2 sm:flex-row sm:items-center" data-assignment-card="${index}">
                                        <div class="min-w-0 flex-1">
                                            <label class="sr-only" for="dashboard-assignment-file-${index}">File tugas ${esc(assignment.course_name || '')} (PDF, maks. 10 MB)</label>
                                            <input type="file" class="mycic-input bg-panel" id="dashboard-assignment-file-${index}" data-field="attachment" accept=".pdf,application/pdf" required>
                                        </div>
                                        <button type="submit" class="${app.buttonClass('primary')}" data-online-required>Kirim</button>
                                    </form>
                                ` : `
                                    <div class="text-sm text-ink-subtle">${assignment.upload_status_text ? esc(assignment.upload_status_text) : 'Portal sedang tidak membuka upload tugas ini. Buka detail untuk melihat statusnya.'}</div>
                                `}
                            </div>
                        </div>
                        `;
                    }).join('')}
                    </div>
                    ${hiddenCount ? `
                        <button type="button" class="${app.buttonClass('secondary')} mt-3 w-full" data-assignment-toggle aria-controls="dashboard-assignment-list" aria-expanded="${assignmentsExpanded ? 'true' : 'false'}">
                            ${assignmentsExpanded ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount} tugas lainnya`}
                        </button>
                    ` : ''}
                `;

                assignmentHost.querySelector('[data-assignment-toggle]')?.addEventListener('click', (event) => {
                    assignmentsExpanded = !assignmentsExpanded;
                    assignmentHost.querySelectorAll('[data-assignment-extra]').forEach((card) => card.classList.toggle('hidden', !assignmentsExpanded));
                    event.currentTarget.setAttribute('aria-expanded', assignmentsExpanded ? 'true' : 'false');
                    event.currentTarget.textContent = assignmentsExpanded ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount} tugas lainnya`;
                });

                assignmentHost.querySelectorAll('[data-assignment-card]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();

                        if (!app.requireOnlineAction('Upload tugas butuh koneksi internet.')) {
                            return;
                        }

                        const assignmentIndex = Number(form.dataset.assignmentCard);
                        const candidate = Number.isInteger(assignmentIndex) ? pendingAssignments[assignmentIndex] : null;
                        const file = form.querySelector('[data-field="attachment"]').files[0];

                        if (!file) {
                            app.showNotice('Pilih file tugas terlebih dahulu.', 'warning');

                            return;
                        }

                        // The server takes PDFs up to 10 MB; checking here saves a long upload that would be refused.
                        if (!/\.pdf$/i.test(file.name)) {
                            app.showNotice('File tugas harus berformat PDF.', 'warning');

                            return;
                        }

                        if (file.size > 10 * 1024 * 1024) {
                            app.showNotice('Ukuran file tugas maksimal 10 MB.', 'warning');

                            return;
                        }

                        if (!candidate?.assignment_payload) {
                            app.showNotice('Tugas ini perlu dibuka dari detail meeting terlebih dahulu.', 'warning');

                            return;
                        }

                        const formData = new FormData();
                        formData.append('courses_id', candidate.assignment_payload.courses_id);
                        formData.append('id', candidate.assignment_payload.id);
                        formData.append('pertemuan', candidate.assignment_payload.pertemuan);
                        formData.append('attachment', file);

                        await app.guardFormSubmit(form, () => app.withSubmitLoading(form.querySelector('button[type="submit"]'), async () => {
                            try {
                                const result = await app.request('/api/assignment', {
                                    method: 'POST',
                                    formData,
                                });

                                const operationId = result?.data?.operation_id;

                                if (operationId) {
                                    // Keep the form locked until the portal has the file;
                                    // on completion the list re-renders without this task.
                                    await app.trackOperation(operationId, 'Upload tugas');
                                } else {
                                    app.showNotice(result?.message || 'Tugas berhasil dikirim.', 'success');
                                    await loadPendingAssignments();
                                }
                            } catch (error) {
                                // An upload of this task is still running (another tab, a
                                // reload): follow it instead of sending the file again.
                                if (error.status === 409 && error.payload?.data?.operation_id) {
                                    app.showNotice(error.payload.message, 'warning');
                                    await app.trackOperation(error.payload.data.operation_id, 'Upload tugas', { announce: false });

                                    return;
                                }

                                app.showNotice(error.payload?.message || error.message, 'error');
                            }
                        }));
                    });
                });

                app.refreshOnlineRequiredState();
            };

