{{--
    The sync status of the signed-in shell (data-sync-alert, whose whole
    class app.setSyncStatus() rewrites from app.ui.syncStrip): the
    connection chip, the realtime chip from sm up, the status line and,
    unless `refresh` is false, the "Sinkronkan data" button. The layout
    shows one in the top bar from lg up and one above the content below lg;
    each sits in a wrapper that does the showing, since the strip's own
    class is rewritten.
--}}
@props(['refresh' => true])

<div class="flex min-h-9 min-w-0 items-center text-xs text-ink-subtle" data-sync-alert role="status">
    <div class="flex w-full min-w-0 items-center justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2.5 overflow-hidden">
            <span class="lg:hidden">
                <span class="inline-flex shrink-0 items-center gap-2 text-xs font-medium text-ink-muted before:size-1.5 before:shrink-0 before:rounded-full before:bg-success before:content-['']" data-connection-chip>
                    <span data-connection-label>Online</span>
                </span>
            </span>
            <span class="hidden shrink-0 items-center gap-2.5 sm:flex lg:hidden">
                <span class="h-3 w-px shrink-0 bg-border" aria-hidden="true"></span>
                <span class="inline-flex shrink-0 items-center gap-2 text-xs font-medium text-ink-muted before:size-1.5 before:shrink-0 before:rounded-full before:bg-success before:content-['']" data-realtime-chip>
                    <span data-realtime-label>Pembaruan otomatis</span>
                </span>
            </span>
            <span class="hidden h-3 w-px shrink-0 bg-border sm:block lg:hidden" aria-hidden="true"></span>
            <span data-sync-status class="line-clamp-2 min-w-0 text-xs text-ink-subtle sm:line-clamp-none sm:truncate lg:hidden data-[tone=warning]:lg:inline data-[tone=error]:lg:inline data-[tone=danger]:lg:inline">Data siap dipakai.</span>
        </div>
        @if ($refresh)
            <button
                type="button"
                class="inline-flex size-8 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50"
                data-sync-refresh
                data-online-required
                aria-label="Sinkronkan data"
                title="Sinkronkan data"
            >
                <span data-sync-refresh-icon aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="block size-4" width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                </span>
                <span class="block size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent hidden" data-sync-refresh-spinner aria-hidden="true"></span>
                <span class="sr-only" data-sync-refresh-label>Sinkronkan data</span>
            </button>
        @endif
    </div>
</div>
