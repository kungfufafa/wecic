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
    <meta name="theme-color" content="#0352A1">
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
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $icons = [
        'home' => <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12l-2 0l9 -9l9 9l-2 0" />
                <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" />
                <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />
            </svg>
        SVG,
        'class' => <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
                <path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" />
                <path d="M3 6l0 13" />
                <path d="M12 6l0 13" />
                <path d="M21 6l0 13" />
            </svg>
        SVG,
        'academic' => <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 9l-10 -4l-10 4l10 4l10 -4v6" />
                <path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4" />
            </svg>
        SVG,
        'finance' => <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" />
                <path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />
            </svg>
        SVG,
        'more' => <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" />
                <path d="M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" />
                <path d="M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" />
                <path d="M14 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" />
            </svg>
        SVG,
    ];

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

    $moreMenu = [
        ['href' => route('skkm.index'), 'label' => 'SKKM', 'badge' => 'skkm', 'active' => request()->routeIs('skkm.*')],
        ['href' => route('thesis.index'), 'label' => 'Skripsi', 'badge' => 'thesis', 'active' => request()->routeIs('thesis.*')],
        ['href' => route('library.index'), 'label' => 'Perpustakaan', 'badge' => 'library', 'active' => request()->routeIs('library.*')],
        ['href' => route('announcement.index'), 'label' => 'Pengumuman', 'badge' => 'announcement', 'active' => request()->routeIs('announcement.*')],
        ['href' => route('notebook.index'), 'label' => 'Notebook', 'badge' => 'notebook', 'active' => request()->routeIs('notebook.*')],
        ['href' => route('archive.index'), 'label' => 'Arsip', 'badge' => 'archive', 'active' => request()->routeIs('archive.*')],
        ['href' => route('profile'), 'label' => 'Profil', 'badge' => 'profile', 'active' => request()->routeIs('profile')],
        ['href' => route('password.edit'), 'label' => 'Ubah password', 'badge' => 'password', 'active' => request()->routeIs('password.*')],
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
    <div id="notice-host" class="mycic-toast-host"></div>
    {{-- Live regions exist from page load so toasts are announced (app.announce). --}}
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-live-polite></div>
    <div class="sr-only" aria-live="assertive" aria-atomic="true" data-live-assertive></div>

    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" data-qr-scanner-modal>
        <div class="mycic-modal-backdrop" data-qr-scanner-backdrop></div>
        <div class="mycic-modal-panel" role="dialog" aria-modal="true" aria-labelledby="qr-scanner-title" tabindex="-1" data-qr-scanner-panel>
            <div class="mycic-modal-header">
                <div>
                    <div class="text-caption font-medium text-ink-subtle">Absensi</div>
                    <h2 class="mycic-modal-title" id="qr-scanner-title">Scan kode QR</h2>
                </div>
                <button type="button" class="mycic-modal-close" aria-label="Tutup" data-qr-scanner-close>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6l-12 12" />
                        <path d="M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mycic-modal-body">
                <div class="relative mb-3 aspect-square overflow-hidden rounded-lg border border-border bg-surface">
                    <video class="h-full w-full object-cover" data-qr-scanner-video playsinline autoplay muted></video>
                </div>
                <div class="mycic-alert-info mb-0" data-qr-scanner-status role="alert">
                    Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.
                </div>
            </div>
        </div>
    </div>

    <div class="mycic-page">
        <header class="mycic-page-header print:hidden">
            <div class="mycic-container flex h-14 items-center gap-3 md:gap-4">
                <a href="{{ route('dashboard') }}" class="mycic-brand-wordmark shrink-0" aria-label="WeCIC, beranda">
                    <x-brand-logo class="h-8" />
                </a>

                <nav class="mycic-top-nav" aria-label="Navigasi utama">
                    @foreach ($desktopNavigation as $item)
                        <x-nav.top-link :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                    @endforeach

                    <div class="mycic-dropdown" data-dropdown>
                        <button
                            type="button"
                            class="mycic-top-nav-link {{ $isMoreSection ? 'mycic-top-nav-link-active' : '' }}"
                            title="Lainnya"
                            data-dropdown-trigger
                            data-nav-slot="lainnya"
                            aria-expanded="false"
                            aria-haspopup="true"
                        >
                            <span class="relative inline-flex">
                                {!! $icons['more'] !!}
                                <span class="mycic-nav-badge hidden" data-nav-badge="lainnya-desktop"></span>
                            </span>
                            <span class="sr-only lg:not-sr-only">Lainnya</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-4 w-4 shrink-0 lg:inline" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6" /></svg>
                        </button>
                        <div class="mycic-dropdown-panel hidden" data-dropdown-panel role="menu" aria-label="Menu lainnya">
                            @foreach ($moreMenu as $item)
                                <a
                                    href="{{ $item['href'] }}"
                                    class="mycic-dropdown-item flex items-center justify-between gap-3 {{ $item['active'] ? 'font-medium text-brand-text' : '' }}"
                                    role="menuitem"
                                    @if($item['active']) aria-current="page" @endif
                                >
                                    <span>{{ $item['label'] }}</span>
                                    <span class="mycic-nav-badge-inline hidden" data-nav-badge="{{ $item['badge'] }}"></span>
                                </a>
                            @endforeach
                            <div class="mycic-dropdown-divider"></div>
                            <a href="{{ route('more') }}" class="mycic-dropdown-item font-medium text-brand-text" role="menuitem">Semua menu</a>
                        </div>
                    </div>
                </nav>

                <div class="ms-auto flex shrink-0 items-center gap-2">
                    <a
                        href="{{ route('notifications.index') }}"
                        class="mycic-btn-icon relative"
                        data-notification-bell
                        @if (request()->routeIs('notifications.*')) aria-current="page" @endif
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" />
                            <path d="M9 17v1a3 3 0 0 0 6 0v-1" />
                        </svg>
                        <span class="sr-only">Notifikasi</span>
                        <span class="mycic-nav-badge hidden" data-nav-badge="notifications"></span>
                    </a>

                    <button
                        type="button"
                        class="mycic-btn-icon"
                        data-theme-toggle
                        aria-pressed="false"
                        aria-label="Ganti tema"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] dark:hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.39 5.39 0 0 1-4.4 2.6 5.37 5.37 0 0 1-3.14-9.74c-.44-.06-.9-.1-1.36-.1z" />
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-[18px] w-[18px] dark:inline" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                    </button>

                    <div class="mycic-dropdown shrink-0" data-account-menu>
                        <button
                            type="button"
                            class="flex cursor-pointer items-center gap-2 rounded-[var(--radius-button)] p-1 transition-colors hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            data-account-menu-trigger
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-label="Menu akun"
                        >
                            <span class="mycic-avatar" data-user-avatar>M</span>
                            <div class="hidden max-w-40 text-start xl:block">
                                <div class="truncate text-sm font-medium text-ink" data-user-name>Mahasiswa</div>
                                <div class="truncate text-caption text-ink-subtle" data-user-identity>Portal kampus</div>
                            </div>
                        </button>
                        <div class="mycic-dropdown-panel hidden" data-account-menu-panel role="menu">
                            <div class="border-b border-border-light px-4 py-2 xl:hidden">
                                <div class="truncate text-sm font-medium text-ink" data-user-name>Mahasiswa</div>
                                <div class="truncate text-caption text-ink-subtle" data-user-identity>Portal kampus</div>
                            </div>
                            <a href="{{ route('profile') }}" class="mycic-dropdown-item" role="menuitem">Profil</a>
                            <a href="{{ route('password.edit') }}" class="mycic-dropdown-item" role="menuitem">Ubah password</a>
                            <a href="{{ route('more') }}#panduan" class="mycic-dropdown-item" role="menuitem">Panduan</a>
                            <a href="{{ config('portal.base_url') }}" target="_blank" rel="noopener noreferrer" class="mycic-dropdown-item" role="menuitem">Portal kampus</a>
                            <div class="mycic-dropdown-divider"></div>
                            <button type="button" class="mycic-dropdown-item-danger" data-logout role="menuitem">Keluar</button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <div class="mycic-container py-5 md:py-6">
                <div class="mycic-sync-strip hidden text-center" data-pull-indicator aria-hidden="true">
                    <span class="mycic-spinner me-2 hidden" data-pull-spinner aria-hidden="true"></span>
                    <span data-pull-copy>Tarik untuk memperbarui</span>
                </div>

                @unless ($hideStatusStrip)
                    <div class="mycic-sync-strip" data-sync-alert role="status">
                        <div class="flex w-full items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2.5 overflow-hidden">
                                <span class="mycic-chip-online shrink-0" data-connection-chip>
                                    <span data-connection-label>Online</span>
                                </span>
                                <span class="hidden h-3 w-px shrink-0 bg-border sm:inline-block" aria-hidden="true"></span>
                                <span class="mycic-chip-online shrink-0 hidden sm:inline-flex" data-realtime-chip>
                                    <span data-realtime-label>Pembaruan otomatis</span>
                                </span>
                                <span class="hidden h-3 w-px shrink-0 bg-border sm:inline-block" aria-hidden="true"></span>
                                <span data-sync-status class="mb-0 line-clamp-2 text-xs text-ink-subtle sm:line-clamp-none sm:truncate">Data siap dipakai.</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @unless ($hideSyncRefresh)
                                    <button
                                        type="button"
                                        class="mycic-btn-icon-sm"
                                        data-sync-refresh
                                        data-online-required
                                        aria-label="Sinkronkan data"
                                        title="Sinkronkan data"
                                    >
                                        <span class="inline-flex items-center justify-center" data-sync-refresh-icon aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                                        </span>
                                        <span class="mycic-spinner hidden" data-sync-refresh-spinner aria-hidden="true"></span>
                                        <span class="sr-only" data-sync-refresh-label>Sinkronkan data</span>
                                    </button>
                                @endunless
                            </div>
                        </div>
                    </div>
                @endunless

                <div data-operation-status class="mb-4"></div>

                @if ($isPortalOffline)
                    <div class="mycic-alert-warning mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden" role="status" data-portal-offline-banner>
                        <span class="min-w-0">
                            <span class="block font-medium">Mode offline</span>
                            <span class="block text-sm">Portal kampus tidak bisa diakses saat kamu masuk. Data diambil dari arsip WeCIC. Absen dan upload yang kamu kirim disimpan dulu di antrean WeCIC (belum terkirim ke portal) dan dikirim otomatis begitu portal pulih, selama tenggatnya belum lewat; sinkronisasi menunggu. <span data-portal-offline-reconnect-text>{{ \App\Http\Controllers\API\PortalAuthController::offlineReconnectMessage(session('campus_offline_reconnect')) }}</span></span>
                        </span>
                        <x-button variant="secondary" size="sm" data-logout="">Masuk ulang</x-button>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>


        <nav class="mycic-bottom-nav print:hidden" aria-label="Navigasi mobile">
            <div class="flex">
                @foreach ($mobileNavigation['start'] as $item)
                    <x-nav.bottom-item :href="$item['href']" :label="$item['label']" :icon="$item['icon']" :badge="$item['badge']" :active="$item['active']" />
                @endforeach

                <div class="mycic-bottom-nav-item mycic-bottom-nav-item-center" data-nav-slot="absen">
                    <button type="button" class="mycic-bottom-nav-action" data-qr-quick aria-label="Scan QR absensi">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M7 7l0 .01" /><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M17 7l0 .01" /><path d="M14 14l3 0" /><path d="M20 14l0 .01" /><path d="M14 14l0 3" /><path d="M14 20l3 0" /><path d="M17 17l3 0" /><path d="M20 17l0 3" /></svg>
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
