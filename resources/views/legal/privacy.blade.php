@extends('legal.layout')

@section('title', 'Kebijakan Privasi — WeCIC')
@section('page_id', 'legal-privacy')
@section('heading', 'Kebijakan privasi WeCIC')
@section('summary', 'Data apa yang diproses WeCIC, ke mana dikirim, berapa lama disimpan, dan cara menghapusnya.')
@section('meta', 'Berlaku sejak 20 November 2026 · Terakhir diperbarui 23 November 2026 · https://wecic.rizqis.com/privasi')

@section('legal_content')
    <nav aria-labelledby="privacy-toc-title" class="rounded-lg border border-border-light bg-surface/50 px-4 py-3 text-sm">
        <p id="privacy-toc-title" class="font-medium text-ink">Isi</p>
        <ol class="mt-2">
            <li><a href="#siapa-kami">Siapa kami</a></li>
            <li><a href="#cara-kerja">Cara kerja WeCIC</a></li>
            <li><a href="#data">Data yang kami proses</a></li>
            <li><a href="#tujuan">Tujuan dan dasar pemrosesan</a></li>
            <li><a href="#penerima">Pihak lain yang menerima data</a></li>
            <li><a href="#masa-simpan">Masa simpan</a></li>
            <li><a href="#keamanan">Keamanan</a></li>
            <li><a href="#hak">Hak dan pilihanmu</a></li>
            <li><a href="#anak">Anak-anak</a></li>
            <li><a href="#perubahan">Perubahan kebijakan</a></li>
            <li><a href="#kontak">Hubungi kami</a></li>
            <li><a href="#english" lang="en">English summary</a></li>
        </ol>
    </nav>

    <section id="siapa-kami" aria-labelledby="siapa-kami-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="siapa-kami-title">1. Siapa kami</h2>
        <p>Kebijakan ini menjelaskan bagaimana WeCIC, yaitu aplikasi Android dan iOS (ID paket <code class="font-mono text-[13px]">com.rizqis.wecic</code>), aplikasi web di <a href="https://wecic.rizqis.com">wecic.rizqis.com</a>, dan server pendukungnya, memproses data pribadimu.</p>
        <p>Pengendali data pribadi menurut UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP) adalah <strong>pengembang WeCIC</strong>, seorang pengembang perorangan. Kontak privasi: <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>.</p>
        <p>WeCIC adalah aplikasi <strong>unofficial</strong>. WeCIC tidak berafiliasi dengan kampus atau universitas mana pun dan tidak mendapat izin atau dukungan dari kampus. Nama portal hanya dipakai untuk menjelaskan portal akademik kampus yang dibuka aplikasi ini.</p>
        <p>Dalam kebijakan ini, "kami" berarti pengembang WeCIC dan "kamu" berarti pengguna WeCIC.</p>
    </section>

    <section id="cara-kerja" aria-labelledby="cara-kerja-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="cara-kerja-title">2. Cara kerja WeCIC</h2>
        <p>WeCIC membantu mahasiswa memakai portal akademik kampus (my.cic.ac.id) dengan lebih mudah. Saat kamu masuk, aplikasi mengirim NIM dan password portalmu ke server WeCIC. Server WeCIC lalu <strong>masuk ke portal atas namamu</strong>, membaca halaman portal (jadwal, kelas, tugas, nilai, tagihan, dan lainnya), lalu mengirim hasilnya ke aplikasi dalam bentuk yang lebih ringkas.</p>
        <p>Tindakan seperti absensi QR, unggah tugas atau jawaban ujian, dan ganti password portal juga diteruskan server WeCIC ke portal. WeCIC tidak mengubah apa pun di portal kecuali atas perintahmu.</p>
    </section>

    <section id="data" aria-labelledby="data-title" class="flex scroll-mt-6 flex-col gap-4">
        <h2 id="data-title">3. Data yang kami proses</h2>

        <h3>3.1 Akun dan login</h3>
        <ul>
            <li><strong>NIM</strong> (username portal): untuk masuk ke portal dan mengenali sesimu. Di server, data dikelompokkan dengan kunci turunan dari NIM, bukan NIM aslinya.</li>
            <li><strong>Token sesi aplikasi:</strong> membuatmu tetap masuk. Di server hanya disimpan dalam bentuk hash.</li>
            <li><strong>Nama perangkat</strong> (misalnya "iPhone"): untuk menandai login di tiap perangkat.</li>
            <li><strong>Cookie sesi portal:</strong> disimpan server supaya tidak perlu masuk ulang ke portal di setiap permintaan.</li>
        </ul>

        <h3>3.2 Password portal</h3>
        <ul>
            <li><strong>Di perangkat:</strong> jika "Tetap masuk" aktif, NIM dan password disimpan di penyimpanan aman sistem operasi (iOS Keychain atau Android Keystore), hanya untuk perangkat itu, agar aplikasi bisa masuk ulang otomatis. Jika dimatikan, password tidak disimpan di perangkat.</li>
            <li><strong>Di server, untuk login offline:</strong> server menyimpan <strong>hash bcrypt</strong> password (bukan password aslinya) agar kamu tetap bisa membuka arsip saat portal tidak bisa diakses.</li>
            <li><strong>Di server, untuk sinkronisasi di background (opsional, mati secara bawaan):</strong> hanya jika kamu menyalakannya, server menyimpan NIM dan password <strong>dalam bentuk terenkripsi</strong> supaya bisa masuk ke portal secara berkala (sekitar setiap 3 jam, materi kuliah sekitar sekali sehari) untuk memperbarui arsip dan mengirim pengingat. Password terenkripsi ini disimpan <strong>sampai kamu mematikan sinkronisasi atau keluar (logout)</strong>, dan dihapus lebih awal bila portal menolaknya (misalnya setelah kamu mengganti password) atau 30 hari setelah login online terakhirmu.</li>
            <li>Password tidak pernah ditulis ke log server dan tidak dibagikan ke pihak mana pun selain portal kampus.</li>
        </ul>

        <h3>3.3 Data dari portal kampus</h3>
        <ul>
            <li><strong>Biodata:</strong> nama, NIM, program studi, tempat dan tanggal lahir, jenis kelamin, agama, alamat, nomor telepon, email, NISN, riwayat sekolah, dan foto profil yang ada di portal. Data agama hanya ditampilkan karena ada di halaman biodata portal dan tidak dipakai untuk tujuan lain.</li>
            <li><strong>Akademik:</strong> jadwal dan kelas, pertemuan, tugas, ujian, KRS, perwalian, nilai dan IPK, transkrip, SKKM, skripsi, dan perpustakaan.</li>
            <li><strong>Keuangan:</strong> tagihan dan riwayat pembayaran. WeCIC hanya menampilkannya; tidak ada pembayaran di dalam aplikasi.</li>
            <li><strong>Pengumuman</strong> kampus dan kontak dosen atau staf yang tercantum di portal.</li>
        </ul>

        <h3>3.4 Berkas dan konten yang kamu kirim</h3>
        <ul>
            <li><strong>Berkas tugas dan jawaban ujian</strong> yang kamu pilih: dikirim ke server WeCIC lalu diteruskan ke portal. Jika portal sedang tidak bisa diakses, berkas disimpan sementara di Antrean dan dikirim saat portal kembali.</li>
            <li><strong>Kode QR absensi:</strong> kamera atau foto hanya dipakai untuk membaca QR di perangkatmu. Gambarnya tidak disimpan dan tidak diunggah; yang dikirim hanya teks hasil pembacaan QR.</li>
            <li><strong>Notebook AI:</strong> materi kuliah (dari arsip atau unggahanmu), teks hasil ekstraksinya, pertanyaanmu, dan jawaban AI. Lihat bagian 5 tentang {{ $notebookAi['label'] }}.</li>
            <li><strong>Ganti password portal:</strong> password lama dan baru hanya diteruskan ke portal.</li>
        </ul>

        <h3>3.5 Notifikasi</h3>
        <ul>
            <li><strong>Token notifikasi push</strong> (Expo push token) dan platform (Android atau iOS), untuk mengirim pengingat tenggat, absensi, dan pengumuman.</li>
            <li><strong>Riwayat notifikasi</strong> (judul, isi, tautan, status dibaca).</li>
            <li>Notifikasi bersifat opsional; izinnya bisa dicabut di pengaturan perangkat.</li>
        </ul>

        <h3>3.6 Data teknis dan laporan error</h3>
        <ul>
            <li><strong>Alamat IP:</strong> untuk membatasi percobaan (rate limiting) dan mencegah penyalahgunaan. Log server tidak mencatat NIM dalam bentuk asli.</li>
            <li><strong>Laporan error dan kinerja (Sentry):</strong> saat aplikasi atau server mengalami error, kami menerima jejak error, model perangkat dan versi sistem operasi, versi aplikasi, riwayat navigasi singkat sebelum error, serta data kinerja dari sebagian sesi. Pengiriman data identitas bawaan dimatikan, laporan tidak dikaitkan dengan NIM atau namamu, dan tidak ada rekaman layar. Data ini disimpan di wilayah Uni Eropa.</li>
        </ul>

        <h3>3.7 Yang tidak kami kumpulkan</h3>
        <ul>
            <li>Lokasi, kontak, mikrofon, dan kalender.</li>
            <li>ID iklan. WeCIC tidak menampilkan iklan, tidak memakai SDK analitik atau pelacakan, dan tidak melacakmu di aplikasi atau situs lain.</li>
            <li>Data pembayaran. WeCIC tidak memproses pembayaran.</li>
            <li>WeCIC <strong>tidak menjual</strong> data pribadimu.</li>
        </ul>
    </section>

    <section id="tujuan" aria-labelledby="tujuan-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="tujuan-title">4. Tujuan dan dasar pemrosesan</h2>
        <ol>
            <li><strong>Menjalankan fitur yang kamu minta</strong> (masuk ke portal, menampilkan data, absensi, unggah berkas, Notebook AI, notifikasi). Dasar: persetujuanmu dan pemenuhan permintaanmu sebagai pengguna.</li>
            <li><strong>Menyimpan arsip dan akses offline</strong>, agar data tetap bisa dibuka saat portal tidak tersedia. Dasar: persetujuan; bisa dihapus kapan saja.</li>
            <li><strong>Sinkronisasi di background dan pengingat.</strong> Dasar: persetujuan yang kamu berikan saat menyalakannya dan izin notifikasi.</li>
            <li><strong>Menjaga keamanan dan keandalan layanan</strong> (rate limiting, log, laporan error). Dasar: kepentingan yang sah.</li>
        </ol>
        <p>Kami tidak memakai datamu untuk iklan, profil pemasaran, atau keputusan otomatis yang berdampak hukum terhadapmu.</p>
    </section>

    <section id="penerima" aria-labelledby="penerima-title" class="flex scroll-mt-6 flex-col gap-4">
        <h2 id="penerima-title">5. Pihak lain yang menerima data</h2>
        <x-table>
            <thead>
                <tr>
                    <x-table.th>Penerima</x-table.th>
                    <x-table.th>Data</x-table.th>
                    <x-table.th>Tujuan</x-table.th>
                </tr>
            </thead>
            <tbody>
                <x-table.tr>
                    <x-table.td class="align-top font-medium text-ink">Portal akademik kampus (my.cic.ac.id)</x-table.td>
                    <x-table.td class="align-top">NIM, password, kode QR absensi, berkas tugas dan ujian, permintaan ganti password</x-table.td>
                    <x-table.td class="align-top">Menjalankan perintahmu di portal</x-table.td>
                </x-table.tr>
                {{-- The AI provider(s) the server uses (`$notebookAi`, NotebookAiProviders::forPages()). --}}
                @foreach ($notebookAi['processors'] as $processor)
                    <x-table.tr>
                        <x-table.td class="align-top font-medium text-ink">{{ match ($processor['driver']) { 'gemini' => 'Google (Gemini API)', 'anthropic' => 'Anthropic (Claude API)', default => $processor['label'] } }}</x-table.td>
                        <x-table.td class="align-top">Pertanyaan Notebook AI, teks materi, berkas PDF atau gambar materi</x-table.td>
                        <x-table.td class="align-top">Menjawab pertanyaan, membaca dan meringkas materi</x-table.td>
                    </x-table.tr>
                @endforeach
                {{-- Providers that received Notebook content before (NotebookAiProviders::pastRecipients()): what was sent stays with them. --}}
                @foreach ($notebookAi['past_processors'] as $pastProcessor)
                    <x-table.tr>
                        <x-table.td class="align-top font-medium text-ink">{{ $pastProcessor }} (sebelumnya)</x-table.td>
                        <x-table.td class="align-top">Isi Notebook AI yang dikirim selama penyedia ini dipakai</x-table.td>
                        <x-table.td class="align-top">Tidak menerima data baru; data yang sudah dikirim tunduk pada kebijakan penyedianya</x-table.td>
                    </x-table.tr>
                @endforeach
                <x-table.tr>
                    <x-table.td class="align-top font-medium text-ink">Expo, diteruskan ke Firebase Cloud Messaging (Google) atau Apple Push Notification service</x-table.td>
                    <x-table.td class="align-top">Token push, judul dan isi notifikasi</x-table.td>
                    <x-table.td class="align-top">Mengirim notifikasi ke perangkatmu</x-table.td>
                </x-table.tr>
                <x-table.tr>
                    <x-table.td class="align-top font-medium text-ink">Sentry (wilayah Uni Eropa)</x-table.td>
                    <x-table.td class="align-top">Laporan error dan kinerja (bagian 3.6)</x-table.td>
                    <x-table.td class="align-top">Menemukan dan memperbaiki error</x-table.td>
                </x-table.tr>
                <x-table.tr>
                    <x-table.td class="align-top font-medium text-ink">Penyedia hosting server</x-table.td>
                    <x-table.td class="align-top">Data yang disimpan server WeCIC</x-table.td>
                    <x-table.td class="align-top">Menjalankan server WeCIC</x-table.td>
                </x-table.tr>
            </tbody>
        </x-table>

        <x-alert tone="info" icon title="Notebook AI dan {{ $notebookAi['label'] }}">
            <div class="mt-1 flex flex-col gap-2">
                <p>Fitur AI di Notebook baru mengirim data ke {{ $notebookAi['label'] }} <strong>setelah kamu memberi persetujuan di dalam aplikasi</strong>. Sebelum itu, tidak ada pertanyaan atau berkas yang dikirim ke penyedia AI. Persetujuan bisa ditarik kapan saja di <strong>Lainnya → Privasi → Izin Notebook AI</strong> di aplikasi, atau di halaman Notebook di web; setelah ditarik, fitur AI berhenti mengirim data. Jika WeCIC berganti penyedia AI, kamu diminta menyetujuinya lagi.</p>
                @foreach ($notebookAi['processors'] as $processor)
                    @if ($processor['free_tier'] && $processor['driver'] === 'gemini')
                        <p>WeCIC memakai <strong>Gemini API tingkat gratis</strong>. Pada tingkat ini, data yang dikirim <strong>dibagikan ke Google dan dapat disimpan serta dipakai Google untuk meningkatkan produknya</strong>, termasuk dibaca oleh peninjau manusia.</p>
                    @elseif ($processor['free_tier'])
                        <p>WeCIC memakai <strong>{{ $processor['label'] }} tingkat gratis</strong>. Pada tingkat ini, data yang dikirim <strong>dapat disimpan dan dipakai penyedianya untuk meningkatkan produknya</strong>.</p>
                    @else
                        <p>Data yang dikirim ke {{ $processor['label'] }} diproses sesuai ketentuan layanan penyedianya untuk menjawab pertanyaan dan membaca materimu.</p>
                    @endif
                @endforeach
                <p>Jangan masukkan data pribadi atau rahasia yang tidak perlu ke Notebook AI. Jawaban AI bisa keliru; periksa sebelum dipakai.</p>
            </div>
        </x-alert>

        <ul>
            <li>Sebagian penerima berada di luar Indonesia. Dengan memakai fitur terkait, kamu menyetujui transfer tersebut.</li>
            <li>Kami dapat mengungkapkan data jika diwajibkan hukum atau perintah otoritas yang berwenang.</li>
            <li>Pemrosesan data di portal kampus tunduk pada kebijakan kampus, bukan kebijakan ini.</li>
        </ul>
    </section>

    <section id="masa-simpan" aria-labelledby="masa-simpan-title" class="flex scroll-mt-6 flex-col gap-4">
        <h2 id="masa-simpan-title">6. Masa simpan</h2>
        <x-table>
            <thead>
                <tr>
                    <x-table.th>Data</x-table.th>
                    <x-table.th>Disimpan</x-table.th>
                </tr>
            </thead>
            <tbody>
                <x-table.tr><x-table.td class="align-top">Token sesi aplikasi</x-table.td><x-table.td class="align-top">Sampai kamu keluar; dihapus otomatis jika tidak dipakai 30 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Password terenkripsi untuk sinkronisasi di background</x-table.td><x-table.td class="align-top">Hanya jika kamu menyalakannya; sampai sinkronisasi dimatikan atau kamu keluar, paling lama 30 hari setelah login online terakhir</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Hash password dan info pengguna untuk login offline</x-table.td><x-table.td class="align-top">30 hari setelah login online terakhir</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Token push</x-table.td><x-table.td class="align-top">Dihapus saat keluar; otomatis dihapus jika tidak aktif 60 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Riwayat notifikasi</x-table.td><x-table.td class="align-top">90 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Item Antrean</x-table.td><x-table.td class="align-top">30 hari setelah selesai diproses</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Cache sementara data portal</x-table.td><x-table.td class="align-top">Paling lama 7 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Log server</x-table.td><x-table.td class="align-top">14 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Arsip data portal dan berkas materi yang diarsipkan</x-table.td><x-table.td class="align-top">Sampai kamu menghapusnya atau menghapus akun; maksimal 30 versi terakhir per jenis data</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Notebook (materi, teks, pertanyaan, jawaban) dan persetujuan AI</x-table.td><x-table.td class="align-top">Sampai kamu menghapus notebook atau menghapus akun</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Laporan error Sentry</x-table.td><x-table.td class="align-top">Dihapus otomatis sesuai masa simpan Sentry, paling lama 90 hari</x-table.td></x-table.tr>
                <x-table.tr><x-table.td class="align-top">Data di perangkat</x-table.td><x-table.td class="align-top">Dihapus saat kamu keluar atau menghapus aplikasi</x-table.td></x-table.tr>
            </tbody>
        </x-table>
        <p>Keluar (logout) mengakhiri sesi, menghapus token push, menghapus data WeCIC di perangkat, dan mematikan sinkronisasi di background sekaligus menghapus password terenkripsinya. Arsip dan notebook di server tetap ada sampai kamu menghapusnya atau <a href="{{ route('legal.data-deletion') }}">menghapus akun</a>.</p>
    </section>

    <section id="keamanan" aria-labelledby="keamanan-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="keamanan-title">7. Keamanan</h2>
        <ul>
            <li>Semua komunikasi antara aplikasi, server WeCIC, portal kampus, dan penyedia pihak ketiga memakai HTTPS/TLS.</li>
            <li>Di perangkat, token dan password disimpan di iOS Keychain atau Android Keystore dan tidak ikut dicadangkan.</li>
            <li>Di server, password terenkripsi, info pengguna, arsip data portal, dan isi Antrean <strong>dienkripsi</strong> dengan kunci aplikasi. Token sesi disimpan sebagai hash.</li>
            <li>Isi Notebook, berkas materi yang diarsipkan, dan data sesi disimpan tanpa enkripsi tingkat aplikasi, tetapi hanya bisa diakses server dan dilindungi kontrol akses server.</li>
            <li>Jika terjadi kegagalan pelindungan data pribadi, kami akan memberi tahu pengguna yang terdampak dan lembaga berwenang paling lambat 3 x 24 jam sejak mengetahuinya, sesuai UU PDP.</li>
        </ul>
    </section>

    <section id="hak" aria-labelledby="hak-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="hak-title">8. Hak dan pilihanmu</h2>
        <p>Sesuai UU PDP, kamu berhak mendapat informasi tentang pemrosesan datamu, mengakses dan meminta salinannya, memperbaiki data yang keliru (data akademik berasal dari portal, jadi perbaikannya diajukan ke kampus), menghapus data, menarik persetujuan, serta mengajukan keberatan atau pengaduan.</p>
        <ul>
            <li><strong>Hapus akun dan semua data:</strong> buka <strong>Lainnya → Privasi → Hapus akun &amp; data WeCIC</strong> di aplikasi, atau <strong>Lainnya → Hapus akun</strong> di web. Langkah lengkap dan daftar data yang dihapus ada di halaman <a href="{{ route('legal.data-deletion') }}">Hapus akun &amp; data</a>.</li>
            <li><strong>Sinkronisasi di background:</strong> matikan di <strong>Arsip → Sinkronisasi di background</strong>; password terenkripsi langsung dihapus.</li>
            <li><strong>Persetujuan Notebook AI:</strong> tarik kapan saja di <strong>Lainnya → Privasi → Izin Notebook AI</strong> di aplikasi, atau di halaman Notebook di web.</li>
            <li><strong>Notebook dan Antrean:</strong> hapus notebook atau batalkan item Antrean langsung di aplikasi.</li>
            <li><strong>Izin perangkat:</strong> cabut izin kamera, foto, atau notifikasi di pengaturan perangkat.</li>
            <li><strong>Lewat email:</strong> kirim permintaan ke <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>. Kami membalas paling lambat 30 hari.</li>
        </ul>
        <p>Akun portal kampus (NIM dan password) milik kampus dan tidak bisa dihapus oleh WeCIC.</p>
    </section>

    <section id="anak" aria-labelledby="anak-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="anak-title">9. Anak-anak</h2>
        <p>WeCIC ditujukan untuk mahasiswa dan tidak ditujukan untuk anak di bawah 13 tahun. Kami tidak dengan sengaja mengumpulkan data anak di bawah 13 tahun; jika mengetahuinya, data itu akan kami hapus. Pengguna di bawah 18 tahun sebaiknya memakai WeCIC dengan sepengetahuan orang tua atau wali.</p>
    </section>

    <section id="perubahan" aria-labelledby="perubahan-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="perubahan-title">10. Perubahan kebijakan</h2>
        <p>Kami dapat memperbarui kebijakan ini jika aplikasi atau aturan berubah. Tanggal "Terakhir diperbarui" akan ikut berubah, dan perubahan penting akan diberitahukan lewat aplikasi atau halaman ini sebelum berlaku.</p>
    </section>

    <section id="kontak" aria-labelledby="kontak-title" class="flex scroll-mt-6 flex-col gap-3">
        <h2 id="kontak-title">11. Hubungi kami</h2>
        <p>Pertanyaan, permintaan, atau pengaduan privasi: <a href="mailto:developer@rizqis.com">developer@rizqis.com</a> (pengembang WeCIC). Bantuan umum ada di halaman <a href="{{ route('legal.support') }}">Bantuan</a>.</p>
    </section>

    <section id="english" lang="en" aria-labelledby="english-title" class="flex scroll-mt-6 flex-col gap-3 border-t border-border pt-8">
        <h2 id="english-title">English summary</h2>
        <p>This summary is provided for convenience; the Indonesian text above prevails.</p>
        <ul>
            <li><strong>Who we are:</strong> WeCIC is an <strong>unofficial</strong> app by an individual developer (the WeCIC developer), not affiliated with or endorsed by any university. Contact: <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>.</li>
            <li><strong>How it works:</strong> the WeCIC server signs in to the campus academic portal on your behalf with your student ID (NIM) and password and shows your academic data in the app.</li>
            <li><strong>Passwords:</strong> the server keeps a bcrypt hash for offline sign-in (30 days). Background sync is <strong>opt-in and off by default</strong>; only when you turn it on is your password stored encrypted, until you turn sync off or sign out (at most 30 days after your last online sign-in).</li>
            <li>
                <strong>Notebook AI ({{ $notebookAi['label_en'] }}):</strong> nothing is sent until you give in-app consent, and you are asked again if WeCIC switches AI provider.
                @foreach ($notebookAi['processors'] as $processor)
                    @if ($processor['free_tier'] && $processor['driver'] === 'gemini')
                        WeCIC uses the <strong>free tier of the Gemini API</strong>, so your questions and notebook sources are <strong>shared with Google, which may store and use them to improve its products</strong>, including human review.
                    @elseif ($processor['free_tier'])
                        WeCIC uses the <strong>free tier of {{ $processor['label'] }}</strong>, so its provider <strong>may store and use what is sent to improve its products</strong>.
                    @else
                        What is sent to {{ $processor['label'] }} is processed under its provider's terms of service.
                    @endif
                @endforeach
                You can withdraw consent at any time.
            </li>
            <li><strong>Other recipients:</strong> the campus portal, Expo / Firebase Cloud Messaging / Apple Push Notification service (push tokens and notification text), Sentry in the EU region (crash and performance data without your name or NIM), and our hosting provider. We do not sell data, show ads or use analytics or tracking SDKs.</li>
            <li><strong>Retention:</strong> session tokens 30 days unused, push tokens 60 days inactive, notifications 90 days, logs 14 days, crash reports up to 90 days; archives and notebooks until you delete them or your account.</li>
            <li><strong>Deletion:</strong> in the app go to <strong>Lainnya (More) → Privasi (Privacy) → Hapus akun &amp; data WeCIC</strong> (on the website: <strong>Lainnya → Hapus akun</strong>), or email <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>; we reply within 30 days. See <a href="{{ route('legal.data-deletion') }}">Delete account &amp; data</a>.</li>
        </ul>
    </section>
@endsection
