import { readFileSync } from 'node:fs';

/**
 * A Blade view's source as the page carries it: every `@include('a.b')` is
 * replaced by the source of resources/views/a/b.blade.php (recursively) the
 * way Blade renders it: Blade comments left out, the included view's leading
 * whitespace trimmed, and the line break right after the directive dropped.
 * A page whose script is split into partials (the Beranda's in
 * resources/views/dashboard/scripts, other pages' in <view>/scripts) then
 * reads as one script, exactly as written before the split, so tests can
 * slice and run it as before.
 *
 * @param {string} name A view path under resources/views without `.blade.php`, e.g. `dashboard` or `announcements/index`.
 * @returns {string}
 */
export function viewSource(name) {
    return expandIncludes(readView(name.replaceAll('.', '/')));
}

function readView(path) {
    return readFileSync(new URL(`../../../resources/views/${path}.blade.php`, import.meta.url), 'utf8');
}

function expandIncludes(source) {
    return source.replace(/@include\('([\w.-]+)'\)\n?/g, (_, view) => expandIncludes(readView(view.replaceAll('.', '/')).replace(/\{\{--[\s\S]*?--\}\}/g, '')).trimStart());
}
