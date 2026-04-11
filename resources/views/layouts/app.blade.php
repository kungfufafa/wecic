<!DOCTYPE html>
<html lang="id" data-campus-base="{{ config('portal.base_url') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#3d5fa0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="myCIC">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;500;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            <p class="qr-scanner-note" data-qr-scanner-status>Arahkan kamera ke QR absensi untuk mengambil 8 digit kode pertama.</p>
        </div>
    </div>

    @php
        $navigation = [
            ['/dashboard', 'Dashboard', request()->is('dashboard')],
            ['/class', 'Kelas', request()->is('class') || request()->is('class/*') || request()->is('meeting/*')],
            ['/exam', 'Ujian', request()->is('exam') || request()->is('exam/*')],
            ['/profile', 'Profil', request()->is('profile')],
        ];
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
                    @foreach ($navigation as [$href, $label, $active])
                        <a href="{{ $href }}" class="app-nav-link" @if($active) aria-current="page" @endif>{{ $label }}</a>
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

        <section class="app-status-strip" aria-live="polite">
            <span class="connection-chip" data-connection-chip data-state="online">
                <span class="connection-chip-orb" aria-hidden="true"></span>
                <span data-connection-label>Online</span>
            </span>
            <p class="app-status-note" data-sync-status data-tone="info">Online. Data terbaru akan disegarkan otomatis.</p>
            <p class="app-status-user-meta hidden" data-user-identity></p>
        </section>

        <main class="grid gap-6">
            @yield('content')
        </main>
    </div>

    <nav class="mobile-bottom-nav lg:hidden" aria-label="Navigasi bawah" data-mobile-nav>
        @foreach ($navigation as [$href, $label, $active])
            <a href="{{ $href }}" class="mobile-bottom-nav-link" @if($active) aria-current="page" @endif>
                <span>{{ $label }}</span>
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
