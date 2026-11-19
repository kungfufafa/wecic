@extends('layouts.app')

@section('title', 'Notebook')
@section('page_id', 'notebook-show')
@section('hide_sync_refresh', '1')

{{--
    Notebook: the header, the AI status banners, and the Sumber, Chat and
    Studio panels. The script is split into parts in
    resources/views/notebook/show/scripts.
--}}
@section('content')
    <x-page-hero :back="route('notebook.index')" back-label="Notebook" class="xl:pb-6">
        <x-slot:title><span class="break-words" id="notebook-title">Memuat…</span></x-slot:title>
        <x-slot:subtitle id="notebook-meta"></x-slot:subtitle>
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="#" id="notebook-class-link" hidden>
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
                Buka kelas
            </x-button>
            <x-button variant="ghost" size="sm" id="notebook-rename">Ganti judul</x-button>
            <x-button variant="ghost" size="sm" class="text-danger! hover:bg-danger-soft!" id="notebook-delete">Hapus</x-button>
        </x-slot:actions>
        <x-slot:tabs class="xl:hidden">
            <x-view-tabs as="div" underline class="w-full overflow-y-hidden" role="tablist" aria-label="Bagian notebook" id="notebook-tabs">
                <x-view-tab data-notebook-tab="sources">Sumber</x-view-tab>
                <x-view-tab data-notebook-tab="chat" :active="true">Chat</x-view-tab>
                <x-view-tab data-notebook-tab="studio">Studio</x-view-tab>
            </x-view-tabs>
        </x-slot:tabs>
    </x-page-hero>

    {{-- One banner per reason the AI cannot answer (`ai_status` of the notebook, or of a failed question). --}}
    <x-alert tone="warning" icon class="mb-4 hidden" data-ai-status-banner="disabled" role="status">
        Fitur AI Notebook dimatikan di server WeCIC. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio aktif lagi setelah AI dinyalakan.
    </x-alert>
    <x-alert tone="warning" icon class="mb-4 hidden" data-ai-status-banner="missing_key" role="status">
        Fitur AI belum diaktifkan di server WeCIC (GEMINI_API_KEY kosong). Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio aktif setelah AI dinyalakan.
    </x-alert>
    <x-alert tone="warning" icon class="mb-4 hidden" data-ai-status-banner="invalid_key" role="status">
        Fitur AI sedang tidak bisa dipakai karena kunci API Gemini di server WeCIC ditolak. Pengelola WeCIC perlu memperbarui GEMINI_API_KEY. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio menunggu sampai kuncinya diperbaiki.
    </x-alert>
    <x-alert tone="warning" icon class="mb-4 hidden" data-ai-status-banner="model_misconfigured" role="status">
        Fitur AI sedang tidak bisa dipakai karena pengaturan model AI di server WeCIC ditolak (model tidak dikenali, sudah tidak tersedia, atau pengaturannya tidak cocok). Pengelola WeCIC perlu memeriksa pengaturan model AI, misalnya GEMINI_MODEL atau NOTEBOOK_THINKING_BUDGET. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio menunggu sampai pengaturannya diperbaiki.
    </x-alert>

    <div class="grid min-w-0 gap-4 xl:grid-cols-[16rem_minmax(0,1fr)_18rem] 2xl:grid-cols-[18rem_minmax(0,1fr)_20rem]">
        <x-card as="section" class="min-w-0 self-start" aria-labelledby="notebook-sources-title" data-notebook-panel="sources">
            <x-card.header>
                <x-card.title id="notebook-sources-title">Sumber</x-card.title>
                <span class="text-xs text-ink-subtle tabular-nums" id="notebook-sources-count"></span>
            </x-card.header>
            <x-card.body class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-2">
                    <x-button size="sm" class="justify-start!" id="source-add-meeting" hidden>+ Tambah pertemuan</x-button>
                    <x-button variant="secondary" size="sm" class="justify-start!" id="source-add-archive">+ Dari arsip materi</x-button>
                    <label class="inline-flex min-h-11 cursor-pointer select-none items-center justify-start gap-1.5 whitespace-nowrap rounded-lg border border-border bg-panel px-3 text-[13px] font-medium text-ink shadow-xs transition-colors hover:bg-surface focus-within:outline-none focus-within:ring-2 focus-within:ring-brand/40 sm:min-h-8 dark:shadow-none">
                        <span>+ Unggah berkas</span>
                        <input type="file" class="sr-only" id="source-upload" accept=".pdf,.docx,.pptx,.txt,.md,.png,.jpg,.jpeg">
                    </label>
                    <x-button variant="secondary" size="sm" class="justify-start!" id="source-add-text">+ Tulis catatan</x-button>
                </div>
                <div class="hidden flex-wrap items-center justify-between gap-2 rounded-lg border border-border-light bg-surface/40 px-3 py-0 text-xs sm:py-2" id="notebook-context-bar">
                    <span class="text-ink-muted tabular-nums" id="notebook-context-summary"></span>
                    <span class="flex gap-3">
                        <button type="button" class="min-h-11 cursor-pointer font-medium text-brand-text underline-offset-4 hover:underline sm:min-h-0" id="notebook-context-all">Semua</button>
                        <button type="button" class="min-h-11 cursor-pointer font-medium text-brand-text underline-offset-4 hover:underline sm:min-h-0" id="notebook-context-none">Kosongkan</button>
                    </span>
                </div>
                <ul class="flex flex-col" id="notebook-sources" aria-busy="true">
                    <x-skeleton as="li" class="h-14 w-full" />
                </ul>
            </x-card.body>
        </x-card>

        <x-card as="section" class="flex min-h-[32rem] min-w-0 flex-col" aria-labelledby="notebook-chat-title" data-notebook-panel="chat">
            <x-card.header>
                <div class="min-w-0">
                    <x-card.title id="notebook-chat-title">Chat</x-card.title>
                    <x-card.subtitle>Jawaban hanya dari sumber yang dicentang. Ketuk nomor rujukan untuk melihat kutipannya.</x-card.subtitle>
                </div>
            </x-card.header>
            <x-card.body class="flex min-h-0 flex-1 flex-col gap-3">
                <ol class="flex max-h-[60vh] min-h-48 flex-1 flex-col gap-4 overflow-y-auto pe-1" id="notebook-messages" aria-label="Percakapan"></ol>
                <form class="flex flex-col gap-2 border-t border-border-light pt-3" id="notebook-ask-form">
                    <label class="sr-only" for="notebook-question">Pertanyaan</label>
                    <x-textarea class="resize-y" id="notebook-question" maxlength="2000" placeholder="Tanyakan dari sumber yang dicentang" required></x-textarea>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs text-ink-subtle" id="notebook-ask-context">Enter untuk kirim, Shift+Enter untuk baris baru</span>
                        <x-button type="submit">
                            Kirim
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14l11 -11" /><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5" /></svg>
                        </x-button>
                    </div>
                </form>
            </x-card.body>
        </x-card>

        <x-card as="section" class="min-w-0 self-start" aria-labelledby="notebook-studio-title" data-notebook-panel="studio">
            <x-card.header>
                <div class="min-w-0">
                    <x-card.title id="notebook-studio-title">Studio</x-card.title>
                    <x-card.subtitle>Bahan belajar mandiri dan kerangka tugas dari sumber yang dicentang, tersimpan otomatis di Catatan.</x-card.subtitle>
                </div>
            </x-card.header>
            <x-card.body class="flex flex-col gap-5">
                <div class="grid grid-cols-2 gap-2" id="notebook-studio-buttons"></div>
                <div class="flex flex-col gap-3 border-t border-border-light pt-4">
                    <h3 class="text-sm font-semibold tracking-tight text-ink">Catatan</h3>
                    <div class="flex flex-col" id="notebook-notes"></div>
                </div>
            </x-card.body>
        </x-card>
    </div>
@endsection

{{--
    One DOMContentLoaded handler for the whole page: its parts share one
    scope, so they are included in this order (setup first, start last).
--}}
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            @include('notebook.show.scripts.setup')
            @include('notebook.show.scripts.markdown')
            @include('notebook.show.scripts.messages')
            @include('notebook.show.scripts.notes')
            @include('notebook.show.scripts.sources')
            @include('notebook.show.scripts.load')
            @include('notebook.show.scripts.ask')
            @include('notebook.show.scripts.studio')
            @include('notebook.show.scripts.meeting-picker')
            @include('notebook.show.scripts.controls')
            @include('notebook.show.scripts.clicks')
            @include('notebook.show.scripts.add-sources')
            @include('notebook.show.scripts.header-buttons')
            @include('notebook.show.scripts.start')
        });
    </script>
@endpush
