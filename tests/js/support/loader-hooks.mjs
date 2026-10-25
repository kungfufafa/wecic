/**
 * Module resolution for loading resources/js/app.js and the feature modules
 * under resources/js/app/ under Node, the way Vite resolves them for the
 * browser:
 * - `./echo` or `../echo` (Laravel Echo + Pusher, configured from
 *   `import.meta.env`) becomes a stub that hands out the test's fake Echo;
 * - `jsqr` (camera QR decoding) becomes a stub that never finds a code;
 * - other extensionless relative imports (`./csrf`, `../csrf`) get their `.js`.
 */
const appScriptsDirectory = new URL('../../../resources/js/', import.meta.url).href;
const stubs = {
    echo: new URL('./stubs/echo.mjs', import.meta.url).href,
    jsqr: new URL('./stubs/jsqr.mjs', import.meta.url).href,
};

export async function resolve(specifier, context, nextResolve) {
    const fromAppScripts = (context.parentURL || '').startsWith(appScriptsDirectory);
    const stubName = specifier.replace(/^\.\.?\//, '');

    if (fromAppScripts && Object.hasOwn(stubs, stubName)) {
        return { url: stubs[stubName], shortCircuit: true };
    }

    if (fromAppScripts && /^\.\.?\/[^.]+$/.test(specifier)) {
        return nextResolve(`${specifier}.js`, context);
    }

    return nextResolve(specifier, context);
}
