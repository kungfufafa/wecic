{{--
    KRS script, the Semester ini and Riwayat & DPA tabs, with arrow keys and
    the ?tab= URL. Part of the DOMContentLoaded handler opened in
    academic/krs.blade.php: it shares that scope (app, esc, hosts, state and
    the helpers in setup).
--}}
            const tabs = Array.from(document.querySelectorAll('[data-krs-tab]'));
            const panels = {
                semester: document.getElementById('krs-panel-semester'),
                riwayat: document.getElementById('krs-panel-riwayat'),
            };

            const selectTab = (name, { focus = false } = {}) => {
                tabs.forEach((tab) => {
                    const active = tab.dataset.krsTab === name;

                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.tabIndex = active ? 0 : -1;

                    if (active && focus) {
                        tab.focus();
                    }
                });

                Object.entries(panels).forEach(([key, panel]) => {
                    panel.hidden = key !== name;
                });

                try {
                    const url = new URL(window.location.href);

                    if (name === 'riwayat') {
                        url.searchParams.set('tab', 'riwayat');
                    } else {
                        url.searchParams.delete('tab');
                    }

                    window.history.replaceState(window.history.state, '', url.toString());
                } catch (error) {
                    // The URL is a convenience only.
                }
            };

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => selectTab(tab.dataset.krsTab));
                tab.addEventListener('keydown', (event) => {
                    const moves = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 };

                    if (!(event.key in moves)) {
                        return;
                    }

                    event.preventDefault();
                    const target = tabs[(moves[event.key] + tabs.length) % tabs.length];

                    selectTab(target.dataset.krsTab, { focus: true });
                });
            });

