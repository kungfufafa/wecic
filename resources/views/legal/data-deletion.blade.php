@extends('legal.layout')

@section('title', 'Hapus Akun & Data — WeCIC')
@section('page_id', 'legal-data-deletion')
@section('heading', 'Hapus akun dan data WeCIC')
@section('summary', 'Cara menghapus akun WeCIC beserta semua data yang disimpan server WeCIC untukmu.')

@section('legal_content')
    <section aria-labelledby="deletion-app-title" class="flex flex-col gap-3">
        <h2 id="deletion-app-title">Hapus dari dalam aplikasi</h2>
        <ol>
            <li>Buka WeCIC dan masuk dengan NIM dan password portalmu.</li>
            <li>Buka menu <strong>Lainnya</strong>, lalu di bagian <strong>Privasi</strong> pilih <strong>Hapus akun &amp; data WeCIC</strong>.</li>
            <li>Baca daftar data yang akan dihapus, lalu nyalakan <strong>Saya mengerti data ini tidak bisa dikembalikan</strong>.</li>
            <li>Ketuk <strong>Hapus akun &amp; data WeCIC</strong>, lalu konfirmasi dengan <strong>Hapus permanen</strong>.</li>
        </ol>
        <p>Di situs web WeCIC, buka <strong>Lainnya → Hapus akun</strong>, lalu pilih <strong>Hapus akun dan semua data saya</strong>.</p>
        <p>Penghapusan langsung berjalan dan kamu keluar dari semua perangkat yang tercatat. Data WeCIC di perangkat ini ikut dibersihkan. Sesi web yang sangat lama (dibuka sebelum fitur ini ada) berakhir sendiri setelah 2 jam tidak dipakai.</p>
    </section>

    <section aria-labelledby="deletion-scope-title" class="flex flex-col gap-3">
        <h2 id="deletion-scope-title">Data yang dihapus</h2>
        <ul>
            <li>Arsip data portal dan semua riwayat versinya, termasuk berkas materi kuliah yang diarsipkan.</li>
            <li>Notebook beserta sumber, berkas unggahan, percakapan AI, dan persetujuan Notebook AI.</li>
            <li>Data login offline (hash password) dan password terenkripsi untuk sinkronisasi di background.</li>
            <li>Riwayat notifikasi, token notifikasi push, Antrean, dan catatan unggahan ujian.</li>
            <li>Data portal yang tersimpan sementara di server, serta sesi web dan login aplikasi di setiap perangkat. Salinan sementara yang tersisa dan sesi lama habis sendiri paling lama dalam 7 hari.</li>
        </ul>
        <x-alert tone="warning" icon>
            Data yang sudah dihapus tidak bisa dikembalikan. Kamu tetap bisa masuk lagi ke WeCIC kapan saja, tetapi arsip dan notebook mulai dari kosong.
        </x-alert>
    </section>

    <section aria-labelledby="deletion-kept-title" class="flex flex-col gap-3">
        <h2 id="deletion-kept-title">Yang tidak ikut dihapus</h2>
        <ul>
            <li><strong>Akun portal akademik kampus</strong> (NIM, password, dan datamu di portal). Akun itu milik kampus; WeCIC tidak bisa dan tidak akan mengubahnya.</li>
            <li><strong>Log server</strong> yang terhapus sendiri dalam 14 hari, dan <strong>laporan error Sentry</strong> yang tidak memuat nama atau NIM dan terhapus sendiri paling lama 90 hari.</li>
            <li>Data yang sudah dikirim ke <strong>Google Gemini</strong> lewat Notebook AI. Karena WeCIC memakai Gemini API tingkat gratis, data itu tunduk pada kebijakan Google dan tidak bisa dihapus oleh WeCIC.</li>
        </ul>
    </section>

    <section id="email" aria-labelledby="deletion-email-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="deletion-email-title">Tidak bisa masuk? Minta lewat email</h2>
        <p>Jika kamu tidak bisa masuk ke WeCIC (misalnya lupa password portal atau sudah tidak kuliah), kirim permintaan penghapusan lewat email:</p>
        <ol>
            <li>Kirim email ke <a href="mailto:developer@rizqis.com?subject=Hapus%20data%20WeCIC">developer@rizqis.com</a> dengan subjek <strong>Hapus data WeCIC</strong>.</li>
            <li>Tulis NIM-mu. Sebaiknya kirim dari email yang tercantum di biodata portalmu.</li>
            <li>Untuk melindungimu, kami dapat meminta bukti bahwa kamu pemilik NIM tersebut sebelum menghapus.</li>
        </ol>
        <p>Kami membalas dan menyelesaikan permintaan <strong>paling lambat 30 hari</strong> sejak permintaan lengkap diterima. Data yang dihapus sama dengan penghapusan dari dalam aplikasi.</p>
    </section>

    <section aria-labelledby="deletion-partial-title" class="flex flex-col gap-3">
        <h2 id="deletion-partial-title">Hanya ingin menghapus sebagian?</h2>
        <ul>
            <li><strong>Arsip → Sinkronisasi di background:</strong> matikan untuk menghapus password terenkripsi di server.</li>
            <li><strong>Notebook:</strong> hapus notebook satu per satu, atau tarik persetujuan Notebook AI.</li>
            <li><strong>Antrean:</strong> batalkan item yang belum terkirim.</li>
            <li><strong>Lainnya → Keluar:</strong> menghapus data WeCIC di perangkat, mengakhiri sesi, dan mematikan sinkronisasi di background.</li>
        </ul>
        <p>Rincian data yang diproses ada di <a href="{{ route('legal.privacy') }}">kebijakan privasi</a>. Pertanyaan lain: <a href="{{ route('legal.support') }}">Bantuan</a>.</p>
    </section>

    <section id="english" lang="en" aria-labelledby="deletion-english-title" class="flex scroll-mt-6 flex-col gap-3 border-t border-border pt-8">
        <h2 id="deletion-english-title">Delete your account and data (English)</h2>
        <ol>
            <li><strong>In the app:</strong> sign in, open <strong>Lainnya (More) → Privasi (Privacy) → Hapus akun &amp; data WeCIC</strong>, turn on <strong>Saya mengerti data ini tidak bisa dikembalikan</strong>, tap <strong>Hapus akun &amp; data WeCIC</strong> and confirm with <strong>Hapus permanen</strong>. On the website: <strong>Lainnya → Hapus akun → Hapus akun dan semua data saya</strong>. Deletion is immediate; remaining temporary copies and older sessions expire within 7 days.</li>
            <li><strong>Deleted:</strong> your portal data archive and archived files, notebooks with their sources, AI chats and AI consent, offline sign-in data and the encrypted background-sync password, notifications, push tokens, the outbox, exam upload records, cached portal data, and all sessions.</li>
            <li><strong>Not deleted:</strong> your campus portal account (owned by the university), server logs (expire within 14 days), crash reports without your name or NIM (expire within 90 days), and content already sent to Google Gemini under its free tier.</li>
            <li><strong>Cannot sign in?</strong> Email <a href="mailto:developer@rizqis.com?subject=Hapus%20data%20WeCIC">developer@rizqis.com</a> with the subject "Hapus data WeCIC" and your student ID (NIM). We may ask you to verify ownership, and we reply within 30 days.</li>
        </ol>
    </section>
@endsection
