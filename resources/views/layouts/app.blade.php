<!DOCTYPE html>
<html lang="id" data-campus-base="{{ config('portal.base_url') }}" data-session-id="{{ session()->getId() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0352A1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="myCIC">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-auth="@yield('auth_mode', 'required')" data-page="@yield('page_id', 'app')">
    <div id="notice-host" class="mycic-toast-host"></div>

    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" data-qr-scanner-modal>
        <div class="mycic-modal-backdrop" data-qr-scanner-backdrop></div>
        <div class="mycic-modal-panel">
            <div class="mycic-modal-header">
                <div>
                    <div class="text-caption uppercase tracking-wide text-ink-subtle">Absensi</div>
                    <h2 class="text-subtitle font-[590] text-ink">Scan kode QR</h2>
                </div>
                <button type="button" class="mycic-modal-close" aria-label="Tutup" data-qr-scanner-close>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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

    @php
        $navigation = [
            [
                'href' => route('dashboard'),
                'label' => 'Dashboard',
                'active' => request()->routeIs('dashboard'),
                'icon' => <<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12l-2 0l9 -9l9 9l-2 0" />
                        <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" />
                        <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />
                    </svg>
                SVG,
            ],
            [
                'href' => route('class.index'),
                'label' => 'Kelas',
                'active' => request()->routeIs('class.*') || request()->routeIs('meeting.show'),
                'icon' => <<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7l9 -4l9 4l-9 4z" />
                        <path d="M3 12l9 4l9 -4" />
                        <path d="M3 17l9 4l9 -4" />
                    </svg>
                SVG,
            ],
            [
                'href' => route('exam.index'),
                'label' => 'Ujian',
                'active' => request()->routeIs('exam.*'),
                'icon' => <<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 11l3 3l8 -8" />
                        <path d="M20 12v7a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h9" />
                    </svg>
                SVG,
            ],
            [
                'href' => route('profile'),
                'label' => 'Profil',
                'active' => request()->routeIs('profile'),
                'icon' => <<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                        <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                    </svg>
                SVG,
            ],
            [
                'href' => route('finance.index'),
                'label' => 'Keuangan',
                'active' => request()->routeIs('finance.*'),
                'icon' => <<<'SVG'
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" />
                        <path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />
                    </svg>
                SVG,
            ],
        ];

        $hideStatusStrip = trim($__env->yieldContent('hide_status_strip')) === '1';
    @endphp

    <div class="mycic-page">
        <header class="mycic-page-header print:hidden">
            <div class="mycic-container relative flex h-14 items-center justify-between gap-4">
                <a href="{{ route('dashboard') }}" class="mycic-brand-wordmark shrink-0" aria-label="myCIC">myCIC</a>

                <nav class="mycic-top-nav absolute inset-x-0 justify-center" aria-label="Navigasi utama">
                    @foreach ($navigation as $item)
                        <a
                            href="{{ $item['href'] }}"
                            class="mycic-top-nav-link {{ $item['active'] ? 'mycic-top-nav-link-active' : '' }}"
                            @if($item['active']) aria-current="page" @endif
                        >
                            {!! $item['icon'] !!}
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="mycic-dropdown shrink-0" data-account-menu>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg p-1 transition-colors hover:bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                        data-account-menu-trigger
                        aria-expanded="false"
                        aria-haspopup="true"
                        aria-label="Menu akun"
                    >
                        <span class="mycic-avatar">M</span>
                        <div class="hidden text-start xl:block">
                            <div class="text-sm font-medium text-ink" data-user-identity>Mahasiswa</div>
                            <div class="text-caption text-ink-subtle">Portal kampus</div>
                        </div>
                    </button>
                    <div class="mycic-dropdown-panel hidden" data-account-menu-panel role="menu">
                        <div class="border-b border-border-light px-4 py-2 xl:hidden">
                            <div class="font-semibold text-ink" data-user-identity>Mahasiswa</div>
                            <div class="text-sm text-ink-subtle">Portal kampus</div>
                        </div>
                        <a href="{{ route('profile') }}" class="mycic-dropdown-item" role="menuitem">Profil</a>
                        <a href="{{ route('password.edit') }}" class="mycic-dropdown-item" role="menuitem">Ubah Password</a>
                        <div class="mycic-dropdown-divider"></div>
                        <button type="button" class="mycic-dropdown-item-danger" data-logout role="menuitem">Keluar</button>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <div class="mycic-container py-4">
                <div class="mycic-sync-strip hidden text-center" data-pull-indicator aria-hidden="true">
                    <span class="mycic-spinner me-2 hidden" data-pull-spinner aria-hidden="true"></span>
                    <span data-pull-copy>Tarik untuk memperbarui</span>
                </div>

                @unless ($hideStatusStrip)
                    <div class="mycic-sync-strip" data-sync-alert role="status">
                        <div class="flex w-full items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2 overflow-hidden">
                                <span class="mycic-chip-online shrink-0" data-connection-chip>
                                    <span data-connection-label>Online</span>
                                </span>
                                <span class="mycic-chip-online shrink-0 hidden sm:inline-flex" data-realtime-chip>
                                    <span data-realtime-label>Real-time</span>
                                </span>
                                <span data-sync-status class="mb-0 truncate text-sm">Data terakhir siap dipakai. Sinkronkan ke server bila perlu.</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="hidden text-sm text-ink-subtle sm:block" data-user-identity></span>
                                <button
                                    type="button"
                                    class="mycic-btn-secondary-sm"
                                    data-sync-refresh
                                    data-online-required
                                    aria-label="Sinkronkan server"
                                    title="Sinkronkan server"
                                >
                                    <span class="inline-flex items-center justify-center" data-sync-refresh-icon aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                                    </span>
                                    <span class="mycic-spinner hidden" data-sync-refresh-spinner aria-hidden="true"></span>
                                    <span class="sr-only" data-sync-refresh-label>Sinkronkan server</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endunless

                <div data-operation-status class="mb-4"></div>

                @yield('content')
            </div>
        </main>

        <nav class="mycic-bottom-nav" aria-label="Navigasi mobile">
            <div class="flex">
                @foreach ($navigation as $item)
                    <a
                        href="{{ $item['href'] }}"
                        class="mycic-bottom-nav-item {{ $item['active'] ? 'mycic-bottom-nav-item-active' : 'mycic-bottom-nav-item-inactive' }}"
                        @if($item['active']) aria-current="page" @endif
                    >
                        {!! str_replace('width="24"', 'width="20"', str_replace('height="24"', 'height="20"', $item['icon'])) !!}
                        <span>{{ $item['label'] }}</span>
                    </a>
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
