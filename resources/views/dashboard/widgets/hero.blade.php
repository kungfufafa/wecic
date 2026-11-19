{{-- The page context and the academic period chips (filled by scripts/period). --}}
<section class="mb-6 flex flex-col gap-3 border-b border-border-light pb-5 sm:flex-row sm:items-end sm:justify-between" aria-labelledby="dashboard-greeting" data-dashboard-widget="hero">
    <div class="flex min-w-0 items-center gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[var(--radius-control)] border border-border bg-surface-tint text-sm font-medium text-brand-text" aria-hidden="true" data-user-avatar>M</span>
        <h1 class="min-w-0" id="dashboard-greeting">
            <span class="block text-caption font-medium text-ink-subtle">Beranda</span>
            <span class="mycic-page-heading block truncate" data-user-name>Mahasiswa</span>
        </h1>
    </div>
    <ul class="flex flex-wrap gap-2 sm:justify-end" id="dashboard-period" aria-label="Periode akademik" aria-busy="true">
        <li class="mycic-skeleton h-7 w-40 rounded-md" aria-hidden="true"></li>
        <li class="sr-only">Memuat periode akademik…</li>
    </ul>
</section>
