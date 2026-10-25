import { ActionItemFeature } from './app/action-items.js';
import { AnnouncementFeature } from './app/announcements.js';
import { ChartFeature } from './app/charts.js';
import { ConnectionFeature } from './app/connection.js';
import { ContactFeature } from './app/contacts.js';
import { DeviceCacheFeature } from './app/device-cache.js';
import { FormattingFeature } from './app/formatting.js';
import { FormFeature } from './app/forms.js';
import { HeaderFeature } from './app/header.js';
import { LinkFeature } from './app/links.js';
import { NotificationFeature } from './app/notifications.js';
import { OperationFeature } from './app/operations.js';
import { OutboxFeature } from './app/outbox.js';
import { PullToRefreshFeature } from './app/pull-to-refresh.js';
import { QrScannerFeature } from './app/qr-scanner.js';
import { QuickAttendanceFeature } from './app/quick-attendance.js';
import { RealtimeFeature } from './app/realtime.js';
import { RenderFeature } from './app/render.js';
import { RequestFeature } from './app/requests.js';
import { SessionFeature } from './app/session.js';
import { SheetFeature } from './app/sheet.js';
import { ToastFeature } from './app/toasts.js';
import * as cacheRules from './lib/cache.js';

/**
 * The app every page runs: one PortalApp on `window.portalApp`, whose
 * methods the pages' own scripts call (`app.request()`, `app.showNotice()`,
 * `app.renderBadge()`…). The constructor holds all of its state; the
 * methods live in resources/js/app/*.js, one feature per file, and are mixed
 * in below. Pure rules the features delegate to are in resources/js/lib/.
 */
class PortalApp {
    constructor() {
        this.campusBase = document.documentElement.dataset.campusBase || window.location.origin;
        this.onlineState = navigator.onLine;
        this.syncHandlers = new Map();
        this.isSyncing = false;
        this.cacheNamespace = 'mycic-cache-v3';
        this.authSnapshotKey = `${this.cacheNamespace}:auth`;
        this.cacheOwnerKey = `${this.cacheNamespace}:owner`;
        // Stored as the owner once a login dropped every earlier copy; an
        // owner is a 32-character hex digest, so it never matches this.
        this.clearedCacheOwner = 'cleared';
        this.themeStorageKey = 'mycic-theme';
        this.protectedPageCacheName = 'mycic-pages-v2';
        this.authRequest = null;
        this.pullRefreshState = {
            active: false,
            armed: false,
            refreshing: false,
            startY: 0,
            threshold: 84,
            maxDistance: 132,
        };
        this.qrScannerState = {
            stream: null,
            rafId: null,
            input: null,
            busy: false,
            canvas: null,
            context: null,
            opening: false,
            openingSince: 0,
            attempt: 0,
            returnFocus: null,
        };
        // A camera request the browser never settles (a permission prompt
        // dismissed without an answer) stops blocking a new tap after this.
        this.qrScannerOpeningLimitMs = 8000;
        this.qrScannerOptions = new WeakMap();
        this.echo = null;
        this.echoChannel = null;
        this.realtimeConnected = false;
        this.isLoggingOut = false;
        this.quickQrTarget = null;
        this.quickQrEmptyMessage = null;
        // Set by the meeting page once it opened the scanner for `?qr=1`.
        this.qrFromUrlHandled = false;
        this.backgroundRefreshTimer = null;
        this.activeOperations = new Map();
        this.operationWaiters = new Map();
        this.finishedOperations = new Map();
        this.operationStorageKey = 'mycic-active-operations';
        this.operationPollIntervalMs = 2000;
        this.operationPollLimitMs = 4 * 60 * 1000;
        this.realtimeRefreshTimers = new Map();
        this.actionItemsReloadTimer = null;
        // PortalSessionMiddleware::BACKGROUND_HEADER: a request the app makes on
        // its own checks the session without counting as the student's activity.
        this.backgroundRequestHeader = 'X-MyCIC-Background';
        // Background work running its synchronous start; see runAsBackground().
        this.backgroundWork = 0;
        this.cachePolicies = cacheRules.CACHE_POLICIES;
        this.syncAliases = new Map();
        this.actionItems = null;
        this.actionItemsRequest = null;
        this.navBadgeOverrides = new Map();
        this.sheetState = { host: null, returnFocus: null, onClose: null };
        this.pendingQuickAttendance = false;
        this.outboxItems = [];
        this.pendingQuickAttendanceTimer = null;
        this.toastLimit = 3;
        this.notificationUnreadCount = null;
        this.notificationToastBuffer = [];
        this.notificationToastTimer = null;
        // public/offline.html counts its reload attempts here.
        this.offlineRetryKey = 'mycic-offline-retry';
    }

    init() {
        this.forgetOfflineRetries();
        this.restoreNotice();
        this.bindLogout();
        this.bindConnectionListeners();
        this.bindSyncRefreshButton();
        this.bindPullToRefresh();
        this.updateConnectionState(navigator.onLine, { announce: false });
        this.pruneExpiredCacheEntries();
        this.refreshOnlineRequiredState();
        this.bindQrScannerUi();
        this.bindAccountMenu();
        this.bindThemeToggle();
        this.bindQuickQrAction();
        this.bindContactSheetTriggers();
        this.bindDownloadLinks();
        this.bindHistoryRestore();

        if (document.body.dataset.auth !== 'guest') {
            const authSnapshot = this.getAuthSnapshot();

            if (authSnapshot) {
                this.hydrateUser(authSnapshot);
            }

            this.initRealtime();
            this.resumeActiveOperations();
            this.scheduleActionItemBadges();
            this.scheduleNotificationBadge();
            this.scheduleOutbox();
            this.consumeQuickAttendanceRequest();
        }
    }
}

/**
 * Copy the methods of each feature class onto PortalApp's prototype. A
 * feature holds no state of its own, and no two features may define the
 * same method: a silent override would change what a page gets.
 */
function mixin(target, ...features) {
    features.forEach((feature) => {
        Object.getOwnPropertyNames(feature.prototype)
            .filter((name) => name !== 'constructor')
            .forEach((name) => {
                if (Object.hasOwn(target.prototype, name)) {
                    throw new Error(`PortalApp already defines ${name}().`);
                }

                Object.defineProperty(target.prototype, name, Object.getOwnPropertyDescriptor(feature.prototype, name));
            });
    });
}

mixin(
    PortalApp,
    ActionItemFeature,
    AnnouncementFeature,
    ChartFeature,
    ConnectionFeature,
    ContactFeature,
    DeviceCacheFeature,
    FormattingFeature,
    FormFeature,
    HeaderFeature,
    LinkFeature,
    NotificationFeature,
    OperationFeature,
    OutboxFeature,
    PullToRefreshFeature,
    QrScannerFeature,
    QuickAttendanceFeature,
    RealtimeFeature,
    RenderFeature,
    RequestFeature,
    SessionFeature,
    SheetFeature,
    ToastFeature,
);

window.portalApp = new PortalApp();
// Before any page script can prime a view from the device cache.
window.portalApp.isolateDeviceCache();

document.addEventListener('DOMContentLoaded', () => {
    window.portalApp.init();
});
