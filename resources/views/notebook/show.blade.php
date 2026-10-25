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
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('notebook.index') }}" class="mycic-link text-sm">Notebook</a>
            <h1 class="mycic-page-heading break-words" id="notebook-title">Memuat…</h1>
            <p class="mycic-page-subheading" id="notebook-meta"></p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="#" class="mycic-btn-ghost-sm hidden" id="notebook-class-link">Buka kelas</a>
            <button type="button" class="mycic-btn-ghost-sm" id="notebook-rename">Ganti judul</button>
            <button type="button" class="mycic-btn-ghost-sm text-danger" id="notebook-delete">Hapus</button>
        </div>
    </header>

    {{-- One banner per reason the AI cannot answer (`ai_status` of the notebook, or of a failed question). --}}
    <div class="mycic-alert-warning mb-4 hidden" data-ai-status-banner="disabled" role="status">
        Fitur AI Notebook dimatikan di server MyCIC. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio aktif lagi setelah AI dinyalakan.
    </div>
    <div class="mycic-alert-warning mb-4 hidden" data-ai-status-banner="missing_key" role="status">
        Fitur AI belum diaktifkan di server MyCIC (GEMINI_API_KEY kosong). Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio aktif setelah AI dinyalakan.
    </div>
    <div class="mycic-alert-warning mb-4 hidden" data-ai-status-banner="invalid_key" role="status">
        Fitur AI sedang tidak bisa dipakai karena kunci API Gemini di server MyCIC ditolak. Pengelola MyCIC perlu memperbarui GEMINI_API_KEY. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio menunggu sampai kuncinya diperbaiki.
    </div>
    <div class="mycic-alert-warning mb-4 hidden" data-ai-status-banner="model_misconfigured" role="status">
        Fitur AI sedang tidak bisa dipakai karena pengaturan model AI di server MyCIC ditolak (model tidak dikenali, sudah tidak tersedia, atau pengaturannya tidak cocok). Pengelola MyCIC perlu memeriksa pengaturan model AI, misalnya GEMINI_MODEL atau NOTEBOOK_THINKING_BUDGET. Catatan pertemuan, teks, DOCX, dan PPTX tetap bisa ditambahkan; PDF, gambar, tanya jawab, dan studio menunggu sampai pengaturannya diperbaiki.
    </div>

    <div class="mb-3 inline-flex items-center gap-1 rounded-xl border border-border/40 bg-surface p-1 lg:hidden" role="tablist" aria-label="Bagian notebook" id="notebook-tabs">
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="sources">Sumber</button>
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="chat">Chat</button>
        <button type="button" role="tab" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm" data-notebook-tab="studio">Studio</button>
    </div>

    <div class="grid min-w-0 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)_20rem]">
        <section class="mycic-card min-w-0 self-start" aria-labelledby="notebook-sources-title" data-notebook-panel="sources">
            <div class="mycic-card-header flex items-center justify-between gap-2">
                <h2 class="mycic-card-title" id="notebook-sources-title">Sumber</h2>
                <span class="text-caption text-ink-subtle" id="notebook-sources-count"></span>
            </div>
            <div class="mycic-card-body flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-2">
                    <button type="button" class="mycic-btn-primary justify-start hidden" id="source-add-meeting">+ Tambah pertemuan</button>
                    <button type="button" class="mycic-btn-secondary-sm justify-start" id="source-add-archive">+ Dari arsip materi</button>
                    <label class="mycic-btn-secondary-sm cursor-pointer justify-start">
                        <span>+ Unggah berkas</span>
                        <input type="file" class="sr-only" id="source-upload" accept=".pdf,.docx,.pptx,.txt,.md,.png,.jpg,.jpeg">
                    </label>
                    <button type="button" class="mycic-btn-secondary-sm justify-start" id="source-add-text">+ Tulis catatan</button>
                </div>
                <div class="hidden flex-wrap items-center justify-between gap-2 rounded-lg border border-border/40 bg-surface px-2.5 py-1.5 text-caption" id="notebook-context-bar">
                    <span class="text-ink-muted" id="notebook-context-summary"></span>
                    <span class="flex gap-2">
                        <button type="button" class="font-semibold text-brand-pressed hover:underline" id="notebook-context-all">Semua</button>
                        <button type="button" class="font-semibold text-brand-pressed hover:underline" id="notebook-context-none">Kosongkan</button>
                    </span>
                </div>
                <ul class="flex flex-col gap-2" id="notebook-sources" aria-busy="true">
                    <li class="mycic-skeleton h-12 w-full"></li>
                </ul>
            </div>
        </section>

        <section class="mycic-card flex min-h-[32rem] min-w-0 flex-col" aria-labelledby="notebook-chat-title" data-notebook-panel="chat">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="notebook-chat-title">Chat</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Jawaban hanya dari sumber yang dicentang. Ketuk nomor rujukan untuk melihat kutipannya.</p>
            </div>
            <div class="mycic-card-body flex min-h-0 flex-1 flex-col gap-3">
                <ol class="flex max-h-[60vh] min-h-48 flex-1 flex-col gap-4 overflow-y-auto pe-1" id="notebook-messages" aria-label="Percakapan"></ol>
                <form class="flex flex-col gap-2" id="notebook-ask-form">
                    <label class="sr-only" for="notebook-question">Pertanyaan</label>
                    <textarea class="mycic-input min-h-20 resize-y" id="notebook-question" maxlength="2000" placeholder="Tanyakan sesuatu tentang sumbermu…" required></textarea>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-caption text-ink-subtle" id="notebook-ask-context">Enter untuk kirim, Shift+Enter untuk baris baru</span>
                        <button type="submit" class="mycic-btn-primary">Kirim</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="mycic-card min-w-0 self-start" aria-labelledby="notebook-studio-title" data-notebook-panel="studio">
            <div class="mycic-card-header">
                <h2 class="mycic-card-title" id="notebook-studio-title">Studio</h2>
                <p class="mt-0.5 text-caption text-ink-subtle">Bahan belajar mandiri dan kerangka tugas dari sumber yang dicentang, tersimpan otomatis di Catatan.</p>
            </div>
            <div class="mycic-card-body flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-2" id="notebook-studio-buttons"></div>
                <div class="flex flex-col gap-3">
                    <h3 class="mycic-section-title">Catatan</h3>
                    <div class="flex flex-col gap-3" id="notebook-notes"></div>
                </div>
            </div>
        </section>
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
