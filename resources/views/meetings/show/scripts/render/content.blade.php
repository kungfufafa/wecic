{{--
    Pertemuan script, the content column: the main material and the
    assignment brief with their outlines and previews, and the preview
    toggles. Part of render() opened in
    meetings/show/scripts/render.blade.php: it shares that scope (payload,
    detail and the meeting, attendance and assignment state derived there)
    and the handler's (app, the helpers and the action slots).
--}}
                // Render Content Sections
                const contentSections = [];

                // 1. Materi Utama
                if (materials.main_topic || materials.main_description || materials.main_material_iframe) {
                    const outlineItems = parseOutlineItems(materials.main_description);
                    let outlineMarkup = '';

                    if (outlineItems.length > 1) {
                        outlineMarkup = `
                            <div class="mt-4 grid gap-2">
                                ${outlineItems.map(item => `
                                    <div class="mycic-outline-item">
                                        <span class="mycic-outline-badge">${app.escapeHtml(item.num || '•')}</span>
                                        <span class="min-w-0 flex-1 text-sm font-medium text-ink">${app.escapeHtml(item.text)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        `;
                    } else if (materials.main_description) {
                        outlineMarkup = `
                            <div class="mt-3 rounded-xl border border-border-light bg-surface/40 p-4 text-sm leading-relaxed text-ink">
                                ${app.escapeHtml(materials.main_description)}
                            </div>
                        `;
                    }

                    let mainResourceMarkup = '';
                    if (materials.main_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.main_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.main_material_iframe);

                        mainResourceMarkup = `
                            <div class="mt-4 rounded-xl border border-border bg-panel p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-bold text-ink">Modul PDF</div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-main-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-main-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-main-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h2 class="mycic-card-title">Materi</h2>
                            </div>
                            <div class="mycic-card-body">
                                <div class="text-base font-bold text-ink">${app.escapeHtml(mainTopicClean)}</div>
                                ${outlineMarkup}
                                ${mainResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // 2. Tugas Perkuliahan
                const deadlineText = materials.assignment_instructions || assignmentInfo.deadline;
                const hasDeadline = Boolean(deadlineText && deadlineText !== ':');

                if (materials.assignment_topic || materials.assignment_instructions || materials.assignment_material_iframe || assignmentInfo.upload_text) {
                    const assignmentTitle = app.escapeHtml(materials.assignment_topic || assignmentInfo.upload_text || 'Tugas');
                    let assignmentResourceMarkup = '';

                    if (materials.assignment_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.assignment_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.assignment_material_iframe);

                        assignmentResourceMarkup = `
                            <div class="mt-4 rounded-xl border border-border bg-panel p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-bold text-ink">Soal PDF</div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-assignment-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-assignment-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-slate-900">
                                    <div class="flex items-center justify-between border-b border-border bg-surface px-3 py-2 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="text-ink-subtle hover:text-ink font-semibold" data-close-preview="preview-assignment-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="mycic-card">
                            <div class="mycic-card-header">
                                <h2 class="mycic-card-title">Tugas</h2>
                            </div>
                            <div class="mycic-card-body">
                                <div class="text-base font-bold text-ink">${assignmentTitle}</div>
                                ${hasDeadline ? `
                                    <div class="mt-3 rounded-xl border border-warning/30 bg-warning-soft/60 px-3 py-2 text-sm">
                                        <span class="text-ink-subtle">Deadline:</span>
                                        <span class="font-bold text-warning">${app.escapeHtml(deadlineText)}</span>
                                    </div>
                                ` : ''}
                                ${assignmentResourceMarkup}
                            </div>
                        </div>
                    `);
                }

                // Render to meeting-content
                const contentContainer = document.getElementById('meeting-content');
                if (contentSections.length > 0) {
                    contentContainer.innerHTML = `<div class="space-y-5">${contentSections.join('')}</div>`;
                } else {
                    contentContainer.innerHTML = app.renderEmptyState('Belum ada materi.', 'secondary');
                }

                // Wire up preview toggles
                document.querySelectorAll('[data-toggle-preview]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const targetId = btn.getAttribute('data-toggle-preview');
                        const target = document.getElementById(targetId);
                        if (target) {
                            target.classList.toggle('hidden');
                        }
                    });
                });

                document.querySelectorAll('[data-close-preview]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const targetId = btn.getAttribute('data-close-preview');
                        const target = document.getElementById(targetId);
                        if (target) {
                            target.classList.add('hidden');
                        }
                    });
                });

