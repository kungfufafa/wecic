{{--
    Jadwal & Kelas script, the class detail modal: opened from a class,
    closed with Escape or the backdrop, Tab kept inside, and its meeting
    list. Part of the DOMContentLoaded handler opened in
    classes/index.blade.php: it shares that scope (app, the elements,
    constants and state in setup).
--}}
            let modalReturnFocus = null;

            const openModal = () => {
                modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                modalEl.classList.remove('hidden');
                modalEl.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
                modalCloseBtn?.focus();
            };

            const closeModal = () => {
                const wasOpen = !modalEl.classList.contains('hidden');
                modalEl.classList.add('hidden');
                modalEl.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
                if (wasOpen && modalReturnFocus?.isConnected) {
                    modalReturnFocus.focus();
                }
                modalReturnFocus = null;
                if (modalMeetingPanel) {
                    modalMeetingPanel.classList.add('hidden');
                    modalMeetingPanel.innerHTML = '';
                }
                if (modalMeetingBtn) {
                    modalMeetingBtn.textContent = 'Lihat pertemuan';
                }
            };

            const bindModalTriggers = () => {
                document.querySelectorAll('[data-class-modal-trigger]').forEach((el) => {
                    onActivate(el, () => {
                        const classId = parseInt(el.dataset.classModalTrigger, 10);
                        const c = cachedClassesData[classId];
                        if (!c) return;

                        modalCode.textContent = c.courseCode || 'KELAS';
                        modalDay.textContent = c.day || '-';
                        modalTitle.textContent = c.courseName;
                        modalTime.textContent = c.formattedTime;
                        modalDuration.textContent = `Durasi: ${formatDuration(c.durationMinutes)}`;
                        modalRoom.textContent = c.roomName;
                        modalGroup.textContent = `Kelas: ${c.className}`;
                        modalLecturer.textContent = c.lecturerName;

                        let contactsHtml = '';
                        if (c.lecturerContact) {
                            contactsHtml += `<span>Telepon/WA: <span class="font-mono text-ink">${app.escapeHtml(c.lecturerContact)}</span></span>`;
                        }
                        if (c.lecturerEmail && c.lecturerEmail !== '-') {
                            contactsHtml += `<span>Email: <span class="text-ink">${app.escapeHtml(c.lecturerEmail)}</span></span>`;
                        }
                        if (!contactsHtml) {
                            contactsHtml = '<span class="text-ink-subtle">Kontak dosen tidak tersedia</span>';
                        }
                        modalContacts.innerHTML = contactsHtml;

                        if (c.classUrl) {
                            modalOpenBtn.href = c.classUrl;
                            modalOpenBtn.classList.remove('hidden');
                        } else {
                            modalOpenBtn.classList.add('hidden');
                        }

                        currentModalDetailApi = c.detailApiUrl;
                        if (currentModalDetailApi) {
                            modalMeetingBtn.classList.remove('hidden');
                            modalMeetingBtn.textContent = 'Lihat pertemuan';
                            modalMeetingPanel.classList.add('hidden');
                            modalMeetingPanel.innerHTML = '';
                        } else {
                            modalMeetingBtn.classList.add('hidden');
                            modalMeetingPanel.classList.add('hidden');
                        }

                        openModal();
                    });
                });
            };

            if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
            if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

            document.addEventListener('keydown', (e) => {
                if (modalEl.classList.contains('hidden')) {
                    return;
                }

                if (e.key === 'Escape') {
                    closeModal();

                    return;
                }

                // Keep Tab inside the open dialog.
                if (e.key === 'Tab') {
                    const focusable = app.focusableElements(modalEl.querySelector('.mycic-modal-panel'));

                    if (!focusable.length) {
                        return;
                    }

                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];

                    if (e.shiftKey && (document.activeElement === first || !modalEl.contains(document.activeElement))) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && (document.activeElement === last || !modalEl.contains(document.activeElement))) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            });

            if (modalMeetingBtn) {
                modalMeetingBtn.addEventListener('click', async () => {
                    if (!currentModalDetailApi) return;
                    const alreadyOpen = !modalMeetingPanel.classList.contains('hidden');
                    if (alreadyOpen) {
                        modalMeetingPanel.classList.add('hidden');
                        modalMeetingBtn.textContent = 'Lihat pertemuan';
                        return;
                    }

                    modalMeetingPanel.classList.remove('hidden');
                    modalMeetingPanel.innerHTML = app.renderEmptyState('Memuat daftar pertemuan.', 'secondary');
                    modalMeetingBtn.textContent = 'Tutup pertemuan';

                    const cachedDetail = app.getCachedResponse(currentModalDetailApi);
                    if (cachedDetail) {
                        renderMeetingLinks(modalMeetingPanel, cachedDetail.payload);
                    }

                    try {
                        const payload = await app.request(currentModalDetailApi);
                        renderMeetingLinks(modalMeetingPanel, payload);
                    } catch (error) {
                        app.showNotice(error.payload?.message || error.message, 'error');
                        modalMeetingPanel.innerHTML = app.renderEmptyState('Gagal memuat daftar pertemuan.', 'danger');
                        modalMeetingBtn.textContent = 'Lihat pertemuan';
                    }
                });
            }

