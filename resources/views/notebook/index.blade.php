@extends('layouts.app')

@section('title', 'Notebook AI & Pengerjaan Tugas')
@section('page_id', 'notebook-index')

@section('content')
<div id="notebook-app" class="flex flex-col gap-5">
    {{-- Top Workspace Header --}}
    <header class="mycic-card border-brand/20 bg-linear-to-r from-surface via-panel to-surface p-4 sm:p-5 shadow-xs">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                    <a href="/class" class="inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                        <span>Kelas</span>
                    </a>
                    <span class="text-ink-subtle text-xs">/</span>
                    <span id="header-course-badge" class="inline-flex items-center rounded-md bg-brand/10 border border-brand/20 px-2 py-0.5 text-xs font-semibold text-brand">
                        Notebook AI
                    </span>
                    <span id="header-meeting-badge" class="inline-flex items-center rounded-md bg-accent/20 border border-accent/40 px-2 py-0.5 text-xs font-bold text-ink">
                        Umum
                    </span>
                    <span id="gemini-status-pill" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-500/10 border border-emerald-500/25 text-emerald-700 dark:text-emerald-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span id="gemini-status-label">Google Gemini Siap</span>
                    </span>
                </div>
                <h1 id="notebook-title" class="text-lg sm:text-2xl font-bold tracking-tight text-ink flex items-center gap-2">
                    <span id="notebook-title-text" class="truncate">Memuat Ruang Belajar...</span>
                    <button type="button" id="edit-title-btn" class="text-ink-subtle hover:text-ink shrink-0 p-1 rounded-md" title="Ubah Judul" aria-label="Ubah Judul Notebook">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                </h1>
                <p id="notebook-subtitle" class="text-xs sm:text-sm text-ink-muted mt-1 truncate">
                    Asisten belajar grounded mirip NotebookLM untuk mendalami materi & menyelesaikan tugas kuliah UCIC.
                </p>
            </div>

            {{-- Action buttons --}}
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <button type="button" id="open-notebooks-modal-btn" class="mycic-btn-secondary-sm inline-flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h7"/></svg>
                    <span>Daftar Notebook</span>
                </button>
                <button type="button" id="new-notebook-btn" class="mycic-btn-secondary-sm inline-flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    <span>Buat Baru</span>
                </button>
                <button type="button" id="settings-key-btn" class="mycic-btn-secondary-sm inline-flex items-center gap-1.5" title="Pengaturan Gemini API Key">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
                    <span>API Key</span>
                </button>
            </div>
        </div>

        {{-- Mobile Switcher Tabs --}}
        <div class="mt-4 flex items-center justify-between border-t border-border-light pt-3 lg:hidden">
            <div class="flex w-full rounded-lg bg-surface p-1 text-xs font-semibold" role="tablist">
                <button type="button" class="notebook-tab flex-1 py-1.5 text-center rounded-md bg-panel text-brand shadow-xs" data-pane-target="sources">
                    Sumber (<span id="mobile-sources-count">0</span>)
                </button>
                <button type="button" class="notebook-tab flex-1 py-1.5 text-center rounded-md text-ink-muted hover:text-ink" data-pane-target="studio">
                    Studio AI & Chat
                </button>
                <button type="button" class="notebook-tab flex-1 py-1.5 text-center rounded-md text-ink-muted hover:text-ink" data-pane-target="draft">
                    Draf Tugas
                </button>
            </div>
        </div>
    </header>

    {{-- 3-Column Responsive Workspace --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-start">
        
        {{-- ========================================= --}}
        {{-- PANEL 1: SUMBER & BAHAN KAJIAN (COL 3)    --}}
        {{-- ========================================= --}}
        <section id="pane-sources" class="lg:col-span-3 flex flex-col gap-4" aria-label="Panel Sumber Perkuliahan">
            <div class="mycic-card">
                <div class="mycic-card-header flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/></svg>
                        <h2 class="mycic-card-title text-sm">Sumber Grounding</h2>
                    </div>
                    <button type="button" id="add-custom-source-btn" class="inline-flex items-center gap-1 rounded-md bg-surface px-2 py-1 text-xs font-semibold text-brand hover:bg-surface-tint">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        <span>Tambah</span>
                    </button>
                </div>
                <div class="mycic-card-body p-3 flex flex-col gap-2.5">
                    <p class="text-xs text-ink-subtle">
                        AI hanya menjawab dan menganalisis berdasarkan dokumen yang dicentang di bawah ini.
                    </p>

                    <div id="sources-list" class="flex flex-col gap-2 mt-1">
                        <div class="mycic-skeleton h-16 w-full rounded-lg"></div>
                        <div class="mycic-skeleton h-16 w-full rounded-lg"></div>
                    </div>
                </div>
            </div>

            {{-- Link to Meeting / Class Info Card --}}
            <div id="meeting-reference-card" class="mycic-card bg-surface/60 border-dashed">
                <div class="mycic-card-body p-3.5 text-xs flex flex-col gap-2">
                    <div class="font-bold text-ink flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <span>Konteks Pertemuan</span>
                    </div>
                    <div id="meeting-context-text" class="text-ink-muted leading-relaxed">
                        Memuat data pertemuan kampus...
                    </div>
                    <div id="meeting-quick-links" class="mt-1 flex flex-wrap gap-2 pt-2 border-t border-border-light"></div>
                </div>
            </div>
        </section>

        {{-- ========================================= --}}
        {{-- PANEL 2: STUDIO AI & CHAT (COL 5 OR 6)    --}}
        {{-- ========================================= --}}
        <section id="pane-studio" class="lg:col-span-5 xl:col-span-6 flex flex-col gap-4" aria-label="Studio AI dan Chat Interaktif">
            
            {{-- Quick Studio Action Chips --}}
            <div class="mycic-card p-3 bg-surface/40">
                <div class="text-caption font-bold uppercase tracking-wider text-ink-subtle mb-2 flex items-center justify-between">
                    <span>⚡ Aksi Studio Cepat</span>
                    <span class="text-[11px] font-normal text-ink-muted">Pilih satu untuk mulai cepat</span>
                </div>

                {{-- Action Group 1: Deep Dive Materi --}}
                <div class="flex flex-col gap-1.5">
                    <div class="text-[11px] font-semibold text-brand flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        <span>Pahami Materi Lebih Dalam</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" class="studio-chip" data-action="deep_dive" data-prompt="Jelaskan konsep inti dari materi perkuliahan ini secara mendalam, sertakan analogi yang mudah dimengerti dan contoh kasus aplikasinya di dunia nyata.">
                            💡 Konsep Inti
                        </button>
                        <button type="button" class="studio-chip" data-action="summary" data-prompt="Buatkan ringkasan komprehensif dari materi ini: poin-poin utama, glosarium istilah penting, dan intisari yang sering keluar di ujian.">
                            📝 Rangkuman & Poin
                        </button>
                        <button type="button" class="studio-chip" data-action="deep_dive" data-prompt="Apa saja 5 pertanyaan paling krusial yang sering membingungkan mahasiswa mengenai materi ini, beserta jawaban jelasnya?">
                            ❓ FAQ Materi
                        </button>
                        <button type="button" class="studio-chip" data-action="quiz" data-prompt="Buatkan 3 soal latihan kuis pemahaman (pilihan ganda dan studi kasus) berdasarkan materi ini, sertakan kunci jawaban dan pembahasannya di akhir.">
                            🎯 Kuis Latihan
                        </button>
                    </div>
                </div>

                {{-- Action Group 2: Pengerjaan Tugas --}}
                <div class="flex flex-col gap-1.5 mt-3 pt-2.5 border-t border-border-light">
                    <div class="text-[11px] font-semibold text-amber-600 dark:text-accent flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <span>Bantu Pengerjaan Tugas</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" class="studio-chip studio-chip-task" data-action="task_outline" data-prompt="Analisis instruksi dan soal tugas yang tertera di sumber. Uraikan apa saja poin penilaian dosen dan berikan roadmap langkah-langkah pengerjaannya secara terstruktur.">
                            🔍 Bedah Soal Tugas
                        </button>
                        <button type="button" class="studio-chip studio-chip-task" data-action="task_solve" data-prompt="Bantu saya menyelesaikan tugas ini secara komprehensif. Susun draf jawaban lengkap, terstruktur, berbobot, dan siap saya sesuaikan untuk pengumpulan.">
                            ⚡ Buat Draf Jawaban Tugas
                        </button>
                        <button type="button" class="studio-chip studio-chip-task" data-action="task_review" data-prompt="Review draf jawaban tugas yang ada di lembar kerja saya di samping. Apakah sudah memenuhi instruksi soal dosen? Berikan evaluasi dan saran penyempurnaan.">
                            ✍️ Koreksi Draf Saya
                        </button>
                    </div>
                </div>
            </div>

            {{-- Main Interactive Chat Container --}}
            <div class="mycic-card flex flex-col h-[600px] overflow-hidden">
                <div class="p-3 border-b border-border-light flex items-center justify-between bg-surface/30">
                    <div class="flex items-center gap-2">
                        <div class="h-2 w-2 rounded-full bg-brand"></div>
                        <span class="text-xs font-bold text-ink">Diskusi Terarah Sumber (Notebook Chat)</span>
                    </div>
                    <button type="button" id="clear-chat-ui-btn" class="text-caption text-ink-subtle hover:text-ink">
                        Bersihkan Tampilan
                    </button>
                </div>

                {{-- Messages Thread Host --}}
                <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                    {{-- Default greeting message --}}
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-xs font-bold text-white shadow-xs">
                            AI
                        </div>
                        <div class="min-w-0 flex-1 rounded-2xl rounded-tl-sm bg-surface p-3.5 text-sm text-ink leading-relaxed">
                            <p class="font-semibold text-ink mb-1">Halo! Saya Asisten Belajar & Tugas UCIC Anda.</p>
                            <p class="text-ink-muted text-xs leading-relaxed">
                                Saya telah menghubungkan sumber materi dan tugas pertemuan ini. Anda dapat:
                            </p>
                            <ul class="list-disc list-inside mt-2 space-y-1 text-xs text-ink-muted">
                                <li>Menanyakan konsep materi yang sulit atau butuh analogi mendalam.</li>
                                <li>Menganalisis soal tugas dosen dan menyusun kerangka solusinya.</li>
                                <li>Membuat draf jawaban tugas lengkap dan memindahkannya ke lembar kerja di sebelah kanan.</li>
                            </ul>
                            <p class="mt-2.5 text-xs text-ink-subtle">
                                Klik salah satu tombol aksi cepat di atas atau ketik pertanyaan langsung di kolom bawah.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Thinking Loading Indicator --}}
                <div id="chat-loading" class="hidden px-4 py-2 border-t border-border-light bg-surface/50">
                    <div class="flex items-center gap-2 text-xs text-brand font-medium">
                        <div class="mycic-spinner h-3.5 w-3.5 border-brand"></div>
                        <span id="loading-text">Membaca sumber & merumuskan penjelasan cerdas...</span>
                    </div>
                </div>

                {{-- Chat Input Form --}}
                <div class="p-3 border-t border-border bg-panel">
                    <form id="chat-form" class="flex flex-col gap-2">
                        <div class="relative">
                            <textarea
                                id="chat-input"
                                rows="2"
                                class="w-full resize-none rounded-xl border border-border bg-canvas/40 px-3.5 py-2.5 text-xs sm:text-sm text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-hidden focus:ring-1 focus:ring-brand"
                                placeholder="Tanyakan materi atau minta bantuan selesaikan tugas (Tekan Enter untuk mengirim)..."
                            ></textarea>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="text-[11px] text-ink-subtle flex items-center gap-2">
                                <span>Shift+Enter untuk baris baru</span>
                            </div>
                            <button
                                type="submit"
                                id="send-chat-btn"
                                class="mycic-btn-primary-sm inline-flex items-center gap-1.5"
                            >
                                <span>Kirim</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        {{-- ========================================= --}}
        {{-- PANEL 3: LEMBAR DRAF TUGAS & WORKSPACE (COL 4) --}}
        {{-- ========================================= --}}
        <section id="pane-draft" class="lg:col-span-4 xl:col-span-3 flex flex-col gap-4" aria-label="Lembar Pengerjaan Draf Tugas">
            <div class="mycic-card flex flex-col h-[740px]">
                <div class="p-3.5 border-b border-border-light flex items-center justify-between bg-surface/30">
                    <div>
                        <h2 class="text-sm font-bold text-ink">Lembar Draf Tugas</h2>
                        <span id="draft-save-status" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Tersimpan</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" id="insert-template-btn" class="p-1 text-ink-subtle hover:text-ink rounded" title="Sisipkan Format Kop Tugas Mahasiswa">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        </button>
                        <button type="button" id="copy-draft-btn" class="p-1 text-ink-subtle hover:text-ink rounded" title="Salin Seluruh Draf">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        </button>
                        <button type="button" id="download-draft-btn" class="p-1 text-ink-subtle hover:text-ink rounded" title="Unduh File (.txt / .md)">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        </button>
                    </div>
                </div>

                {{-- Draft Editor Textarea --}}
                <div class="flex-1 p-3 flex flex-col">
                    <textarea
                        id="draft-editor"
                        class="w-full flex-1 resize-none rounded-lg border border-border bg-canvas/30 p-3 font-mono text-xs leading-relaxed text-ink focus:border-brand focus:outline-hidden"
                        placeholder="Ketik draf jawaban tugas Anda di sini, atau klik tombol 'Kirim ke Draf' pada balasan AI..."
                    ></textarea>
                </div>

                {{-- Draft Footer Quick Upload / Link to SIPANDAI --}}
                <div class="p-3 border-t border-border-light bg-surface/20 flex flex-col gap-2">
                    <div class="flex items-center justify-between text-[11px] text-ink-subtle">
                        <span id="draft-word-count">0 kata · 0 karakter</span>
                        <button type="button" id="save-draft-manual-btn" class="font-semibold text-brand hover:underline">
                            Simpan Sekarang
                        </button>
                    </div>

                    <div id="assignment-portal-shortcut" class="mt-1">
                        {{-- Rendered dynamically if meeting has assignment upload link --}}
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

{{-- MODAL: PENGATURAN API KEY GEMINI --}}
<div id="api-key-modal" class="mycic-modal hidden" tabindex="-1" aria-hidden="true">
    <div class="mycic-modal-backdrop" id="api-key-modal-backdrop"></div>
    <div class="mycic-modal-panel max-w-md">
        <div class="mycic-modal-header">
            <div>
                <div class="text-caption uppercase tracking-wide text-brand font-bold">Google Gemini</div>
                <h2 class="text-subtitle font-bold text-ink">Pengaturan API Key</h2>
            </div>
            <button type="button" class="mycic-modal-close" id="close-api-key-modal-btn" aria-label="Tutup">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="mycic-modal-body space-y-4">
            <p class="text-xs text-ink-muted leading-relaxed">
                Fitur Notebook AI menggunakan model <strong>Google Gemini Flash</strong> terbaru (didukung auto-discovery model aktif). Jika belum disetel di server, Anda dapat memasukkan API Key pribadi Anda di bawah ini secara aman.
            </p>

            <div class="rounded-xl border border-border-light bg-surface p-3 text-xs text-ink-muted space-y-1.5">
                <div class="font-bold text-ink">Cara mendapatkan API Key gratis (1 menit):</div>
                <ol class="list-decimal list-inside space-y-1 text-ink-muted">
                    <li>Buka <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer" class="text-brand font-semibold hover:underline">Google AI Studio</a>.</li>
                    <li>Login akun Google Anda dan klik <em>"Create API key"</em>.</li>
                    <li>Salin kuncinya dan tempelkan di kotak berikut.</li>
                </ol>
            </div>

            <div>
                <label for="custom-gemini-key-input" class="block text-xs font-bold text-ink mb-1.5">
                    Gemini API Key
                </label>
                <input
                    type="password"
                    id="custom-gemini-key-input"
                    class="mycic-input w-full text-xs font-mono"
                    placeholder="AIzaSy..."
                    autocomplete="off"
                >
            </div>

            <div id="key-test-feedback" class="text-xs hidden"></div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border-light">
                <button type="button" id="test-key-btn" class="mycic-btn-secondary-sm">
                    Uji Koneksi
                </button>
                <button type="button" id="save-key-btn" class="mycic-btn-primary-sm">
                    Simpan Pengaturan
                </button>
            </div>
        </div>
    </div>
</div>

{{-- MODAL: DAFTAR NOTEBOOK MAHASISWA --}}
<div id="notebooks-list-modal" class="mycic-modal hidden" tabindex="-1" aria-hidden="true">
    <div class="mycic-modal-backdrop" id="notebooks-list-backdrop"></div>
    <div class="mycic-modal-panel max-w-lg">
        <div class="mycic-modal-header">
            <div>
                <div class="text-caption uppercase tracking-wide text-brand font-bold">Riwayat Belajar</div>
                <h2 class="text-subtitle font-bold text-ink">Daftar Notebook Tersimpan</h2>
            </div>
            <button type="button" class="mycic-modal-close" id="close-notebooks-list-modal-btn" aria-label="Tutup">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="mycic-modal-body space-y-3">
            <div id="all-notebooks-container" class="max-h-96 overflow-y-auto space-y-2">
                <div class="mycic-skeleton h-14 w-full rounded-lg"></div>
            </div>
        </div>
    </div>
</div>

{{-- MODAL: TAMBAH SUMBER CUSTOM --}}
<div id="add-source-modal" class="mycic-modal hidden" tabindex="-1" aria-hidden="true">
    <div class="mycic-modal-backdrop" id="add-source-backdrop"></div>
    <div class="mycic-modal-panel max-w-md">
        <div class="mycic-modal-header">
            <div>
                <div class="text-caption uppercase tracking-wide text-brand font-bold">Grounding</div>
                <h2 class="text-subtitle font-bold text-ink">Tambah Sumber Materi/Catatan</h2>
            </div>
            <button type="button" class="mycic-modal-close" id="close-add-source-modal-btn" aria-label="Tutup">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="mycic-modal-body space-y-3">
            <div>
                <label for="custom-source-title" class="block text-xs font-bold text-ink mb-1">Judul Sumber</label>
                <input type="text" id="custom-source-title" class="mycic-input w-full text-xs" placeholder="Contoh: Catatan Tambahan Dosen, Studi Kasus">
            </div>
            <div>
                <label for="custom-source-content" class="block text-xs font-bold text-ink mb-1">Isi Dokumen / Teks</label>
                <textarea id="custom-source-content" rows="6" class="mycic-input w-full text-xs" placeholder="Paste teks materi kuliah, studi kasus, atau pertanyaan tugas di sini..."></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t border-border-light">
                <button type="button" id="confirm-add-source-btn" class="mycic-btn-primary-sm">
                    Tambahkan ke Sumber
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.studio-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.35rem 0.65rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border);
    background-color: var(--color-panel);
    color: var(--color-ink);
    transition: all 0.15s ease;
    cursor: pointer;
}
.studio-chip:hover {
    border-color: var(--color-brand);
    color: var(--color-brand);
    transform: translateY(-1px);
}
.studio-chip-task {
    background-color: var(--color-surface);
}
.studio-chip-task:hover {
    border-color: #d97706;
    color: #d97706;
}
.mycic-markdown-content h2, .mycic-markdown-content h3 {
    font-weight: 700;
    margin-top: 0.75rem;
    margin-bottom: 0.25rem;
    color: var(--color-ink);
}
.mycic-markdown-content h2 { font-size: 0.95rem; }
.mycic-markdown-content h3 { font-size: 0.875rem; }
.mycic-markdown-content ul, .mycic-markdown-content ol {
    margin-left: 1.25rem;
    margin-top: 0.35rem;
    margin-bottom: 0.35rem;
}
.mycic-markdown-content li { margin-bottom: 0.2rem; }
.mycic-markdown-content p { margin-bottom: 0.5rem; }
.mycic-markdown-content pre {
    background: #0f172a;
    color: #f8fafc;
    padding: 0.75rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    font-size: 0.75rem;
    margin: 0.5rem 0;
}
.mycic-markdown-content code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.mycic-markdown-content blockquote {
    border-left: 3px solid var(--color-brand);
    padding-left: 0.75rem;
    margin: 0.5rem 0;
    color: var(--color-ink-muted);
    font-style: italic;
}
</style>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const app = window.portalApp;

    const initialMeetingId = @json($meetingId);
    const initialCoursesId = @json($coursesId);
    const initialAction = @json($initialAction);
    const initialNotebookId = @json($notebookId);
    const hasServerApiKey = @json($hasDefaultApiKey);

    // State
    let currentNotebook = null;
    let sources = [];
    let draftSaveTimer = null;
    let studentInfo = null;

    // Local API Key preference
    const LOCAL_KEY_STORAGE = 'mycic_gemini_api_key';
    const getStoredApiKey = () => localStorage.getItem(LOCAL_KEY_STORAGE) || '';
    const setStoredApiKey = (val) => {
        if (val) localStorage.setItem(LOCAL_KEY_STORAGE, val.trim());
        else localStorage.removeItem(LOCAL_KEY_STORAGE);
        updateApiKeyStatusUi();
    };

    const updateApiKeyStatusUi = () => {
        const key = getStoredApiKey();
        const pill = document.getElementById('gemini-status-pill');
        const label = document.getElementById('gemini-status-label');

        if (key || hasServerApiKey) {
            pill.className = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-500/10 border border-emerald-500/25 text-emerald-700 dark:text-emerald-300';
            label.textContent = key ? 'Gemini (Key Pribadi)' : 'Gemini AI Aktif';
        } else {
            pill.className = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium bg-amber-500/10 border border-amber-500/25 text-amber-700 dark:text-amber-300';
            label.textContent = 'Atur API Key Gemini';
        }
    };
    updateApiKeyStatusUi();

    // Markdown simple parser
    const renderMarkdown = (text) => {
        if (!text) return '';
        let escaped = app.escapeHtml(text);

        // Code blocks ```code```
        escaped = escaped.replace(/```([a-z0-9]*)\n([\s\S]*?)```/gi, (match, lang, code) => {
            return `<div class="relative group my-2">
                <div class="flex items-center justify-between bg-slate-800 text-slate-300 px-3 py-1 text-[11px] rounded-t-md font-mono">
                    <span>${lang || 'code'}</span>
                    <button type="button" class="copy-code-btn hover:text-white" data-code="${encodeURIComponent(code)}">Salin Kode</button>
                </div>
                <pre class="rounded-b-md m-0"><code>${code}</code></pre>
            </div>`;
        });

        // Inline code `code`
        escaped = escaped.replace(/`([^`]+)`/g, '<code class="bg-surface px-1.5 py-0.5 rounded text-brand font-mono text-[11px]">$1</code>');

        // Headings ###
        escaped = escaped.replace(/^### (.*$)/gim, '<h3>$1</h3>');
        escaped = escaped.replace(/^## (.*$)/gim, '<h2>$1</h2>');
        escaped = escaped.replace(/^# (.*$)/gim, '<h2 class="text-base font-bold">$1</h2>');

        // Bold **text**
        escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        // Italic *text*
        escaped = escaped.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Blockquotes >
        escaped = escaped.replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>');

        // Unordered lists - item
        escaped = escaped.replace(/^\s*[\-\*]\s+(.*)$/gim, '<li>$1</li>');
        escaped = escaped.replace(/(<li>.*<\/li>)/gims, '<ul>$1</ul>');

        // New lines to <p>
        return `<div class="mycic-markdown-content">${escaped.replace(/\n\n/g, '<br><br>')}</div>`;
    };

    // Load Student Profile for Context
    try {
        const profileResp = await app.request('/api/profile');
        if (profileResp?.data?.profile) {
            studentInfo = profileResp.data.profile;
        }
    } catch (e) {
        // Fallback gracefully
    }

    // Modal Helpers
    const toggleModal = (modalId, show) => {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        if (show) modal.classList.remove('hidden');
        else modal.classList.add('hidden');
    };

    // Bind API Key Modal
    document.getElementById('settings-key-btn')?.addEventListener('click', () => {
        document.getElementById('custom-gemini-key-input').value = getStoredApiKey();
        document.getElementById('key-test-feedback').classList.add('hidden');
        toggleModal('api-key-modal', true);
    });
    document.getElementById('close-api-key-modal-btn')?.addEventListener('click', () => toggleModal('api-key-modal', false));
    document.getElementById('api-key-modal-backdrop')?.addEventListener('click', () => toggleModal('api-key-modal', false));

    document.getElementById('save-key-btn')?.addEventListener('click', () => {
        const val = document.getElementById('custom-gemini-key-input').value.trim();
        setStoredApiKey(val);
        toggleModal('api-key-modal', false);
        app.showNotice(val ? 'API Key tersimpan secara lokal di browser.' : 'API Key dihapus (menggunakan default server).', 'success');
    });

    document.getElementById('test-key-btn')?.addEventListener('click', async () => {
        const val = document.getElementById('custom-gemini-key-input').value.trim();
        const feedback = document.getElementById('key-test-feedback');
        feedback.className = 'text-xs mycic-alert-info';
        feedback.textContent = 'Memverifikasi API Key ke Google...';
        feedback.classList.remove('hidden');

        try {
            const resp = await app.request('/api/notebook/verify-key', {
                method: 'POST',
                json: { api_key: val },
            });
            if (resp.valid) {
                feedback.className = 'text-xs mycic-alert-success';
                feedback.textContent = '✓ ' + resp.message;
            } else {
                feedback.className = 'text-xs mycic-alert-danger';
                feedback.textContent = '✕ ' + resp.message;
            }
        } catch (err) {
            feedback.className = 'text-xs mycic-alert-danger';
            feedback.textContent = '✕ Gagal menguji API Key.';
        }
    });

    // Mobile tabs switcher
    document.querySelectorAll('.notebook-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.paneTarget;
            document.querySelectorAll('.notebook-tab').forEach(t => {
                t.classList.remove('bg-panel', 'text-brand', 'shadow-xs');
                t.classList.add('text-ink-muted');
            });
            tab.classList.add('bg-panel', 'text-brand', 'shadow-xs');
            tab.classList.remove('text-ink-muted');

            // Toggle panes on mobile
            const pSources = document.getElementById('pane-sources');
            const pStudio = document.getElementById('pane-studio');
            const pDraft = document.getElementById('pane-draft');

            pSources.classList.toggle('hidden', target !== 'sources');
            pStudio.classList.toggle('hidden', target !== 'studio');
            pDraft.classList.toggle('hidden', target !== 'draft');
        });
    });

    // Render Sources List
    const renderSourcesList = () => {
        const host = document.getElementById('sources-list');
        const countSpan = document.getElementById('mobile-sources-count');
        if (!host) return;

        if (countSpan) countSpan.textContent = sources.length;

        if (sources.length === 0) {
            host.innerHTML = `<div class="p-3 text-center text-xs text-ink-subtle bg-surface/50 rounded-lg">Belum ada sumber. Tambahkan sumber materi atau catatan.</div>`;
            return;
        }

        host.innerHTML = sources.map((source, idx) => {
            const typeBadge = source.type === 'assignment' ? 'Tugas' : (source.type === 'custom' ? 'Catatan' : 'Materi');
            const isChecked = source.active !== false;

            return `
                <div class="flex items-start gap-2.5 p-2.5 rounded-xl border border-border bg-panel hover:border-brand/40 transition-colors">
                    <input type="checkbox" id="source-toggle-${idx}" class="mt-1 h-3.5 w-3.5 rounded border-border text-brand focus:ring-brand" ${isChecked ? 'checked' : ''} data-source-index="${idx}">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-surface text-ink-muted">${typeBadge}</span>
                            <label for="source-toggle-${idx}" class="text-xs font-bold text-ink truncate cursor-pointer">${app.escapeHtml(source.title)}</label>
                        </div>
                        ${source.content ? `<p class="text-[11px] text-ink-subtle line-clamp-2 mt-1">${app.escapeHtml(source.content.substring(0, 140))}</p>` : ''}
                        ${source.file_url ? `
                            <div class="mt-1.5 flex items-center gap-2">
                                <a href="${app.absoluteCampusUrl(source.file_url)}" target="_blank" rel="noreferrer" class="text-[10px] font-semibold text-brand hover:underline inline-flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <span>Buka Dokumen</span>
                                </a>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');

        // Bind source toggles
        host.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const i = Number(e.target.dataset.sourceIndex);
                if (sources[i]) {
                    sources[i].active = e.target.checked;
                    syncSourcesToNotebook();
                }
            });
        });
    };

    // Sync sources to notebook model
    const syncSourcesToNotebook = async () => {
        if (!currentNotebook) return;
        try {
            await app.request(`/api/notebook/${currentNotebook.id}`, {
                method: 'PATCH',
                json: { sources },
            });
        } catch (e) {}
    };

    // Add Custom Source Modal
    document.getElementById('add-custom-source-btn')?.addEventListener('click', () => {
        document.getElementById('custom-source-title').value = '';
        document.getElementById('custom-source-content').value = '';
        toggleModal('add-source-modal', true);
    });
    document.getElementById('close-add-source-modal-btn')?.addEventListener('click', () => toggleModal('add-source-modal', false));
    document.getElementById('add-source-backdrop')?.addEventListener('click', () => toggleModal('add-source-modal', false));

    document.getElementById('confirm-add-source-btn')?.addEventListener('click', () => {
        const title = document.getElementById('custom-source-title').value.trim();
        const content = document.getElementById('custom-source-content').value.trim();

        if (!title || !content) {
            app.showNotice('Judul dan isi sumber wajib diisi.', 'warning');
            return;
        }

        sources.push({
            title,
            content,
            type: 'custom',
            active: true,
        });

        renderSourcesList();
        syncSourcesToNotebook();
        toggleModal('add-source-modal', false);
        app.showNotice('Sumber kustom berhasil ditambahkan.', 'success');
    });

    // Append message to chat UI
    const appendChatMessage = (role, text, actionType = null) => {
        const container = document.getElementById('chat-messages');
        const isUser = role === 'user';
        const msgDiv = document.createElement('div');
        msgDiv.className = `flex items-start gap-3 ${isUser ? 'flex-row-reverse' : ''}`;

        const avatar = isUser
            ? `<div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-700 text-xs font-bold text-white">Saya</div>`
            : `<div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-xs font-bold text-white shadow-xs">AI</div>`;

        const bubbleClasses = isUser
            ? 'rounded-2xl rounded-tr-sm bg-brand text-white p-3.5 text-xs sm:text-sm leading-relaxed max-w-[85%]'
            : 'rounded-2xl rounded-tl-sm bg-surface p-3.5 text-xs sm:text-sm text-ink leading-relaxed max-w-[95%]';

        const contentMarkup = isUser
            ? `<div class="whitespace-pre-wrap">${app.escapeHtml(text)}</div>`
            : renderMarkdown(text);

        const aiToolbar = !isUser ? `
            <div class="mt-3 pt-2 border-t border-border-light flex flex-wrap items-center gap-2 text-xs">
                <button type="button" class="copy-text-btn inline-flex items-center gap-1 text-ink-muted hover:text-brand font-medium" data-text="${encodeURIComponent(text)}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    <span>Salin</span>
                </button>
                <button type="button" class="push-draft-btn inline-flex items-center gap-1 text-ink-muted hover:text-brand font-medium" data-text="${encodeURIComponent(text)}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m10 13 2 2 4-4"/></svg>
                    <span>Kirim ke Draf Tugas</span>
                </button>
            </div>
        ` : '';

        msgDiv.innerHTML = `
            ${avatar}
            <div class="${bubbleClasses}">
                ${contentMarkup}
                ${aiToolbar}
            </div>
        `;

        container.appendChild(msgDiv);
        container.scrollTop = container.scrollHeight;
    };

    // Chat delegates: Copy and Push to draft
    document.getElementById('chat-messages').addEventListener('click', (e) => {
        const copyBtn = e.target.closest('.copy-text-btn');
        if (copyBtn) {
            const raw = decodeURIComponent(copyBtn.dataset.text || '');
            navigator.clipboard.writeText(raw).then(() => {
                app.showNotice('Teks berhasil disalin ke clipboard.', 'success');
            });
            return;
        }

        const pushBtn = e.target.closest('.push-draft-btn');
        if (pushBtn) {
            const raw = decodeURIComponent(pushBtn.dataset.text || '');
            const editor = document.getElementById('draft-editor');
            const current = editor.value.trim();
            editor.value = current ? `${current}\n\n---\n\n${raw}` : raw;
            updateDraftWordCount();
            scheduleDraftAutoSave();
            app.showNotice('Konten ditambahkan ke Lembar Draf Tugas.', 'success');

            // If on mobile, hint or switch to draft
            const draftTab = document.querySelector('[data-pane-target="draft"]');
            if (window.innerWidth < 1024 && draftTab) {
                draftTab.click();
            }
            return;
        }

        const copyCodeBtn = e.target.closest('.copy-code-btn');
        if (copyCodeBtn) {
            const code = decodeURIComponent(copyCodeBtn.dataset.code || '');
            navigator.clipboard.writeText(code).then(() => {
                app.showNotice('Kode berhasil disalin.', 'success');
            });
            return;
        }
    });

    // Clear Chat UI
    document.getElementById('clear-chat-ui-btn')?.addEventListener('click', () => {
        const host = document.getElementById('chat-messages');
        host.innerHTML = `<div class="p-3 text-center text-xs text-ink-subtle">Riwayat chat di layar dibersihkan. Catatan dan draf Anda tetap aman.</div>`;
    });

    // Send Prompt to AI
    const sendAiPrompt = async (promptText, actionType = 'chat') => {
        if (!promptText || !promptText.trim()) return;

        if (!currentNotebook) {
            app.showNotice('Membuat ruang notebook terlebih dahulu...', 'info');
            await initNotebook();
            if (!currentNotebook) return;
        }

        const customKey = getStoredApiKey();
        if (!customKey && !hasServerApiKey) {
            toggleModal('api-key-modal', true);
            app.showNotice('Silakan masukkan Google Gemini API Key Anda untuk melanjutkan.', 'warning');
            return;
        }

        appendChatMessage('user', promptText, actionType);

        const loading = document.getElementById('chat-loading');
        const loadingText = document.getElementById('loading-text');
        const sendBtn = document.getElementById('send-chat-btn');
        const chatInput = document.getElementById('chat-input');

        if (actionType === 'task_solve') {
            loadingText.textContent = 'Menganalisis soal tugas & merumuskan draf jawaban terstruktur...';
        } else if (actionType === 'deep_dive') {
            loadingText.textContent = 'Mengkaji materi kuliah secara mendalam & menyusun analogi...';
        } else if (actionType === 'quiz') {
            loadingText.textContent = 'Menyusun soal latihan pemahaman & kunci pembahasan...';
        } else {
            loadingText.textContent = 'Membaca sumber & merumuskan penjelasan cerdas...';
        }

        loading.classList.remove('hidden');
        sendBtn.setAttribute('disabled', 'disabled');
        chatInput.value = '';

        try {
            const activeSources = sources.filter(s => s.active !== false);
            const resp = await app.request(`/api/notebook/${currentNotebook.id}/chat`, {
                method: 'POST',
                json: {
                    prompt: promptText,
                    action_type: actionType,
                    api_key: customKey || undefined,
                    active_sources: activeSources,
                },
            });

            if (resp.message && resp.message.content) {
                appendChatMessage('model', resp.message.content, actionType);
            }
        } catch (error) {
            const errMsg = error.payload?.message || error.message || 'Gagal menghubungi Gemini AI.';
            appendChatMessage('model', `⚠️ **Terjadi Kesalahan:**\n${errMsg}\n\n*Jika menggunakan API Key pribadi, pastikan kunci masih aktif di Google AI Studio.*`);
        } finally {
            loading.classList.add('hidden');
            sendBtn.removeAttribute('disabled');
        }
    };

    // Chat Form Submit
    document.getElementById('chat-form')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const text = document.getElementById('chat-input').value.trim();
        sendAiPrompt(text, 'chat');
    });

    // Enter to Send in Textarea
    document.getElementById('chat-input')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const text = e.target.value.trim();
            if (text) sendAiPrompt(text, 'chat');
        }
    });

    // Quick Studio Action Chips Click
    document.querySelectorAll('.studio-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.dataset.action;
            const prompt = btn.dataset.prompt;

            // If action is task_review, append current draft content if available
            if (action === 'task_review') {
                const draft = document.getElementById('draft-editor').value.trim();
                const fullPrompt = draft
                    ? `${prompt}\n\nBerikut draf pengerjaan saya:\n\"\"\"\n${draft}\n\"\"\"`
                    : `${prompt}\n\n(Catatan: Lembar draf saat ini masih kosong, berikan checklist apa saja yang harus disiapkan)`;
                sendAiPrompt(fullPrompt, action);
                return;
            }

            sendAiPrompt(prompt, action);
        });
    });

    // Draft Word & Char Counter
    const updateDraftWordCount = () => {
        const text = document.getElementById('draft-editor').value.trim();
        const words = text ? text.split(/\s+/).length : 0;
        const chars = text.length;
        document.getElementById('draft-word-count').textContent = `${words} kata · ${chars} karakter`;
    };

    // Auto-save Draft
    const scheduleDraftAutoSave = () => {
        const status = document.getElementById('draft-save-status');
        status.textContent = 'Menyimpan...';
        status.className = 'text-[11px] text-amber-600 font-medium';

        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(async () => {
            if (!currentNotebook) return;
            try {
                const content = document.getElementById('draft-editor').value;
                await app.request(`/api/notebook/${currentNotebook.id}/save-draft`, {
                    method: 'POST',
                    json: { draft_content: content },
                });
                status.textContent = 'Tersimpan';
                status.className = 'text-[11px] text-emerald-600 dark:text-emerald-400 font-medium';
            } catch (e) {
                status.textContent = 'Gagal menyimpan';
                status.className = 'text-[11px] text-red-500 font-medium';
            }
        }, 1200);
    };

    document.getElementById('draft-editor')?.addEventListener('input', () => {
        updateDraftWordCount();
        scheduleDraftAutoSave();
    });

    document.getElementById('save-draft-manual-btn')?.addEventListener('click', () => {
        scheduleDraftAutoSave();
    });

    // Insert Student Kop Template
    document.getElementById('insert-template-btn')?.addEventListener('click', () => {
        const editor = document.getElementById('draft-editor');
        const course = currentNotebook?.course_name || 'Mata Kuliah';
        const meetingNum = currentNotebook?.meeting_number ? `Pertemuan ${currentNotebook.meeting_number}` : 'Tugas Mandiri';
        const nim = studentInfo?.student_id || studentInfo?.nim || '-';
        const name = studentInfo?.name || studentInfo?.nama || 'Mahasiswa';

        const template = `========================================================
TUGAS PERKULIAHAN - UNIVERSITAS CATUR INSAN CENDEKIA (UCIC)
========================================================
Mata Kuliah : ${course}
Pertemuan   : ${meetingNum}
Nama        : ${name}
NIM         : ${nim}
Tanggal     : ${new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
========================================================

SOAL / TUJUAN TUGAS:
[Tuliskan ringkasan soal di sini]

JAWABAN & PEMBAHASAN:
1. 
`;
        const current = editor.value.trim();
        editor.value = current ? `${template}\n\n${current}` : template;
        updateDraftWordCount();
        scheduleDraftAutoSave();
        app.showNotice('Format kop tugas berhasil disisipkan.', 'success');
    });

    // Copy Draft
    document.getElementById('copy-draft-btn')?.addEventListener('click', () => {
        const text = document.getElementById('draft-editor').value;
        if (!text) {
            app.showNotice('Lembar draf masih kosong.', 'info');
            return;
        }
        navigator.clipboard.writeText(text).then(() => {
            app.showNotice('Seluruh draf tugas berhasil disalin.', 'success');
        });
    });

    // Download Draft (.txt / .md)
    document.getElementById('download-draft-btn')?.addEventListener('click', () => {
        const text = document.getElementById('draft-editor').value;
        if (!text) {
            app.showNotice('Lembar draf masih kosong.', 'info');
            return;
        }
        const title = (currentNotebook?.title || 'tugas_ucic').replace(/[^a-zA-Z0-9_\-]/g, '_');
        const blob = new Blob([text], { type: 'text/markdown;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${title}.md`;
        a.click();
        URL.revokeObjectURL(url);
        app.showNotice('Draf berhasil diunduh sebagai file Markdown.', 'success');
    });

    // Notebooks List Modal
    document.getElementById('open-notebooks-modal-btn')?.addEventListener('click', async () => {
        toggleModal('notebooks-list-modal', true);
        const container = document.getElementById('all-notebooks-container');
        container.innerHTML = `<div class="mycic-skeleton h-14 w-full rounded-lg"></div>`;

        try {
            const resp = await app.request('/api/notebook');
            const list = resp?.notebooks || [];
            if (list.length === 0) {
                container.innerHTML = `<div class="p-4 text-center text-xs text-ink-subtle">Belum ada notebook tersimpan. Buat notebook baru untuk memulai.</div>`;
                return;
            }

            container.innerHTML = list.map(item => `
                <div class="flex items-center justify-between p-3 rounded-xl border border-border bg-panel hover:border-brand/40 transition-colors">
                    <div class="min-w-0 flex-1">
                        <a href="/notebook?notebook_id=${item.id}" class="text-xs font-bold text-ink hover:text-brand block truncate">
                            ${app.escapeHtml(item.title)}
                        </a>
                        <div class="text-[11px] text-ink-subtle mt-0.5">
                            ${app.escapeHtml(item.course_name || 'Umum')} · ${item.messages_count || 0} pesan
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a href="/notebook?notebook_id=${item.id}" class="mycic-btn-secondary-sm text-xs">Buka</a>
                        <button type="button" class="delete-nb-btn p-1 text-ink-subtle hover:text-danger rounded" data-nb-id="${item.id}" title="Hapus">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>
            `).join('');

            container.querySelectorAll('.delete-nb-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Hapus notebook ini beserta riwayat diskusinya?')) return;
                    const id = btn.dataset.nbId;
                    await app.request(`/api/notebook/${id}`, { method: 'DELETE' });
                    btn.closest('.rounded-xl').remove();
                    app.showNotice('Notebook dihapus.', 'info');
                });
            });
        } catch (e) {
            container.innerHTML = `<div class="p-4 text-center text-xs text-danger">Gagal memuat daftar notebook.</div>`;
        }
    });
    document.getElementById('close-notebooks-list-modal-btn')?.addEventListener('click', () => toggleModal('notebooks-list-modal', false));
    document.getElementById('notebooks-list-backdrop')?.addEventListener('click', () => toggleModal('notebooks-list-modal', false));

    // New Notebook Button
    document.getElementById('new-notebook-btn')?.addEventListener('click', async () => {
        const title = prompt('Masukkan judul notebook baru:', 'Notebook Mandiri');
        if (!title) return;

        try {
            const resp = await app.request('/api/notebook', {
                method: 'POST',
                json: { title },
            });
            if (resp.notebook) {
                window.location.href = `/notebook?notebook_id=${resp.notebook.id}`;
            }
        } catch (e) {
            app.showNotice('Gagal membuat notebook.', 'error');
        }
    });

    // Rename title
    document.getElementById('edit-title-btn')?.addEventListener('click', async () => {
        if (!currentNotebook) return;
        const newTitle = prompt('Ubah judul notebook:', currentNotebook.title);
        if (!newTitle || newTitle === currentNotebook.title) return;

        try {
            await app.request(`/api/notebook/${currentNotebook.id}`, {
                method: 'PATCH',
                json: { title: newTitle },
            });
            currentNotebook.title = newTitle;
            document.getElementById('notebook-title-text').textContent = newTitle;
            app.showNotice('Judul berhasil diperbarui.', 'success');
        } catch (e) {
            app.showNotice('Gagal memperbarui judul.', 'error');
        }
    });

    // Initialize Notebook from query params
    const initNotebook = async () => {
        try {
            // Case 1: Load specific notebook by ID
            if (initialNotebookId) {
                const resp = await app.request(`/api/notebook/${initialNotebookId}`);
                if (resp.notebook) {
                    currentNotebook = resp.notebook;
                    sources = currentNotebook.sources || [];
                    applyNotebookData();
                    return;
                }
            }

            // Case 2: Meeting Context supplied
            if (initialMeetingId && initialCoursesId) {
                document.getElementById('notebook-title-text').textContent = 'Menghubungkan Materi Pertemuan...';
                
                // Fetch meeting details from campus API
                const meetingResp = await app.request(`/api/meeting/${initialMeetingId}?courses_id=${initialCoursesId}`);
                const detail = meetingResp?.class_detail || {};
                const materials = detail.materials || {};
                const meetingInfo = detail.meeting_info || {};
                const assignmentInfo = detail.assignment_info || {};

                // Parse breadcrumb for course name
                let courseName = 'Mata Kuliah';
                const matchCourse = meetingInfo.breadcrumb?.match(/Kelas\s+([^>]+?)(?:\s*>|$)/i);
                if (matchCourse && matchCourse[1]) {
                    courseName = matchCourse[1].trim();
                }

                // Inferred meeting number
                const meetingNum = materials.assignment_topic?.match(/(\d+)/)?.[1]
                    || materials.main_topic?.match(/Pertemuan\s+(\d+)/i)?.[1]
                    || (meetingInfo.breadcrumb?.match(/Pertemuan\s+(\d+)/i)?.[1])
                    || '';

                const cleanTopic = (materials.main_topic || materials.assignment_topic || 'Pertemuan Perkuliahan')
                    .replace(/^(?:Pokok\s+Bahasan|Materi|Topik)\s*:\s*/i, '').trim();

                const nbTitle = `${courseName} - ${meetingNum ? `P${meetingNum}: ` : ''}${cleanTopic}`;

                // Compile default sources from meeting
                const gatheredSources = [];

                if (materials.main_topic || materials.main_description) {
                    gatheredSources.push({
                        title: `Materi: ${cleanTopic}`,
                        type: 'materi',
                        content: [materials.main_topic, materials.main_description].filter(Boolean).join('\n\n'),
                        file_url: materials.main_material_iframe || null,
                        active: true,
                    });
                }

                if (materials.assignment_topic || materials.assignment_instructions || assignmentInfo.upload_text) {
                    gatheredSources.push({
                        title: `Tugas: ${materials.assignment_topic || assignmentInfo.upload_text || 'Tugas Perkuliahan'}`,
                        type: 'assignment',
                        content: [
                            materials.assignment_topic,
                            materials.assignment_instructions,
                            assignmentInfo.deadline ? `Batas Waktu: ${assignmentInfo.deadline}` : null,
                        ].filter(Boolean).join('\n\n'),
                        file_url: materials.assignment_material_iframe || null,
                        active: true,
                    });
                }

                // Create or retrieve notebook on backend
                const nbResp = await app.request('/api/notebook', {
                    method: 'POST',
                    json: {
                        title: nbTitle,
                        course_id: String(initialCoursesId),
                        course_name: courseName,
                        meeting_id: String(initialMeetingId),
                        meeting_number: meetingNum,
                        sources: gatheredSources,
                    },
                });

                if (nbResp.notebook) {
                    currentNotebook = nbResp.notebook;
                    sources = currentNotebook.sources || gatheredSources;
                    applyNotebookData(detail);

                    // Setup shortcut to meeting
                    setupMeetingShortcuts(detail, initialMeetingId, initialCoursesId);

                    // Trigger initial action if supplied (e.g., from button in meeting page)
                    if (initialAction === 'task_solve') {
                        setTimeout(() => {
                            sendAiPrompt('Analisis soal tugas ini dan buatkan draf solusi/jawaban terstruktur yang siap saya sesuaikan.', 'task_solve');
                        }, 500);
                    } else if (initialAction === 'deep_dive') {
                        setTimeout(() => {
                            sendAiPrompt('Jelaskan materi pertemuan ini secara mendalam dengan analogi dan contoh konkret.', 'deep_dive');
                        }, 500);
                    }
                    return;
                }
            }

            // Case 3: Default General Notebook
            const defaultResp = await app.request('/api/notebook', {
                method: 'POST',
                json: {
                    title: 'Notebook Belajar Mandiri',
                    sources: [],
                },
            });
            currentNotebook = defaultResp.notebook;
            sources = currentNotebook.sources || [];
            applyNotebookData();
        } catch (e) {
            document.getElementById('notebook-title-text').textContent = 'Notebook AI Belajar';
            app.showNotice('Terjadi kendala saat memuat notebook.', 'warning');
        }
    };

    // Apply Notebook Data to UI
    const applyNotebookData = (detailContext = null) => {
        if (!currentNotebook) return;

        document.getElementById('notebook-title-text').textContent = currentNotebook.title;
        document.getElementById('header-course-badge').textContent = currentNotebook.course_name || 'Notebook AI';
        document.getElementById('header-meeting-badge').textContent = currentNotebook.meeting_number ? `P${currentNotebook.meeting_number}` : 'Umum';

        // Render Sources
        renderSourcesList();

        // Render Existing Draft
        if (currentNotebook.draft_content) {
            document.getElementById('draft-editor').value = currentNotebook.draft_content;
            updateDraftWordCount();
        }

        // Render Past Message Thread
        if (currentNotebook.messages && currentNotebook.messages.length > 0) {
            const host = document.getElementById('chat-messages');
            host.innerHTML = '';
            currentNotebook.messages.forEach(msg => {
                appendChatMessage(msg.role, msg.content, msg.action_type);
            });
        }

        // Context info
        if (currentNotebook.meeting_id) {
            document.getElementById('meeting-context-text').innerHTML = `
                Terhubung dengan <strong>${app.escapeHtml(currentNotebook.course_name || 'Mata Kuliah')}</strong> 
                (${currentNotebook.meeting_number ? `Pertemuan ${currentNotebook.meeting_number}` : 'Pertemuan ' + currentNotebook.meeting_id}).
            `;
        } else {
            document.getElementById('meeting-context-text').textContent = 'Notebook mandiri. Anda dapat menambahkan catatan dan materi custom di panel atas.';
        }
    };

    const setupMeetingShortcuts = (detail, meetingId, coursesId) => {
        const linksHost = document.getElementById('meeting-quick-links');
        const portalShortcut = document.getElementById('assignment-portal-shortcut');

        if (linksHost) {
            linksHost.innerHTML = `
                <a href="/meeting/${meetingId}?courses_id=${coursesId}" class="inline-flex items-center gap-1 font-semibold text-brand hover:underline">
                    <span>Lihat Halaman Pertemuan</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            `;
        }

        if (portalShortcut) {
            portalShortcut.innerHTML = `
                <a href="/meeting/${meetingId}?courses_id=${coursesId}" class="w-full inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand/30 bg-brand/10 hover:bg-brand/20 px-3 py-2 text-xs font-bold text-brand transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                    <span>Kumpul Tugas di Halaman Pertemuan</span>
                </a>
            `;
        }
    };

    // Initialize now
    await initNotebook();
});
</script>
@endpush
