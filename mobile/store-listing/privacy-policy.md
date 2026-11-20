# Kebijakan Privasi WeCIC

<!--
CATATAN UNTUK PENGEMBANG (tidak ikut dipublikasikan):
- Versi yang dipublikasikan adalah halaman https://wecic.rizqis.com/privasi
  (resources/views/legal/privacy.blade.php). Dokumen ini adalah teks sumbernya.
  Jika salah satunya diubah, ubah juga yang lain beserta tanggal "Terakhir diperbarui".
- Disusun ulang 2026-11-20 dari kode: sinkronisasi di background opt-in, persetujuan Notebook AI
  (Gemini tingkat gratis, dibagikan ke Google), hapus akun di aplikasi (DELETE /api/v1/account),
  dan halaman publik /privasi, /bantuan, /hapus-data.
- Sesuai keputusan pemilik, pengendali disebut "pengembang WeCIC (perorangan)" tanpa nama pribadi
  dan tanpa alamat surat; kontak hanya email.
- Klaim yang masih harus dikonfirmasi pemilik:
  - Retensi Sentry "paling lama 90 hari": paket Sentry belum dicek.
  - Log server 14 hari: produksi harus memakai LOG_STACK=daily dan LOG_DAILY_DAYS=14
    (.env.example masih LOG_STACK=single, yang tidak pernah dirotasi).
  - Penyedia hosting hanya disebut umum, tanpa nama atau negara.
-->

**Berlaku sejak:** 20 November 2026
**Terakhir diperbarui:** 20 November 2026
**URL resmi kebijakan ini:** https://wecic.rizqis.com/privasi

> **WeCIC adalah aplikasi unofficial.** WeCIC tidak berafiliasi dengan kampus atau universitas mana pun dan tidak mendapat izin atau dukungan dari kampus.

## 1. Siapa kami

Kebijakan ini menjelaskan bagaimana WeCIC, yaitu aplikasi Android dan iOS (ID paket `com.rizqis.wecic`), aplikasi web di wecic.rizqis.com, dan server pendukungnya, memproses data pribadimu.

Pengendali data pribadi menurut UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP) adalah pengembang WeCIC, seorang pengembang perorangan. Kontak privasi: **developer@rizqis.com**.

WeCIC adalah aplikasi unofficial. WeCIC tidak berafiliasi dengan kampus atau universitas mana pun dan tidak mendapat izin atau dukungan dari kampus. Nama portal hanya dipakai untuk menjelaskan portal akademik kampus yang dibuka aplikasi ini.

Dalam kebijakan ini, "kami" berarti pengembang WeCIC dan "kamu" berarti pengguna WeCIC.

## 2. Cara kerja WeCIC

WeCIC membantu mahasiswa memakai portal akademik kampus (my.cic.ac.id) dengan lebih mudah. Saat kamu masuk, aplikasi mengirim NIM dan password portalmu ke server WeCIC. Server WeCIC lalu masuk ke portal atas namamu, membaca halaman portal (jadwal, kelas, tugas, nilai, tagihan, dan lainnya), lalu mengirim hasilnya ke aplikasi dalam bentuk yang lebih ringkas.

Tindakan seperti absensi QR, unggah tugas atau jawaban ujian, dan ganti password portal juga diteruskan server WeCIC ke portal. WeCIC tidak mengubah apa pun di portal kecuali atas perintahmu.

## 3. Data yang kami proses

### 3.1 Akun dan login

- **NIM (username portal):** untuk masuk ke portal dan mengenali sesimu. Di server, data dikelompokkan dengan kunci turunan dari NIM, bukan NIM aslinya.
- **Token sesi aplikasi:** membuatmu tetap masuk. Di server hanya disimpan dalam bentuk hash.
- **Nama perangkat** (misalnya "iPhone"): untuk menandai login di tiap perangkat.
- **Cookie sesi portal:** disimpan server supaya tidak perlu masuk ulang ke portal di setiap permintaan.

### 3.2 Password portal

- **Di perangkat:** jika "Tetap masuk" aktif, NIM dan password disimpan di penyimpanan aman sistem operasi (iOS Keychain atau Android Keystore), hanya untuk perangkat itu, agar aplikasi bisa masuk ulang otomatis. Jika dimatikan, password tidak disimpan di perangkat.
- **Di server, untuk login offline:** server menyimpan hash bcrypt password (bukan password aslinya) agar kamu tetap bisa membuka arsip saat portal tidak bisa diakses.
- **Di server, untuk sinkronisasi di background (opsional, mati secara bawaan):** hanya jika kamu menyalakannya, server menyimpan NIM dan password dalam bentuk terenkripsi supaya bisa masuk ke portal secara berkala (sekitar setiap 3 jam, materi kuliah sekitar sekali sehari) untuk memperbarui arsip dan mengirim pengingat. Password terenkripsi ini disimpan sampai kamu mematikan sinkronisasi atau keluar (logout), dan dihapus lebih awal bila portal menolaknya (misalnya setelah kamu mengganti password) atau 30 hari setelah login online terakhirmu.
- Password tidak pernah ditulis ke log server dan tidak dibagikan ke pihak mana pun selain portal kampus.

### 3.3 Data dari portal kampus

- **Biodata:** nama, NIM, program studi, tempat dan tanggal lahir, jenis kelamin, agama, alamat, nomor telepon, email, NISN, riwayat sekolah, dan foto profil yang ada di portal. Data agama hanya ditampilkan karena ada di halaman biodata portal dan tidak dipakai untuk tujuan lain.
- **Akademik:** jadwal dan kelas, pertemuan, tugas, ujian, KRS, perwalian, nilai dan IPK, transkrip, SKKM, skripsi, dan perpustakaan.
- **Keuangan:** tagihan dan riwayat pembayaran. WeCIC hanya menampilkannya; tidak ada pembayaran di dalam aplikasi.
- **Pengumuman** kampus dan kontak dosen atau staf yang tercantum di portal.

### 3.4 Berkas dan konten yang kamu kirim

- **Berkas tugas dan jawaban ujian** yang kamu pilih: dikirim ke server WeCIC lalu diteruskan ke portal. Jika portal sedang tidak bisa diakses, berkas disimpan sementara di Antrean dan dikirim saat portal kembali.
- **Kode QR absensi:** kamera atau foto hanya dipakai untuk membaca QR di perangkatmu. Gambarnya tidak disimpan dan tidak diunggah; yang dikirim hanya teks hasil pembacaan QR.
- **Notebook AI:** materi kuliah (dari arsip atau unggahanmu), teks hasil ekstraksinya, pertanyaanmu, dan jawaban AI. Lihat bagian 5 tentang Google Gemini.
- **Ganti password portal:** password lama dan baru hanya diteruskan ke portal.

### 3.5 Notifikasi

- **Token notifikasi push** (Expo push token) dan platform (Android atau iOS), untuk mengirim pengingat tenggat, absensi, dan pengumuman.
- **Riwayat notifikasi** (judul, isi, tautan, status dibaca).
- Notifikasi bersifat opsional; izinnya bisa dicabut di pengaturan perangkat.

### 3.6 Data teknis dan laporan error

- **Alamat IP:** untuk membatasi percobaan (rate limiting) dan mencegah penyalahgunaan. Log server tidak mencatat NIM dalam bentuk asli.
- **Laporan error dan kinerja (Sentry):** saat aplikasi atau server mengalami error, kami menerima jejak error, model perangkat dan versi sistem operasi, versi aplikasi, riwayat navigasi singkat sebelum error, serta data kinerja dari sebagian sesi. Pengiriman data identitas bawaan dimatikan, laporan tidak dikaitkan dengan NIM atau namamu, dan tidak ada rekaman layar. Data ini disimpan di wilayah Uni Eropa.

### 3.7 Yang tidak kami kumpulkan

- Lokasi, kontak, mikrofon, dan kalender.
- ID iklan. WeCIC tidak menampilkan iklan, tidak memakai SDK analitik atau pelacakan, dan tidak melacakmu di aplikasi atau situs lain.
- Data pembayaran. WeCIC tidak memproses pembayaran.
- WeCIC tidak menjual data pribadimu.

## 4. Tujuan dan dasar pemrosesan

1. **Menjalankan fitur yang kamu minta** (masuk ke portal, menampilkan data, absensi, unggah berkas, Notebook AI, notifikasi). Dasar: persetujuanmu dan pemenuhan permintaanmu sebagai pengguna.
2. **Menyimpan arsip dan akses offline**, agar data tetap bisa dibuka saat portal tidak tersedia. Dasar: persetujuan; bisa dihapus kapan saja.
3. **Sinkronisasi di background dan pengingat.** Dasar: persetujuan yang kamu berikan saat menyalakannya dan izin notifikasi.
4. **Menjaga keamanan dan keandalan layanan** (rate limiting, log, laporan error). Dasar: kepentingan yang sah.

Kami tidak memakai datamu untuk iklan, profil pemasaran, atau keputusan otomatis yang berdampak hukum terhadapmu.

## 5. Pihak lain yang menerima data

| Penerima | Data | Tujuan |
|---|---|---|
| Portal akademik kampus (my.cic.ac.id) | NIM, password, kode QR absensi, berkas tugas dan ujian, permintaan ganti password | Menjalankan perintahmu di portal |
| Google (Gemini API) | Pertanyaan Notebook AI, teks materi, berkas PDF atau gambar materi | Menjawab pertanyaan, membaca dan meringkas materi |
| Expo, diteruskan ke Firebase Cloud Messaging (Google) atau Apple Push Notification service | Token push, judul dan isi notifikasi | Mengirim notifikasi ke perangkatmu |
| Sentry (wilayah Uni Eropa) | Laporan error dan kinerja (bagian 3.6) | Menemukan dan memperbaiki error |
| Penyedia hosting server | Data yang disimpan server WeCIC | Menjalankan server WeCIC |

> **Notebook AI dan Google Gemini.** Fitur AI di Notebook baru mengirim data ke Google Gemini setelah kamu memberi persetujuan di dalam aplikasi. Sebelum itu, tidak ada pertanyaan atau berkas yang dikirim ke Google. Persetujuan bisa ditarik kapan saja di Lainnya → Privasi → Izin Notebook AI di aplikasi, atau di halaman Notebook di web; setelah ditarik, fitur AI berhenti mengirim data.
>
> WeCIC memakai Gemini API tingkat gratis. Pada tingkat ini, data yang dikirim **dibagikan ke Google** dan dapat disimpan serta dipakai Google untuk meningkatkan produknya, termasuk dibaca oleh peninjau manusia. Jangan masukkan data pribadi atau rahasia yang tidak perlu ke Notebook AI. Jawaban AI bisa keliru; periksa sebelum dipakai.

- Sebagian penerima berada di luar Indonesia. Dengan memakai fitur terkait, kamu menyetujui transfer tersebut.
- Kami dapat mengungkapkan data jika diwajibkan hukum atau perintah otoritas yang berwenang.
- Pemrosesan data di portal kampus tunduk pada kebijakan kampus, bukan kebijakan ini.

## 6. Masa simpan

| Data | Disimpan |
|---|---|
| Token sesi aplikasi | Sampai kamu keluar; dihapus otomatis jika tidak dipakai 30 hari |
| Password terenkripsi untuk sinkronisasi di background | Hanya jika kamu menyalakannya; sampai sinkronisasi dimatikan atau kamu keluar, paling lama 30 hari setelah login online terakhir |
| Hash password dan info pengguna untuk login offline | 30 hari setelah login online terakhir |
| Token push | Dihapus saat keluar; otomatis dihapus jika tidak aktif 60 hari |
| Riwayat notifikasi | 90 hari |
| Item Antrean | 30 hari setelah selesai diproses |
| Cache sementara data portal | Paling lama 7 hari |
| Log server | 14 hari |
| Arsip data portal dan berkas materi yang diarsipkan | Sampai kamu menghapusnya atau menghapus akun; maksimal 30 versi terakhir per jenis data |
| Notebook (materi, teks, pertanyaan, jawaban) dan persetujuan AI | Sampai kamu menghapus notebook atau menghapus akun |
| Laporan error Sentry | Dihapus otomatis sesuai masa simpan Sentry, paling lama 90 hari |
| Data di perangkat | Dihapus saat kamu keluar atau menghapus aplikasi |

Keluar (logout) mengakhiri sesi, menghapus token push, menghapus data WeCIC di perangkat, dan mematikan sinkronisasi di background sekaligus menghapus password terenkripsinya. Arsip dan notebook di server tetap ada sampai kamu menghapusnya atau menghapus akun.

## 7. Keamanan

- Semua komunikasi antara aplikasi, server WeCIC, portal kampus, dan penyedia pihak ketiga memakai HTTPS/TLS.
- Di perangkat, token dan password disimpan di iOS Keychain atau Android Keystore dan tidak ikut dicadangkan.
- Di server, password terenkripsi, info pengguna, arsip data portal, dan isi Antrean dienkripsi dengan kunci aplikasi. Token sesi disimpan sebagai hash.
- Isi Notebook, berkas materi yang diarsipkan, dan data sesi disimpan tanpa enkripsi tingkat aplikasi, tetapi hanya bisa diakses server dan dilindungi kontrol akses server.
- Jika terjadi kegagalan pelindungan data pribadi, kami akan memberi tahu pengguna yang terdampak dan lembaga berwenang paling lambat 3 x 24 jam sejak mengetahuinya, sesuai UU PDP.

## 8. Hak dan pilihanmu

Sesuai UU PDP, kamu berhak mendapat informasi tentang pemrosesan datamu, mengakses dan meminta salinannya, memperbaiki data yang keliru (data akademik berasal dari portal, jadi perbaikannya diajukan ke kampus), menghapus data, menarik persetujuan, serta mengajukan keberatan atau pengaduan.

- **Hapus akun dan semua data:** buka Lainnya → Privasi → Hapus akun & data WeCIC di aplikasi, atau Lainnya → Hapus akun di web. Langkah lengkap dan daftar data yang dihapus ada di halaman [Hapus akun & data](https://wecic.rizqis.com/hapus-data).
- **Sinkronisasi di background:** matikan di Arsip → Sinkronisasi di background (atau Lainnya → Privasi); password terenkripsi langsung dihapus.
- **Persetujuan Notebook AI:** tarik kapan saja di Lainnya → Privasi → Izin Notebook AI di aplikasi, atau di halaman Notebook di web.
- **Notebook dan Antrean:** hapus notebook atau batalkan item Antrean langsung di aplikasi.
- **Izin perangkat:** cabut izin kamera, foto, atau notifikasi di pengaturan perangkat.
- **Lewat email:** kirim permintaan ke developer@rizqis.com. Kami membalas paling lambat 30 hari.

Akun portal kampus (NIM dan password) milik kampus dan tidak bisa dihapus oleh WeCIC.

## 9. Anak-anak

WeCIC ditujukan untuk mahasiswa dan tidak ditujukan untuk anak di bawah 13 tahun. Kami tidak dengan sengaja mengumpulkan data anak di bawah 13 tahun; jika mengetahuinya, data itu akan kami hapus. Pengguna di bawah 18 tahun sebaiknya memakai WeCIC dengan sepengetahuan orang tua atau wali.

## 10. Perubahan kebijakan

Kami dapat memperbarui kebijakan ini jika aplikasi atau aturan berubah. Tanggal "Terakhir diperbarui" akan ikut berubah, dan perubahan penting akan diberitahukan lewat aplikasi atau halaman ini sebelum berlaku.

## 11. Hubungi kami

Pertanyaan, permintaan, atau pengaduan privasi: **developer@rizqis.com** (pengembang WeCIC). Bantuan umum ada di halaman [Bantuan](https://wecic.rizqis.com/bantuan).

---

## English summary

*This summary is provided for convenience; the Indonesian text above prevails.*

- **Who we are:** WeCIC is an unofficial app by an individual developer (the WeCIC developer), not affiliated with or endorsed by any university. Contact: developer@rizqis.com.
- **How it works:** the WeCIC server signs in to the campus academic portal on your behalf with your student ID (NIM) and password and shows your academic data in the app.
- **Passwords:** the server keeps a bcrypt hash for offline sign-in (30 days). Background sync is opt-in and off by default; only when you turn it on is your password stored encrypted, until you turn sync off or sign out (at most 30 days after your last online sign-in).
- **Notebook AI (Google Gemini):** nothing is sent until you give in-app consent. WeCIC uses the free tier of the Gemini API, so your questions and notebook sources are shared with Google, which may store and use them to improve its products, including human review. You can withdraw consent at any time.
- **Other recipients:** the campus portal, Expo / Firebase Cloud Messaging / Apple Push Notification service (push tokens and notification text), Sentry in the EU region (crash and performance data without your name or NIM), and our hosting provider. We do not sell data, show ads or use analytics or tracking SDKs.
- **Retention:** session tokens 30 days unused, push tokens 60 days inactive, notifications 90 days, logs 14 days, crash reports up to 90 days; archives and notebooks until you delete them or your account.
- **Deletion:** in the app go to Lainnya (More) → Privasi (Privacy) → Hapus akun & data WeCIC (on the website: Lainnya → Hapus akun), or email developer@rizqis.com; we reply within 30 days. See [Delete account & data](https://wecic.rizqis.com/hapus-data).
