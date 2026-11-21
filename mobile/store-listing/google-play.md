# WeCIC: panduan isian Google Play Console

Panduan ini mengisi **setiap bagian** Google Play Console untuk WeCIC (`com.rizqis.wecic`), urut seperti checklist "Siapkan aplikasi Anda" di Dasbor Console. Semua teks untuk toko ada di blok kode, siap disalin.

- Bahasa listing utama: **Indonesia (id-ID)**. Terjemahan kedua: **English (en-US)**.
- Jumlah karakter di samping tiap kolom dihitung per karakter (huruf, spasi, tanda baca, baris baru). Batasnya sudah dicek.
- Status: riset per 2026-11-05 dari halaman bantuan resmi Google, diperbarui 2026-11-20 setelah mode reviewer, hapus akun, persetujuan Notebook AI, sinkronisasi background opt-in, dan halaman publik selesai dibangun. Label **[BELUM TERVERIFIKASI]** berarti tidak ditemukan di halaman resmi. Label **TODO** berarti keputusan atau data milik pengembang, bukan dikarang di sini. Placeholder `[isi dari ...]` diisi dari server, tidak pernah ditulis di repo.
- Panduan App Store ada di file terpisah.

Keputusan pemilik (final, 2026-11-20): mode reviewer di produksi sebagai akun uji; sinkronisasi di background **opt-in**; kontak publik `developer@rizqis.com`; Gemini API **tingkat gratis**, jadi data Notebook AI dinyatakan **dibagikan ke Google**; aplikasi **unofficial** tanpa izin kampus, dengan kata netral "portal akademik kampus"; origin produksi `https://wecic.rizqis.com`.

URL publik (sudah dibangun):

| Halaman | URL |
|---|---|
| Kebijakan privasi | `https://wecic.rizqis.com/privasi` |
| Bantuan / website | `https://wecic.rizqis.com/bantuan` |
| Hapus akun & data (Data safety) | `https://wecic.rizqis.com/hapus-data` |

---

## 0. Blocker: status sebelum mengirim

### 0.1 Sudah selesai di kode (2026-11-20)

| # | Blocker | Status |
|---|---|---|
| B2 | Belum ada kebijakan privasi, halaman dukungan, dan URL penghapusan data | **Selesai 2026-11-20.** Tiga halaman publik di atas, di luar middleware `portal.web`. Aplikasi menautkannya dari layar masuk dan Lainnya → Privasi |
| B3 | Reviewer tidak bisa masuk | **Selesai 2026-11-20.** Mode reviewer produksi lewat `PORTAL_REVIEWER_USERNAME` dan `PORTAL_REVIEWER_PASSWORD`. Login itu dijawab dari data contoh yang sudah disanitasi, tidak kedaluwarsa, tanpa OTP, dan tidak pernah menghubungi my.cic.ac.id (§2.2) |
| B4 | Kamera Android diblokir | **Selesai 2026-11-20** di app.json: `cameraPermission: false` dihapus. Manifest dari prebuild bersih berisi `CAMERA`. Cek ulang di build EAS (H6) |
| B6 | Belum ada URL API produksi | **Selesai 2026-11-20.** `mobile/eas.json` profil `production` dan `preview` memakai `EXPO_PUBLIC_API_URL=https://wecic.rizqis.com` |
| B7 | Belum ada cara menghapus seluruh data di server | **Selesai 2026-11-20.** Lainnya → Privasi → "Hapus akun & data WeCIC" memanggil `DELETE /api/v1/account` (web: `/account/delete`). Jalur email untuk yang tidak bisa masuk ada di `/hapus-data` |
| — | Izin bawaan template yang tidak dipakai | **Selesai 2026-11-20.** `android.blockedPermissions`: `SYSTEM_ALERT_WINDOW`, `READ/WRITE_EXTERNAL_STORAGE`, `USE_BIOMETRIC`, `USE_FINGERPRINT`, `RECORD_AUDIO` |
| — | Auto Backup menyalin cache data akademik | **Selesai 2026-11-20.** `android.allowBackup: false` |
| — | Pengungkapan sebelum login | **Selesai 2026-11-20.** Layar masuk menjelaskan bahwa kata sandi dikirim ke server WeCIC dan data portal disimpan di sana, dengan tautan "Kebijakan privasi" dan sakelar "Nyalakan sinkronisasi di background" yang mati secara bawaan |
| — | Persetujuan Notebook AI (Google Gemini) | **Selesai 2026-11-20.** Layar `/ai-consent` dengan "Setuju" dan "Tidak sekarang". Server menolak permintaan AI tanpa persetujuan (403 `ai_consent_required`) |
| — | Kata sandi disimpan server secara bawaan | **Selesai 2026-11-20.** Sinkronisasi di background opt-in. Kata sandi terenkripsi dihapus saat fitur dimatikan atau saat keluar |

### 0.2 Masih harus dikerjakan manusia

| # | Blocker | Kenapa | Yang harus dilakukan |
|---|---|---|---|
| B1 / H1 | **Risiko merek dan Device and Network Abuse** | Tidak ada izin kampus. Nama "WeCIC" memuat "CIC", warna aksen aplikasi (`#0352A1`) masih biru kampus, dan aplikasi masuk ke serta mengambil data dari my.cic.ac.id. Kebijakan Impersonation, Intellectual Property, Misrepresentation, dan Device and Network Abuse tetap bisa dipakai untuk menghapus aplikasi jika ada keluhan | Penafian unofficial sudah ada di baris pertama deskripsi (§4.3), di aplikasi, dan di halaman web. Jangan pakai logo atau lambang kampus. **TODO (keputusan pemilik):** terima risikonya, atau ganti nama dan warna. Tidak bisa diselesaikan lewat kode |
| B3 / H2 | **Kredensial reviewer di produksi** | Mode reviewer mati selama env kosong | Isi `PORTAL_REVIEWER_USERNAME` (bukan angka saja) dan `PORTAL_REVIEWER_PASSWORD` di `.env` produksi, `php artisan config:cache`, pastikan `tests/Fixtures/portal` ter-deploy, lalu salin ke App access (§2.2) |
| B5 / H3 | **Push di build toko: FCM / Firebase** | `extra.eas.projectId` belum ada dan kredensial FCM belum diunggah | `eas init` di `mobile/`. Buat proyek Firebase untuk `com.rizqis.wecic`, lalu unggah **service account key FCM V1** ke EAS (`eas credentials`). Kirim notifikasi uji dari build internal |
| H4 | **Screenshot asli** | Generator masih membuat placeholder | Ambil 6 tangkapan sesuai `raw/README.md` dengan akun reviewer (datanya sudah data contoh), lalu jalankan ulang `node mobile/store-listing/tools/generate-assets.mjs` |
| B8 | **Uji tertutup 12 penguji selama 14 hari** | Akun pribadi yang dibuat setelah 13 Nov 2023 harus menjalankan uji tertutup dengan **minimal 12 penguji yang ikut terus-menerus selama 14 hari** sebelum bisa mengajukan produksi | Rekrut 12+ penguji (dan cadangan) sekarang (§6.3) |
| H5 | **Service account untuk `eas submit`** | `eas submit -p android` butuh kunci service account Google Cloud dengan akses ke Play Console | Buat service account, beri izin rilis di Play Console (Users and permissions), lalu daftarkan kuncinya di EAS. Unggahan pertama tetap harus manual lewat Console |
| B9 | **Verifikasi pengembang Android** berlaku di Indonesia sejak 30 Sep 2026 | Aplikasi dari pengembang yang belum terverifikasi tidak bisa dipasang di perangkat bersertifikat di Indonesia | Pastikan halaman "Android developer verification" di Play Console berstatus terdaftar. Play App Signing otomatis mengklaim paket (§6.1) |
| B10 | **Target API 36 (Android 16)** wajib untuk aplikasi baru sejak 31 Agu 2026 | Unggahan dengan target lebih rendah ditolak | Expo SDK 58 seharusnya sudah menargetkan 36; cek di build EAS atau `npx expo config --type introspect` di salinan bersih |
| H6 | **Prebuild ulang dan deploy server** | Folder `android/` lokal basi; fitur baru butuh migrasi | Pakai build EAS cloud atau `npx expo prebuild --clean`. Di server: `php artisan migrate`, `npm run build`, `config:cache` |
| H7 | **Konfirmasi klaim kebijakan privasi** | Beberapa angka belum punya sumber di repo | Retensi Sentry 90 hari, log 14 hari (produksi harus `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`), nama dan negara hosting |

Perbaikan lain yang disarankan (bukan blocker keras):
- Tambahkan tombol lapor atau tandai jawaban AI di Notebook AI, untuk berjaga-jaga terhadap kebijakan AI-Generated Content. Belum dibangun.
- Layar masuk saat ini mengungkapkan pengiriman kata sandi lalu memakai tombol "Masuk". Jika reviewer meminta persetujuan eksplisit, tambahkan kata persetujuan di tombol atau kotak centang terpisah.
- Logout di satu perangkat mematikan sinkronisasi di background untuk semua perangkat milik mahasiswa itu. Konfirmasi keluar sudah menyebut sinkronisasi dimatikan, tetapi tidak menyebut "semua perangkat".

---

## 1. Dialog "Buat aplikasi" (Home > Create app)

| Kolom | Isi | Catatan |
|---|---|---|
| App name | `WeCIC` (5/30) | Nama ini bisa diganti nanti di Main store listing. Lihat §4.1 untuk risiko nama. |
| Default language | **Indonesian – id** | Bawaannya en-US; ganti sekarang. |
| App or game | **App** | |
| Free or paid | **Free** | Aplikasi gratis tidak bisa diubah menjadi berbayar nanti. |
| Declarations | Centang **Developer Program Policies** dan **US export laws** | Wajib untuk membuat aplikasi. |

---

## 2. Konten aplikasi (Policy > App content)

Dasbor menampilkan tugas "Beri tahu kami tentang konten aplikasi Anda" dalam urutan di bawah. Beberapa butir (ID iklan, izin foto dan video, layanan latar depan) hanya muncul bila relevan bagi build yang diunggah.

### 2.1 Privacy policy

| Kolom | Isi |
|---|---|
| Privacy policy URL | `https://wecic.rizqis.com/privasi` (32 karakter) |

Halaman ini sudah dibangun (`resources/views/legal/privacy.blade.php`, teks sumber di `privacy-policy.md`) dan bisa dibuka dari aplikasi (layar masuk, Lainnya → Privasi → Kebijakan privasi, layar Izin Notebook AI). Isinya sesuai syarat kebijakan User Data:
- Pengendali: pengembang WeCIC (perorangan), kontak privasi `developer@rizqis.com`.
- Semua jenis data (lihat §2.6), pengamanan, masa simpan, dan cara menghapusnya.
- Sinkronisasi di background opt-in dan mati secara bawaan. Kata sandi terenkripsi hanya disimpan setelah mahasiswa menyalakannya, dihapus saat dimatikan atau saat keluar, dan paling lama 30 hari setelah login online terakhir.
- Notebook AI baru mengirim data ke Google Gemini setelah persetujuan di aplikasi. WeCIC memakai tingkat gratis, jadi data **dibagikan ke Google** dan dapat dipakai Google untuk meningkatkan produknya, termasuk ditinjau manusia.
- Arsip dan notebook disimpan sampai pengguna menghapusnya atau menghapus akun.
- Biodata yang diarsipkan termasuk **agama**, alamat, nomor HP, tanggal lahir, dan riwayat sekolah.
- Penerima data: portal akademik kampus, Google Gemini, Expo Push (lalu FCM/APNs), Sentry (wilayah UE), hosting.
- Tidak ada iklan, analitik pemasaran, atau penjualan data.
- Penafian unofficial, tidak berafiliasi dengan kampus mana pun.

### 2.2 App access (Sign-in details)

Pilih: **All or some functionality in my app is restricted**.

Lalu **Add instructions** (maksimal 5 set). Isi satu set dengan akun **mode reviewer** (keputusan pemilik):

| Kolom | Isi |
|---|---|
| Name | `Reviewer account` |
| Username / email | `[isi dari PORTAL_REVIEWER_USERNAME di server]` (bukan angka saja) |
| Password | `[isi dari PORTAL_REVIEWER_PASSWORD di server]`. Jangan tulis di repo atau di chat |
| Any other instructions | Teks di bawah (bahasa Inggris, karena reviewer membaca Inggris) |

Versi panjang (untuk catatan; kolom "informasi lain" di Console maksimal 500 karakter, pakai versi pendek di bawahnya):
```text
WeCIC is an unofficial companion app for students who use the campus academic portal my.cic.ac.id. It is not affiliated with the university. Every screen except sign-in needs an account.

This is a dedicated reviewer account on our production server (https://wecic.rizqis.com). It never contacts the university portal: all screens show sanitized sample data (student "Mahasiswa Contoh", NIM 20990000001). It does not expire, works from any location and has no 2-step verification or OTP.

1. Open the app. On the "Masuk" (Sign in) screen, enter the username and password above, then tap "Masuk". Leave "Nyalakan sinkronisasi di background" off; it is not available for this account.
2. Bottom tabs: Beranda (Home), Absensi (Attendance), Kelas (Classes), Akademik (Academics), Lainnya (More).
3. Attendance: Absensi tab > scan any QR code, pick one from the gallery, or type any code. The check-in is simulated and reported as recorded. Assignment upload and delete, exam answer upload (the open sample exam), portal password change and file downloads are simulated the same way; nothing is sent to the university.
4. AI Notebook: Lainnya > Notebook AI. Asking a question first opens the consent screen for Google Gemini ("Setuju" / "Tidak sekarang"). Nothing is sent to Google before consent. Consent can be withdrawn under Lainnya > Privasi > Izin Notebook AI.
5. Account deletion: Lainnya > Privasi > "Hapus akun & data WeCIC". You may test it: it signs out only your own session (other reviewers stay signed in) and resets the shared sample notebooks and consent; signing in again afterwards works. Public page: https://wecic.rizqis.com/hapus-data
6. Push notifications are optional. The app works if notification permission is denied.

All reviewers share the same sample data. If sign-in fails, contact developer@rizqis.com.
```

Versi pendek yang diisi di Console (500 karakter maksimal):
```text
Unofficial companion app for the campus portal my.cic.ac.id. This reviewer account uses sanitized sample data, never contacts the university, does not expire and has no OTP. Sign in on the "Masuk" screen; leave background sync off. Attendance (any code), assignment and exam uploads, password change and downloads are simulated and succeed. AI Notebook asks for Gemini consent first. Delete account: Lainnya > Privasi. Help: developer@rizqis.com
```

Tentang mode reviewer:
- Login reviewer membuka sesi `store-reviewer` yang dijawab dari fixture di `tests/Fixtures/portal`. Password yang salah ditolak (401) tanpa menghubungi portal. Akun mahasiswa lain berjalan seperti biasa.
- Aksi tulis yang bisa dicapai dari aplikasi disimulasikan oleh `PortalDemoFixtures` dengan halaman sukses portal dari `tests/Fixtures/portal/demo`, apa pun yang dikirim: absensi QR (kode apa saja), upload dan hapus tugas (tugas yang diunggah tampil dengan file dan tombol hapus sampai dihapus, disimpan di cache 1 hari dan dibagi semua sesi sandbox), upload jawaban ujian (ujian contoh 99001 yang masih terbuka), dan ganti sandi (pesan menyebut mode demo, sandi akun reviewer tidak berubah, tidak ada hash offline yang disimpan). Unduhan `/portal/files/...` dijawab dengan berkas buatan sesuai tipenya (PDF satu halaman, GIF 1x1, dst.). Tidak ada request yang keluar ke my.cic.ac.id.
- Tautan keluar ke portal asli disembunyikan untuk sesi reviewer dan demo: tombol "Tambah SKKM di portal" (web dan aplikasi) dan tugas "Isi Kuesioner VMTS" di pusat tindakan.
- Sinkronisasi di background tidak tersedia untuk akun ini (409, `available: false`), dan tidak ada password atau hash yang disimpan.
- Semua reviewer berbagi satu pemilik data, jadi persetujuan AI, notebook, dan hapus akun dari satu reviewer terlihat oleh reviewer berikutnya.
- Syarat kredensial reviewer (dari ringkasan hasil pencarian halaman bantuan Google; halaman lengkapnya **[BELUM TERVERIFIKASI]**): selalu bisa dipakai, bisa dipakai berulang, berlaku dari lokasi mana pun, bebas error, dan tidak terhalang verifikasi 2 langkah atau OTP. Server harus menyala selama review.

Opsional: isi kredensial yang sama di **Test and release > Pre-launch report > Settings**, agar crawler pre-launch bisa melewati layar masuk.

### 2.3 Ads

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Does your app contain ads? | **No, my app does not contain ads** | Tidak ada SDK iklan di `mobile/package.json` atau `composer.json`. |

### 2.4 Content rating (kuesioner IARC)

Langkah: App content > Content rating > Start > isi email korespondensi IARC (`developer@rizqis.com`) > pilih kategori > jawab semua pertanyaan > Save > Next > tinjau rating > Submit.

Teks pertanyaan IARC hanya terlihat di Console. Daftar di bawah disusun dari deskripsi IARC dan panduan pihak ketiga **[BELUM TERVERIFIKASI kata per kata]**. Jawab sesuai yang tampil di Console dengan prinsip yang sama.

| Bagian / pertanyaan | Jawaban | Alasan |
|---|---|---|
| Kategori | **Reference, News, or Educational** (alternatif: Utility, Productivity, Communication) | Aplikasi akademik mahasiswa. |
| Kekerasan (violence, blood, gore) | **No** | Tidak ada. |
| Rasa takut / horor | **No** | |
| Seksualitas / ketelanjangan | **No** | |
| Bahasa kasar | **No** | Pengumuman berasal dari kampus, bukan dari pengguna. |
| Zat terkontrol (narkoba, alkohol, rokok) | **No** | |
| Humor kasar | **No** | |
| Judi / simulasi judi | **No** | |
| Can users interact or exchange content with each other? | **No** | Tidak ada obrolan atau konten antarpengguna. Notebook privat per pemilik; pengumuman hanya dibaca. |
| Does the app share the user's current physical location with other users? | **No** | Tidak ada izin atau kode lokasi. |
| Does the app allow users to purchase digital goods? | **No** | Tidak ada pembelian dalam aplikasi. Tagihan kuliah hanya ditampilkan. |
| Unrestricted internet access / web browser | **No** | Peramban dalam aplikasi hanya membuka tautan tetap (situs WeCIC, perpustakaan, tautan ujian). Pengguna tidak bisa mengetik URL bebas. |
| Is the app a web browser or search engine? | **No** | |
| Jika ditanya tentang konten buatan AI | **Jawab Yes/sesuai fakta** | Notebook AI (Google Gemini) menghasilkan teks bebas tentang materi kuliah. |

Perkiraan hasil: **3+ / Everyone** (IARC generik untuk Indonesia).

### 2.5 Target audience and content

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Target age groups | Centang **18 and over** saja | Pengguna adalah mahasiswa. Memilih hanya 18+ membuka opsi "Restrict Minor Access". |
| Kenapa bukan di bawah 13? | | Tidak ada konten untuk anak, dan memilih kelompok anak memicu Families Policy (SDK, iklan, dan aturan data yang lebih ketat) yang tidak relevan. |
| Kenapa tidak 16–17? | | Opsional. Sebagian mahasiswa baru mungkin berusia 17. Mencentang 16–17 tidak memicu Families Policy, tapi "18 and over saja" paling sederhana. **TODO:** putuskan; jika mahasiswa 17 tahun diperbolehkan, tambahkan 16–17. |
| Appeal to children? | **No** | Ikon, grafis, dan teks tidak menyasar anak. |
| Restrict Minor Access | Opsional (muncul bila hanya 18+) | Boleh dibiarkan mati. **TODO:** aktifkan jika ingin mencegah pengguna di bawah umur. |

### 2.6 Data safety

Wajib untuk trek tertutup, terbuka, dan produksi. Draf bisa disimpan; ekspor atau impor CSV tersedia. Google tidak memverifikasi isinya, jadi tanggung jawab ada pada pengembang. Jawaban di bawah diperbarui 2026-11-20 dan harus cocok dengan `https://wecic.rizqis.com/privasi`.

**Bagian 1: Data collection and security**

| Pertanyaan | Jawaban | Alasan / syarat |
|---|---|---|
| Does your app collect or share any of the required user data types? | **Yes** | Data dikirim dari perangkat ke server WeCIC dan SDK, dan isi Notebook AI dibagikan ke Google setelah persetujuan. |
| Is all of the user data collected by your app encrypted in transit? | **Yes** | B6 selesai: build produksi memakai `https://wecic.rizqis.com`. Server ke portal, Gemini, Expo, dan Sentry memakai HTTPS. Pastikan nginx memaksa HTTPS (server tidak memakai `forceScheme`/HSTS) dan Reverb memakai `wss`. |
| Account creation | **Username and password** (pilih opsi login dengan username dan sandi yang tampil di Console) [BELUM TERVERIFIKASI kata per kata] | Mahasiswa masuk dengan akun portal yang sudah ada, tetapi server membuat profil, token, arsip, dan notebook per mahasiswa. Jadi kebijakan Account deletion berlaku. |
| Delete account URL | `https://wecic.rizqis.com/hapus-data` | Halaman publik tanpa login. Berisi langkah di aplikasi (Lainnya → Privasi → Hapus akun & data WeCIC → "Saya mengerti..." → Hapus permanen) dan di web (Lainnya → Hapus akun), data yang dihapus dan yang tidak, serta jalur email `developer@rizqis.com` dengan subjek "Hapus data WeCIC" untuk yang tidak bisa masuk (dibalas paling lambat 30 hari). |
| Do you provide a way for users to request that their data is deleted? | **Yes** | B7 selesai: hapus akun dan semua data dari aplikasi, plus penghapusan sebagian (matikan sinkronisasi, hapus notebook, tarik persetujuan AI, batalkan Antrean). |
| Independent security review | **No** (lewati) | Opsional; butuh audit MASA. |

**Bagian 2: Data types**

Aturan berbagi yang dipakai:
- **Google Gemini = Shared.** WeCIC memakai Gemini API **tingkat gratis**. Pada tingkat ini Google dapat menyimpan dan memakai data untuk meningkatkan produknya, jadi Google tidak bertindak murni sebagai service provider. Data yang terkena: Files and docs dan Other user-generated content (isi Notebook AI). Data itu baru dikirim setelah persetujuan di aplikasi, jadi tandai **Opsional**.
- **Expo Push (FCM/APNs) dan Sentry = tidak dibagikan.** Keduanya memproses data atas nama pengembang (service provider).
- **Portal akademik kampus = tidak dibagikan.** Kiriman ke my.cic.ac.id adalah transfer yang dimulai pengguna sendiri, dengan pengungkapan di layar masuk.

| Kategori | Jenis data | Dikumpulkan? | Dibagikan? | Wajib / opsional | Ephemeral? | Tujuan | Sumber di aplikasi |
|---|---|---|---|---|---|---|---|
| Location | Approximate, Precise | **No** | No | | | | Tidak ada izin atau kode lokasi. |
| Personal info | Name | **Yes** | No | Wajib | No | App functionality, Account management | Biodata dan info pengguna dari portal. |
| | Email address | **Yes** | No | Wajib | No | App functionality | Biodata portal. |
| | User IDs | **Yes** | No | Wajib | No | App functionality, Account management | NIM (username portal). |
| | Address | **Yes** | No | Wajib | No | App functionality | Alamat, kota, provinsi, kode pos di biodata (diarsipkan). |
| | Phone number | **Yes** | No | Wajib | No | App functionality | Telepon dan HP di biodata. |
| | Race and ethnicity | **No** | No | | | | Tidak ada di biodata. |
| | Political or religious beliefs | **Yes** | No | Wajib | No | App functionality | Kolom **agama** di biodata ikut diarsipkan. |
| | Sexual orientation | **No** | No | | | | |
| | Other info | **Yes** | No | Wajib | No | App functionality, Account management | Sandi portal (kredensial): selalu dikirim untuk login, hash bcrypt untuk login offline, dan disimpan terenkripsi **hanya** jika sinkronisasi di background dinyalakan. Juga tempat/tanggal lahir, jenis kelamin, NISN, riwayat sekolah, program studi. Play tidak punya jenis "password"; "Other info" paling dekat. |
| Financial info | User payment info | **No** | No | | | | Tidak ada nomor kartu atau rekening. |
| | Purchase history | **No** | No | | | | Tidak ada pembelian dalam aplikasi. |
| | Credit score | **No** | No | | | | |
| | Other financial info | **Yes** | No | Wajib | No | App functionality | Tagihan kuliah dan riwayat pembayaran (diarsipkan). |
| Health and fitness | Health info, Fitness info | **No** | No | | | | |
| Messages | Emails, SMS or MMS, Other in-app messages | **No** | No | | | | Tidak ada pesan antarpengguna. Pengumuman kampus bukan pesan pengguna. |
| Photos and videos | Photos | **No** | No | | | | QR dari foto dibaca di perangkat; foto tidak diunggah. Gambar yang diunggah sebagai sumber Notebook AI masuk ke Files and docs. **TODO verifikasi:** jika server mengunduh dan menyimpan berkas foto profil dari portal (bukan hanya URL), ubah menjadi Yes. |
| | Videos | **No** | No | | | | |
| Audio files | Semua | **No** | No | | | | Mikrofon diblokir (`RECORD_AUDIO` di `blockedPermissions`). |
| Files and docs | Files and docs | **Yes** | **Yes** (Google Gemini) | **Opsional** | No | App functionality | Berkas tugas/jawaban ujian (PDF/ZIP/RAR), sumber Notebook AI, materi kuliah yang diarsipkan. Sumber Notebook AI (PDF, gambar, teks) dikirim ke Gemini setelah persetujuan. |
| Calendar | Calendar events | **No** | No | | | | Jadwal kuliah berasal dari portal, bukan dari kalender perangkat. |
| Contacts | Contacts | **No** | No | | | | |
| App activity | App interactions | **Yes** | No | Wajib | No | Analytics | Breadcrumb navigasi dan trace performa Sentry. |
| | In-app search history | **TODO** | | | | | Cek apakah kata kunci pencarian perpustakaan disimpan server. Jika hanya diteruskan, jawab Yes + Ephemeral, atau No bila tidak meninggalkan perangkat. |
| | Installed apps | **No** | No | | | | |
| | Other user-generated content | **Yes** | **Yes** (Google Gemini) | **Opsional** | No | App functionality | Pertanyaan dan jawaban Notebook AI. Pertanyaan dikirim ke Gemini setelah persetujuan. |
| | Other actions | **Yes** | No | **Opsional** | No | App functionality | Kode QR absensi yang dikirim dan antrean outbox. |
| Web browsing | Web browsing history | **No** | No | | | | |
| App info and performance | Crash logs | **Yes** | No | Wajib | No | App functionality, Analytics | Sentry wilayah UE (`sendDefaultPii: false`). |
| | Diagnostics | **Yes** | No | Wajib | No | App functionality, Analytics | Trace performa Sentry, model perangkat dan OS. |
| | Other app performance data | **No** | No | | | | |
| Device or other IDs | Device or other IDs | **Yes** | No | **Opsional** | No | App functionality | Token push Expo (bisa ditolak dengan tidak memberi izin notifikasi; dihapus saat keluar atau setelah 60 hari tidak aktif) dan nama perangkat (`device_name`) yang disimpan di sesi. |

Untuk data yang **dibagikan**, Console menanyakan tujuan berbagi. Pilih **App functionality** saja. Tujuan yang **tidak** dipakai di mana pun: Advertising or marketing, Personalization, Developer communications, Fraud prevention. Tidak ada data yang dijual.

### 2.7 Government apps

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Is your app developed by or on behalf of a government? | **No** | Dibuat pengembang perorangan. Universitas swasta bukan lembaga pemerintah. |

### 2.8 Financial features

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Fitur keuangan | **My app doesn't provide any financial features** | Aplikasi hanya *menampilkan* tagihan kuliah. Tidak ada pembayaran, pinjaman, transfer, atau investasi. Evaluasi ulang jika kelak ada alur pembayaran. |

### 2.9 Health apps

| Pertanyaan | Jawaban |
|---|---|
| Fitur kesehatan | **My app doesn't provide any health features** |

### 2.10 News apps

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Is your app a news app? | **No** | Pengumuman kampus adalah fitur pendukung, bukan penerbitan berita. |

### 2.11 COVID-19 contact tracing and status apps (jika masih muncul)

Apakah butir ini masih ada di Console 2026 **[BELUM TERVERIFIKASI]**. Jika muncul, pilih: **My app is not a publicly available COVID-19 contact tracing or status app**.

### 2.12 Advertising ID

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Does your app use advertising ID? | **No** | Manifest gabungan (build debug 3 Nov) tidak berisi `com.google.android.gms.permission.AD_ID`. Cek ulang manifest build rilis; jika ada dependensi yang menambahkannya, hapus dengan `tools:node="remove"`. |

### 2.13 Photo and video permissions

Tidak perlu deklarasi. Manifest gabungan tidak berisi `READ_MEDIA_IMAGES` atau `READ_MEDIA_VIDEO`; `READ/WRITE_EXTERNAL_STORAGE` dibatasi `maxSdkVersion` 32. Pemilihan foto memakai pemilih sistem. Jika form ini muncul setelah build rilis diunggah, cari dependensi yang menambah izin itu dan blokir lewat `android.blockedPermissions`, jangan isi deklarasinya (kemungkinan besar ditolak).

### 2.14 Foreground service permissions

Tidak perlu deklarasi. Manifest tidak berisi `FOREGROUND_SERVICE*`. Notifikasi lewat FCM.

### 2.15 Sensitive permissions (Permissions declaration form)

Tidak ada izin sensitif (SMS, Call Log, All files access, lokasi latar belakang, dan sejenisnya), jadi form ini tidak muncul.

---

## 3. Store settings (Grow > Store presence > Store settings)

### 3.1 App category

| Kolom | Isi | Alasan |
|---|---|---|
| App or game | **App** | |
| Category | **Education** | Aplikasi untuk keperluan kuliah. |
| Tags (maks. 5) | **TODO:** pilih dari daftar tag Play yang paling dekat dengan: education / pendidikan, student tools, productivity, study / belajar, schedule / jadwal | Tag harus dipilih dari daftar resmi di Console; nama tag pastinya tidak bisa dipastikan di luar Console. |

### 3.2 Store listing contact details

| Kolom | Isi | Status |
|---|---|---|
| Email (wajib) | `developer@rizqis.com` | Siap |
| Phone (opsional) | Kosongkan, atau **TODO** nomor format internasional `+62...` | Opsional |
| Website (disarankan) | `https://wecic.rizqis.com/bantuan` | Siap. Halaman bantuan publik dengan FAQ, kontak, dan tautan ke kebijakan privasi serta hapus data |

### 3.3 External marketing

Dengan memublikasikan, Anda memberi Google lisensi memakai aset listing untuk promosi (DDA Bagian 6). Apakah sakelar opt-out lama masih ada **[BELUM TERVERIFIKASI]**. Biarkan bawaan.

---

## 4. Main store listing (Grow > Store presence > Main store listing)

Isi bahasa utama **Indonesian – id** dulu, lalu **Manage translations > Add your own translations > English (United States) – en-US** untuk versi Inggris.

### 4.1 App name (maks. 30 karakter)

Bahasa Indonesia, 21/30:
```text
WeCIC: Asisten Kuliah
```

English, 24/30:
```text
WeCIC: Student Companion
```

Tidak memakai "Resmi", "Official", atau nama universitas.

> **TODO: risiko nama (B1 / H1).** "WeCIC" memuat "CIC", singkatan nama kampus, dan pemilik memutuskan tidak meminta izin kampus. Dengan penafian unofficial dan tanpa logo kampus, risikonya berkurang tapi tidak hilang. Jika ada keluhan merek, Google dapat menghapus aplikasi. Satu-satunya mitigasi yang tersisa adalah nama tanpa "CIC". Keputusan ada pada pemilik.

### 4.2 Short description (maks. 80 karakter)

Tanpa emoji, simbol, atau baris baru.

Bahasa Indonesia, 72/80:
```text
Jadwal, absensi QR, tugas, nilai, dan tagihan kuliah dalam satu aplikasi
```

English, 72/80:
```text
Classes, QR attendance, assignments, grades and bills in one student app
```

### 4.3 Full description (maks. 4.000 karakter)

Penafian unofficial sengaja ditaruh di baris pertama dan **harus dipertahankan**. Teks memakai kata netral "portal akademik kampus" dan tidak menyebut nama universitas, supaya tidak terkesan berafiliasi. Jangan menyebut "resmi" atau "official".

Bahasa Indonesia, 2.145/4000:
```text
WeCIC adalah aplikasi unofficial buatan pengembang independen dan tidak berafiliasi dengan, didukung, atau dikelola oleh kampus mana pun. Kamu masuk memakai akun portal akademik kampus (my.cic.ac.id) milikmu sendiri.

WeCIC merangkum urusan kuliah sehari-hari dalam satu aplikasi yang ringan: jadwal, absensi, tugas, ujian, nilai, sampai tagihan.

ABSENSI
• Pindai QR absensi dari dosen dengan kamera.
• Atau pilih foto berisi QR dari galeri. QR dibaca langsung di HP, fotonya tidak diunggah.

KELAS, TUGAS, DAN UJIAN
• Lihat kelas, pertemuan, dan materi kuliah.
• Kumpulkan tugas (PDF, ZIP, RAR) dan pantau tenggatnya.
• Lihat ujian dan unggah berkas jawaban.

AKADEMIK
• KRS, nilai dan IPK, transkrip, SKKM, dan skripsi.

ADMINISTRASI DAN INFO KAMPUS
• Tagihan dan riwayat pembayaran. WeCIC hanya menampilkan tagihan, tidak memproses pembayaran.
• Pengumuman, biodata, dan perpustakaan.

NOTEBOOK AI
• Belajar dari materi kuliah dengan bantuan AI (Google Gemini).
• Jawaban disusun dari sumber yang ada di notebook. Tetap periksa kembali jawaban AI.

PENGINGAT
• Notifikasi tenggat tugas dan pengingat absensi.
• Pembaruan realtime saat ada data baru.

LAINNYA
• Arsip data kuliah.
• Antrean kirim (outbox): absensi dan unggahan disimpan dulu saat sinyal hilang, lalu dikirim saat online.
• Ganti kata sandi portal.
• Mode terang dan gelap.

PRIVASI DAN DATA
• Kata sandi portal dikirim lewat koneksi terenkripsi ke server WeCIC, agar server bisa masuk ke portal atas namamu.
• Sinkronisasi di background bersifat opsional dan mati secara bawaan. Jika kamu menyalakannya, kata sandi disimpan terenkripsi di server dan langsung dihapus saat fitur dimatikan atau saat kamu keluar.
• Notebook AI baru mengirim data setelah kamu setuju. WeCIC memakai Gemini API tingkat gratis, jadi pertanyaan dan materi yang dikirim dibagikan ke Google dan dapat dipakai Google untuk meningkatkan produknya. Persetujuan bisa ditarik kapan saja.
• Hapus akun dan semua data WeCIC langsung dari menu Lainnya.
• Tidak ada iklan dan tidak ada pembelian dalam aplikasi.

Kebijakan privasi: https://wecic.rizqis.com/privasi
Pertanyaan dan masukan: developer@rizqis.com
```

English, 2.142/4000:
```text
WeCIC is an unofficial app made by an independent developer and is not affiliated with, endorsed by, or operated by any university. You sign in with your own campus academic portal account (my.cic.ac.id).

WeCIC brings everyday campus tasks into one lightweight app: classes, attendance, assignments, exams, grades and bills.

ATTENDANCE
• Scan your lecturer's attendance QR code with the camera.
• Or pick a photo of the QR code from your gallery. The code is read on your phone and the photo is not uploaded.

CLASSES, ASSIGNMENTS AND EXAMS
• See your classes, meetings and course material.
• Submit assignments (PDF, ZIP, RAR) and keep track of deadlines.
• See your exams and upload answer files.

ACADEMICS
• Course plan (KRS), grades and GPA, transcript, SKKM credits and thesis.

ADMIN AND CAMPUS INFO
• Bills and payment history. WeCIC only shows bills and does not process payments.
• Announcements, personal data and library.

AI NOTEBOOK
• Study your course material with AI help (Google Gemini).
• Answers are drawn from the sources in the notebook. Always double-check AI answers.

REMINDERS
• Assignment deadline and attendance reminders.
• Realtime updates when new data arrives.

MORE
• Archive of your study data.
• Outbox: attendance and uploads are queued while you are offline and sent when you are back online.
• Change your portal password.
• Light and dark mode.

PRIVACY AND DATA
• Your portal password is sent over an encrypted connection to the WeCIC server so the server can sign in to the portal for you.
• Background sync is optional and off by default. If you turn it on, your password is stored encrypted on the server and deleted as soon as you turn it off or sign out.
• AI Notebook sends nothing until you agree. WeCIC uses the free tier of the Gemini API, so the questions and materials you send are shared with Google, which may use them to improve its products. You can withdraw consent at any time.
• Delete your account and all WeCIC data right from the Lainnya (More) menu.
• No ads and no in-app purchases.

Privacy policy: https://wecic.rizqis.com/privasi
Questions and feedback: developer@rizqis.com
```

Catatan kejujuran untuk deskripsi di atas (status 2026-11-20):
- Pemindaian QR dengan kamera: B4 selesai di app.json; pastikan di build EAS bahwa kamera terbuka.
- Pengingat: hanya benar setelah **B5 / H3** (FCM) beres. Pengingat saat aplikasi tertutup juga butuh sinkronisasi di background yang dinyalakan pengguna.
- Koneksi terenkripsi: B6 selesai (`https://wecic.rizqis.com`).
- Hapus akun di aplikasi: B7 selesai.

### 4.4 Penafian (pakai juga di aplikasi dan kebijakan privasi)

Bahasa Indonesia, 137 karakter:
```text
WeCIC adalah aplikasi unofficial buatan pengembang independen dan tidak berafiliasi dengan, didukung, atau dikelola oleh kampus mana pun.
```

English, 126 karakter:
```text
WeCIC is an unofficial app by an independent developer and is not affiliated with, endorsed by, or operated by any university.
```

Aplikasi memakai versi pendeknya: "Aplikasi unofficial, tidak berafiliasi dengan kampus mana pun." (layar masuk) dan footer Lainnya. Halaman web menampilkan peringatan yang sama di setiap halaman.

Kenapa penting: kebijakan Impersonation melarang aplikasi yang mengesankan hubungan atau izin dari pihak yang tidak memberikannya. Kebijakan Intellectual Property melarang memakai merek atau logo pihak lain tanpa izin. Penafian tidak menggantikan izin; penafian hanya mengurangi kesan resmi. Kebijakan pada dasarnya tidak menetapkan kata-kata penafian tertentu.

### 4.5 Graphics

Berkas dibuat terpisah oleh `mobile/store-listing/tools/generate-assets.mjs`. Path di bawah adalah path keluaran generator itu.

| Aset | Spesifikasi Play | Berkas | Catatan |
|---|---|---|---|
| App icon | 512 × 512 px, PNG 32-bit dengan alpha, maks. 1024 KB | `mobile/store-listing/assets/google-play/icon-512.png` | Tanpa lencana atau teks peringkat/harga/kategori. Tanpa logo atau lambang kampus. |
| Feature graphic (wajib) | 1024 × 500 px, JPEG atau PNG 24-bit, **tanpa alpha** | `mobile/store-listing/assets/google-play/feature-graphic-1024x500.png` | Pastikan PNG disimpan tanpa kanal alpha. Tanpa logo atau warna kampus. |
| Phone screenshots, id-ID | Min. 2, maks. 8; JPEG atau PNG 24-bit tanpa alpha; sisi 320 sampai 3840 px; sisi panjang maks. 2× sisi pendek. Disarankan min. 4, min. 1080 px, 9:16 | `mobile/store-listing/assets/google-play/phone/id/01.png` sampai `06.png` (1080 × 1920) | 6 layar: Beranda, Absensi, Tugas, Nilai, Keuangan, Kuliah. |
| Phone screenshots, en-US | Sama | `mobile/store-listing/assets/google-play/phone/en/01.png` sampai `06.png` | Unggah di terjemahan en-US. |
| 7" / 10" tablet screenshots | Min. 4 per jenis, 1080 sampai 7680 px, 16:9 atau 9:16 | Tidak ada | Opsional. Aplikasi portrait dan berfokus ke ponsel. |
| Chromebook | Sama dengan tablet | Tidak ada | Opsional. |
| Preview video | Satu URL YouTube publik atau unlisted, iklan mati, bisa disematkan | Tidak ada | Opsional. Jika dibuat: tunjukkan aplikasi asli dalam 10 detik pertama. |

Sebelum mengunggah tangkapan layar:
- Ambil dengan akun reviewer: datanya sudah data contoh (Mahasiswa Contoh, NIM 20990000001), jadi tidak ada data mahasiswa sungguhan. Jangan pakai data mahasiswa lain.
- Saat ini generator membuat placeholder jika `mobile/store-listing/raw/*.png` belum ada. Pastikan yang diunggah adalah tangkapan layar asli aplikasi, bukan placeholder (H4).
- Layar Absensi harus menampilkan pemindai yang benar-benar berfungsi di Android.
- Jangan tampilkan logo, lambang, atau nama kampus.

---

## 5. Countries / regions (Test and release > Production > Countries/regions)

| Kolom | Isi | Alasan |
|---|---|---|
| Negara | **Indonesia** saja | Pengguna adalah mahasiswa satu kampus di Cirebon. Juga perlu dipilih di setiap trek uji. |
| Harga | Gratis (sudah dipilih di §1) | |

---

## 6. Rilis

### 6.1 Build dan penandatanganan

| Butir | Isi |
|---|---|
| Format | **Android App Bundle (.aab)**, wajib untuk aplikasi baru. |
| Build | `mobile/eas.json` sudah ada (profil `production` dengan `EXPO_PUBLIC_API_URL=https://wecic.rizqis.com`). Jalankan `eas init` sekali (H3), lalu `eas build -p android --profile production`. |
| Play App Signing | Aplikasi baru otomatis terdaftar dengan kunci yang dibuat Google. Simpan **upload key** (EAS menyimpannya; unduh cadangan lewat `eas credentials`). Play App Signing juga otomatis mendaftarkan paket untuk verifikasi pengembang. |
| Target API | **36 (Android 16)**. Cek setelah prebuild (blocker B10). |
| versionCode | Dikelola EAS (`appVersionSource: remote`, `autoIncrement` di profil `production`); jangan tulis di app.json. |
| Cek manifest rilis | Ada `CAMERA`, tidak ada `AD_ID`, `READ_MEDIA_*`, `FOREGROUND_SERVICE*`, `RECORD_AUDIO`, atau izin dev-client (`SYSTEM_ALERT_WINDOW`, `ACCESS_LOCAL_NETWORK`). `android:allowBackup="false"`. `android.hardware.wifi` dan `android.hardware.screen.portrait` berstatus `required="false"` (plugin `mobile/plugins/with-optional-hardware-features.js`); tanpa itu Play membatasi tablet, desktop, dan XR. |

### 6.2 Uji internal (opsional, disarankan dulu)

Test and release > Testing > Internal testing. Tambahkan diri sendiri sebagai penguji, unggah .aab, cek bahwa login, kamera, notifikasi, dan HTTPS produksi berjalan. Trek internal tidak mewajibkan Data safety, tapi trek tertutup mewajibkannya.

### 6.3 Uji tertutup (wajib untuk akun pribadi baru)

| Butir | Isi |
|---|---|
| Syarat | Akun pribadi yang dibuat setelah 13 Nov 2023: **minimal 12 penguji ikut terus-menerus selama 14 hari** sebelum "Apply for production". |
| Penguji | **TODO:** daftar email Google 12+ teman kuliah (ditambah cadangan bila ada yang keluar), lewat daftar email atau Google Group. |
| Negara | Indonesia |
| Release name | `1.0.0 (1)` |

Catatan rilis (maks. 500 karakter per bahasa), id-ID 130/500, en-US 135/500:
```text
<id-ID>
Rilis pertama WeCIC: jadwal, absensi QR, tugas, ujian, nilai, tagihan, pengumuman, Notebook AI, dan pengingat dalam satu aplikasi.
</id-ID>
<en-US>
First release of WeCIC: classes, QR attendance, assignments, exams, grades, bills, announcements, AI Notebook and reminders in one app.
</en-US>
```

### 6.4 Apply for production

Setelah 14 hari, Dashboard > Apply for production. Form menanyakan:
- Cara merekrut penguji, keterlibatan mereka, dan masukan yang diterima. **TODO:** catat masukan dan perbaikan selama uji.
- Target audiens, nilai aplikasi, dan perkiraan pemasangan tahun pertama. **TODO:** angka realistis (misalnya sebagian mahasiswa aktif kampus).
- Perubahan setelah pengujian dan cara Anda menilai aplikasi siap produksi.

Lalu buat rilis produksi dengan .aab yang sama atau lebih baru, negara Indonesia, dan kirim untuk ditinjau.

### 6.5 Akun pengembang

| Butir | Isi |
|---|---|
| Jenis akun | **TODO:** pribadi atau organisasi. Pribadi: biaya registrasi USD 25, verifikasi identitas lewat profil pembayaran, **nama legal dan negara tampil publik**. Organisasi: wajib **D-U-N-S** (gratis, hingga sekitar 30 hari) dan tidak terkena syarat 12 penguji. |
| Verifikasi | OTP untuk email kontak, telepon kontak, dan email pengembang. |
| Verifikasi pengembang Android | Pastikan status paket "Registered" di halaman Android developer verification. |

---

## 7. Checklist sebelum mengirim

Blocker (status 2026-11-20)
- [ ] B1 / H1 Keputusan pemilik soal risiko nama "WeCIC" dan warna biru kampus; penafian unofficial dan tanpa logo kampus sudah ada
- [x] B2 URL kebijakan privasi, dukungan, dan penghapusan data dibangun; tautannya ada di aplikasi
- [ ] B2 Ketiga halaman bisa dibuka publik di produksi setelah deploy dan `npm run build`
- [x] B3 Mode reviewer dibangun
- [ ] B3 / H2 Kredensial reviewer terisi di `.env` produksi dan di App access, login dicoba dari build rilis
- [x] B4 `cameraPermission: false` dihapus dari app.json
- [ ] B4 Izin `CAMERA` ada di manifest build EAS; pemindai QR jalan di Android
- [ ] B5 / H3 `eas init`, `projectId`, proyek Firebase, service account FCM V1 di EAS; notifikasi uji terkirim
- [x] B6 `EXPO_PUBLIC_API_URL=https://wecic.rizqis.com` di `eas.json`
- [ ] B6 Reverb lewat `wss` dan nginx memaksa HTTPS
- [x] B7 "Hapus akun & data WeCIC" di aplikasi dan halaman `/hapus-data`
- [ ] B8 12+ penguji uji tertutup selama 14 hari
- [ ] B9 Status verifikasi pengembang terdaftar
- [ ] B10 `targetSdkVersion` 36 di build EAS
- [ ] H5 Service account Google Cloud untuk `eas submit`
- [ ] H6 Migrasi server dijalankan; build dari prebuild bersih
- [ ] H7 Klaim retensi Sentry, log, dan hosting dikonfirmasi

Aplikasi
- [x] Pengungkapan di layar masuk dengan tautan kebijakan privasi; sinkronisasi di background opt-in
- [x] Layar persetujuan Gemini sebelum pertama kali memakai Notebook AI, dengan "Tidak sekarang" dan "Tarik izin"
- [x] Penafian unofficial di layar masuk dan footer Lainnya
- [x] Izin tak terpakai diblokir (`SYSTEM_ALERT_WINDOW`, biometrik, penyimpanan eksternal, mikrofon)
- [ ] Layar baru (Privasi di Lainnya, Izin Notebook AI, Hapus akun) dicek di perangkat, mode terang dan gelap
- [ ] Aplikasi tetap berfungsi bila izin notifikasi ditolak

Console
- [ ] App content: privasi, akses aplikasi (akun reviewer), iklan (No), rating konten, target audiens (18+), Data safety, pemerintah (No), keuangan (none), kesehatan (none), berita (No), ID iklan (No)
- [ ] Store settings: kategori Education, tag, email, website `https://wecic.rizqis.com/bantuan`
- [ ] Main store listing id-ID dan en-US: nama, deskripsi singkat, deskripsi lengkap (§4)
- [ ] Ikon 512, feature graphic 1024×500 tanpa alpha, 6 tangkapan layar asli per bahasa dari akun reviewer (H4)
- [ ] Negara: Indonesia
- [ ] .aab dari EAS, Play App Signing aktif
- [ ] Data safety cocok dengan kebijakan privasi: Notebook AI dibagikan ke Google (opsional), URL hapus akun diisi
- [ ] Server produksi menyala selama review

---

## Sumber

- Store listing: https://support.google.com/googleplay/android-developer/answer/9859152
- Grafis: https://support.google.com/googleplay/android-developer/answer/9866151
- Kategori dan tag: https://support.google.com/googleplay/android-developer/answer/9859673
- App content / persiapan review: https://support.google.com/googleplay/android-developer/answer/9859455
- Sign-in requirements (ringkasan pencarian saja): https://support.google.com/googleplay/android-developer/answer/15748846
- Content rating: https://support.google.com/googleplay/android-developer/answer/9859655
- Target audience: https://support.google.com/googleplay/android-developer/answer/9867159
- Data safety: https://support.google.com/googleplay/android-developer/answer/10787469
- User Data policy: https://support.google.com/googleplay/android-developer/answer/10144311
- Account deletion: https://support.google.com/googleplay/android-developer/answer/13327111
- Financial features: https://support.google.com/googleplay/android-developer/answer/13849271
- Health apps: https://support.google.com/googleplay/android-developer/answer/14738291
- News dan government apps: https://support.google.com/googleplay/android-developer/answer/9949730 , https://support.google.com/googleplay/android-developer/answer/10523915
- Advertising ID: https://support.google.com/googleplay/android-developer/answer/6048248
- Izin foto dan video: https://support.google.com/googleplay/android-developer/answer/13986130
- Foreground service: https://support.google.com/googleplay/android-developer/answer/13392821
- Impersonation: https://support.google.com/googleplay/android-developer/answer/9888374
- Intellectual property: https://support.google.com/googleplay/android-developer/answer/9888072
- Misrepresentation: https://support.google.com/googleplay/android-developer/answer/9888689
- Device and Network Abuse: https://support.google.com/googleplay/android-developer/answer/16559646
- AI-Generated Content: https://support.google.com/googleplay/android-developer/answer/14094294
- Uji tertutup akun pribadi baru: https://support.google.com/googleplay/android-developer/answer/14151465
- Akun pengembang: https://support.google.com/googleplay/android-developer/answer/13628312
- Play App Signing: https://support.google.com/googleplay/android-developer/answer/9842756
- App bundle: https://developer.android.com/guide/app-bundle
- Target API: https://developer.android.com/google/play/requirements/target-sdk
- Verifikasi pengembang: https://developer.android.com/developer-verification
