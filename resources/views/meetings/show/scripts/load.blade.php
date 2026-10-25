{{--
    Pertemuan script, the cached first paint, loadMeeting() with its sync
    handler, and the first load. Part of the DOMContentLoaded handler opened
    in meetings/show.blade.php: it shares that scope (app, meetingId,
    coursesId, endpoint and the constants in setup).
--}}
            app.primeCachedView(endpoint, render, 'detail meeting');

            const ready = await app.ensureAuthenticated();
            if (!ready) {
                return;
            }

            const loadMeeting = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(endpoint, { fresh: force, background });

                    // Snapshots built before the server learned to flag empty
                    // pages carry no `found`; judge those by their content.
                    const detail = payload?.class_detail || {};
                    const hasContent = [
                        detail.meeting_info?.breadcrumb,
                        detail.lecturer_info?.name,
                        detail.schedule_info?.date,
                        detail.materials?.main_topic,
                        detail.materials?.assignment_topic,
                        detail.attendance_info?.status,
                    ].some((value) => String(value || '').trim() !== '');

                    if (detail.found === false || !hasContent) {
                        renderNotFound('Pertemuan ini tidak ada di portal, atau link yang kamu buka sudah tidak berlaku.');

                        return;
                    }

                    render(payload);
                    app.markViewSynced('Detail meeting', payload, { force });
                } catch (error) {
                    if (error.status === 404) {
                        renderNotFound(app.describeError(error));

                        return;
                    }

                    app.setSyncStatus('Detail pertemuan gagal dimuat.', 'error');
                    app.mountErrorState(
                        document.getElementById('meeting-content'),
                        'Detail pertemuan gagal dimuat.',
                        () => loadMeeting({ force }),
                        { subtitle: app.describeError(error) }
                    );
                    document.getElementById('meeting-actions').innerHTML = '';
                }
            };

            app.registerSyncHandler('meeting-show', loadMeeting);

            await loadMeeting();
