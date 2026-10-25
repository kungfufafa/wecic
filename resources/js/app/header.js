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
            meta.setAttribute('content', document.documentElement.dataset.theme === 'dark' ? '#091019' : '#0352A1');
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
}
