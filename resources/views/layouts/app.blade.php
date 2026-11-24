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

                if (localStorage.getItem('mycic-sidebar-collapsed') === '1') {
                    document.documentElement.dataset.sidebarCollapsed = '1';
                }
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
        ['home', 'class', 'academic', 'more'],
        array_map($svg, ['home', 'class', 'academic', 'more']),
    );

    $isClassSection = request()->routeIs('class.*', 'meeting.show', 'exam.*');
    $isAcademicSection = request()->routeIs('krs.*', 'grades.*', 'transcript.*');
    $isFinanceSection = request()->routeIs('finance.*');
    $isMoreSection = request()->routeIs('skkm.*', 'announcement.*', 'thesis.*', 'library.*', 'profile', 'password.*', 'more', 'archive.*', 'notebook.*');
    $isNotebookSection = request()->routeIs('notebook.*');
    $isMoreHubSection = request()->routeIs('skkm.*', 'announcement.*', 'thesis.*', 'library.*', 'more', 'archive.*');
    $isSettingsSection = request()->routeIs('profile', 'password.*', 'notifications.*', 'account.*');
    $activeRailContext = match (true) {
        $isClassSection => 'kuliah',
        $isAcademicSection => 'akademik',
        $isFinanceSection => 'keuangan',
        $isNotebookSection => 'notebook',
        $isMoreHubSection => 'more',
        $isSettingsSection => 'settings',
        default => 'beranda',
    };
    $hasSidebarTrail = in_array($activeRailContext, ['kuliah', 'akademik', 'notebook', 'more', 'settings'], true);
    $sidebarContextTitle = match ($activeRailContext) {
        'kuliah' => 'Kuliah',
        'akademik' => 'Akademik',
        'notebook' => 'Notebook',
        'more' => 'Lainnya',
        'settings' => 'Pengaturan',
        default => 'WeCIC',
    };
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
        The signed-in shell. From lg up: a two-tier Devin-style sidebar (far-left
        activity rail + collapsible contextual sidebar) on the canvas and the page
        in an inset panel, its top bar holding the breadcrumb, sync status, bell
        and theme toggle. Below lg: the same top bar with the logo and avatar,
        and the bottom navigation. The document scrolls (pull to refresh reads
        window.scrollY); [data-app-shell] leaves room for the bottom navigation.
    --}}
    <div @class(['min-h-dvh max-lg:pb-[calc(5.5rem+env(safe-area-inset-bottom,0px))] print:p-0', 'lg:ps-72' => $hasSidebarTrail, 'lg:ps-12' => ! $hasSidebarTrail]) data-app-shell data-has-trail="{{ $hasSidebarTrail ? '1' : '0' }}">
        <aside class="fixed inset-y-0 start-0 z-[45] hidden lg:flex print:hidden">
            {{-- Primary Activity Rail (48px) --}}
            <div class="flex w-12 shrink-0 flex-col items-center justify-between border-e border-border-light bg-canvas py-2.5 dark:border-transparent" data-activity-rail>
                <nav class="flex flex-col items-center gap-1" aria-label="Navigasi utama">
                    <a
                        href="{{ route('dashboard') }}"
                        class="group relative flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-[current=page]:bg-black/[0.08] aria-[current=page]:text-ink data-[rail-selected=true]:bg-black/[0.08] data-[rail-selected=true]:text-ink dark:hover:bg-white/5 dark:aria-[current=page]:bg-surface dark:data-[rail-selected=true]:bg-surface"
                        aria-label="WeCIC, beranda"
                        title="Beranda"
                        data-rail-slot="beranda"
                        data-nav-slot="beranda"
                        @if (request()->routeIs('dashboard')) aria-current="page" @endif
                    >
                        <x-brand-logo class="sr-only" />
                        <x-icon name="home" />
                    </a>

                    <x-nav.rail-item :href="route('class.index')" label="Kuliah" icon="class" rail-slot="kuliah" nav-slot="kuliah" badge="kuliah" data-rail-has-trail="1" :active="$isClassSection" />
                    <x-nav.rail-item :href="route('krs.index')" label="Akademik" icon="academic" rail-slot="akademik" nav-slot="akademik" badge="akademik" data-rail-has-trail="1" :active="$isAcademicSection" />
                    <x-nav.rail-item :href="route('finance.index')" label="Keuangan" icon="finance" rail-slot="keuangan" nav-slot="keuangan" badge="keuangan" :active="$isFinanceSection" />
                    <x-nav.rail-item :href="route('notebook.index')" label="Notebook" icon="notebook" rail-slot="notebook" nav-slot="notebook" badge="notebook" data-rail-has-trail="1" :active="$isNotebookSection" />
                    <x-nav.rail-item :href="route('more')" label="Lainnya" icon="dots" rail-slot="more" nav-slot="more" badge="lainnya-desktop" data-rail-has-trail="1" :active="$isMoreHubSection" />
                </nav>

                <div class="flex flex-col items-center gap-1">
                    <x-nav.rail-item :href="route('more') . '#panduan'" label="Panduan & Bantuan" icon="help" rail-slot="help" data-rail-has-trail="1" />
                    <x-nav.rail-item :href="route('profile')" label="Pengaturan" icon="settings" rail-slot="settings" data-rail-has-trail="1" :active="$isSettingsSection" />
                    <button
                        type="button"
                        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-subtle transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:hover:bg-white/5"
                        data-sidebar-toggle
                        aria-label="{{ $hasSidebarTrail ? 'Ciutkan sidebar' : 'Perluas sidebar' }}"
                        aria-expanded="{{ $hasSidebarTrail ? 'true' : 'false' }}"
                        title="{{ $hasSidebarTrail ? 'Ciutkan sidebar' : 'Perluas sidebar' }}"
                    >
                        <x-icon name="sidebar-toggle" />
                    </button>
                </div>
            </div>

            {{-- Contextual Trail Secondary Sidebar (240px; hidden by default on Beranda and Keuangan) --}}
            <div @class(['w-60 min-w-0 flex-col bg-canvas', 'flex' => $hasSidebarTrail, 'hidden' => ! $hasSidebarTrail]) data-sidebar-panel data-sidebar-active-context="{{ $activeRailContext }}" data-sidebar-has-trail="{{ $hasSidebarTrail ? '1' : '0' }}">
                <div class="flex h-11 shrink-0 items-center justify-between gap-2 px-3.5">
                    <span class="truncate text-[13px] font-semibold tracking-tight text-ink" data-sidebar-context-title>{{ $sidebarContextTitle }}</span>

                    <div class="relative" data-dropdown>
                        <button
                            type="button"
                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-ink-subtle transition-colors hover:bg-black/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:hover:bg-white/5"
                            data-dropdown-trigger
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-label="Pintasan cepat"
                        >
                            <x-icon name="dots" size="sm" />
                        </button>
                        <div class="absolute end-0 top-full z-50 mt-2 min-w-52 rounded-xl border border-border bg-panel p-1.5 shadow-float hidden" data-dropdown-panel role="menu">
                            <p class="px-2.5 py-1.5 text-xs font-medium text-ink-subtle">Pintasan</p>
                            <a href="{{ route('more') }}" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none sm:min-h-9" role="menuitem"><x-icon name="more" size="sm" class="text-ink-subtle" />Semua menu</a>
                            <a href="{{ route('more') }}#panduan" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none sm:min-h-9" role="menuitem"><x-icon name="help" size="sm" class="text-ink-subtle" />Panduan</a>
                            <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-start text-sm text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:bg-surface focus-visible:text-ink focus-visible:outline-none sm:min-h-9" role="menuitem"><x-icon name="external-link" size="sm" class="text-ink-subtle" />Portal kampus</a>
                        </div>
                    </div>
                </div>

                <div class="px-3 pb-2 pt-1">
                    <div class="relative flex items-center">
                        <span class="pointer-events-none absolute start-2.5 top-1/2 z-10 -translate-y-1/2 text-ink-subtle">
                            <x-icon name="search" class="size-3.5!" width="14" height="14" />
                        </span>
                        <input
                            type="search"
                            class="h-8 w-full min-w-0 rounded-lg border border-border bg-panel pe-7 ps-8 text-xs text-ink transition-[color,box-shadow,border-color,background-color] placeholder:text-ink-subtle focus-visible:border-brand focus-visible:bg-panel focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/20 dark:border-border/80 dark:bg-surface/60 dark:focus-visible:bg-surface"
                            placeholder="Cari menu..."
                            aria-label="Cari menu navigasi"
                            autocomplete="off"
                            data-sidebar-search
                        >
                        <kbd class="pointer-events-none absolute end-1.5 top-1/2 flex h-5 min-w-5 -translate-y-1/2 items-center justify-center rounded border border-border bg-panel/80 px-1 font-mono text-[10px] font-medium text-ink-subtle dark:bg-surface" aria-hidden="true" data-sidebar-search-kbd>/</kbd>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-2.5 pb-3 pt-0.5">
                    <nav @class(['flex-col gap-0.5', 'flex' => $activeRailContext === 'kuliah', 'hidden' => $activeRailContext !== 'kuliah']) aria-label="Menu Kuliah" data-sidebar-trail="kuliah">
                        <x-nav.sidebar-subitem :href="route('class.index')" label="Jadwal & Kelas" icon="calendar" sub-slot="class" :active="request()->routeIs('class.*', 'meeting.show')" />
                        <x-nav.sidebar-subitem :href="route('exam.index')" label="Ujian" icon="exam" sub-slot="exam" badge="exam" :active="request()->routeIs('exam.*')" />
                    </nav>

                    <nav @class(['flex-col gap-0.5', 'flex' => $activeRailContext === 'akademik', 'hidden' => $activeRailContext !== 'akademik']) aria-label="Menu Akademik" data-sidebar-trail="akademik">
                        <x-nav.sidebar-subitem :href="route('krs.index')" label="KRS & Perwalian" icon="list-check" sub-slot="krs" badge="krs" :active="request()->routeIs('krs.*')" />
                        <x-nav.sidebar-subitem :href="route('grades.index')" label="Nilai" icon="chart" sub-slot="grades" badge="grades" :active="request()->routeIs('grades.*')" />
                        <x-nav.sidebar-subitem :href="route('transcript.index')" label="Transkrip" icon="file" sub-slot="transcript" badge="transcript" :active="request()->routeIs('transcript.*')" />
                    </nav>

                    <nav @class(['flex-col gap-0.5', 'flex' => $activeRailContext === 'notebook', 'hidden' => $activeRailContext !== 'notebook']) aria-label="Menu Notebook" data-sidebar-trail="notebook">
                        <x-nav.sidebar-subitem :href="route('notebook.index')" label="Notebook" icon="notebook" sub-slot="notebook" :active="request()->routeIs('notebook.*') && ! request()->routeIs('notebook.ai-settings')" />
                        <x-nav.sidebar-subitem :href="route('notebook.ai-settings')" label="Pengaturan AI" icon="settings" sub-slot="notebook-ai-settings" :active="request()->routeIs('notebook.ai-settings')" />
                    </nav>

                    <nav @class(['flex-col gap-0.5', 'flex' => $activeRailContext === 'more', 'hidden' => $activeRailContext !== 'more']) aria-label="Menu Lainnya" data-sidebar-trail="more">
                        <x-nav.sidebar-subitem :href="route('skkm.index')" label="SKKM" icon="skkm" sub-slot="skkm" badge="skkm" :active="request()->routeIs('skkm.*')" />
                        <x-nav.sidebar-subitem :href="route('thesis.index')" label="Skripsi" icon="thesis" sub-slot="thesis" :active="request()->routeIs('thesis.*')" />
                        <x-nav.sidebar-subitem :href="route('library.index')" label="Perpustakaan" icon="library" sub-slot="library" :active="request()->routeIs('library.*')" />
                        <x-nav.sidebar-subitem :href="route('announcement.index')" label="Pengumuman" icon="announcement" sub-slot="announcement" badge="announcement" :active="request()->routeIs('announcement.*')" />
                        <x-nav.sidebar-subitem :href="route('archive.index')" label="Arsip" icon="archive" sub-slot="archive" :active="request()->routeIs('archive.*')" />
                        <x-nav.sidebar-subitem :href="route('more')" label="Semua menu" icon="more" sub-slot="more" :active="request()->routeIs('more')" />
                    </nav>

                    {{-- Contextual Settings navigation when settings / account pages are active --}}
                    <nav @class(['flex-col gap-0.5', 'flex' => $activeRailContext === 'settings', 'hidden' => $activeRailContext !== 'settings']) aria-label="Navigasi pengaturan" data-sidebar-trail="settings" data-sidebar-context-nav="settings">
                        <div class="flex flex-col gap-0.5" role="group" aria-labelledby="settings-account-label">
                            <p class="px-2.5 pb-1 pt-1 text-[11px] font-medium tracking-wide text-ink-subtle" id="settings-account-label">Akun &amp; Preferensi</p>
                            <x-nav.sidebar-subitem :href="route('profile')" label="Profil" icon="user" sub-slot="profile" :active="request()->routeIs('profile')" />
                            <x-nav.sidebar-subitem :href="route('password.edit')" label="Ubah password" icon="lock" sub-slot="password" :active="request()->routeIs('password.*')" />
                            <x-nav.sidebar-subitem :href="route('notifications.index')" label="Notifikasi" icon="bell" sub-slot="notifications" :active="request()->routeIs('notifications.*')" />
                        </div>

                        <div class="flex flex-col gap-0.5" role="group" aria-labelledby="settings-privacy-label">
                            <p class="px-2.5 pb-1 pt-3.5 text-[11px] font-medium tracking-wide text-ink-subtle" id="settings-privacy-label">Privasi &amp; Data</p>
                            <x-nav.sidebar-subitem :href="route('account.delete')" label="Hapus akun" icon="alert" sub-slot="account-delete" :active="request()->routeIs('account.*')" />
                        </div>
                    </nav>

                    <p class="px-2.5 py-4 text-center text-xs text-ink-subtle hidden" data-sidebar-search-empty>Menu tidak ditemukan.</p>
                </div>

                <div class="shrink-0 px-2.5 py-2">
                    <div class="relative" data-account-menu>
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-start transition-colors hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand aria-expanded:bg-black/5 dark:hover:bg-white/5 dark:aria-expanded:bg-white/5"
                            data-account-menu-trigger
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-label="Menu akun"
                        >
                            <x-avatar class="size-5! rounded-md! text-[11px]!" data-user-avatar>M</x-avatar>
                            <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-ink" data-user-name>Mahasiswa</span>
                            <span class="sr-only" data-user-identity>Portal kampus</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 shrink-0 text-ink-subtle" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                        </button>
                        <div class="absolute inset-x-0 bottom-full z-50 mb-1.5 min-w-56 rounded-lg border border-border bg-panel p-1.5 shadow-float hidden" data-account-menu-panel role="menu">
                            <div class="flex items-center gap-3 px-2.5 pb-2.5 pt-2">
                                <x-avatar class="size-9! rounded! text-sm! font-semibold! ring-0!" data-user-avatar>M</x-avatar>
                                <div class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium leading-tight text-ink" data-user-name>Mahasiswa</span>
                                    <span class="mt-0.5 block truncate text-xs text-ink-subtle tabular-nums" data-user-identity>Portal kampus</span>
                                </div>
                            </div>
                            <div class="mb-1.5 flex flex-wrap items-center gap-1.5 px-2.5 pb-1">
                                <a
                                    href="{{ route('profile') }}"
                                    class="relative inline-flex h-7 cursor-pointer items-center gap-1.5 rounded border border-border bg-panel px-2.5 text-xs font-medium text-ink shadow-2xs transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-[current=page]:border-brand/40 aria-[current=page]:bg-surface-tint aria-[current=page]:text-brand-text"
                                    role="menuitem"
                                    @if (request()->routeIs('profile')) aria-current="page" @endif
                                >
                                    <span>Profil</span>
                                    <span class="ms-auto h-4 min-w-4 shrink-0 rounded-full bg-danger px-1 text-center text-[10px] font-medium leading-4 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="profile"></span>
                                </a>
                                <a
                                    href="{{ route('password.edit') }}"
                                    class="relative inline-flex h-7 cursor-pointer items-center gap-1.5 rounded border border-border bg-panel px-2.5 text-xs font-medium text-ink shadow-2xs transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-[current=page]:border-brand/40 aria-[current=page]:bg-surface-tint aria-[current=page]:text-brand-text"
                                    role="menuitem"
                                    @if (request()->routeIs('password.*')) aria-current="page" @endif
                                >
                                    <span>Ubah password</span>
                                    <span class="ms-auto h-4 min-w-4 shrink-0 rounded-full bg-danger px-1 text-center text-[10px] font-medium leading-4 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="password"></span>
                                </a>
                            </div>
                            <a href="{{ route('notifications.index') }}" class="flex min-h-10 w-full cursor-pointer items-center justify-between gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" role="menuitem">
                                <span class="min-w-0 flex-1">Notifikasi</span>
                                <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
                            </a>
                            <a href="{{ route('notebook.ai-settings') }}" class="flex min-h-10 w-full cursor-pointer items-center justify-between gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" role="menuitem">
                                <span class="min-w-0 flex-1">Pengaturan AI</span>
                                <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
                            </a>
                            <div class="-mx-1.5 my-1 h-px bg-border-light" role="separator"></div>
                            <button type="button" class="flex min-h-10 w-full cursor-pointer items-center gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" data-logout role="menuitem">Keluar</button>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <div class="lg:py-2 lg:pe-2 lg:ps-0 print:p-0">
            <div class="flex min-h-dvh flex-col bg-panel lg:min-h-[calc(100dvh-1rem)] lg:rounded-xl lg:border lg:border-border lg:shadow-xs dark:lg:shadow-none print:min-h-0 print:rounded-none print:border-0 print:shadow-none">
                <header class="sticky top-0 z-40 flex h-14 shrink-0 items-center gap-3 border-b border-border bg-panel px-4 md:px-6 lg:-mx-px lg:-mt-px lg:top-2 lg:h-11 lg:rounded-t-xl lg:border lg:border-border lg:px-6 lg:shadow-[0_-0.5rem_0_0.5rem_var(--color-canvas)] print:hidden">
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
                                <path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z" />
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="hidden size-[18px] dark:block" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                                <path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" />
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
                            <div class="absolute end-0 top-full z-50 mt-2 min-w-60 rounded-lg border border-border bg-panel p-1.5 shadow-float hidden" data-account-menu-panel role="menu">
                                <div class="flex items-center gap-3 px-2.5 pb-2.5 pt-2">
                                    <x-avatar class="size-9! rounded! text-sm! font-semibold! ring-0!" data-user-avatar>M</x-avatar>
                                    <div class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium leading-tight text-ink" data-user-name>Mahasiswa</span>
                                        <span class="mt-0.5 block truncate text-xs text-ink-subtle tabular-nums" data-user-identity>Portal kampus</span>
                                    </div>
                                </div>
                                <div class="mb-1.5 flex flex-wrap items-center gap-1.5 px-2.5 pb-1">
                                    <a
                                        href="{{ route('profile') }}"
                                        class="relative inline-flex h-7 cursor-pointer items-center gap-1.5 rounded border border-border bg-panel px-2.5 text-xs font-medium text-ink shadow-2xs transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-[current=page]:border-brand/40 aria-[current=page]:bg-surface-tint aria-[current=page]:text-brand-text"
                                        role="menuitem"
                                        @if (request()->routeIs('profile')) aria-current="page" @endif
                                    >
                                        <span>Profil</span>
                                        <span class="ms-auto h-4 min-w-4 shrink-0 rounded-full bg-danger px-1 text-center text-[10px] font-medium leading-4 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="profile"></span>
                                    </a>
                                    <a
                                        href="{{ route('password.edit') }}"
                                        class="relative inline-flex h-7 cursor-pointer items-center gap-1.5 rounded border border-border bg-panel px-2.5 text-xs font-medium text-ink shadow-2xs transition-colors hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 aria-[current=page]:border-brand/40 aria-[current=page]:bg-surface-tint aria-[current=page]:text-brand-text"
                                        role="menuitem"
                                        @if (request()->routeIs('password.*')) aria-current="page" @endif
                                    >
                                        <span>Ubah password</span>
                                        <span class="ms-auto h-4 min-w-4 shrink-0 rounded-full bg-danger px-1 text-center text-[10px] font-medium leading-4 text-white tabular-nums data-[tone=brand]:bg-brand data-[tone=info]:bg-brand data-[tone=warning]:bg-warning data-[tone=warning]:text-amber-950 data-[variant=dot]:h-2 data-[variant=dot]:min-w-2 data-[variant=dot]:px-0 hidden" data-nav-badge="password"></span>
                                    </a>
                                </div>
                                <a href="{{ route('notifications.index') }}" class="flex min-h-10 w-full cursor-pointer items-center justify-between gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" role="menuitem">
                                    <span class="min-w-0 flex-1">Notifikasi</span>
                                    <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
                                </a>
                                <a href="{{ route('notebook.ai-settings') }}" class="flex min-h-10 w-full cursor-pointer items-center justify-between gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" role="menuitem">
                                    <span class="min-w-0 flex-1">Pengaturan AI</span>
                                    <x-icon name="chevron-right" size="sm" class="text-ink-subtle" />
                                </a>
                                <div class="-mx-1.5 my-1 h-px bg-border-light" role="separator"></div>
                                <button type="button" class="flex min-h-10 w-full cursor-pointer items-center gap-2 rounded px-2.5 py-2 text-start text-[13px] font-normal text-ink transition-colors hover:bg-surface focus-visible:bg-surface focus-visible:outline-none sm:min-h-8" data-logout role="menuitem">Keluar</button>
                            </div>
                        </div>
                    </div>
                </header>

                {{-- overflow-x-clip keeps anything too wide from scrolling the page sideways (it is no scroll container, so sticky asides still stick). --}}
                <main class="flex-1 overflow-x-clip">
                    <div class="mx-auto w-full max-w-6xl px-4 py-6 md:px-6 lg:px-8 lg:py-8">
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
                                    <span class="block text-sm">Portal kampus sedang offline. Data dari arsip WeCIC; absen &amp; upload masuk antrean dan belum terkirim ke portal. <span data-portal-offline-reconnect-text>{{ \App\Http\Controllers\API\PortalAuthController::offlineReconnectMessage(session('campus_offline_reconnect')) }}</span></span>
                                </span>
                                <x-button variant="secondary" size="sm" data-logout="">Masuk ulang</x-button>
                            </x-alert>
                        @endif

                        @yield('content')
                    </div>
                </main>

                <div class="pointer-events-none sticky bottom-2 z-40 -mx-px -mb-px -mt-[11px] hidden h-3 shrink-0 rounded-b-xl border-x border-b border-border shadow-[0_0.5rem_0_0.5rem_var(--color-canvas)] lg:block print:hidden" aria-hidden="true" data-shell-bottom-cap></div>
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
