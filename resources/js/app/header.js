/**
 * The page header: the light/dark theme toggle and the account and
 * "Lainnya" dropdown menus.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class HeaderFeature {
    bindThemeToggle() {
        this.refreshThemeToggleState();

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

                this.setTheme(nextTheme);
            });
        });

        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
                const storedTheme = localStorage.getItem(this.themeStorageKey);

                if (storedTheme !== 'dark' && storedTheme !== 'light') {
                    this.setTheme(event.matches ? 'dark' : 'light', { persist: false });
                }
            });
        }
    }

    setTheme(theme, { persist = true } = {}) {
        document.documentElement.dataset.theme = theme;

        if (persist) {
            localStorage.setItem(this.themeStorageKey, theme);
        }

        this.updateThemeColorMeta();
        this.refreshThemeToggleState();
    }

    updateThemeColorMeta() {
        const meta = document.querySelector('meta[name="theme-color"]');

        if (meta) {
            meta.setAttribute('content', document.documentElement.dataset.theme === 'dark' ? '#111113' : '#ffffff');
        }
    }

    refreshThemeToggleState() {
        const isDark = document.documentElement.dataset.theme === 'dark';

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            button.setAttribute('aria-label', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
        });
    }

    /**
     * Account menu and the desktop "Lainnya" menu: one open at a time,
     * closed by an outside click or Escape.
     */
    bindAccountMenu() {
        const panelSelector = '[data-account-menu-panel], [data-dropdown-panel]';
        const triggerSelector = '[data-account-menu-trigger], [data-dropdown-trigger]';
        const closeAll = () => {
            document.querySelectorAll(panelSelector).forEach((panel) => panel.classList.add('hidden'));
            document.querySelectorAll(triggerSelector).forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
        };

        document.querySelectorAll('[data-account-menu], [data-dropdown]').forEach((menu) => {
            const trigger = menu.querySelector(triggerSelector);
            const panel = menu.querySelector(panelSelector);

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const isOpen = !panel.classList.contains('hidden');

                closeAll();

                if (isOpen) {
                    return;
                }

                panel.classList.remove('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            });

            document.addEventListener('click', (event) => {
                if (menu.contains(event.target)) {
                    return;
                }

                panel.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            const openTrigger = document.querySelector(
                '[data-account-menu-trigger][aria-expanded="true"], [data-dropdown-trigger][aria-expanded="true"]',
            );

            if (openTrigger) {
                closeAll();
                openTrigger.focus();
            }
        });
    }

    /**
     * Two-tier desktop sidebar: collapse/expand toggle, contextual trail panels,
     * and quick `/` menu filter.
     */
    bindSidebar() {
        this.refreshSidebarToggleState();

        const panel = document.querySelector('[data-sidebar-panel]');

        if (panel) {
            this.applySidebarContext(panel.dataset.sidebarActiveContext || 'beranda');
        }

        document.querySelectorAll('[data-rail-has-trail="1"]').forEach((railItem) => {
            railItem.addEventListener('click', (event) => {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                if (document.documentElement.dataset.sidebarCollapsed !== '1') {
                    return;
                }

                this.setSidebarCollapsed(false);

                const currentPath = window.location.pathname;
                const targetUrl = new URL(railItem.getAttribute('href') || '/', window.location.origin);

                if (currentPath === targetUrl.pathname && (!targetUrl.hash || window.location.hash === targetUrl.hash)) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const currentPanel = document.querySelector('[data-sidebar-panel]');

                if (currentPanel && currentPanel.dataset.sidebarHasTrail === '0') {
                    if (currentPanel.classList.contains('hidden')) {
                        this.openNoTrailPanel(currentPanel);
                    } else {
                        this.applySidebarContext(currentPanel.dataset.sidebarActiveContext || 'beranda');
                    }

                    return;
                }

                const collapsed = document.documentElement.dataset.sidebarCollapsed === '1';

                this.setSidebarCollapsed(!collapsed);
            });
        });

        const searchInput = document.querySelector('[data-sidebar-search]');

        if (searchInput) {
            const onSearchChange = () => {
                this.filterSidebarMenu(searchInput.value);
            };

            searchInput.addEventListener('input', onSearchChange);
            searchInput.addEventListener('search', onSearchChange);
            searchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    const hadValue = searchInput.value !== '';
                    const isNoTrailPage = document.querySelector('[data-sidebar-panel]')?.dataset.sidebarHasTrail === '0';

                    if (hadValue || isNoTrailPage) {
                        event.preventDefault();
                        searchInput.value = '';
                        this.filterSidebarMenu('');
                    }

                    searchInput.blur?.();

                    return;
                }

                if (event.key === 'Enter') {
                    const panelScope = document.querySelector('[data-sidebar-panel]') || document;
                    const firstMatch = panelScope.querySelector('[data-sidebar-match="1"]');

                    if (firstMatch) {
                        event.preventDefault();

                        if (typeof firstMatch.click === 'function') {
                            firstMatch.click();
                        } else {
                            firstMatch.dispatchEvent(new Event('click', { bubbles: true, cancelable: true }));
                        }
                    }
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const target = event.target;
            const tagName = target?.tagName;

            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT' || target?.isContentEditable === true) {
                return;
            }

            const sheet = document.querySelector('[data-app-sheet]');
            const qrModal = document.querySelector('[data-qr-scanner-modal]');
            const hasOpenDialog = (sheet && !sheet.classList.contains('hidden'))
                || (qrModal && !qrModal.classList.contains('hidden'))
                || Array.from(document.querySelectorAll('[aria-modal="true"]')).some((dialog) => {
                    const parent = dialog.parentElement;

                    return !dialog.classList.contains('hidden') && (!parent || !parent.classList.contains('hidden'));
                });

            if (hasOpenDialog) {
                return;
            }

            const sidebarSearch = document.querySelector('[data-sidebar-search]');

            if (!sidebarSearch) {
                return;
            }

            event.preventDefault();

            if (document.documentElement.dataset.sidebarCollapsed === '1') {
                this.setSidebarCollapsed(false);
            }

            const sidebarPanel = document.querySelector('[data-sidebar-panel]');

            if (sidebarPanel && sidebarPanel.dataset.sidebarHasTrail === '0') {
                this.openNoTrailPanel(sidebarPanel);
            }

            sidebarSearch.focus();
        });
    }

    openNoTrailPanel(panel) {
        if (!panel) {
            return;
        }

        if (document.documentElement.dataset.sidebarCollapsed === '1') {
            this.setSidebarCollapsed(false);
        }

        const titleNode = panel.querySelector('[data-sidebar-context-title]');

        if (titleNode && panel.dataset.sidebarDefaultTitle === undefined) {
            panel.dataset.sidebarDefaultTitle = titleNode.textContent?.trim() || '';
        }

        const fallbackTrail = panel.querySelector('[data-sidebar-trail="more"]');

        if (fallbackTrail) {
            if (titleNode) {
                titleNode.textContent = 'Lainnya';
            }

            Array.from(panel.querySelectorAll('[data-sidebar-trail]')).forEach((trail) => {
                const show = trail === fallbackTrail;

                trail.classList.toggle('hidden', !show);
                trail.classList.toggle('flex', show);
            });
        }

        panel.classList.remove('hidden');
        panel.classList.add('flex');

        const shell = document.querySelector('[data-app-shell]');

        if (shell) {
            shell.classList.remove('lg:ps-12');
            shell.classList.add('lg:ps-72');
        }

        this.refreshSidebarToggleState();
    }

    applySidebarContext(context = 'beranda') {
        const panel = document.querySelector('[data-sidebar-panel]');

        if (!panel) {
            return;
        }

        const activeContext = context || panel.dataset.sidebarActiveContext || 'beranda';
        const hasTrail = panel.dataset.sidebarHasTrail !== '0';
        const trails = Array.from(panel.querySelectorAll('[data-sidebar-trail]'));
        const emptyState = panel.querySelector('[data-sidebar-search-empty]');
        const titleNode = panel.querySelector('[data-sidebar-context-title]');

        if (titleNode && panel.dataset.sidebarDefaultTitle !== undefined) {
            titleNode.textContent = panel.dataset.sidebarDefaultTitle;
        }

        emptyState?.classList.add('hidden');

        trails.forEach((trail) => {
            trail.querySelectorAll('a, p, [role="group"]').forEach((node) => {
                node.classList.remove('hidden');
                node.removeAttribute('data-sidebar-match');
            });

            const isActiveTrail = trail.dataset.sidebarTrail === activeContext;

            trail.classList.toggle('hidden', !isActiveTrail);
            trail.classList.toggle('flex', isActiveTrail);
        });

        const shell = document.querySelector('[data-app-shell]');

        if (!hasTrail) {
            panel.classList.add('hidden');
            panel.classList.remove('flex');

            if (shell) {
                shell.classList.remove('lg:ps-72');
                shell.classList.add('lg:ps-12');
            }

            this.refreshSidebarToggleState();

            return;
        }

        panel.classList.remove('hidden');
        panel.classList.add('flex');

        if (shell) {
            shell.classList.remove('lg:ps-12');
            shell.classList.add('lg:ps-72');
        }

        this.refreshSidebarToggleState();
    }

    setSidebarCollapsed(collapsed, { persist = true } = {}) {
        const nextCollapsed = Boolean(collapsed);

        if (nextCollapsed) {
            document.documentElement.dataset.sidebarCollapsed = '1';
        } else {
            delete document.documentElement.dataset.sidebarCollapsed;
        }

        if (persist) {
            try {
                if (nextCollapsed) {
                    localStorage.setItem(this.sidebarCollapsedKey, '1');
                } else {
                    localStorage.removeItem(this.sidebarCollapsedKey);
                }
            } catch (error) {
            }
        }

        this.refreshSidebarToggleState();
    }

    refreshSidebarToggleState() {
        const panel = document.querySelector('[data-sidebar-panel]');
        const isNoTrailClosed = Boolean(panel && panel.dataset.sidebarHasTrail === '0' && panel.classList.contains('hidden'));
        const isCollapsed = document.documentElement.dataset.sidebarCollapsed === '1' || isNoTrailClosed;
        const label = isCollapsed ? 'Perluas sidebar' : 'Ciutkan sidebar';

        document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
            button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        });
    }

    filterSidebarMenu(rawQuery = '') {
        const panel = document.querySelector('[data-sidebar-panel]');

        if (!panel) {
            return;
        }

        const query = String(rawQuery ?? '').trim().toLowerCase();
        const kbd = panel.querySelector('[data-sidebar-search-kbd]') || document.querySelector('[data-sidebar-search-kbd]');
        const emptyState = panel.querySelector('[data-sidebar-search-empty]');

        if (kbd) {
            kbd.classList.toggle('hidden', query !== '');
        }

        if (query === '') {
            this.applySidebarContext(panel.dataset.sidebarActiveContext || 'beranda');

            return;
        }

        panel.classList.remove('hidden');
        panel.classList.add('flex');

        const matchesText = (element) => element.textContent.toLowerCase().includes(query);
        const activeContext = panel.dataset.sidebarActiveContext || 'beranda';
        const trails = Array.from(panel.querySelectorAll('[data-sidebar-trail]'));
        const activeTrail = trails.find((trail) => trail.dataset.sidebarTrail === activeContext)
            || trails.find((trail) => trail.dataset.sidebarTrail === 'more')
            || null;
        let visibleCount = 0;

        trails.forEach((trail) => {
            if (trail !== activeTrail) {
                trail.querySelectorAll('a').forEach((link) => link.removeAttribute('data-sidebar-match'));
                trail.classList.add('hidden');
                trail.classList.remove('flex');

                return;
            }

            const groups = Array.from(trail.querySelectorAll('[role="group"]'));
            const groupedLinks = new Set();
            const groupedLabels = new Set();
            let trailMatches = 0;

            groups.forEach((group) => {
                group.querySelectorAll('p').forEach((label) => groupedLabels.add(label));

                const groupLinks = Array.from(group.querySelectorAll('a'));
                let groupMatches = 0;

                groupLinks.forEach((link) => {
                    groupedLinks.add(link);

                    const matches = matchesText(link);

                    link.classList.toggle('hidden', !matches);

                    if (matches) {
                        link.setAttribute('data-sidebar-match', '1');
                        groupMatches += 1;
                        trailMatches += 1;
                        visibleCount += 1;
                    } else {
                        link.removeAttribute('data-sidebar-match');
                    }
                });

                group.classList.toggle('hidden', groupMatches === 0);
            });

            const ungroupedLinks = Array.from(trail.querySelectorAll('a'))
                .filter((link) => !groupedLinks.has(link));
            let ungroupedMatches = 0;

            ungroupedLinks.forEach((link) => {
                const matches = matchesText(link);

                link.classList.toggle('hidden', !matches);

                if (matches) {
                    link.setAttribute('data-sidebar-match', '1');
                    ungroupedMatches += 1;
                    trailMatches += 1;
                    visibleCount += 1;
                } else {
                    link.removeAttribute('data-sidebar-match');
                }
            });

            Array.from(trail.querySelectorAll('p')).forEach((label) => {
                if (!groupedLabels.has(label)) {
                    label.classList.toggle('hidden', ungroupedMatches === 0);
                }
            });

            trail.classList.toggle('hidden', trailMatches === 0);
            trail.classList.toggle('flex', trailMatches > 0);
        });

        emptyState?.classList.toggle('hidden', visibleCount > 0);
    }
}
