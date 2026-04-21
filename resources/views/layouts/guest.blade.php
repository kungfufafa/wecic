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
<body data-auth="guest" data-page="@yield('page_id', 'guest')">
    <div id="notice-host" class="toast-container position-fixed top-0 end-0 p-3"></div>

    <div class="page page-center">
        <div class="container container-tight py-4">
            @yield('content')
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
