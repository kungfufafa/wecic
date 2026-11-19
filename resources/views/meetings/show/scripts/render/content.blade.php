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
                const PDF_ICON = '<svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 13h6" /><path d="M9 17h3" /></svg>';

                // 1. Materi Utama
                if (materials.main_topic || materials.main_description || materials.main_material_iframe) {
                    const outlineItems = parseOutlineItems(materials.main_description);
                    let outlineMarkup = '';

                    if (outlineItems.length > 1) {
                        outlineMarkup = `
                            <ol class="mt-3 divide-y divide-border-light">
                                ${outlineItems.map(item => `
                                    <li class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                                        <span class="w-5 shrink-0 text-xs font-medium leading-5 text-ink-subtle tabular-nums">${app.escapeHtml(item.num || '•')}</span>
                                        <span class="min-w-0 flex-1 text-sm leading-snug text-ink">${app.escapeHtml(item.text)}</span>
                                    </li>
                                `).join('')}
                            </ol>
                        `;
                    } else if (materials.main_description) {
                        outlineMarkup = `
                            <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                                ${app.escapeHtml(materials.main_description)}
                            </p>
                        `;
                    }

                    let mainResourceMarkup = '';
                    if (materials.main_material_iframe) {
                        const fileUrl = app.absoluteCampusUrl(materials.main_material_iframe);
                        const downloadUrl = app.buildCampusDownloadUrl(materials.main_material_iframe);

                        mainResourceMarkup = `
                            <div class="mt-4 rounded-lg bg-surface p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="flex shrink-0 text-ink-subtle" aria-hidden="true">${PDF_ICON}</span>
                                        <span class="text-sm font-medium leading-snug text-ink">Modul PDF</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-main-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-main-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-panel">
                                    <div class="flex items-center justify-between border-b border-border-light bg-surface/60 px-3 py-1.5 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="${app.ui.button.ghostSm}" data-close-preview="preview-main-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    // The topic is the page's h1, so the card opens with the sub-topics and the module.
                    if (outlineMarkup || mainResourceMarkup) {
                        contentSections.push(`
                            <div class="${app.ui.card}" data-slot="card">
                                <div class="${app.ui.cardHeader}">
                                    <div class="${app.ui.sectionTitleRow}">
                                        <h2 class="${app.ui.cardTitle}">Materi</h2>
                                        ${outlineItems.length > 1 ? `<span class="${app.ui.sectionCount}">${outlineItems.length} sub-topik</span>` : ''}
                                    </div>
                                </div>
                                <div class="${app.ui.cardBody} [&>*:first-child]:mt-0">
                                    ${outlineMarkup}
                                    ${mainResourceMarkup}
                                </div>
                            </div>
                        `);
                    }
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
                            <div class="mt-4 rounded-lg bg-surface p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="flex shrink-0 text-ink-subtle" aria-hidden="true">${PDF_ICON}</span>
                                        <span class="text-sm font-medium leading-snug text-ink">Soal PDF</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-toggle-preview="preview-assignment-modul">Pratinjau</button>
                                        <a href="${fileUrl}" target="_blank" rel="noreferrer" class="${app.buttonClass('secondary', { small: true })}">Buka</a>
                                        ${downloadUrl ? `<a href="${downloadUrl}" class="${app.buttonClass('primary', { small: true })}">Download</a>` : ''}
                                    </div>
                                </div>
                                <div id="preview-assignment-modul" class="mt-4 hidden overflow-hidden rounded-lg border border-border bg-panel">
                                    <div class="flex items-center justify-between border-b border-border-light bg-surface/60 px-3 py-1.5 text-xs">
                                        <span class="font-medium text-ink-muted">Pratinjau</span>
                                        <button type="button" class="${app.ui.button.ghostSm}" data-close-preview="preview-assignment-modul">Tutup</button>
                                    </div>
                                    <iframe src="${fileUrl}" class="h-[540px] w-full border-0" loading="lazy"></iframe>
                                </div>
                            </div>
                        `;
                    }

                    contentSections.push(`
                        <div class="${app.ui.card}" data-slot="card">
                            <div class="${app.ui.cardHeader}">
                                <h2 class="${app.ui.cardTitle}">Tugas</h2>
                            </div>
                            <div class="${app.ui.cardBody}">
                                <div class="text-base font-medium leading-snug text-ink">${assignmentTitle}</div>
                                ${hasDeadline ? `
                                    <div class="${app.ui.alert.warning} mt-3 flex items-center gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="${app.ui.alertIcon.warning} mt-0!" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                                        <span class="text-ink-muted">Deadline:</span>
                                        <span class="font-medium text-warning tabular-nums">${app.escapeHtml(deadlineText)}</span>
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
                    contentContainer.innerHTML = `<div class="flex flex-col gap-4">${contentSections.join('')}</div>`;
                } else {
                    contentContainer.innerHTML = app.renderEmptyState('Belum ada materi.', 'secondary', '', { icon: 'file' });
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

