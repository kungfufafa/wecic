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
            const SOURCE_KIND = { meeting: 'Catatan pertemuan', archived_file: 'Arsip materi', upload: 'Unggahan', text: 'Teks' };
            // The student's own notes (NotebookMessage::MAX_NOTE_CHARS).
            const NOTE_MAX_CHARS = @json(\App\Models\NotebookMessage::MAX_NOTE_CHARS);
            // The empty chat's starters until the notebook loads (`suggestions` then: the ready sources' guide questions).
            const STARTER_QUESTIONS = @json(\App\Services\NotebookAssistant::STARTER_QUESTIONS);
            // In tutor mode the starters ask to be guided, never for a finished answer.
            const TUTOR_SUGGESTIONS = [
                'Tuntun aku memahami konsep utama materi ini langkah demi langkah.',
                'Uji dulu pemahamanku tentang materi ini dengan satu pertanyaan.',
                'Aku bingung di bagian tersulit; beri petunjuk pelan-pelan.',
                'Beri contoh soal serupa untuk latihan, lalu cek jawabanku.',
            ];
            // answer, tutor or ringkas (NotebookAssistant::ASK_MODES), remembered per notebook in this browser.
            const ASK_MODES = @json(\App\Services\NotebookAssistant::ASK_MODES);
            const ASK_MODE_KEY = `mycic-notebook-ask-mode:${notebookId}`;
            const storedAskMode = () => {
                try {
                    const stored = window.localStorage.getItem(ASK_MODE_KEY);

                    return Object.hasOwn(ASK_MODES, stored) ? stored : 'answer';
                } catch {
                    return 'answer';
                }
            };

            const state = {
                notebook: null,
                sources: [],
                messages: [],
                // Chat messages older than this are only in the payload as saved notes.
                chatFromId: null,
                meetings: null,
                archivedCourses: [],
                studioKinds: {},
                // The empty chat's starter questions (`suggestions`), shown as text.
                suggestions: STARTER_QUESTIONS,
                sourceLimit: null,
                aiAvailable: true,
                // ok, disabled, missing_key, invalid_key or model_misconfigured (NotebookAssistant::aiStatus()).
                aiStatus: 'ok',
                // Whether the student agreed to send Notebook content to the AI provider(s) (`ai_consent`); null until a response says.
                aiConsent: null,
                // The AI provider(s) as students read them (`ai_consent.provider`).
                aiProviderLabel: @json($notebookAi['label']),
                // What the consent text rendered on this page is for (`consent_key`), sent with the consent so a
                // provider switched since the page loaded is not agreed to unseen.
                aiConsentKey: @json($notebookAi['consent_key']),
                busy: false,
                pollTimer: null,
                pollStartedAt: null,
                pollReading: '',
                tab: 'chat',
                // Sources left out of the context (NotebookLM-style source ticks); empty = everything.
                // Kept on the server (`context_source_ids`) so the app opens with the same choice.
                excluded: new Set(),
                // The `context_source_ids` last taken from the server, so a poll that brings the same choice back leaves the ticks alone.
                contextChoiceKey: null,
                // What the server has of each own note (`kind: note`), so leaving its editor saves only a change.
                noteSaved: {},
                // The save of each own note still on its way.
                noteSaving: {},
                askMode: storedAskMode(),
                // The Catatan entries kept open when the list is redrawn (a new Studio output opens itself).
                openNoteIds: new Set(),
                // Practice on a quiz or flashcards by message id, so a redraw (renderNotes) keeps the progress:
                // quiz {choices, flagged, reviewing}; flashcards {mode, order, position, flipped, known, again}.
                practice: {},
                // What is left of today's AI requests (`ai_budget`); null until a response says.
                aiBudget: null,
            };

            // A bare 502/504 (no message from MyCIC) means the web server gave up before the model answered;
            // MyCIC's own 502s ("Hasilnya terlalu panjang…") say what to do instead.
            const describeAiError = (error, fallback) => ((error?.status === 504 || error?.status === 502) && !error?.payload?.message
                ? 'Model AI tidak menjawab dalam batas waktu server (sedang sibuk). Tunggu sebentar lalu coba lagi.'
                : app.describeError(error, fallback));

            const setAiBudget = (data) => {
                if (data?.ai_budget && Number.isFinite(Number(data.ai_budget.remaining))) {
                    state.aiBudget = { limit: Number(data.ai_budget.limit), remaining: Number(data.ai_budget.remaining) };
                }
            };

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

            const setAiConsent = (data) => {
                if (data?.ai_consent && typeof data.ai_consent === 'object') {
                    state.aiConsent = Boolean(data.ai_consent.granted);

                    if (typeof data.ai_consent.provider === 'string' && data.ai_consent.provider !== '') {
                        state.aiProviderLabel = data.ai_consent.provider;
                    }
                }
            };

            const renderAiConsent = () => {
                el('notebook-ai-consent').classList.toggle('hidden', state.aiConsent !== false);
                el('notebook-ai-consent-granted').classList.toggle('hidden', state.aiConsent !== true);
            };

            const showAiConsent = () => {
                const panel = el('notebook-ai-consent');

                renderAiConsent();
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                panel.focus({ preventScroll: true });
            };

            // An AI action before the student agreed shows the consent step instead of sending anything.
            const needsAiConsent = () => {
                if (state.aiConsent !== false) {
                    return false;
                }

                showAiConsent();
                app.showNotice(`Setujui dulu pengiriman data ke ${state.aiProviderLabel} untuk memakai AI Notebook.`, 'warning');

                return true;
            };

            // A question the server could not answer because of its AI settings says which, so the banner follows;
            // one refused for a missing consent brings the consent step back.
            const noteAiStatus = (error) => {
                if (error?.payload?.reason === 'ai_consent_required') {
                    setAiConsent(error.payload.data);
                    state.aiConsent = false;
                    showAiConsent();

                    return;
                }

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

