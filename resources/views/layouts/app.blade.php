<!DOCTYPE html>
<html lang="id" data-campus-base="{{ config('portal.base_url') }}" data-session-id="{{ session()->getId() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="WeCIC">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
    <script>
        (() => {
            let theme = 'light';

            try {
                const storedTheme = localStorage.getItem('mycic-theme');

                theme = storedTheme === 'dark' || storedTheme === 'light'
                    ? storedTheme
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) {
            }

            document.documentElement.dataset.theme = theme;

            if (theme === 'dark') {
                document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#111113');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    // The nav icons come from the same paths as <x-icon> (resources/views/components/data/icons.php).
    $iconPaths = require resource_path('views/components/data/icons.php');
    $svg = static fn (string $name): string => '<svg xmlns="http://www.w3.org/2000/svg" class="size-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$iconPaths[$name].'</svg>';
    $icons = array_combine(
        ['home', 'class', 'academic', 'finance', 'more', 'skkm', 'thesis', 'library', 'announcement', 'notebook', 'archive'],
        array_map($svg, ['home', 'class', 'academic', 'finance', 'more', 'skkm', 'thesis', 'library', 'announcement', 'notebook', 'archive']),
    );

    $isClassSection = request()->routeIs('class.*', 'meeting.show', 'exam.*');
    $isAcademicSection = request()->routeIs('krs.*', 'grades.*', 'transcript.*');
    $isFinanceSection = request()->routeIs('finance.*');
    $isMoreSection = request()->routeIs('skkm.*', 'announcement.*', 'thesis.*', 'library.*', 'profile', 'password.*', 'more', 'archive.*', 'notebook.*');
    $isPortalOffline = session('campus_offline') === true;

    $mobileNavigation = [
        'start' => [
            ['href' => route('dashboard'), 'label' => 'Beranda', 'icon' => $icons['home'], 'badge' => 'beranda', 'active' => request()->routeIs('dashboard')],
            ['href' => route('class.index'), 'label' => 'Kuliah', 'icon' => $icons['class'], 'badge' => 'kuliah', 'active' => $isClassSection],
        ],
        'end' => [
            ['href' => route('krs.index'), 'label' => 'Akademik', 'icon' => $icons['academic'], 'badge' => 'akademik', 'active' => $isAcademicSection],
            ['href' => route('more'), 'label' => 'Lainnya', 'icon' => $icons['more'], 'badge' => 'lainnya', 'active' => $isFinanceSection || $isMoreSection],
        ],
    ];

    $desktopNavigation = [
        ['href' => route('dashboard'), 'label' => 'Beranda', 'icon' => $icons['home'], 'badge' => 'beranda', 'active' => request()->routeIs('dashboard')],
        ['href' => route('class.index'), 'label' => 'Kuliah', 'icon' => $icons['class'], 'badge' => 'kuliah', 'active' => $isClassSection],
        ['href' => route('krs.index'), 'label' => 'Akademik', 'icon' => $icons['academic'], 'badge' => 'akademik', 'active' => $isAcademicSection],
        ['href' => route('finance.index'), 'label' => 'Keuangan', 'icon' => $icons['finance'], 'badge' => 'keuangan', 'active' => $isFinanceSection],
    ];

    // The sidebar's "Lainnya" group. Profil and Ubah password are in the account menu.
    $moreMenu = [
        ['href' => route('skkm.index'), 'label' => 'SKKM', 'icon' => $icons['skkm'], 'badge' => 'skkm', 'active' => request()->routeIs('skkm.*')],
        ['href' => route('thesis.index'), 'label' => 'Skripsi', 'icon' => $icons['thesis'], 'badge' => 'thesis', 'active' => request()->routeIs('thesis.*')],
        ['href' => route('library.index'), 'label' => 'Perpustakaan', 'icon' => $icons['library'], 'badge' => 'library', 'active' => request()->routeIs('library.*')],
        ['href' => route('announcement.index'), 'label' => 'Pengumuman', 'icon' => $icons['announcement'], 'badge' => 'announcement', 'active' => request()->routeIs('announcement.*')],
        ['href' => route('notebook.index'), 'label' => 'Notebook', 'icon' => $icons['notebook'], 'badge' => 'notebook', 'active' => request()->routeIs('notebook.*')],
        ['href' => route('archive.index'), 'label' => 'Arsip', 'icon' => $icons['archive'], 'badge' => 'archive', 'active' => request()->routeIs('archive.*')],
    ];

    $refreshableDatasets = array_keys(array_filter(
        (array) config('portal.datasets', []),
        static fn ($dataset): bool => is_array($dataset) && ! ($dataset['parameterized'] ?? false)
    ));

    $hideStatusStrip = trim($__env->yieldContent('hide_status_strip')) === '1';
    $hideSyncRefresh = trim($__env->yieldContent('hide_sync_refresh')) === '1';

    // Names the student whose copies this device may show (app.isolateDeviceCache):
    // a one-way hash of the data owner, never the NIM itself.
    $campusSessionToken = session('campus_session_token');
    $deviceCacheOwner = is_string($campusSessionToken) && $campusSessionToken !== ''
        ? substr(hash('sha256', 'device-cache|'.app(\App\Services\PortalDataOwner::class)->key($campusSessionToken)), 0, 32)
        : '';
@endphp
<body data-auth="@yield('auth_mode', 'required')" data-page="@yield('page_id', 'app')" data-refreshable-datasets="{{ implode(',', $refreshableDatasets) }}" data-portal-offline="{{ $isPortalOffline ? '1' : '0' }}" data-portal-offline-reconnect="{{ session('campus_offline_reconnect') ?? '' }}" data-cache-owner="{{ $deviceCacheOwner }}">
    <x-toast-host />
    {{-- Live regions exist from page load so toasts are announced (app.announce). --}}
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-live-polite></div>
    <div class="sr-only" aria-live="assertive" aria-atomic="true" data-live-assertive></div>

    <x-modal data-qr-scanner-modal>
        <x-modal.backdrop data-qr-scanner-backdrop />
        <x-modal.panel role="dialog" aria-modal="true" aria-labelledby="qr-scanner-title" tabindex="-1" data-qr-scanner-panel>
            <x-modal.header>
                <div>
                    <div class="text-xs font-medium text-ink-subtle">Absensi</div>
                    <x-modal.title id="qr-scanner-title">Scan kode QR</x-modal.title>
                </div>
                <x-modal.close data-qr-scanner-close />
            </x-modal.header>
            <x-modal.body>
                <div class="relative mb-3 aspect-square overflow-hidden rounded-lg border border-border bg-surface">
                    <video class="size-full object-cover" data-qr-scanner-video playsinline autoplay muted></video>
                </div>
                <x-alert tone="info" data-qr-scanner-status role="alert">Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.</x-alert>
            </x-modal.body>
        </x-modal.panel>
    </x-modal>

    {{--
        The signed-in shell. From lg up: the sidebar on the canvas and the page
        in an inset panel, its top bar holding the sync status, the bell and the
        theme toggle. Below lg: the same top bar with the logo and the avatar,
        and the bottom navigation. The document scrolls (pull to refresh reads
        window.scrollY); [data-app-shell] leaves room for the bottom navigation.
    --}}
    <div class="min-h-dvh max-lg:pb-[calc(5.5rem+env(safe-area-inset-bottom,0px))] lg:ps-60 print:p-0" data-app-shell>
        <aside class="fixed inset-y-0 start-0 z-40 hidden w-60 flex-col lg:flex print:hidden">
            <div class="flex h-14 shrink-0 items-center px-5">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" aria-label="WeCIC, beranda">
                    <x-brand-logo class="h-7" />
                </a>
            </div>

            <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-3 pb-3 pt-1" aria-label="Navigasi utama">
                @foreach ($desktopNavigation as $item)
                    <x-nav.sidebar-item :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                @endforeach

                <div class="flex flex-col gap-0.5" role="group" aria-labelledby="sidebar-more-label">
                    <p class="px-2.5 pb-1 pt-4 text-xs font-medium text-ink-subtle" id="sidebar-more-label">Lainnya</p>
                    @foreach ($moreMenu as $item)
                        <x-nav.sidebar-item :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                    @endforeach
                    {{-- Opens the /more hub; its badge (lainnya-desktop) sums what the hub lists. --}}
                    <x-nav.sidebar-item :href="route('more')" label="Semua menu" :icon="$icons['more']" badge="lainnya-desktop" nav-slot="more" :active="request()->routeIs('more')" />
                </div>
            </nav>

            <div class="shrink-0 p-3">
                <div class="relative" data-account-menu>
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2.5 rounded-lg p-2 text-start transition-colors hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-expanded:bg-black/5 dark:hover:bg-white/5 dark:aria-expanded:bg-white/5"
                        data-account-menu-trigger
                        aria-expanded="false"
                        aria-haspopup="true"
                        aria-label="Menu akun"
                    >
                        <x-avatar data-user-avatar>M</x-avatar>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink" data-user-name>Mahasiswa</span>
                            <span class="block truncate text-xs text-ink-subtle" data-user-identity>Portal kampus</span>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0 text-ink-subtle" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4 -4l4 4" /><path d="M16 15l-4 4l-4 -4" /></svg>
                    </button>
                    <div class="absolute inset-x-0 bottom-full z-50 mb-1 rounded-xl border border-border bg-panel p-1 shadow-float hidden" data-account-menu-panel role="menu">
                        <a href="{{ route('profile') }}" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem" @if (request()->routeIs('profile')) aria-current="page" @endif>
                            <span class="min-w-0 flex-1">Profil</span>
                            <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="profile"></span>
                        </a>
                        <a href="{{ route('password.edit') }}" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem" @if (request()->routeIs('password.*')) aria-current="page" @endif>
                            <span class="min-w-0 flex-1">Ubah password</span>
                            <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="password"></span>
                        </a>
                        <a href="{{ route('more') }}#panduan" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem">Panduan</a>
                        <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem">Portal kampus</a>
                        <div class="-mx-1 my-1 h-px bg-border-light" role="separator"></div>
                        <button type="button" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm font-medium text-danger transition-colors hover:bg-danger-soft focus-visible:bg-danger-soft focus-visible:outline-none sm:min-h-9" data-logout role="menuitem">Keluar</button>
                    </div>
                </div>
            </div>
        </aside>

        <div class="lg:p-2 print:p-0">
            <div class="flex min-h-dvh flex-col bg-panel lg:min-h-[calc(100dvh-1rem)] lg:rounded-xl lg:border lg:border-border lg:shadow-xs dark:lg:shadow-none print:min-h-0 print:rounded-none print:border-0 print:shadow-none">
                <header class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-border bg-panel/90 px-4 backdrop-blur md:px-6 lg:rounded-t-xl lg:px-8 print:hidden">
                    <a href="{{ route('dashboard') }}" class="inline-flex shrink-0 items-center rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand lg:hidden" aria-label="Beranda">
                        <x-brand-logo class="h-7" />
                    </a>

                    {{-- From lg up: where the page sits (pages may set @section('breadcrumb')), then the sync status beside the bell. --}}
                    <div class="hidden min-w-0 flex-1 items-center lg:flex" data-top-bar-crumbs>
                        @hasSection('breadcrumb')
                            @yield('breadcrumb')
                        @else
                            <x-breadcrumb size="md" />
                        @endif
                    </div>

                    @unless ($hideStatusStrip)
                        <div class="hidden min-w-0 max-w-md shrink lg:block">
                            <x-sync-strip :refresh="! $hideSyncRefresh" />
                        </div>
                        <span class="hidden h-5 w-px shrink-0 bg-border lg:block" aria-hidden="true"></span>
                    @endunless

                    <div class="ms-auto flex shrink-0 items-center gap-1 lg:ms-0">
                        <a
                            href="{{ route('notifications.index') }}"
                            class="inline-flex size-9 shrink-0 cursor-pointer select-none items-center justify-center rounded-lg text-ink-muted ring-offset-panel transition-colors hover:bg-surface hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 relative aria-[current=page]:bg-surface aria-[current=page]:text-ink"
                            data-notification-bell
                            @if (request()->routeIs('notifications.*')) aria-current="page" @endif
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" />
                                <path d="M9 17v1a3 3 0 0 0 6 0v-1" />
                            </svg>
                            <span class="sr-only">Notifikasi</span>
                            <span class="pointer-events-none absolute end-0.5 top-0.5 h-4 min-w-4 rounded-full bg-danger px-1 text-center text-[11px] font-medium leading-4 text-white tabular-nums ring-2 ring-panel data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2.5 data-[variant=dot]:min-w-2.5 data-[variant=dot]:px-0 hidden" data-nav-badge="notifications"></span>
                        </a>

                        <x-button variant="icon" data-theme-toggle aria-pressed="false" aria-label="Ganti tema">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-[18px] dark:hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.39 5.39 0 0 1-4.4 2.6 5.37 5.37 0 0 1-3.14-9.74c-.44-.06-.9-.1-1.36-.1z" />
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="hidden size-[18px] dark:block" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 8a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                                <path d="M12 2v2" />
                                <path d="M12 20v2" />
                                <path d="M4.93 4.93l1.41 1.41" />
                                <path d="M17.66 17.66l1.41 1.41" />
                                <path d="M2 12h2" />
                                <path d="M20 12h2" />
                                <path d="M6.34 17.66l-1.41 1.41" />
                                <path d="M19.07 4.93l-1.41 1.41" />
                            </svg>
                        </x-button>

                        {{-- Below lg the sidebar is hidden, so the top bar's avatar opens its own copy of the account menu. --}}
                        <div class="relative ms-1 shrink-0 lg:hidden" data-account-menu>
                            <button
                                type="button"
                                class="inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:min-h-9 sm:min-w-9"
                                data-account-menu-trigger
                                aria-expanded="false"
                                aria-haspopup="true"
                                aria-label="Menu akun"
                            >
                                <x-avatar data-user-avatar>M</x-avatar>
                            </button>
                            <div class="absolute end-0 top-full z-50 mt-2 min-w-52 rounded-xl border border-border bg-panel p-1 shadow-float hidden" data-account-menu-panel role="menu">
                                <div class="border-b border-border-light px-2.5 pb-2 pt-1.5 mb-1">
                                    <span class="block truncate text-sm font-medium text-ink" data-user-name>Mahasiswa</span>
                                    <span class="block truncate text-xs text-ink-subtle" data-user-identity>Portal kampus</span>
                                </div>
                                <a href="{{ route('profile') }}" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem" @if (request()->routeIs('profile')) aria-current="page" @endif>
                                    <span class="min-w-0 flex-1">Profil</span>
                                    <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="profile"></span>
                                </a>
                                <a href="{{ route('password.edit') }}" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem" @if (request()->routeIs('password.*')) aria-current="page" @endif>
                                    <span class="min-w-0 flex-1">Ubah password</span>
                                    <span class="ms-auto h-5 min-w-5 shrink-0 rounded-full bg-danger px-1.5 text-center text-[11px] font-medium leading-5 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="password"></span>
                                </a>
                                <a href="{{ route('more') }}#panduan" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem">Panduan</a>
                                <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none aria-[current=page]:font-medium aria-[current=page]:text-ink sm:min-h-9" role="menuitem">Portal kampus</a>
                                <div class="-mx-1 my-1 h-px bg-border-light" role="separator"></div>
                                <button type="button" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-start text-sm font-medium text-danger transition-colors hover:bg-danger-soft focus-visible:bg-danger-soft focus-visible:outline-none sm:min-h-9" data-logout role="menuitem">Keluar</button>
                            </div>
                        </div>
                    </div>
                </header>

                {{-- overflow-x-clip keeps anything too wide from scrolling the page sideways (it is no scroll container, so sticky asides still stick). --}}
                <main class="flex-1 overflow-x-clip">
                    <div class="mx-auto w-full max-w-6xl px-4 py-6 md:px-6 lg:px-8">
                        <div class="rounded-lg border border-border bg-panel px-4 py-3 text-sm text-ink-muted text-center mb-3 hidden" data-pull-indicator aria-hidden="true">
                            <span class="flex items-center justify-center gap-2">
                                <span class="block size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent hidden" data-pull-spinner aria-hidden="true"></span>
                                <span data-pull-copy>Tarik untuk memperbarui</span>
                            </span>
                        </div>

                        @unless ($hideStatusStrip)
                            <div class="mb-4 border-b border-border-light pb-2 lg:hidden print:hidden">
                                <x-sync-strip :refresh="! $hideSyncRefresh" />
                            </div>
                        @endunless

                        <div data-operation-status class="mb-4 empty:mb-0"></div>

                        @if ($isPortalOffline)
                            <x-alert tone="warning" class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden" role="status" data-portal-offline-banner>
                                <span class="min-w-0">
                                    <span class="block font-medium">Mode offline</span>
                                    <span class="block text-sm">Portal kampus tidak bisa diakses saat kamu masuk. Data diambil dari arsip WeCIC. Absen dan upload yang kamu kirim disimpan dulu di antrean WeCIC (belum terkirim ke portal) dan dikirim otomatis begitu portal pulih, selama tenggatnya belum lewat; sinkronisasi menunggu. <span data-portal-offline-reconnect-text>{{ \App\Http\Controllers\API\PortalAuthController::offlineReconnectMessage(session('campus_offline_reconnect')) }}</span></span>
                                </span>
                                <x-button variant="secondary" size="sm" data-logout="">Masuk ulang</x-button>
                            </x-alert>
                        @endif

                        @yield('content')
                    </div>
                </main>
            </div>
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-panel/95 pb-[env(safe-area-inset-bottom,0px)] backdrop-blur lg:hidden print:hidden" aria-label="Navigasi mobile">
            <div class="mx-auto flex max-w-lg items-center px-2 py-1.5">
                @foreach ($mobileNavigation['start'] as $item)
                    <x-nav.bottom-item :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                @endforeach

                <div class="flex flex-1 flex-col items-center gap-1 px-1 py-1" data-nav-slot="absen">
                    <button type="button" class="flex h-8 w-11 cursor-pointer items-center justify-center rounded-lg bg-brand text-brand-ink shadow-xs transition-colors hover:bg-brand-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:bg-brand-pressed" data-qr-quick aria-label="Scan QR absensi">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M17 7l0 .01" /><path d="M14 14l3 0" /><path d="M20 14l0 .01" /><path d="M14 14l0 3" /><path d="M14 20l3 0" /><path d="M17 17l3 0" /><path d="M20 17l0 3" /></svg>
                    </button>
                    <span class="text-[11px] font-medium text-ink-muted">Absen</span>
                </div>

                @foreach ($mobileNavigation['end'] as $item)
                    <x-nav.bottom-item :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                @endforeach
            </div>
        </nav>
    </div>

    @stack('scripts')
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            });
        }
    </script>
</body>
</html>
