{{--
    Notebook script, the page's elements, the source and polling constants,
    the shared state, the AI status banners, and the helpers for the sources
    in context. Every later part uses them. Part of the DOMContentLoaded
    handler opened in notebook/show.blade.php: it shares that scope (app,
    el, base, the elements, constants and state in setup).
--}}
            const app = window.portalApp;
            const notebookId = @json($notebookId);
            const base = `/api/notebooks/${notebookId}`;
            const el = (id) => document.getElementById(id);
            const sourcesHost = el('notebook-sources');
            const messagesHost = el('notebook-messages');
            const notesHost = el('notebook-notes');
            const studioButtons = el('notebook-studio-buttons');
            const askForm = el('notebook-ask-form');
            const questionInput = el('notebook-question');

            const SOURCE_STATUS = {
                pending: { label: 'Menunggu', tone: 'neutral' },
                processing: { label: 'Dibaca…', tone: 'info' },
                ready: { label: 'Siap', tone: 'success' },
                failed: { label: 'Gagal', tone: 'danger' },
                stalled: { label: 'Terhenti', tone: 'warning' },
            };
            // Reading a source takes minutes, but a busy queue may hold it longer: without progress the page
            // asks less often, and stops after the hour in which the server marks a lost reading stalled.
            const POLL_LIMIT_MS = 60 * 60 * 1000;
            const POLL_SLOW_AFTER_MS = 2 * 60 * 1000;
            const SOURCE_KIND = { meeting: 'Catatan pertemuan', archived_file: 'Arsip materi', upload: 'Unggahan', text: 'Catatan' };
            const SUGGESTIONS = [
                'Ringkas inti materi ini dalam 5 poin.',
                'Konsep apa yang paling penting untuk ujian?',
                'Jelaskan bagian tersulit dengan contoh sederhana.',
                'Tugas apa saja yang ada di pertemuan-pertemuan ini dan kapan tenggatnya?',
            ];

            const state = {
                notebook: null,
                sources: [],
                messages: [],
                // Chat messages older than this are only in the payload as saved notes.
                chatFromId: null,
                meetings: null,
                archivedCourses: [],
                studioKinds: {},
                sourceLimit: null,
                aiAvailable: true,
                // ok, disabled, missing_key, invalid_key or model_misconfigured (NotebookAssistant::aiStatus()).
                aiStatus: 'ok',
                busy: false,
                pollTimer: null,
                pollStartedAt: null,
                pollReading: '',
                tab: 'chat',
                // Sources left out of the context (NotebookLM-style source ticks); empty = everything.
                excluded: new Set(),
            };

            // A bare 502/504 (no message from MyCIC) means the web server gave up before the model answered;
            // MyCIC's own 502s ("Hasilnya terlalu panjang…") say what to do instead.
            const describeAiError = (error, fallback) => ((error?.status === 504 || error?.status === 502) && !error?.payload?.message
                ? 'Model AI tidak menjawab dalam batas waktu server (sedang sibuk). Tunggu sebentar lalu coba lagi.'
                : app.describeError(error, fallback));

            // Older responses carry only ai_available.
            const setAiStatus = (data) => {
                state.aiStatus = data?.ai_status || (data?.ai_available === false ? 'missing_key' : 'ok');
                state.aiAvailable = state.aiStatus === 'ok';
            };

            const renderAiStatus = () => {
                document.querySelectorAll('[data-ai-status-banner]').forEach((banner) => {
                    banner.classList.toggle('hidden', banner.dataset.aiStatusBanner !== state.aiStatus);
                });
            };

            // A question the server could not answer because of its AI settings says which, so the banner follows.
            const noteAiStatus = (error) => {
                if (!error?.payload?.data?.ai_status) {
                    return;
                }

                setAiStatus(error.payload.data);
                renderAiStatus();
                renderStudioButtons();
            };

            const formatChars = (chars) => (chars >= 1000 ? `${(chars / 1000).toFixed(chars >= 10000 ? 0 : 1).replace('.', ',')} rb` : String(chars)) + ' karakter';

            const readySources = () => state.sources.filter((source) => source.status === 'ready');
            const contextSources = () => readySources().filter((source) => !state.excluded.has(source.id));
            const contextSourceIds = () => (state.excluded.size ? contextSources().map((source) => source.id) : []);
            // Every ready source unticked: an empty selection would make the server read them all.
            const noContextChosen = () => state.excluded.size > 0 && contextSources().length === 0;
            const isCourseNotebook = () => state.notebook?.kind === 'course';

