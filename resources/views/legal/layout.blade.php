@extends('layouts.guest')

{{--
    The public pages both app stores link to (privacy policy, support, data
    deletion): readable without a session, with links between them.
--}}
@php
    $legalLinks = [
        ['route' => 'legal.privacy', 'label' => 'Kebijakan privasi'],
        ['route' => 'legal.support', 'label' => 'Bantuan'],
        ['route' => 'legal.data-deletion', 'label' => 'Hapus akun & data'],
    ];
@endphp

@section('content')
    <div class="flex min-h-dvh flex-col">
        <header class="border-b border-border bg-panel">
            <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <a href="{{ route('login') }}" class="inline-flex w-fit items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40" aria-label="WeCIC, ke halaman masuk">
                    <x-brand-logo class="h-7" />
                </a>
                <nav aria-label="Informasi WeCIC" class="-mx-1 flex flex-wrap gap-1">
                    @foreach ($legalLinks as $legalLink)
                        <a
                            href="{{ route($legalLink['route']) }}"
                            @class([
                                'inline-flex min-h-9 items-center rounded-md px-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
                                'bg-surface text-ink' => request()->routeIs($legalLink['route']),
                                'text-ink-muted hover:bg-surface hover:text-ink' => ! request()->routeIs($legalLink['route']),
                            ])
                            @if (request()->routeIs($legalLink['route'])) aria-current="page" @endif
                        >{{ $legalLink['label'] }}</a>
                    @endforeach
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
            <article class="flex flex-col gap-8 rounded-xl border border-border bg-panel px-4 py-6 text-[15px] leading-relaxed text-ink-muted shadow-xs sm:px-8 sm:py-8 dark:shadow-none [&_a]:font-medium [&_a]:text-brand-text [&_a]:underline-offset-4 [&_a:hover]:underline [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-ink [&_h3]:text-[15px] [&_h3]:font-semibold [&_h3]:text-ink [&_ol]:flex [&_ol]:list-decimal [&_ol]:flex-col [&_ol]:gap-1.5 [&_ol]:ps-5 [&_strong]:font-semibold [&_strong]:text-ink [&_ul]:flex [&_ul]:list-disc [&_ul]:flex-col [&_ul]:gap-1.5 [&_ul]:ps-5 [&_li]:marker:text-ink-subtle">
                <header>
                    <h1 class="text-2xl font-semibold tracking-tight text-ink">@yield('heading')</h1>
                    @hasSection('summary')
                        <p class="mt-1 text-sm text-ink-muted">@yield('summary')</p>
                    @endif
                    @hasSection('meta')
                        <p class="mt-3 text-xs text-ink-subtle">@yield('meta')</p>
                    @endif
                </header>

                <x-alert tone="warning" icon title="Aplikasi unofficial">
                    <p class="mt-0.5">WeCIC dibuat oleh pengembang perorangan dan tidak berafiliasi dengan, tidak didukung oleh, serta tidak dikelola oleh kampus atau universitas mana pun. WeCIC hanya membantu kamu membuka portal akademik kampus (my.cic.ac.id) dengan akunmu sendiri.</p>
                </x-alert>

                @yield('legal_content')
            </article>
        </main>

        <footer class="border-t border-border bg-panel">
            <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 px-4 py-6 text-xs leading-relaxed text-ink-subtle sm:px-6">
                <nav aria-label="Tautan kaki halaman" class="flex flex-wrap gap-x-4 gap-y-2">
                    @foreach ($legalLinks as $legalLink)
                        <a href="{{ route($legalLink['route']) }}" class="font-medium text-ink-muted underline-offset-4 hover:text-ink hover:underline">{{ $legalLink['label'] }}</a>
                    @endforeach
                    <a href="{{ route('login') }}" class="font-medium text-ink-muted underline-offset-4 hover:text-ink hover:underline">Masuk ke WeCIC</a>
                </nav>
                <p>WeCIC · aplikasi unofficial, tidak berafiliasi dengan kampus. Kontak: <a href="mailto:developer@rizqis.com" class="font-medium text-ink-muted underline-offset-4 hover:text-ink hover:underline">developer@rizqis.com</a></p>
            </div>
        </footer>
    </div>
@endsection
