<!DOCTYPE html>
<html lang="id" data-campus-base="{{ config('portal.base_url') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#066fd1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="myCIC">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="/vendor/tabler/css/tabler.min.css">
    @vite(['resources/js/app.js'])
</head>
<body data-auth="@yield('auth_mode', 'required')" data-page="@yield('page_id', 'app')">
    <div id="notice-host" class="toast-container position-fixed top-0 end-0 p-3"></div>

    <div class="modal modal-blur fade" tabindex="-1" aria-hidden="true" data-qr-scanner-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <div class="text-secondary text-uppercase small">Absensi</div>
                        <h5 class="modal-title">Scan kode QR</h5>
                    </div>
                    <button type="button" class="btn-close" aria-label="Tutup" data-qr-scanner-close></button>
                </div>
                <div class="modal-body">
                    <div class="ratio ratio-1x1 rounded border overflow-hidden bg-secondary-lt mb-3">
                        <video class="w-100 h-100 object-fit-cover" data-qr-scanner-video playsinline autoplay muted></video>
                    </div>
                    <div class="alert alert-info mb-0" data-qr-scanner-status role="alert">
                        Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.
                    </div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-1" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-1" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-1" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-1" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-1" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" />
                        <path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />
                    </svg>
                SVG,
            ],
        ];

        $hideStatusStrip = trim($__env->yieldContent('hide_status_strip')) === '1';
    @endphp

    <div class="page">
        <header class="navbar navbar-expand-md d-print-none sticky-top border-bottom">
            <div class="container-xl d-flex align-items-center justify-content-between py-1">
                <div class="navbar-brand navbar-brand-autodark m-0">
                    <a href="{{ route('dashboard') }}" class="text-decoration-none text-reset fw-bold" aria-label="myCIC" style="font-size: 1.25rem; letter-spacing: -0.02em;">myCIC</a>
                </div>

                <div class="navbar-nav flex-row order-md-last align-items-center">
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 p-0 pe-2" data-bs-toggle="dropdown" aria-label="Open user menu">
                            <span class="avatar avatar-sm rounded-circle bg-primary-lt text-primary shadow-sm">M</span>
                            <div class="d-none d-xl-block ps-2 text-start">
                                <div data-user-identity>Mahasiswa</div>
                                <div class="mt-1 small text-secondary">Portal kampus</div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow mt-2 shadow-sm">
                            <div class="dropdown-item-text d-xl-none pb-2 border-bottom mb-2">
                                <div class="fw-bold" data-user-identity>Mahasiswa</div>
                                <div class="small text-secondary">Portal kampus</div>
                            </div>
                            <a href="{{ route('profile') }}" class="dropdown-item">Profil</a>
                            <a href="{{ route('password.edit') }}" class="dropdown-item">Ubah Password</a>
                            <div class="dropdown-divider"></div>
                            <button type="button" class="dropdown-item text-danger" data-logout>Keluar</button>
                        </div>
                    </div>
                </div>

                <div class="collapse navbar-collapse d-none d-md-flex justify-content-center" id="navbar-menu">
                    <ul class="navbar-nav gap-2">
                        @foreach ($navigation as $item)
                            <li class="nav-item">
                                <a href="{{ $item['href'] }}" class="nav-link @if($item['active']) active fw-semibold @endif" @if($item['active']) aria-current="page" @endif>
                                    <span class="nav-link-icon d-md-none d-lg-inline-block">{!! $item['icon'] !!}</span>
                                    <span class="nav-link-title">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </header>

        <div class="page-wrapper pb-5 pb-md-0">
            <div class="page-body">
                <div class="container-xl">
                    <div class="alert alert-info text-center mb-3 d-none" data-pull-indicator aria-hidden="true">
                        <span class="spinner-border spinner-border-sm me-2 d-none" data-pull-spinner aria-hidden="true"></span>
                        <span data-pull-copy>Tarik untuk memperbarui</span>
                    </div>

                    @unless ($hideStatusStrip)
                        <div class="alert alert-info mb-3 mb-md-4 py-2 py-md-3" data-sync-alert role="status">
                            <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <span class="badge bg-green-lt text-green flex-shrink-0" data-connection-chip>
                                        <span data-connection-label>Online</span>
                                    </span>
                                    <span data-sync-status class="text-truncate small mb-0">Data terakhir siap dipakai. Sinkronkan ke server bila perlu.</span>
                                </div>
                                <div class="d-flex align-items-center flex-shrink-0">
                                    <span class="text-secondary small d-none d-sm-block me-2" data-user-identity></span>
                                    <button
                                        type="button"
                                        class="btn btn-info btn-sm d-inline-flex align-items-center justify-content-center px-2 py-1"
                                        data-sync-refresh
                                        data-online-required
                                        aria-label="Sinkronkan server"
                                        title="Sinkronkan server"
                                    >
                                        <span class="d-inline-flex align-items-center justify-content-center" data-sync-refresh-icon aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                                        </span>
                                        <span class="spinner-border spinner-border-sm d-none" data-sync-refresh-spinner aria-hidden="true"></span>
                                        <span class="visually-hidden" data-sync-refresh-label>Sinkronkan server</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endunless

                    @yield('content')
                </div>
            </div>
        </div>

        <!-- Mobile Bottom Navigation -->
        <div class="navbar navbar-expand d-md-none fixed-bottom border-top" style="min-height: auto; padding: 0.5rem 0;">
            <div class="container-fluid justify-content-around p-0">
                @foreach ($navigation as $item)
                    <a href="{{ $item['href'] }}" class="text-center text-decoration-none d-flex flex-column align-items-center {{ $item['active'] ? 'text-primary' : 'text-secondary' }}" style="flex: 1;">
                        <div class="mb-1">{!! str_replace('width="24"', 'width="20"', str_replace('height="24"', 'height="20"', $item['icon'])) !!}</div>
                        <div class="small fw-medium" style="font-size: 0.65rem;">{{ $item['label'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <script src="/vendor/tabler/js/tabler.min.js"></script>
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
