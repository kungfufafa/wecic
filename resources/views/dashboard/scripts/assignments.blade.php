{{--
    Beranda script, the "Tugas" list of pending assignments. Part of the
    DOMContentLoaded handler opened in dashboard.blade.php: it shares that
    scope (app, esc, the constants and state in setup).
--}}
            // ---- Tugas ---------------------------------------------------------------------------

            const renderPendingAssignments = (payload) => {
                const assignmentHost = document.getElementById('dashboard-pending-assignments');
                const assignmentCountNode = document.getElementById('dashboard-assignments-count');
                const pendingAssignments = payload?.data?.assignments || [];
                latestAssignments = pendingAssignments;
                latestAssignmentsPayload = payload;

                if (assignmentCountNode) {
                    assignmentCountNode.textContent = pendingAssignments.length ? String(pendingAssignments.length) : '';
                }

                if (!hasChanged('assignments', { pendingAssignments, day: todayIso() })) {
                    return;
                }

                assignmentHost.removeAttribute('aria-busy');

                if (!pendingAssignments.length) {
                    assignmentHost.innerHTML = app.renderEmptyState('Tidak ada tugas.', 'secondary', '', { icon: 'circle-check' });

                    return;
                }

                const compactDeadlineText = (raw) => {
                    const cleaned = String(raw || '')
                        .replace(/^Batas\s+waktu\s+upload\s+tugas\s*(?:Pertemuan\s+\d+\s*)?/i, '')
                        .replace(/\bdan\s+Waktu\s+upload\s+tugas\s+sudah\s+berakhir\.?$/i, '')
                        .replace(/^(?:S\.?d\.?|Sampai(?:\s+dengan)?)\s+/i, '')
                        .replace(/^(?:Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),\s*/i, '')
                        .trim();

                    return cleaned || String(raw || '').trim();
                };

                const describeDeadline = (assignment) => {
                    const compact = compactDeadlineText(assignment.deadline);

                    if (!assignment.deadline_date) {
                        return { label: compact || null, tone: 'neutral', full: assignment.deadline || '' };
                    }

                    const daysLeft = app.daysUntil(assignment.deadline_date);

                    if (daysLeft === null) {
                        return { label: compact || null, tone: 'neutral', full: assignment.deadline || '' };
                    }

                    const shortDate = app.formatIndonesianDate(assignment.deadline_date, { month: 'short', year: false, fallback: '' }) || compact;
                    const suffix = shortDate ? ` · ${shortDate}` : '';

                    if (daysLeft < 0) {
                        return { label: `Lewat ${Math.abs(daysLeft)} hari${suffix}`, tone: 'danger', full: assignment.deadline || '' };
                    }
                    if (daysLeft === 0) {
                        return { label: `Hari ini${suffix}`, tone: 'danger', full: assignment.deadline || '' };
                    }
                    if (daysLeft === 1) {
                        return { label: `Besok${suffix}`, tone: 'warning', full: assignment.deadline || '' };
                    }
                    if (daysLeft <= 3) {
                        return { label: `${daysLeft} hari lagi${suffix}`, tone: 'warning', full: assignment.deadline || '' };
                    }

                    return { label: `${daysLeft} hari lagi${suffix}`, tone: 'neutral', full: assignment.deadline || '' };
                };

                const hiddenCount = Math.max(0, pendingAssignments.length - ASSIGNMENT_PREVIEW_LIMIT);

                // One card, one row per task: icon tile, course with concise deadline badge,
                // right-aligned actions, and inline upload form only when direct upload is open.
                assignmentHost.innerHTML = `
                    <div class="${app.ui.card} overflow-hidden" data-slot="card">
                    <ul class="${app.ui.list}" id="dashboard-assignment-list">
                    ${pendingAssignments.map((assignment, index) => {
                        const deadline = describeDeadline(assignment);
                        const statusColor = deadline.tone;
                        const isExtra = index >= ASSIGNMENT_PREVIEW_LIMIT;
                        const title = assignment.course_name || assignment.material_title || assignment.course_code || 'Tugas';

                        return `
                        <li class="px-4 py-3.5 sm:px-5${isExtra && !assignmentsExpanded ? ' hidden' : ''}"${isExtra ? ' data-assignment-extra' : ''}>
                            <div class="flex items-start gap-3">
                                <span class="${app.ui.iconTile[statusColor] || app.ui.iconTile.neutral} mt-0.5 size-9 rounded-lg" aria-hidden="true">
                                    ${app.renderIcon('notebook', 'sm')}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                                            ${assignment.course_code || assignment.lecturer_name ? `
                                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-ink-subtle">
                                                    ${assignment.course_code ? `<span class="font-mono font-medium text-ink-muted">${esc(assignment.course_code)}</span>` : ''}
                                                    ${assignment.course_code && assignment.lecturer_name ? '<span aria-hidden="true">·</span>' : ''}
                                                    ${assignment.lecturer_name ? `<span>${esc(assignment.lecturer_name)}</span>` : ''}
                                                </div>
                                            ` : ''}
                                            <h3 class="${app.ui.cardTitle}">${esc(title)}</h3>
                                            ${assignment.material_title && assignment.material_title !== title ? `
                                                <p class="text-xs text-ink-muted">${esc(assignment.material_title)}</p>
                                            ` : ''}
                                            <div class="mt-1 flex flex-wrap items-center gap-2 empty:hidden">
                                                ${deadline.label ? `
                                                    <span class="${app.ui.badge[statusColor] || app.ui.badge.neutral} tabular-nums"${deadline.full ? ` title="${esc(deadline.full)}"` : ''}>${ICONS.clock}${esc(deadline.label)}</span>
                                                ` : ''}
                                                ${!assignment.can_submit_directly ? `<span class="text-xs text-ink-subtle">${assignment.upload_status_text ? esc(assignment.upload_status_text) : 'Upload ditutup di portal. Buka detail untuk melihat status.'}</span>` : ''}
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-center">
                                            ${assignment.assignment_file_url
                                                ? `<a href="${esc(app.absoluteCampusUrl(assignment.assignment_file_url))}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Lampiran</a>`
                                                : ''}
                                            <a href="${esc(assignment.meeting_url)}" class="${app.buttonClass('secondary', { small: true })}">Detail</a>
                                        </div>
                                    </div>

                                    ${assignment.can_submit_directly ? `
                                        <form class="mt-3 flex flex-col gap-2 rounded-lg border border-border-light bg-surface/50 p-2.5 sm:flex-row sm:items-center" data-assignment-card="${index}">
                                            <div class="min-w-0 flex-1">
                                                <label class="sr-only" for="dashboard-assignment-file-${index}">File tugas ${esc(assignment.course_name || '')} (PDF, maks. 10 MB)</label>
                                                <input type="file" class="${app.ui.fileInput} min-h-11 sm:min-h-9" id="dashboard-assignment-file-${index}" data-field="attachment" accept=".pdf,application/pdf" required>
                                            </div>
                                            <button type="submit" class="${app.buttonClass('primary')}" data-online-required>Kirim</button>
                                        </form>
                                    ` : ''}
                                </div>
                            </div>
                        </li>
                        `;
                    }).join('')}
                    </ul>
                    ${hiddenCount ? `
                        <div class="border-t border-border-light p-2">
                            <button type="button" class="${app.buttonClass('ghost', { small: true })} w-full" data-assignment-toggle aria-controls="dashboard-assignment-list" aria-expanded="${assignmentsExpanded ? 'true' : 'false'}">
                                ${assignmentsExpanded ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount} tugas lainnya`}
                            </button>
                        </div>
                    ` : ''}
                    </div>
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

