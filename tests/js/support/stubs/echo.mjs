/**
 * Stands in for resources/js/echo.js: the browser stand-in decides whether
 * Reverb is configured (a fake Echo) or not (null, no app key).
 */
export function createPortalEcho() {
    return globalThis.__mycicTestEchoFactory ? globalThis.__mycicTestEchoFactory() : null;
}
