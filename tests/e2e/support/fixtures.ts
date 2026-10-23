import {
    test as base,
    expect,
    type APIRequestContext,
    type Page,
    type Request,
    type Route,
    type WebSocketRoute,
} from '@playwright/test';

export { expect };

/**
 * What a mocked /api/* endpoint answers: a status and a JSON body, or a
 * function of the request returning one.
 */
export type ApiAnswer =
    | { status?: number; json?: unknown; headers?: Record<string, string> }
    | ((request: Request) => { status?: number; json?: unknown; headers?: Record<string, string> } | Promise<{ status?: number; json?: unknown; headers?: Record<string, string> }>);

export type ApiCall = {
    method: string;
    path: string;
    search: string;
    body: string | null;
    background: boolean;
};

const APP_ORIGIN = 'http://127.0.0.1:8000';

/**
 * Hosts of the campus portal. The browser must never reach them; the e2e
 * server points its portal URLs at portal.invalid and refuses to send.
 */
const PORTAL_HOST = /(^|\.)cic\.ac\.id$|(^|\.)portal\.invalid$/i;

const isApp = (url: URL): boolean => url.origin === APP_ORIGIN;

/**
 * Every /api/* request of every page in the test. A request no answer
 * matches gets a 501 and fails the test when it ends: a spec says what each
 * page reads, and nothing reaches the server's real API (and so the portal).
 */
export class ApiMock {
    private readonly answers: Array<{ method: string; path: string | RegExp; answer: ApiAnswer }> = [];

    readonly calls: ApiCall[] = [];

    readonly unmocked: string[] = [];

    /**
     * Answer `method path` (an exact pathname, or a pattern). A later answer
     * for the same request wins over an earlier one.
     */
    answer(method: string, path: string | RegExp, answer: ApiAnswer): this {
        this.answers.unshift({ method: method.toUpperCase(), path, answer });

        return this;
    }

    callsTo(method: string, path: string): ApiCall[] {
        return this.calls.filter((call) => call.method === method.toUpperCase() && call.path === path);
    }

    async handle(route: Route): Promise<void> {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method();

        this.calls.push({
            method,
            path: url.pathname,
            search: url.search,
            body: request.postData(),
            background: (await request.headerValue('x-mycic-background')) === '1',
        });

        const match = this.answers.find((candidate) => candidate.method === method
            && (typeof candidate.path === 'string' ? candidate.path === url.pathname : candidate.path.test(url.pathname)));

        if (!match) {
            this.unmocked.push(`${method} ${url.pathname}${url.search}`);
            await route.fulfill({ status: 501, json: { status: 'error', message: `Permintaan e2e tanpa mock: ${method} ${url.pathname}` } });

            return;
        }

        const answer = typeof match.answer === 'function' ? await match.answer(request) : match.answer;

        await route.fulfill({ status: answer.status ?? 200, json: answer.json ?? {}, headers: answer.headers });
    }
}

type Socket = { route: WebSocketRoute; channels: Set<string> };

/**
 * A stand-in for Reverb speaking the Pusher protocol the app's Echo client
 * uses: it accepts every socket, confirms private channel subscriptions and
 * pushes events to whoever subscribed, as the server's broadcasts would.
 */
export class FakeReverb {
    private readonly sockets = new Set<Socket>();

    private connections = 0;

    accept(route: WebSocketRoute): void {
        const socket: Socket = { route, channels: new Set() };

        this.connections += 1;
        this.sockets.add(socket);

        route.onMessage((message) => {
            let frame: { event?: string; data?: { channel?: string } } = {};

            try {
                frame = JSON.parse(String(message));
            } catch {
                return;
            }

            const channel = frame.data?.channel;

            if (frame.event === 'pusher:subscribe' && channel) {
                socket.channels.add(channel);
                route.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel, data: '{}' }));
            } else if (frame.event === 'pusher:unsubscribe' && channel) {
                socket.channels.delete(channel);
            } else if (frame.event === 'pusher:ping') {
                route.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }));
            }
        });
        route.onClose(() => this.sockets.delete(socket));
        route.send(JSON.stringify({
            event: 'pusher:connection_established',
            data: JSON.stringify({ socket_id: `${this.connections}.${1000 + this.connections}`, activity_timeout: 120 }),
        }));
    }

    subscribers(channel: string): number {
        return [...this.sockets].filter((socket) => socket.channels.has(channel)).length;
    }

    /**
     * Push `event` to every socket subscribed to `channel`, the way Reverb
     * delivers a broadcast; returns how many sockets got it.
     */
    broadcast(channel: string, event: string, payload: Record<string, unknown>): number {
        const subscribed = [...this.sockets].filter((socket) => socket.channels.has(channel));

        subscribed.forEach((socket) => socket.route.send(JSON.stringify({ event, channel, data: JSON.stringify(payload) })));

        return subscribed.length;
    }
}

/**
 * The fake signed-in campus session of routes/e2e.php, on the test's
 * browser context (its request context shares the browser's cookies).
 */
export class E2eSession {
    sessionId: string | null = null;

    constructor(private readonly request: APIRequestContext) {}

    /**
     * Sign the browser in; returns the app session id, which names the
     * session's realtime channel (portal.session.{id}).
     */
    async start(): Promise<string> {
        const response = await this.request.post('/__e2e/session');

        expect(response.status(), 'POST /__e2e/session signs the browser in').toBe(201);

        const body = await response.json();

        this.sessionId = String(body.session_id);

        return this.sessionId;
    }

    /**
     * End the campus session and the browser's app session, like a logout.
     */
    async end(): Promise<void> {
        const response = await this.request.delete('/__e2e/session');

        expect(response.status(), 'DELETE /__e2e/session signs the browser out').toBe(204);
    }
}

type Fixtures = {
    api: ApiMock;
    reverb: FakeReverb;
    portalRequests: string[];
    campusSession: E2eSession;
};

export const test = base.extend<Fixtures>({
    /**
     * Nothing leaves this machine, and a request for the portal fails the test.
     */
    portalRequests: [async ({ context }, use) => {
        const portalRequests: string[] = [];

        await context.route((url) => !isApp(url), async (route) => {
            const url = new URL(route.request().url());

            if (PORTAL_HOST.test(url.hostname)) {
                portalRequests.push(`${route.request().method()} ${url.href}`);
            }

            await route.abort('blockedbyclient');
        });

        await use(portalRequests);

        expect(portalRequests, 'the browser never requests the campus portal').toEqual([]);
    }, { auto: true }],

    /**
     * Every /api/* request of every page in the test goes to the ApiMock.
     */
    api: [async ({ context }, use) => {
        const api = new ApiMock();

        await context.route((url) => isApp(url) && url.pathname.startsWith('/api/'), (route) => api.handle(route));

        await use(api);

        expect(api.unmocked, 'every /api/* request a page makes is mocked').toEqual([]);
    }, { auto: true }],

    /**
     * Every socket goes to the fake Reverb, never to a server; private
     * channels are signed without the server (/broadcasting/auth).
     */
    reverb: [async ({ context }, use) => {
        const reverb = new FakeReverb();

        await context.routeWebSocket(() => true, (route) => reverb.accept(route));
        await context.route((url) => isApp(url) && url.pathname === '/broadcasting/auth', (route) => route.fulfill({
            status: 200,
            json: { auth: 'e2e-key:e2e-signature' },
        }));

        await use(reverb);
    }, { auto: true }],

    /**
     * The fake campus session; whatever a test started is ended afterwards.
     */
    campusSession: async ({ context }, use) => {
        const session = new E2eSession(context.request);

        await use(session);

        await session.end();
    },
});

/**
 * Counts the times a page navigates (a reload included) after this call.
 */
export function trackNavigations(page: Page): { count: () => number } {
    let navigations = 0;

    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
            navigations += 1;
        }
    });

    return { count: () => navigations };
}

/**
 * Today as the app's pages compute it (`YYYY-MM-DD` on the device), in the
 * time zone the specs run the browser in (playwright.config.ts).
 */
export function todayInJakarta(): string {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Jakarta' }).format(new Date());
}
