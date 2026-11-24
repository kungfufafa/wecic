{{--
    The Beranda's page header: a clean, borderless horizontal layout matching
    the rest of the app shell. The greeting sits on the left (`Halo, {nama}`
    with a screen-reader-only "Beranda"), one muted meta line below it
    (today's date · T.A · semester), and quick
    actions on the right. scripts/period fills [data-dashboard-salutation],
    #dashboard-hero-date and #dashboard-period.
    Hand-written rather than <x-page-hero> because the h1 holds the
    [data-user-name] hook. "Absen QR" is another [data-qr-quick] button:
    the app binds every one of them (quick-attendance.js).
--}}
<section class="mb-6" aria-labelledby="dashboard-greeting" data-dashboard-widget="hero">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tight text-ink text-balance sm:text-2xl" id="dashboard-greeting"><span class="sr-only">Beranda: </span><span data-dashboard-salutation>Halo</span>, <span data-user-name>Mahasiswa</span></h1>
            <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-ink-muted">
                <span id="dashboard-hero-date">{{ now()->locale('id')->isoFormat('dddd, D MMMM YYYY') }}</span>
                <ul class="flex flex-wrap items-center gap-x-1.5 gap-y-1 max-sm:basis-full" id="dashboard-period" aria-label="Periode akademik" aria-busy="true">
                    <li class="h-4 w-40 animate-pulse rounded-md bg-surface" aria-hidden="true" data-slot="skeleton"></li>
                    <li class="sr-only">Memuat periode akademik…</li>
                </ul>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <x-button size="sm" data-qr-quick><x-icon name="qr" size="sm" />Absen QR</x-button>
            <x-button variant="secondary" size="sm" href="{{ route('class.index') }}"><x-icon name="calendar" size="sm" />Jadwal</x-button>
            <x-button variant="secondary" size="sm" href="#dashboard-assignments"><x-icon name="exam" size="sm" />Tugas</x-button>
        </div>
    </div>
</section>
