import { register } from 'node:module';

/**
 * A browser stand-in for running PortalApp (resources/js/app.js) under Node
 * without jsdom or any other package: a small DOM, Web Storage, location, a
 * manual clock, fetch, Cache Storage and a fake Laravel Echo.
 *
 * Selectors: tag, `*`, #id, .class, [attr], [attr="value"] and their
 * combinations (`meta[name="csrf-token"]`), comma lists and the descendant
 * combinator (`form input`). Anything else (pseudo-classes, `>`) matches
 * nothing, so a test that depends on one fails instead of passing by luck.
 */

const FORM_CONTROLS = new Set(['BUTTON', 'INPUT', 'SELECT', 'TEXTAREA']);
const BROWSER_GLOBALS = ['window', 'document', 'navigator', 'localStorage', 'sessionStorage', 'fetch', 'HTMLElement', 'HTMLFormElement', '__mycicTestEchoFactory'];
const ORIGIN = 'https://mycic.invalid';

const kebab = (name) => name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);
const camel = (name) => name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());

/**
 * Split on `separator` outside brackets, parentheses and quotes.
 */
function splitOutside(text, separator) {
    const parts = [];
    let depth = 0;
    let quote = null;
    let current = '';

    for (const character of text) {
        if (quote) {
            quote = character === quote ? null : quote;
        } else if (character === '"' || character === '\'') {
            quote = character;
        } else if (character === '[' || character === '(') {
            depth += 1;
        } else if (character === ']' || character === ')') {
            depth -= 1;
        } else if (depth === 0 && separator.test(character)) {
            parts.push(current);
            current = '';

            continue;
        }

        current += character;
    }

    parts.push(current);

    return parts.map((part) => part.trim()).filter(Boolean);
}

const COMPOUND = /^(\*|[a-zA-Z][\w-]*)?((?:#[\w-]+|\.[\w-]+|\[[\w-]+(?:=(?:"[^"]*"|'[^']*'|[^\]"']*))?\])*)$/;
const COMPOUND_PART = /#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\]"']*)))?\]/g;

function parseCompound(text) {
    const match = COMPOUND.exec(text);

    if (!match) {
        return null;
    }

    const parts = [...match[2].matchAll(COMPOUND_PART)].map((part) => {
        if (part[1] !== undefined) {
            return { kind: 'id', value: part[1] };
        }

        if (part[2] !== undefined) {
            return { kind: 'class', value: part[2] };
        }

        return { kind: 'attribute', name: part[3], value: part[4] ?? part[5] ?? part[6] };
    });

    return { tag: match[1] && match[1] !== '*' ? match[1].toUpperCase() : null, parts };
}

function compileSelector(selector) {
    return splitOutside(String(selector), /,/)
        .map((complex) => splitOutside(complex, /\s/).map(parseCompound))
        .filter((compounds) => compounds.length > 0 && compounds.every(Boolean));
}

function matchesCompound(element, { tag, parts }) {
    if (tag && element.tagName !== tag) {
        return false;
    }

    return parts.every((part) => {
        if (part.kind === 'id') {
            return element.getAttribute('id') === part.value;
        }

        if (part.kind === 'class') {
            return element.classList.contains(part.value);
        }

        return part.value === undefined
            ? element.hasAttribute(part.name)
            : element.getAttribute(part.name) === part.value;
    });
}

function matchesComplex(element, compounds) {
    let index = compounds.length - 1;

    if (!matchesCompound(element, compounds[index])) {
        return false;
    }

    index -= 1;

    for (let ancestor = element.parentNode; ancestor && index >= 0; ancestor = ancestor.parentNode) {
        if (matchesCompound(ancestor, compounds[index])) {
            index -= 1;
        }
    }

    return index < 0;
}

export class FakeElement extends EventTarget {
    constructor(tagName, attributes = {}) {
        super();
        this.tagName = String(tagName).toUpperCase();
        this.attributeMap = new Map();
        this.children = [];
        this.parentNode = null;
        this.hidden = false;
        this.innerHTML = '';
        this.ownText = '';
        this.style = {};
        this.offsetWidth = 0;

        if (FORM_CONTROLS.has(this.tagName)) {
            this.disabled = false;
            this.type = this.tagName === 'BUTTON' ? 'submit' : 'text';
            this.value = '';
            this.defaultValue = '';
            this.files = null;
        }

        this.dataset = new Proxy({}, {
            get: (_, key) => (typeof key === 'string' ? this.attributeMap.get(`data-${kebab(key)}`) : undefined),
            set: (_, key, value) => {
                this.attributeMap.set(`data-${kebab(String(key))}`, String(value));

                return true;
            },
            has: (_, key) => typeof key === 'string' && this.attributeMap.has(`data-${kebab(key)}`),
            deleteProperty: (_, key) => {
                this.attributeMap.delete(`data-${kebab(String(key))}`);

                return true;
            },
            ownKeys: () => [...this.attributeMap.keys()].filter((name) => name.startsWith('data-')).map((name) => camel(name.slice(5))),
            getOwnPropertyDescriptor: (_, key) => (typeof key === 'string' && this.attributeMap.has(`data-${kebab(key)}`)
                ? { value: this.attributeMap.get(`data-${kebab(key)}`), enumerable: true, configurable: true, writable: true }
                : undefined),
        });

        const tokens = () => (this.getAttribute('class') || '').split(/\s+/).filter(Boolean);
        const write = (names) => this.setAttribute('class', [...new Set(names)].join(' '));

        this.classList = {
            add: (...names) => write([...tokens(), ...names]),
            remove: (...names) => write(tokens().filter((name) => !names.includes(name))),
            contains: (name) => tokens().includes(name),
            toggle: (name, force) => {
                const present = force === undefined ? !tokens().includes(name) : Boolean(force);

                if (present) {
                    write([...tokens(), name]);
                } else {
                    write(tokens().filter((token) => token !== name));
                }

                return present;
            },
        };

        Object.entries(attributes).forEach(([name, value]) => this.setAttribute(name, value));
    }

    get className() {
        return this.getAttribute('class') || '';
    }

    set className(value) {
        this.setAttribute('class', value);
    }

    get id() {
        return this.getAttribute('id') || '';
    }

    get textContent() {
        return this.ownText + this.children.map((child) => child.textContent).join('');
    }

    set textContent(value) {
        this.ownText = String(value ?? '');
        this.children.forEach((child) => {
            child.parentNode = null;
        });
        this.children = [];
    }

    get firstElementChild() {
        return this.children[0] ?? null;
    }

    getAttribute(name) {
        return this.attributeMap.has(name) ? this.attributeMap.get(name) : null;
    }

    setAttribute(name, value) {
        this.attributeMap.set(name, String(value));

        if (FORM_CONTROLS.has(this.tagName)) {
            if (name === 'disabled') {
                this.disabled = true;
            } else if (name === 'type') {
                this.type = String(value);
            } else if (name === 'value') {
                this.value = String(value);
                this.defaultValue = String(value);
            }
        }
    }

    removeAttribute(name) {
        this.attributeMap.delete(name);
    }

    hasAttribute(name) {
        return this.attributeMap.has(name);
    }

    appendChild(child) {
        child.remove();
        child.parentNode = this;
        this.children.push(child);

        return child;
    }

    append(...children) {
        children.forEach((child) => this.appendChild(child));
    }

    replaceChildren(...children) {
        this.textContent = '';
        this.append(...children);
    }

    get parentElement() {
        return this.parentNode instanceof FakeElement ? this.parentNode : null;
    }

    /**
     * In a document: under an <html> root.
     */
    get isConnected() {
        let element = this;

        while (element.parentNode instanceof FakeElement) {
            element = element.parentNode;
        }

        return element.tagName === 'HTML';
    }

    scrollIntoView() {}

    remove() {
        if (this.parentNode) {
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        }
    }

    * descendants() {
        for (const child of this.children) {
            yield child;
            yield* child.descendants();
        }
    }

    matches(selector) {
        return compileSelector(selector).some((compounds) => matchesComplex(this, compounds));
    }

    closest(selector) {
        for (let element = this; element instanceof FakeElement; element = element.parentNode) {
            if (element.matches(selector)) {
                return element;
            }
        }

        return null;
    }

    querySelectorAll(selector) {
        const compiled = compileSelector(selector);

        return [...this.descendants()].filter((element) => compiled.some((compounds) => matchesComplex(element, compounds)));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    focus() {}

    getClientRects() {
        return this.hidden ? [] : [{}];
    }
}

class FakeDocument extends EventTarget {
    constructor() {
        super();
        this.documentElement = new FakeElement('html');
        this.head = new FakeElement('head');
        this.body = new FakeElement('body');
        this.documentElement.append(this.head, this.body);
        this.activeElement = this.body;
        this.readyState = 'complete';
    }

    createElement(tagName) {
        return new FakeElement(tagName);
    }

    querySelectorAll(selector) {
        return [this.documentElement, ...this.documentElement.querySelectorAll(selector)]
            .filter((element) => element.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    getElementById(id) {
        return this.querySelector(`#${id}`);
    }
}

/**
 * Web Storage: items are own properties, so `Object.keys(localStorage)`
 * lists them as it does in a browser.
 */
class FakeStorage {
    getItem(key) {
        return Object.hasOwn(this, key) ? this[key] : null;
    }

    setItem(key, value) {
        this[key] = String(value);
    }

    removeItem(key) {
        delete this[key];
    }

    clear() {
        Object.keys(this).forEach((key) => delete this[key]);
    }

    key(index) {
        return Object.keys(this)[index] ?? null;
    }

    get length() {
        return Object.keys(this).length;
    }
}

class FakeLocation {
    constructor(url) {
        this.url = new URL(url, ORIGIN);
        this.assignments = [];
        this.reloads = 0;
    }

    get href() {
        return this.url.href;
    }

    set href(value) {
        this.assignments.push(String(value));
        this.url = new URL(value, this.url);
    }

    get origin() {
        return this.url.origin;
    }

    get hostname() {
        return this.url.hostname;
    }

    get pathname() {
        return this.url.pathname;
    }

    get search() {
        return this.url.search;
    }

    assign(value) {
        this.href = value;
    }

    replace(value) {
        this.href = value;
    }

    reload() {
        this.reloads += 1;
    }
}

/**
 * Timers run only when a test moves the clock, so nothing fires behind a
 * test's back and nothing outlives it.
 */
class ManualClock {
    constructor() {
        this.now = 0;
        this.nextId = 1;
        this.timers = new Map();
    }

    setTimeout(callback, delay = 0, ...args) {
        const id = this.nextId;

        this.nextId += 1;
        this.timers.set(id, { callback, args, at: this.now + Math.max(0, Number(delay) || 0) });

        return id;
    }

    clearTimeout(id) {
        this.timers.delete(Number(id));
    }

    async advance(milliseconds) {
        const target = this.now + milliseconds;

        for (;;) {
            const [id, timer] = [...this.timers.entries()]
                .filter(([, entry]) => entry.at <= target)
                .sort(([, left], [, right]) => left.at - right.at)[0] ?? [];

            if (!timer) {
                break;
            }

            this.timers.delete(id);
            this.now = timer.at;
            timer.callback(...timer.args);
            await settle();
        }

        this.now = target;
    }
}

class FakeChannel {
    constructor(name) {
        this.name = name;
        this.listeners = new Map();
        this.onSubscribed = null;
        this.onError = null;
    }

    listen(event, callback) {
        this.listeners.set(event, callback);

        return this;
    }

    subscribed(callback) {
        this.onSubscribed = callback;

        return this;
    }

    error(callback) {
        this.onError = callback;

        return this;
    }

    /**
     * Deliver a broadcast the way Echo does: only to a listener of that name.
     */
    receive(event, payload) {
        const listener = this.listeners.get(event);

        if (!listener) {
            throw new Error(`Nothing listens for ${event} on ${this.name}.`);
        }

        listener(payload);
    }
}

class FakeConnection {
    constructor() {
        this.handlers = new Map();
    }

    bind(event, callback) {
        this.handlers.set(event, [...(this.handlers.get(event) || []), callback]);
    }

    change(previous, current) {
        (this.handlers.get('state_change') || []).forEach((callback) => callback({ previous, current }));
    }
}

export class FakeEcho {
    constructor() {
        this.channels = new Map();
        this.disconnected = false;
        this.connection = new FakeConnection();
        this.connector = { pusher: { connection: this.connection } };
    }

    private(name) {
        const channel = new FakeChannel(name);

        this.channels.set(name, channel);

        return channel;
    }

    disconnect() {
        this.disconnected = true;
    }
}

/**
 * Let pending promise chains (fetch, response.json(), awaits) run.
 */
export async function settle(rounds = 5) {
    for (let round = 0; round < rounds; round += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * A JSON answer from the app's API.
 */
export function json(status, body, headers = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'content-type': 'application/json', ...headers },
    });
}

let activeBrowser = null;

/**
 * Put a fresh browser in place of Node's globals. `server(request)` answers
 * every fetch; it may return a Response, throw (a network error) or never
 * settle (a request that only ends when the app aborts it).
 *
 * @param {{ url?: string, auth?: string, page?: string, sessionId?: string, cacheOwner?: string, portalOffline?: boolean, online?: boolean, reverb?: boolean, csrfToken?: string }} options
 */
export function installBrowser({
    url = '/dashboard',
    auth = 'required',
    page = 'dashboard',
    sessionId = 'session-abc',
    cacheOwner = '',
    portalOffline = false,
    online = true,
    reverb = true,
    csrfToken = 'csrf-token-1',
} = {}) {
    activeBrowser?.dispose();

    const document = new FakeDocument();
    const clock = new ManualClock();
    const location = new FakeLocation(url);
    const localStorage = new FakeStorage();
    const sessionStorage = new FakeStorage();
    const navigator = { onLine: online };
    const caches = {
        deleted: [],
        async delete(name) {
            this.deleted.push(name);

            return true;
        },
    };
    const window = new EventTarget();
    const browser = {
        window,
        document,
        location,
        localStorage,
        sessionStorage,
        navigator,
        caches,
        clock,
        echoes: [],
        requests: [],
        server: () => json(404, { message: 'Not Found' }),
        saved: new Map(),
        disposed: false,
        element(tagName, attributes = {}, parent = document.body) {
            return parent.appendChild(new FakeElement(tagName, attributes));
        },
        /**
         * The toasts on screen, oldest first.
         */
        toasts() {
            return (document.getElementById('notice-host')?.children || [])
                .map((toast) => ({ message: toast.dataset.message, tone: toast.dataset.tone }));
        },
        dispose() {
            if (this.disposed) {
                return;
            }

            this.disposed = true;
            clock.timers.clear();
            BROWSER_GLOBALS.forEach((name) => {
                const descriptor = this.saved.get(name);

                if (descriptor) {
                    Object.defineProperty(globalThis, name, descriptor);
                } else {
                    delete globalThis[name];
                }
            });

            if (activeBrowser === this) {
                activeBrowser = null;
            }
        },
    };

    document.documentElement.setAttribute('data-campus-base', 'https://portal.invalid');

    if (auth !== 'guest' && sessionId) {
        document.documentElement.setAttribute('data-session-id', sessionId);
    }

    document.body.setAttribute('data-auth', auth);
    document.body.setAttribute('data-page', page);

    if (auth !== 'guest') {
        document.body.setAttribute('data-portal-offline', portalOffline ? '1' : '0');
        document.body.setAttribute('data-cache-owner', cacheOwner);
    }

    if (csrfToken) {
        document.head.appendChild(new FakeElement('meta', { name: 'csrf-token', content: csrfToken }));
    }

    // Both layouts open with the toast host.
    document.body.appendChild(new FakeElement('div', { id: 'notice-host' }));

    const fetch = (input, config = {}) => {
        const request = {
            url: new URL(String(input), location.href),
            method: config.method || 'GET',
            headers: { ...(config.headers || {}) },
            body: config.body,
            cache: config.cache,
            keepalive: config.keepalive === true,
        };

        browser.requests.push(request);

        return new Promise((resolve, reject) => {
            config.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
            Promise.resolve().then(() => browser.server(request)).then(resolve, reject);
        });
    };

    Object.assign(window, {
        document,
        navigator,
        location,
        localStorage,
        sessionStorage,
        caches,
        fetch,
        setTimeout: (callback, delay, ...args) => clock.setTimeout(callback, delay, ...args),
        clearTimeout: (id) => clock.clearTimeout(id),
    });

    const globals = {
        window,
        document,
        navigator,
        localStorage,
        sessionStorage,
        fetch,
        HTMLElement: FakeElement,
        HTMLFormElement: class {
            static [Symbol.hasInstance](value) {
                return value instanceof FakeElement && value.tagName === 'FORM';
            }
        },
        __mycicTestEchoFactory: () => {
            if (!reverb) {
                return null;
            }

            const echo = new FakeEcho();

            browser.echoes.push(echo);

            return echo;
        },
    };

    BROWSER_GLOBALS.forEach((name) => {
        const descriptor = Object.getOwnPropertyDescriptor(globalThis, name);

        if (descriptor) {
            browser.saved.set(name, descriptor);
        }

        Object.defineProperty(globalThis, name, { value: globals[name], configurable: true, writable: true, enumerable: false });
    });

    activeBrowser = browser;

    return browser;
}

let portalAppClass = null;

/**
 * The PortalApp class from resources/js/app.js, loaded once per test file
 * in a throwaway browser so its load-time work (`window.portalApp`, the
 * device-cache claim) never touches a test's own browser.
 */
async function loadPortalAppClass() {
    if (portalAppClass) {
        return portalAppClass;
    }

    register('./loader-hooks.mjs', import.meta.url);

    const boot = installBrowser({ auth: 'guest', reverb: false });

    try {
        await import('../../../resources/js/app.js');
        portalAppClass = globalThis.window.portalApp.constructor;
    } finally {
        boot.dispose();
    }

    return portalAppClass;
}

/**
 * A PortalApp on a fresh browser, as a page's bundle creates it (before
 * `init()` runs on DOMContentLoaded).
 *
 * @param {Parameters<typeof installBrowser>[0]} options
 * @returns {Promise<{ app: object, browser: ReturnType<typeof installBrowser> }>}
 */
export async function createPortalApp(options = {}) {
    const PortalApp = await loadPortalAppClass();
    const browser = installBrowser(options);

    return { app: new PortalApp(), browser };
}
