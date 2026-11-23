{{--
    KRS script, renderAll(), the KRS and advising payloads received, and the
    latest KRS detail read once in the background (Baru/Ulang). Part of the
    DOMContentLoaded handler opened in academic/krs.blade.php: it shares
    that scope (app, esc, hosts, state and the helpers in setup).
--}}
            const renderAll = () => {
                renderStatus();
                renderSchedule();
                renderNotes();
                renderCourses();
                renderDpaCard();
                renderHistory();
            };

            const receiveKrs = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.krs = payload.data;
                state.krsMeta = payload.meta || {};
                renderAll();
            };

            const receiveAdvising = (payload) => {
                if (!payload?.data) {
                    return;
                }

                state.advising = payload.data;
                state.advisingMeta = payload.meta || {};

                const latestId = latestKrsId();
                const cachedDetail = latestId ? cachedData(`/api/academic/krs/${latestId}`) : null;

                if (cachedDetail?.found) {
                    state.detail = cachedDetail;
                }

                renderAll();
            };

            /**
             * Baru/Ulang only appears on the KRS detail page: read it once in
             * the background, after the page has rendered.
             */
            const loadLatestDetail = async ({ background = false } = {}) => {
                const latestId = latestKrsId();

                if (!latestId || state.detailRequested || (state.detail?.krs_id === latestId) || !state.krs?.courses?.length || !navigator.onLine) {
                    return;
                }

                state.detailRequested = true;

                try {
                    const payload = await app.request(`/api/academic/krs/${latestId}`, { redirectOnAuth: false, timeout: 20000, background });

                    if (payload?.data?.found) {
                        state.detail = payload.data;
                        renderCourses();
                        renderHistory();
                    }
                } catch (error) {
                    // Optional detail: the list stays without Baru/Ulang chips.
                }
            };

