<!DOCTYPE html>
<html lang="id" data-campus-base="{{ config('portal.base_url') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0352A1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="myCIC">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-auth="@yield('auth_mode', 'required')" data-page="@yield('page_id', 'app')">
    <div id="notice-host" class="fixed right-4 top-4 z-50 w-[min(24rem,calc(100vw-2rem))]"></div>
    <div class="qr-scanner-modal hidden" data-qr-scanner-modal aria-hidden="true">
        <div class="qr-scanner-dialog">
            <div class="qr-scanner-head">
                <div>
                    <p class="section-label">Absensi</p>
                    <p class="qr-scanner-title">Scan kode QR</p>
                </div>
                <button type="button" class="ghost-button" data-qr-scanner-close>Tutup</button>
            </div>
            <div class="qr-scanner-view">
                <video data-qr-scanner-video playsinline autoplay muted></video>
            </div>
            <p class="qr-scanner-note" data-qr-scanner-status>Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.</p>
        </div>
    </div>

    @php
        $navigation = [
            [
                'href' => '/dashboard',
                'label' => 'Dashboard',
                'active' => request()->is('dashboard'),
                'icon' => 'dashboard',
            ],
            [
                'href' => '/class',
                'label' => 'Kelas',
                'active' => request()->is('class') || request()->is('class/*') || request()->is('meeting/*'),
                'icon' => 'class',
            ],
            [
                'href' => '/exam',
                'label' => 'Ujian',
                'active' => request()->is('exam') || request()->is('exam/*'),
                'icon' => 'exam',
            ],
            [
                'href' => '/profile',
                'label' => 'Profil',
                'active' => request()->is('profile'),
                'icon' => 'profile',
            ],
        ];

        $navigationIcons = [
            'dashboard' => <<<'SVG'
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.75 10.75 12 5l7.25 5.75V19a1.25 1.25 0 0 1-1.25 1.25h-3.5V13h-5v7.25H6A1.25 1.25 0 0 1 4.75 19v-8.25Z" />
                </svg>
            SVG,
            'class' => <<<'SVG'
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.75 6.75A1.75 1.75 0 0 1 6.5 5h11A1.75 1.75 0 0 1 19.25 6.75v10.5A1.75 1.75 0 0 1 17.5 19h-11a1.75 1.75 0 0 1-1.75-1.75V6.75Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 9h8M8 12h8M8 15h4.5" />
                </svg>
            SVG,
            'exam' => <<<'SVG'
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.25 4.75h6.5l4.5 4.5v9A1.75 1.75 0 0 1 17.5 20h-11a1.75 1.75 0 0 1-1.75-1.75v-11A1.75 1.75 0 0 1 6.5 5.5h1.75Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.75 4.75v4.5h4.5M8.5 13h7M8.5 16h4.5" />
                </svg>
            SVG,
            'profile' => <<<'SVG'
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 12.25a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5.5 19.25a6.5 6.5 0 0 1 13 0" />
                </svg>
            SVG,
        ];
    @endphp

    @php
        $hideStatusStrip = trim($__env->yieldContent('hide_status_strip')) === '1';
    @endphp

    <div class="app-shell">
        <header class="app-header">
            <div class="app-header-shell">
                <div class="app-brand-row">
                    <a href="/dashboard" data-display class="brand-mark app-brand-link" aria-label="myCIC">
                        <span class="brand-mark-my">my</span><span class="brand-mark-cic">CIC</span>
                    </a>

                    <button type="button" class="header-logout lg:hidden" data-logout>Logout</button>
                </div>

                <nav class="app-nav-shell hidden lg:inline-flex" aria-label="Navigasi utama">
                    @foreach ($navigation as $item)
                        <a href="{{ $item['href'] }}" class="app-nav-link" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center justify-end lg:flex">
                    <button type="button" class="header-logout" data-logout>Logout</button>
                </div>
            </div>
        </header>

        <div class="pull-refresh-indicator" data-pull-indicator data-state="idle" aria-hidden="true">
            <span class="pull-refresh-orb" aria-hidden="true"></span>
            <span data-pull-copy>Tarik untuk memperbarui</span>
        </div>

        @unless ($hideStatusStrip)
            <section class="app-status-strip" aria-live="polite">
                <span class="connection-chip" data-connection-chip data-state="online">
                    <span class="connection-chip-orb" aria-hidden="true"></span>
                    <span data-connection-label>Online</span>
                </span>
                <p class="app-status-note" data-sync-status data-tone="info">Data terbaru disegarkan otomatis.</p>
                <p class="app-status-user-meta hidden" data-user-identity></p>
            </section>
        @endunless

        <main class="grid gap-6">
            @yield('content')
        </main>
    </div>

    <nav class="mobile-bottom-nav lg:hidden" aria-label="Navigasi bawah" data-mobile-nav>
        @foreach ($navigation as $item)
            <a href="{{ $item['href'] }}" class="mobile-bottom-nav-link" @if($item['active']) aria-current="page" @endif>
                <span class="mobile-bottom-nav-icon">{!! $navigationIcons[$item['icon']] !!}</span>
                <span class="mobile-bottom-nav-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

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
