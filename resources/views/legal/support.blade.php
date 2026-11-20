@extends('legal.layout')

@section('title', 'Bantuan — WeCIC')
@section('page_id', 'legal-support')
@section('heading', 'Bantuan WeCIC')
@section('summary', 'Jawaban untuk pertanyaan yang sering muncul, dan cara menghubungi pengembang.')

@section('legal_content')
    <section aria-labelledby="support-contact-title" class="flex flex-col gap-3">
        <h2 id="support-contact-title">Hubungi kami</h2>
        <p>Email: <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>. Balasan biasanya dalam 1–3 hari kerja.</p>
        <p>Untuk masalah akun portal, atau nilai, KRS, dan tagihan yang salah di portal itu sendiri, hubungi bagian akademik kampusmu. WeCIC hanya menampilkan data dari portal dan tidak bisa mengubahnya.</p>
    </section>

    <section aria-labelledby="support-faq-title" class="flex flex-col gap-5">
        <h2 id="support-faq-title">Pertanyaan umum</h2>

        <div class="flex flex-col gap-2">
            <h3>Bagaimana cara masuk?</h3>
            <p>Masuk dengan NIM dan password yang sama seperti di portal akademik kampus (my.cic.ac.id). WeCIC tidak punya akun terpisah. Jika gagal, pastikan kamu bisa masuk ke portal lewat browser. Saat portal sedang gangguan, WeCIC juga tidak bisa memuat data baru, tetapi arsip tetap bisa dibuka.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Apa itu "Tetap masuk"?</h3>
            <p>Jika aktif, NIM dan password disimpan di penyimpanan aman perangkat (Keychain atau Keystore) agar aplikasi bisa masuk ulang otomatis. Matikan jika kamu memakai perangkat bersama. Setelah mengganti password portal, keluar lalu masuk lagi dengan password baru.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Apa itu sinkronisasi di background?</h3>
            <p>Fitur opsional yang <strong>mati secara bawaan</strong>. Jika kamu menyalakannya (saat masuk atau di <strong>Arsip → Sinkronisasi di background</strong>), server menyimpan password portalmu dalam bentuk terenkripsi supaya bisa memperbarui arsip dan mengirim pengingat walau aplikasi tidak dibuka. Mematikannya atau keluar dari aplikasi langsung menghapus password tersimpan itu.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Notifikasi tidak muncul.</h3>
            <p>Izinkan notifikasi untuk WeCIC di pengaturan perangkat, lalu buka aplikasi sekali agar perangkat terdaftar. Pengingat saat aplikasi tidak dibuka butuh sinkronisasi di background. Matikan penghemat baterai yang agresif untuk WeCIC jika notifikasi sering terlambat.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Absensi QR tidak berhasil.</h3>
            <p>Izinkan akses kamera, arahkan ke QR dari dosen, atau pilih foto QR dari galeri. Gambar hanya dibaca di perangkat dan tidak diunggah. Jika portal tidak bisa diakses, absensi masuk Antrean dan dikirim otomatis saat portal kembali. QR yang sudah kedaluwarsa akan ditolak portal.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Apakah Notebook AI mengirim dataku ke pihak lain?</h3>
            <p>Ya, tetapi hanya setelah kamu menyetujuinya di aplikasi. Pertanyaan dan isi sumber notebook dikirim ke Google Gemini (tingkat gratis), dan Google dapat memakainya untuk meningkatkan produknya. Persetujuan bisa ditarik kapan saja di <strong>Lainnya → Privasi → Izin Notebook AI</strong> di aplikasi, atau di halaman Notebook di web. Rinciannya ada di <a href="{{ route('legal.privacy') }}#penerima">kebijakan privasi</a>.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Bagaimana cara menghapus akun dan dataku?</h3>
            <p>Buka <strong>Lainnya → Privasi → Hapus akun &amp; data WeCIC</strong> di aplikasi, atau <strong>Lainnya → Hapus akun</strong> di web. Jika tidak bisa masuk, kirim email ke <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>. Langkah lengkapnya ada di halaman <a href="{{ route('legal.data-deletion') }}">Hapus akun &amp; data</a>.</p>
        </div>

        <div class="flex flex-col gap-2">
            <h3>Apakah WeCIC aplikasi resmi kampus?</h3>
            <p>Bukan. WeCIC adalah aplikasi unofficial buatan pengembang perorangan dan tidak berafiliasi dengan kampus. Data tetap bersumber dari portal akademik kampus.</p>
        </div>
    </section>

    <section aria-labelledby="support-links-title" class="flex flex-col gap-3">
        <h2 id="support-links-title">Dokumen terkait</h2>
        <ul>
            <li><a href="{{ route('legal.privacy') }}">Kebijakan privasi</a></li>
            <li><a href="{{ route('legal.data-deletion') }}">Hapus akun &amp; data</a></li>
        </ul>
    </section>

    <section id="english" lang="en" aria-labelledby="support-english-title" class="flex scroll-mt-6 flex-col gap-3 border-t border-border pt-8">
        <h2 id="support-english-title">Support (English)</h2>
        <p>WeCIC is an <strong>unofficial</strong> app by an individual developer and is not affiliated with any university. It signs in to the campus academic portal with your own student ID (NIM) and password.</p>
        <ul>
            <li><strong>Contact:</strong> <a href="mailto:developer@rizqis.com">developer@rizqis.com</a> (we usually reply within 1–3 business days). For wrong grades, study plans or bills on the portal itself, contact your campus academic office.</li>
            <li><strong>Sign-in problems:</strong> check that you can sign in to the portal in a browser; when the portal is down, archived data still opens.</li>
            <li><strong>Background sync</strong> is optional and off by default. Turning it off, or signing out, deletes the encrypted password stored on the server.</li>
            <li><strong>Notebook AI</strong> sends your questions and sources to Google Gemini (free tier, Google may use them to improve its products) only after you consent in the app.</li>
            <li><strong>Delete your account and data:</strong> in the app open <strong>Lainnya (More) → Hapus akun (Delete account)</strong>, or see <a href="{{ route('legal.data-deletion') }}">Delete account &amp; data</a>.</li>
            <li><a href="{{ route('legal.privacy') }}">Privacy policy</a></li>
        </ul>
    </section>
@endsection
