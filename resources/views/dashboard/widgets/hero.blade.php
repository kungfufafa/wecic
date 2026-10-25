{{-- The greeting and the academic period chips (filled by scripts/period). --}}
<section class="mycic-hero mb-5" aria-labelledby="dashboard-greeting" data-dashboard-widget="hero">
    <div class="flex min-w-0 items-center gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/20 bg-white/15 text-sm font-bold text-white" aria-hidden="true" data-user-avatar>M</span>
        <h1 class="min-w-0" id="dashboard-greeting">
            <span class="block text-xs font-medium text-white/75">Halo,</span>
            <span class="block truncate text-base font-bold text-white sm:text-lg" data-user-name>Mahasiswa</span>
        </h1>
    </div>
    <ul class="mt-4 flex flex-wrap gap-2" id="dashboard-period" aria-label="Periode akademik" aria-busy="true">
        <li class="h-7 w-44 animate-pulse rounded-lg bg-white/15" aria-hidden="true"></li>
        <li class="sr-only">Memuat periode akademik…</li>
    </ul>
</section>
