{{--
    Jadwal & Kelas script, the page's elements and the modal's,
    onActivate(), the day and view-mode constants, and the shared state
    (view mode, day filter, search, the parsed classes). Every later part
    uses them. Part of the DOMContentLoaded handler opened in
    classes/index.blade.php: it shares that scope (app, the elements,
    constants and state in setup).
--}}
            const app = window.portalApp;
            const endpoint = '/api/class';
            const summaryCards = document.querySelectorAll('[data-summary-value]');
            const activeDaysMetric = document.getElementById('summary-active-days');
            const totalHoursMetric = document.getElementById('summary-total-hours');
            const todayMetric = document.getElementById('summary-today');
            const todayNote = document.getElementById('summary-today-note');
            const dayStripHost = document.getElementById('day-summary-strip');
            const searchInput = document.getElementById('class-search-input');
            const blockingGridHost = document.getElementById('blocking-grid-container');
            const timelineHost = document.getElementById('timeline-container');
            const listHost = document.getElementById('class-list');
            const unscheduledHost = document.getElementById('unscheduled-classes-container');
            const viewTabs = document.querySelectorAll('[data-view-target]');
            const viewPanels = {
                blocking: document.getElementById('view-blocking'),
                timeline: document.getElementById('view-timeline'),
                list: document.getElementById('view-list'),
            };

            // Modal elements
            const modalEl = document.getElementById('class-detail-modal');
            const modalCloseBtn = document.getElementById('class-modal-close');
            const modalBackdrop = document.getElementById('class-modal-backdrop');
            const modalCode = document.getElementById('modal-class-code');
            const modalDay = document.getElementById('modal-class-day');
            const modalTitle = document.getElementById('modal-class-title');
            const modalTime = document.getElementById('modal-class-time');
            const modalDuration = document.getElementById('modal-class-duration');
            const modalRoom = document.getElementById('modal-class-room');
            const modalGroup = document.getElementById('modal-class-group');
            const modalLecturer = document.getElementById('modal-class-lecturer');
            const modalContacts = document.getElementById('modal-class-contacts');
            const modalOpenBtn = document.getElementById('modal-btn-open-class');
            const modalMeetingBtn = document.getElementById('modal-btn-toggle-meeting');
            const modalOpenWrap = modalEl.querySelector('[data-modal-open-wrap]');
            const modalMeetingWrap = modalEl.querySelector('[data-modal-meeting-wrap]');
            const modalMeetingPanel = document.getElementById('modal-meeting-panel');

            // Grid blocks and day tiles are role="button" divs: Enter and Space act like a click.
            const onActivate = (el, handler) => {
                el.addEventListener('click', handler);
                el.addEventListener('keydown', (event) => {
                    if (event.target === el && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        handler();
                    }
                });
            };

            const DAYS_LIST = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const INDO_DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const TODAY_DAY = INDO_DAYS[new Date().getDay()];

            const VIEW_MODES = ['blocking', 'timeline', 'list'];
            const requestedView = new URLSearchParams(window.location.search).get('view');
            let currentViewMode = 'blocking';
            let selectedDayFilter = 'all';

            try {
                const storedView = localStorage.getItem('mycic-class-view-mode');
                currentViewMode = VIEW_MODES.includes(storedView) ? storedView : 'blocking';
            } catch (error) {
                currentViewMode = 'blocking';
            }

            // `?view=today` (the "Jadwal hari ini" shortcut): today's classes as a list,
            // without overwriting the view the student picked before.
            let persistViewMode = true;

            if (requestedView === 'today') {
                currentViewMode = 'list';
                selectedDayFilter = DAYS_LIST.includes(TODAY_DAY) ? TODAY_DAY : 'all';
                persistViewMode = false;
            } else if (VIEW_MODES.includes(requestedView)) {
                currentViewMode = requestedView;
                persistViewMode = false;
            }
            let currentSearchQuery = '';
            let cachedClassesData = [];
            let cachedPayload = null;
            let currentModalDetailApi = null;

