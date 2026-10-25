import * as urlRules from '../lib/urls.js';

/**
 * Links to the campus portal and MyCIC's own pages (rules in lib/urls.js),
 * and the download links held back offline.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class LinkFeature {
    absoluteCampusUrl(path) {
        return urlRules.absoluteCampusUrl(path, this.campusBase);
    }

    buildCampusDownloadUrl(path, filename, options) {
        return urlRules.buildCampusDownloadUrl(path, filename, options);
    }

    /**
     * Download links (buildCampusDownloadUrl) answer a failure with JSON, so
     * opened in the app's own tab they would replace the page with it. They
     * are held back when they cannot work, and otherwise open in a new tab:
     * a file still downloads, an error no longer takes the app with it.
     * The installed app keeps them in its own window: on iOS a new tab opens
     * in a browser that does not share the app's cookies, so the download
     * would arrive signed out.
     */
    bindDownloadLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

            if (!link) {
                return;
            }

            let url;

            try {
                url = new URL(link.getAttribute('href'), window.location.origin);
            } catch (error) {
                return;
            }

            if (url.origin !== window.location.origin || url.pathname !== '/api/support/download') {
                return;
            }

            if (this.isPortalOfflineSession() || !navigator.onLine) {
                event.preventDefault();
                this.showNotice(
                    this.isPortalOfflineSession()
                        ? `Unduhan belum tersedia di mode offline. ${this.portalOfflineReconnectMessage()}`
                        : 'Unduhan butuh koneksi internet.',
                    'warning'
                );

                return;
            }

            if (!link.target && !link.hasAttribute('download') && !this.isInstalledApp()) {
                link.target = '_blank';
                link.rel = 'noopener';
            }
        }, true);
    }

    /**
     * Whether MyCIC runs as the installed (home-screen) app rather than in a
     * browser tab.
     */
    isInstalledApp() {
        try {
            return window.navigator.standalone === true
                || Boolean(window.matchMedia?.('(display-mode: standalone)').matches);
        } catch (error) {
            return false;
        }
    }

    buildMeetingUrlFromHref(href) {
        return urlRules.buildMeetingUrlFromHref(href);
    }

    buildClassUrl(id, dosenId, courseId) {
        return urlRules.buildClassUrl(id, dosenId, courseId);
    }

    parseQueryString(value) {
        return urlRules.parseQueryString(value, this.campusBase);
    }

    extractAssignmentShortcut(value) {
        return urlRules.extractAssignmentShortcut(value, this.campusBase);
    }

    extractAttendanceShortcut(value) {
        return urlRules.extractAttendanceShortcut(value, this.campusBase);
    }

    /**
     * Only links a student can safely follow: same-site paths, http(s),
     * mailto and tel. Anything else (javascript:, data:) gives null.
     */
    safeHref(url) {
        return urlRules.safeHref(url, window.location.origin);
    }

    isExternalHref(url) {
        return urlRules.isExternalHref(url, window.location.origin);
    }
}
