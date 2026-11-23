{{--
    KRS script, the cached first paint, the loaders with their sync handler,
    the countdowns refreshed when the day changes, and the first load. Part
    of the DOMContentLoaded handler opened in academic/krs.blade.php: it
    shares that scope (app, esc, hosts, state and the helpers in setup).
--}}
            primeFromCache('/api/academic/advising', receiveAdvising, 'riwayat perwalian');
            primeFromCache('/api/academic/krs', receiveKrs, 'KRS semester ini');

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                if (!(state.krs || state.advising)) {
                    app.mountErrorState(hosts.status, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    app.mountErrorState(hosts.history, 'Sesi kampus belum bisa diperiksa.', () => window.location.reload(), {
                        subtitle: 'Periksa koneksi internet, lalu coba lagi.',
                    });
                    hosts.courses.innerHTML = '';
                    hosts.schedule.innerHTML = '';
                    hosts.dpa.innerHTML = '';
                }

                return;
            }

            const loadKrs = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/academic/krs', { fresh: force, background });

                    receiveKrs(payload);

                    return payload;
                } catch (error) {
                    if (!state.krs) {
                        app.mountErrorState(hosts.status, 'KRS gagal dimuat.', () => loadPage({ force }), { subtitle: app.describeError(error) });
                        hosts.courses.innerHTML = '';
                        hosts.schedule.innerHTML = '';
                    } else {
                        app.showNotice(`KRS gagal diperbarui. ${app.describeError(error)}`, 'warning');
                    }

                    throw error;
                }
            };

            const loadAdvising = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request('/api/academic/advising', { fresh: force, background });

                    receiveAdvising(payload);

                    return payload;
                } catch (error) {
                    if (!state.advising) {
                        app.mountErrorState(hosts.history, 'Riwayat KRS gagal dimuat.', () => loadPage({ force }), { subtitle: app.describeError(error) });

                        if (!state.krs) {
                            hosts.dpa.innerHTML = '';
                        } else {
                            renderDpaCard();
                        }
                    }

                    throw error;
                }
            };

            const loadPage = async ({ force = false, background = false } = {}) => {
                const [krsResult] = await Promise.allSettled([loadKrs({ force, background }), loadAdvising({ force, background })]);

                if (krsResult.status === 'fulfilled' && krsResult.value) {
                    app.markViewSynced('KRS', krsResult.value, { force });
                } else {
                    app.setSyncStatus('KRS gagal dimuat.', 'error');
                }

                void loadLatestDetail({ background });
            };

            app.registerSyncHandler('krs-index', loadPage, { aliases: ['advising-index', 'announcement-index'] });

            // Countdowns count whole days, so they only change when the day does.
            // Re-rendering also rebuilds the action buttons: never do it while the
            // student is using them or while the DPA contact sheet is open.
            const refreshCountdowns = () => {
                if (!state.krs || state.renderedDay === todayKey()) {
                    return;
                }

                const focused = document.activeElement;
                const sheetOpen = Boolean(app.sheetState?.host && !app.sheetState.host.classList.contains('hidden'));

                if (sheetOpen || (focused && (hosts.status.contains(focused) || hosts.schedule.contains(focused)))) {
                    return;
                }

                renderStatus();
                renderSchedule();
            };

            window.setInterval(refreshCountdowns, 60000);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && state.krs) {
                    refreshCountdowns();
                }
            });

            await loadPage();
