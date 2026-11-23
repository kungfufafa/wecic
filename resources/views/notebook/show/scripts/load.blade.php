{{--
    Notebook script, the header, renderAll(), and load() with the poll that
    re-reads while a source is still being read. Part of the
    DOMContentLoaded handler opened in notebook/show.blade.php: it shares
    that scope (app, el, base, the elements, constants and state in setup).
--}}
            const renderHeader = () => {
                const notebook = state.notebook || {};
                const meetingsCount = Number(notebook.meetings_count || 0);

                el('notebook-title').textContent = notebook.title || 'Notebook';
                el('notebook-meta').textContent = [
                    notebook.kind === 'course' ? 'Notebook mata kuliah' : notebook.course_name,
                    notebook.course_code,
                    notebook.lecturer_name,
                    meetingsCount ? `${meetingsCount} pertemuan` : null,
                    `${state.sources.length} sumber`,
                ].filter(Boolean).join(' · ');
                document.title = `${notebook.title || 'Notebook'} · Notebook`;
                renderAiStatus();
                renderAiConsent();
                el('notebook-delete').textContent = notebook.kind === 'course' ? 'Kosongkan' : 'Hapus';

                const classLink = el('notebook-class-link');
                classLink.hidden = !notebook.class_url;
                classLink.href = notebook.class_url || '#';
            };

            const renderAll = () => {
                renderHeader();
                renderSources();
                renderMessages();
                renderNotes();
                renderStudioButtons();
            };

            const schedulePoll = () => {
                window.clearTimeout(state.pollTimer);

                // A file waiting for tomorrow's reading budget makes no progress today.
                const reading = state.sources
                    .filter((source) => (source.status === 'pending' || source.status === 'processing') && !source.stalled && !(source.waiting && source.error))
                    .map((source) => source.id)
                    .join(',');

                if (!reading) {
                    state.pollReading = '';
                    state.pollStartedAt = null;

                    return;
                }

                // Any progress (a source added, read, or read again) starts a fresh polling window.
                if (reading !== state.pollReading) {
                    state.pollReading = reading;
                    state.pollStartedAt = Date.now();
                }

                const idle = Date.now() - state.pollStartedAt;

                if (idle < POLL_LIMIT_MS) {
                    state.pollTimer = window.setTimeout(() => load({ quiet: true, background: true }), idle < POLL_SLOW_AFTER_MS ? 4000 : 20000);
                }
            };

            const receive = (data) => {
                state.notebook = data.notebook;
                state.sources = Array.isArray(data.sources) ? data.sources : [];
                state.messages = Array.isArray(data.messages) ? data.messages : [];
                state.chatFromId = Number.isInteger(data.chat_from_id) ? data.chat_from_id : null;
                state.meetings = data.meetings && typeof data.meetings === 'object' ? data.meetings : null;
                state.archivedCourses = Array.isArray(data.archived_courses) ? data.archived_courses : [];
                state.studioKinds = data.studio_kinds || {};
                state.sourceLimit = Number.isFinite(Number(data.source_limit)) ? Number(data.source_limit) : null;
                setAiStatus(data);
                setAiConsent(data);
                setAiBudget(data);

                const known = new Set(state.sources.map((source) => source.id));
                state.excluded.forEach((id) => {
                    if (!known.has(id)) {
                        state.excluded.delete(id);
                    }
                });
            };

            const load = async ({ quiet = false, background = false } = {}) => {
                try {
                    const payload = await app.request(base, { background });

                    if (!payload?.data) {
                        return;
                    }

                    receive(payload.data);

                    if (quiet) {
                        renderHeader();
                        renderSources();
                        renderStudioButtons();
                    } else {
                        renderAll();
                    }

                    app.setSyncStatus('Notebook tersimpan di WeCIC.', 'info');
                    schedulePoll();
                } catch (error) {
                    if (error.status === 404) {
                        app.storeNotice('Notebook tidak ditemukan.', 'warning');
                        window.location.href = '/notebook';

                        return;
                    }

                    if (!quiet) {
                        sourcesHost.removeAttribute('aria-busy');
                        app.mountErrorState(messagesHost, 'Notebook gagal dimuat.', () => load(), { subtitle: app.describeError(error) });
                    }
                }
            };

