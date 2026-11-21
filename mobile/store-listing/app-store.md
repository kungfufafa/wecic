# WeCIC: panduan isian App Store Connect

Panduan ini mengisi App Store Connect (ASC) untuk WeCIC versi 1.0.0, iPhone saja, mengikuti urutan layar di konsol. Semua teks yang tampil di toko ditulis dalam bahasa Indonesia (`id`, bahasa utama). Versi bahasa Inggris (`en-US`) disediakan sebagai lokalisasi kedua.

Dibuat 2026-11-05 dari dokumentasi resmi Apple dan audit kode repo ini. Diperbarui 2026-11-20 setelah mode reviewer, hapus akun, persetujuan Notebook AI, sinkronisasi background opt-in, dan halaman publik selesai dibangun.

Keputusan pemilik (final, 2026-11-20):

- Reviewer memakai **mode reviewer** di server produksi, bukan akun mahasiswa uji dari kampus.
- Sinkronisasi di background yang menyimpan kata sandi portal terenkripsi bersifat **opt-in** (mati secara bawaan).
- Kontak publik: `developer@rizqis.com`. Origin produksi: `https://wecic.rizqis.com`.
- Notebook AI memakai **Gemini API tingkat gratis**, jadi data yang dikirim **dibagikan ke Google** dan diungkapkan seperti itu.
- WeCIC adalah aplikasi **unofficial** tanpa izin kampus. Teks toko memakai kata netral seperti "portal akademik kampus" dan tidak menyiratkan afiliasi.

URL publik (sudah dibangun, route Laravel di luar `portal.web`):

| Halaman | URL |
|---|---|
| Kebijakan privasi | `https://wecic.rizqis.com/privasi` |
| Bantuan (Support URL) | `https://wecic.rizqis.com/bantuan` |
| Hapus akun & data | `https://wecic.rizqis.com/hapus-data` |

Cara membaca:

- Setiap field punya blok kode yang siap disalin, dengan jumlah karakter di sampingnya. Untuk Keywords dan Notes yang dihitung adalah byte (UTF-8).
- **TODO** menandai keputusan atau data yang harus diisi developer sendiri. Isi TODO tidak boleh ditebak.
- Teks yang mengandung TODO atau placeholder `[isi dari ...]` **jangan disalin apa adanya**. Ganti dulu bagian itu, lalu hitung ulang panjangnya.

---

## 0. PENGHALANG: status sebelum submit

### 0.1 Sudah selesai di kode (2026-11-20)

| # | Penghalang | Aturan | Status |
|---|---|---|---|
| 2 | Reviewer tidak bisa masuk karena semua fitur ada di balik login portal | 2.1(a) | **Selesai 2026-11-20.** Mode reviewer produksi: `PORTAL_REVIEWER_USERNAME` dan `PORTAL_REVIEWER_PASSWORD` di `.env` server. Login dengan kredensial itu dijawab dari data contoh yang sudah disanitasi dan tidak pernah menghubungi portal. Lihat §6 |
| 3 | Tidak ada persetujuan sebelum data dikirim ke Google Gemini | 5.1.2(i) | **Selesai 2026-11-20.** Layar `/ai-consent` di aplikasi (Lainnya → Privasi → Izin Notebook AI, juga muncul otomatis saat bertanya atau memakai Studio). Server menolak setiap permintaan AI tanpa persetujuan tersimpan (403 `ai_consent_required`). Persetujuan bisa ditarik |
| 4a | Belum ada kebijakan privasi, halaman support, atau halaman hapus data | 5.1.1(i) | **Selesai 2026-11-20.** Tiga halaman publik di atas. Aplikasi membukanya dari Lainnya → Privasi (Kebijakan privasi, Bantuan) dan dari layar masuk |
| 4b | Tidak ada opsi hapus akun dan data | 5.1.1(v) | **Selesai 2026-11-20.** Lainnya → Privasi → "Hapus akun & data WeCIC" memanggil `DELETE /api/v1/account` dan menghapus arsip, berkas, notebook, persetujuan AI, notifikasi, token push, antrean, dan semua sesi |
| 4d | URL API produksi belum ada | 2.1 | **Selesai 2026-11-20.** `mobile/eas.json` profil `production` dan `preview` memakai `EXPO_PUBLIC_API_URL=https://wecic.rizqis.com` |
| 4e | Teks Face ID bawaan Expo padahal biometrik tidak dipakai | 5.1.1(ii) | **Selesai 2026-11-20.** Plugin `expo-secure-store` diatur `faceIDPermission: false`, jadi `NSFaceIDUsageDescription` tidak ada lagi |
| 4g | Kata sandi disimpan di server untuk sinkronisasi, aktif secara bawaan | 5.1.1 | **Selesai 2026-11-20.** Sinkronisasi di background sekarang opt-in. Kata sandi terenkripsi hanya disimpan setelah mahasiswa menyalakannya, dan langsung dihapus saat fitur dimatikan atau saat keluar. Layar masuk punya pengungkapan dan sakelar "Nyalakan sinkronisasi di background" yang mati secara bawaan |
| 7.1 | `ITSAppUsesNonExemptEncryption` belum diset | Export compliance | **Selesai 2026-11-20.** `ios.config.usesNonExemptEncryption: false` di app.json |
| — | Privacy manifest | ITMS-91053 | **Selesai 2026-11-20.** `ios.privacyManifests` di app.json (tanpa tracking, tipe data dan alasan API terisi) |

### 0.2 Masih harus dikerjakan manusia

| # | Butir | Kenapa | Yang harus dilakukan |
|---|---|---|---|
| H1 | **Risiko Content Rights dan merek (5.2.1, 5.2.2, 4.1)** | Tidak ada izin kampus. WeCIC masuk ke my.cic.ac.id dan menampilkan datanya. Nama "WeCIC" memuat "CIC", dan warna aksen aplikasi (`#0352A1`) masih biru kampus. Apple boleh meminta bukti otorisasi kapan saja | Disclaimer unofficial sudah ada di aplikasi, halaman web, dan baris pertama deskripsi. Jangan pakai logo kampus di ikon atau screenshot. Jawab Content Rights dengan jujur (§2.4). **TODO (keputusan pemilik):** terima risiko penolakan, atau ganti nama dan warna. Risiko ini tidak bisa diselesaikan lewat kode |
| H2 | **Kredensial reviewer di produksi** | Mode reviewer mati selama env kosong | Isi `PORTAL_REVIEWER_USERNAME` (bukan angka saja) dan `PORTAL_REVIEWER_PASSWORD` (panjang, acak) di `.env` produksi, jalankan `php artisan config:cache`, pastikan `tests/Fixtures/portal` ikut ter-deploy, lalu salin keduanya ke App Review Information (§6). Jangan tulis password di repo |
| H3 | **Push di build toko** | `extra.eas.projectId` belum ada di app.json, jadi token push Expo tidak bisa dibuat | Jalankan `eas init` di `mobile/` (perlu login akun Expo). Di iOS, biarkan EAS membuat APNs key saat `eas build`, lalu pastikan entitlement `aps-environment` bernilai production |
| H4 | **Screenshot asli** (4f) | 2.3.3: screenshot harus menampilkan aplikasi yang sedang dipakai. File di `assets/app-store/` masih placeholder | Ambil 6 tangkapan sesuai `raw/README.md` (paling aman memakai akun reviewer, karena datanya sudah data contoh), lalu jalankan ulang `node mobile/store-listing/tools/generate-assets.mjs` |
| H5 | **Deploy server** | Fitur baru butuh migrasi | `php artisan migrate` (tabel `notebook_ai_consents`, kolom opt-in sinkronisasi), `npm run build`, `config:cache`. Pastikan HTTPS dan Reverb `wss` |
| H6 | **Prebuild ulang** | Folder `ios/` dan `android/` lokal sudah basi | Pakai build EAS cloud, atau `npx expo prebuild --clean` sebelum build lokal |
| H7 | **Konfirmasi klaim kebijakan privasi** | Beberapa angka belum punya sumber di repo | Retensi Sentry 90 hari (cek paket Sentry), log server 14 hari (produksi harus `LOG_STACK=daily` dengan `LOG_DAILY_DAYS=14`), nama dan negara penyedia hosting |

---

## 1. Dialog New App (Apps > + > New App)

| Field | Isi | Catatan |
|---|---|---|
| Platforms | **iOS** | Jangan centang macOS, tvOS, atau visionOS |
| Name | `WeCIC` | 5 karakter (maks 30). Lihat H1 di §0.2 soal "CIC" |
| Primary Language | **Indonesian** | Bisa diubah nanti |
| Bundle ID | `com.rizqis.wecic` | Daftarkan dulu di developer.apple.com > Identifiers, atau biarkan EAS yang membuatnya. Tidak bisa diubah setelah build diunggah |
| SKU | `wecic-ios-001` | Hanya huruf, angka, `-`, `.`, `_`. Tidak bisa diubah. Tidak tampil ke publik |
| User Access | **Full Access** | Pilih Limited Access hanya jika ada anggota tim lain di akun |

Seller name di App Store diambil dari akun developer:

- akun **Individual**: nama legal pemilik akun;
- akun **Organization**: nama badan hukum, dan butuh D-U-N-S.

**TODO:** tentukan jenis akun. Pembebasan biaya hanya berlaku untuk institusi pendidikan terakreditasi, nirlaba, atau pemerintah. WeCIC adalah aplikasi unofficial, jadi listing ini memakai akun pengembang sendiri, bukan akun kampus.

---

## 2. App Information (General > App Information)

### 2.1 Name (lokalisasi `id` dan `en-US`)

```text
WeCIC
```
5 karakter, maks 30. Sama untuk kedua bahasa.

### 2.2 Subtitle

Indonesian (`id`):
```text
Asisten kuliah mahasiswa
```
24 karakter, maks 30.

English (`en-US`):
```text
Student study companion
```
23 karakter, maks 30.

Subtitle sengaja tidak memuat "CIC" atau nama universitas (5.2.1, 2.3.7). Kata di subtitle juga ikut diindeks pencarian, jadi tidak perlu diulang di Keywords.

### 2.3 Category

| Field | Isi |
|---|---|
| Primary | **Education** |
| Secondary | **Productivity** |

### 2.4 Content Rights

Pertanyaan: *Does your app contain, show, or access third-party content?*

- Jawab **Yes**. Aplikasi menampilkan konten dari portal akademik kampus (my.cic.ac.id) milik akun mahasiswa itu sendiri.
- Lanjutannya meminta konfirmasi bahwa kamu punya semua hak yang diperlukan. Keputusan pemilik: **tidak ada izin kampus**. Kotak itu hanya boleh dicentang jika kamu yakin punya hak tersebut. Mahasiswa memberi kuasa atas datanya sendiri, tetapi kampus tidak memberi izin akses otomatis ke portal.
- **TODO (keputusan pemilik, risiko H1):** jika ASC tidak bisa dilanjutkan tanpa centang, putuskan sendiri apakah klaim itu bisa dipertanggungjawabkan. Panduan ini tidak bisa memutuskannya. 5.2.2 tetap mengizinkan Apple meminta bukti otorisasi, dan tanpa surat izin aplikasi bisa ditolak atau dihapus setelah ada keluhan.

### 2.5 Age Rating (Set Up Age Ratings)

Ini sistem rating baru (4+, 9+, 13+, 16+, 18+). Apple tidak menerbitkan teks pertanyaannya kata per kata. Butir di bawah disusun dari daftar kategori dan definisi resmi Apple.

**Langkah 1: In-App Controls**

| Pertanyaan | Jawaban |
|---|---|
| Parental Controls | No |
| Age Assurance | No |

**Langkah 1 (lanjutan): Capabilities**

| Pertanyaan | Jawaban | Alasan |
|---|---|---|
| Unrestricted Web Access | **No** | Browser dalam aplikasi hanya membuka tautan tetap (situs WeCIC, perpustakaan, tautan ujian dari portal). Pengguna tidak bisa mengetik URL sendiri. Jika nanti ada kolom URL bebas, jawabannya menjadi Yes dan rating naik ke 16+ |
| User-Generated Content | No | Notebook bersifat privat dan tidak didistribusikan ke pengguna lain |
| Social Media | No | Tidak ada feed atau fitur penemuan pengguna |
| Messaging and Chat | No | Tidak ada chat antar pengguna. Tombol mailto, tel, dan wa.me ke dosen membuka aplikasi lain |
| Advertising | No | Tidak ada SDK iklan |

**Langkah 2: Content** (jawab **None** atau **No** untuk semua)

| Kelompok | Butir | Jawaban |
|---|---|---|
| Mature Themes | Profanity or Crude Humor, Horror/Fear Themes, Alcohol/Tobacco/Drug Use or References | None |
| Medical or Wellness | Medical or Treatment Information, Health or Wellness Topics | None |
| Sexuality or Nudity | Mature or Suggestive Themes, Sexual Content or Nudity, Graphic Sexual Content and Nudity | None |
| Violence | Cartoon or Fantasy Violence, Realistic Violence, Prolonged Graphic or Sadistic Realistic Violence, Guns or Other Weapons | None |
| Chance-Based Activities | Gambling (No), Simulated Gambling (None), Contests (None), Loot Boxes (No) | No/None |

**Langkah 3: Age Categories and Override**

- Pilih **Not Applicable**. Jangan pilih Made for Kids.
- Override tidak perlu, kecuali Syarat Layanan menetapkan usia minimum.
- Age Suitability URL boleh dikosongkan.

**Hasil yang diharapkan: 4+**

### 2.6 Privacy Policy URL dan User Privacy Choices URL

Kedua field ini diisi di halaman **App Privacy**, tetapi berlaku untuk seluruh aplikasi.

Privacy Policy URL (wajib):
```text
https://wecic.rizqis.com/privasi
```
32 karakter.

- Halaman publik tanpa login, berbahasa Indonesia dengan ringkasan bahasa Inggris (`resources/views/legal/privacy.blade.php`, teks sumbernya juga di `privacy-policy.md`).
- Bisa dibuka dari aplikasi: Lainnya → Privasi → Kebijakan privasi, layar masuk, dan layar Izin Notebook AI.
- Isinya mencakup data yang dikumpulkan (§4), sinkronisasi di background yang opt-in, Google Gemini tingkat gratis (dibagikan ke Google setelah persetujuan), Expo/FCM/APNs, Sentry (wilayah UE), hosting, masa simpan, cara menghapus data, dan kontak developer@rizqis.com.

User Privacy Choices URL (opsional, disarankan):
```text
https://wecic.rizqis.com/hapus-data
```
35 karakter. Halaman ini menjelaskan hapus akun dari aplikasi, data yang dihapus dan yang tidak, jalur email bagi yang tidak bisa masuk, dan penghapusan sebagian.

### 2.7 License Agreement

Pakai **Apple Standard EULA** (bawaan). Tidak ada yang perlu diubah.

---

## 3. Pricing and Availability

| Field | Isi | Catatan |
|---|---|---|
| Price | **Free** (USD 0) | Tidak perlu Paid Apps Agreement atau data bank |
| Availability | **Indonesia saja** (disarankan) | Seluruh pengguna adalah mahasiswa di Cirebon. Mengecualikan storefront Uni Eropa juga mengurangi kewajiban DSA (§7). Pendaftaran deklarasi DSA tetap wajib |
| Pre-Orders | Off | |
| iPhone and iPad Apps on Apple Silicon Mac | **Matikan** | Alur kamera dan QR tidak cocok untuk Mac |
| Apple Vision Pro | **Matikan** | |
| Tax Category | Biarkan default | Aplikasi gratis |

---

## 4. App Privacy (label nutrisi privasi)

Halaman: App Privacy > Get Started.

**Q: Do you or your third-party partners collect data from this app?** Jawab **Yes, we collect data from this app**.

Untuk semua tipe data di bawah, **Used for tracking = No**. WeCIC tidak memakai IDFA, tidak punya SDK iklan atau analitik, dan privacy manifest di app.json menyatakan `NSPrivacyTracking=false`. Data yang Google pakai untuk meningkatkan produknya (Gemini tingkat gratis) tidak digabung dengan data aplikasi lain untuk iklan, jadi bukan tracking menurut definisi Apple.

### Centang tipe data berikut

| Kategori | Tipe data | Purpose | Linked to user | Bukti di kode |
|---|---|---|---|---|
| Contact Info | **Name** | App Functionality | Yes | Biodata dan profil diarsipkan di server |
| Contact Info | **Email Address** | App Functionality | Yes | Biodata |
| Contact Info | **Phone Number** | App Functionality | Yes | Biodata (telepon/HP) |
| Contact Info | **Physical Address** | App Functionality | Yes | Biodata (alamat, kota, provinsi, kode pos) |
| Sensitive Info | **Sensitive Info** | App Functionality | Yes | Biodata memuat **agama** dan ikut diarsipkan |
| Financial Info | **Other Financial Info** | App Functionality | Yes | Tagihan dan riwayat pembayaran diarsipkan. Bukan Payment Info, karena tidak ada pembayaran |
| User Content | **Other User Content** | App Functionality, **Other Purposes** | Yes | Berkas tugas dan jawaban ujian, sumber notebook, pertanyaan ke Notebook AI. Isi Notebook AI dikirim ke Google Gemini tingkat gratis **hanya setelah persetujuan di aplikasi**, dan Google dapat memakainya untuk meningkatkan produknya. "Other Purposes" mewakili pemakaian oleh mitra pihak ketiga itu |
| Identifiers | **User ID** | App Functionality | Yes | NIM atau username portal |
| Identifiers | **Device ID** | App Functionality | Yes | Token push Expo/APNs disimpan per perangkat (`portal_push_tokens`), dihapus saat keluar atau setelah 60 hari tidak aktif |
| Diagnostics | **Crash Data** | App Functionality | **No** | Sentry wilayah UE, dengan `sendDefaultPii: false` dan tanpa `setUser` |
| Diagnostics | **Performance Data** | App Functionality | **No** | Sentry tracing 0.2 |
| Other Data | **Other Data Types** | App Functionality | Yes | Rekam akademik (nilai, KRS, transkrip, SKKM, kehadiran, jadwal), nama perangkat saat login (`device_name`), dan kata sandi portal. Kata sandi selalu dikirim ke server untuk login dan hash bcrypt-nya dipakai untuk login offline. Kata sandi terenkripsi hanya disimpan jika mahasiswa menyalakan sinkronisasi di background (opt-in) |

### Jangan centang

- Health and Fitness, Payment Info, Credit Info, Precise/Coarse Location, Contacts
- Emails or Text Messages, Audio, Gameplay Content, Customer Support
- Browsing History, Search History, Purchase History
- Advertising Data, Surroundings, Body
- **Photos or Videos:** foto QR didekode di perangkat dan tidak diunggah. Kamera hanya membaca QR.

### Opsional

- **Product Interaction** (Usage Data, Not linked, App Functionality). Centang jika kamu menganggap breadcrumb navigasi di Sentry sebagai data interaksi. Pilihan yang konservatif adalah mencentangnya.

### Selaraskan dengan privacy manifest

**Selesai 2026-11-20.** `ios.privacyManifests` di app.json mendeklarasikan tipe yang sama dengan label di atas: Name, EmailAddress, PhoneNumber, PhysicalAddress, SensitiveInfo, OtherFinancialInfo, UserID, DeviceID, OtherUserContent, dan OtherDataTypes (semuanya linked, purpose App Functionality), plus CrashData dan PerformanceData (not linked). OtherUserContent juga memakai purpose `NSPrivacyCollectedDataTypePurposeOther` untuk Gemini tingkat gratis. Semua tanpa tracking. Bila label berubah, ubah manifest di build yang sama.

**Catatan:** label ini adalah inferensi dari audit kode, diperbarui 2026-11-20 untuk sinkronisasi opt-in, persetujuan Gemini, dan hapus akun. Jika retensi atau jenis data di server berubah, perbarui labelnya.

---

## 5. Halaman versi iOS (iOS App > 1.0 Prepare for Submission)

Isi setiap field untuk **Indonesian** dulu, lalu pilih **English (U.S.)** di menu bahasa (kanan atas) untuk lokalisasi kedua.

### 5.1 Screenshots (iPhone)

Spesifikasi Apple:

- 1 sampai 10 screenshot per ukuran per bahasa.
- Format PNG atau JPEG, **tanpa alpha**.
- Harus layak untuk audiens 4+ (2.3.8).

| Slot di ASC | Ukuran | File `id` | File `en-US` | Status |
|---|---|---|---|---|
| iPhone 6.9" | 1320 × 2868 | `mobile/store-listing/assets/app-store/iphone-6.9/id/01.png` … `06.png` | `…/iphone-6.9/en/01.png` … `06.png` | Ukuran OK, tanpa alpha, **masih placeholder** |
| iPhone 6.5" | 1284 × 2778 | `mobile/store-listing/assets/app-store/iphone-6.5/id/01.png` … `06.png` | `…/iphone-6.5/en/01.png` … `06.png` | Sama seperti di atas |
| iPhone 6.3" (opsional, untuk jaga-jaga) | 1206 × 2622 | belum ada | belum ada | **TODO**, lihat catatan di bawah |
| iPad | — | tidak perlu | — | Aplikasi iPhone saja (`supportsTablet: false`) |

Catatan:

- Halaman spesifikasi Apple tidak konsisten soal ukuran mana yang wajib, 6.5" atau 6.3"/6.1". Dua set di atas biasanya sudah cukup. Kalau ASC meminta slot 6.3", tambahkan ukuran 1206 × 2622 ke generator (`tools/generate-assets.mjs`).
- Path di brief awal (`assets/screenshots/iphone-6.9/`) berbeda dari lokasi file sebenarnya. File yang benar ada di `assets/app-store/iphone-6.9/{id,en}/`.
- Ikon 1024 × 1024 tanpa alpha ada di `mobile/store-listing/assets/app-store/icon-1024.png` dan diambil otomatis dari binary.

Urutan dan caption screenshot (dari `generate-assets.mjs`):

| # | Layar | Caption `id` | Caption `en-US` |
|---|---|---|---|
| 01 | Beranda | Jadwal dan absensi hari ini, sekali lihat | Today's classes and attendance at a glance |
| 02 | Absensi | Absen kelas cukup pindai QR | Check in to class with a QR scan |
| 03 | Tugas | Tenggat tugas tak lagi terlewat | Never miss an assignment deadline |
| 04 | Nilai | IPK, IPS, dan KHS dalam genggaman | GPA and semester grades in your pocket |
| 05 | Keuangan | Tagihan dan riwayat pembayaran | Bills and payment history |
| 06 | Kuliah | Semua kelas dan jadwal kuliah | All your classes and schedule |

Sebelum mengambil tangkapan:

- Pakai akun reviewer (§6). Datanya sudah data contoh (Mahasiswa Contoh, NIM 20990000001), jadi tidak ada data mahasiswa sungguhan yang perlu disensor.
- Jangan tampilkan logo kampus. WeCIC unofficial dan tidak punya izin kampus.

App Preview (video) bersifat opsional dan dilewati untuk versi 1.0.

### 5.2 Promotional Text

Teks ini bisa diubah kapan saja tanpa review ulang.

Indonesian:
```text
Absen pakai QR, pantau tenggat tugas, cek nilai dan tagihan kuliah dalam satu aplikasi. Pengingat otomatis biar tugas dan absensi tidak terlewat.
```
145 karakter, maks 170.

English:
```text
Scan the QR to check in, track assignment deadlines, and see grades and bills in one app. Automatic reminders so no task or check-in slips by.
```
142 karakter, maks 170.

### 5.3 Description

Teks polos tanpa HTML. Baris pertama adalah disclaimer unofficial dan **harus dipertahankan**. Kata yang dipakai netral ("portal akademik kampus") dan tidak menyebut nama universitas, supaya tidak terkesan berafiliasi.

Indonesian:
```text
WeCIC adalah aplikasi unofficial buatan pengembang independen. WeCIC tidak berafiliasi dengan, tidak didukung, dan tidak dikelola oleh kampus mana pun.

WeCIC merangkum kebutuhan kuliah harianmu dalam satu aplikasi yang ringan. Masuk dengan akun portal akademik kampus (my.cic.ac.id) yang sudah kamu punya, lalu jadwal, tugas, nilai, dan tagihan langsung tampil rapi di HP.

PERKULIAHAN
• Absensi: pindai QR absensi dari dosen dengan kamera, pilih foto berisi QR, atau kirim kode absensi kelas hari ini.
• Kelas: jadwal, materi, dan rekap kehadiran per mata kuliah.
• Tugas: daftar tugas yang belum dikumpulkan beserta tenggatnya, lengkap dengan unggah berkas tugas.
• Ujian: daftar ujian dan unggah jawaban.

AKADEMIK
• KRS: rencana studi dan perwalian.
• Nilai: IPK, IPS, dan KHS per semester.
• Transkrip: semua nilai mata kuliah.
• SKKM: poin kegiatan kemahasiswaan.
• Skripsi: status dan kesiapan skripsi.

ADMINISTRASI
• Keuangan: tagihan dan riwayat pembayaran (hanya ditampilkan, tidak ada pembayaran di aplikasi).
• Pengumuman: info dari kampus.
• Biodata: data pribadi dan kontak.

LAINNYA
• Notebook AI: belajar dari materi kuliahmu. Ajukan pertanyaan dan dapatkan jawaban yang diambil dari sumber di notebook tersebut. Fitur ini memakai Google Gemini dan baru mengirim data setelah kamu menyetujuinya di aplikasi.
• Perpustakaan: tautan perpustakaan dan repositori skripsi.
• Arsip: riwayat data portal yang tersimpan.
• Antrean: absensi dan unggahan yang dibuat saat portal sedang tidak bisa dijangkau akan dikirim otomatis begitu tersambung kembali.
• Ganti kata sandi portal langsung dari aplikasi.
• Notifikasi pengingat tenggat tugas dan absensi.
• Mode terang dan gelap.

TENTANG DATA DAN PRIVASI
• Untuk menampilkan datamu, WeCIC mengirim NIM dan kata sandi portal ke server WeCIC melalui koneksi terenkripsi (HTTPS), lalu server masuk ke portal atas namamu.
• Sinkronisasi di background bersifat opsional dan mati secara bawaan. Jika kamu menyalakannya, kata sandi disimpan terenkripsi di server dan langsung dihapus saat fitur dimatikan atau saat kamu keluar.
• Notebook AI memakai Gemini API tingkat gratis, jadi pertanyaan dan materi yang kamu kirim dibagikan ke Google dan dapat dipakai Google untuk meningkatkan produknya. Persetujuan bisa ditarik kapan saja.
• Akun dan semua data WeCIC bisa kamu hapus sendiri di menu Lainnya.
• Tidak ada iklan dan WeCIC tidak menjual data.

Kebijakan privasi: https://wecic.rizqis.com/privasi
Ada saran atau kendala? Hubungi developer@rizqis.com.
```
2.508 karakter, maks 4.000.

English:
```text
WeCIC is an unofficial app made by an independent developer. It is not affiliated with, endorsed by, or operated by any university.

WeCIC brings your everyday college tasks into one lightweight app. Sign in with the campus academic portal account (my.cic.ac.id) you already have, and your schedule, assignments, grades and bills appear neatly on your phone.

CLASSES
• Attendance: scan your lecturer's attendance QR with the camera, pick a photo that contains the QR, or send today's class attendance code.
• Classes: schedule, materials and attendance record per course.
• Assignments: pending assignments with their deadlines, plus file upload.
• Exams: exam list and answer upload.

ACADEMICS
• KRS: study plan and academic advising.
• Grades: GPA and per-semester results.
• Transcript: every course grade.
• SKKM: student activity points.
• Thesis: thesis status and readiness.

ADMINISTRATION
• Finance: bills and payment history (view only, no payments in the app).
• Announcements: news from campus.
• Profile: personal and contact details.

MORE
• AI Notebook: study from your course materials. Ask questions and get answers drawn from the sources in that notebook. This feature uses Google Gemini and sends nothing until you agree in the app.
• Library: library links and thesis repository.
• Archive: saved history of your portal data.
• Outbox: attendance and uploads made while the portal is unreachable are sent automatically once it is back.
• Change your portal password from the app.
• Reminders for assignment deadlines and attendance.
• Light and dark mode.

ABOUT YOUR DATA
• To show your data, WeCIC sends your student ID and portal password to the WeCIC server over an encrypted (HTTPS) connection, and the server signs in to the portal on your behalf.
• Background sync is optional and off by default. If you turn it on, your password is stored encrypted on the server and deleted as soon as you turn it off or sign out.
• AI Notebook uses the free tier of the Gemini API, so the questions and materials you send are shared with Google, which may use them to improve its products. You can withdraw consent at any time.
• You can delete your account and all WeCIC data yourself under Lainnya (More).
• No ads, and WeCIC does not sell data.

Privacy policy: https://wecic.rizqis.com/privasi
Suggestions or problems? Contact developer@rizqis.com.
```
2.367 karakter, maks 4.000.

Disclaimer terpisah, jika diperlukan di tempat lain (layar Tentang, kebijakan privasi, atau baris pertama Promotional Text):
```text
WeCIC adalah aplikasi unofficial buatan pengembang independen dan tidak berafiliasi dengan, didukung, atau dikelola oleh kampus mana pun.
```
137 karakter.
```text
WeCIC is an unofficial app by an independent developer and is not affiliated with, endorsed by, or operated by any university.
```
126 karakter.

### 5.4 Keywords

Aturan pengisian:

- Dipisah koma tanpa spasi.
- Setiap kata lebih dari 2 karakter.
- Tidak mengulang kata dari nama aplikasi ("WeCIC") atau subtitle.
- Tidak memuat nama universitas, nama aplikasi lain, atau merek dagang (2.3.7).

Indonesian:
```text
absensi,absen,krs,khs,ipk,ips,nilai,transkrip,tugas,ujian,jadwal,dosen,kampus,skkm,skripsi,tagihan
```
98 byte, maks 100. Tidak ada kata duplikat. "kuliah", "mahasiswa", dan "asisten" sudah tercakup oleh subtitle.

English:
```text
attendance,college,university,gpa,grades,transcript,assignment,exam,schedule,campus,thesis,reminder
```
99 byte, maks 100. "student", "study", dan "companion" sudah tercakup oleh subtitle.

### 5.5 Support URL (wajib)

```text
https://wecic.rizqis.com/bantuan
```
32 karakter. Halaman publik (`resources/views/legal/support.blade.php`, teks sumber di `support.md`) dengan kontak developer@rizqis.com, FAQ, ringkasan bahasa Inggris, dan tautan ke kebijakan privasi serta halaman hapus data. Sesuai keputusan pemilik, kontak hanya email, tanpa alamat surat.

### 5.6 Marketing URL (opsional)

```text
https://wecic.rizqis.com
```
24 karakter. Opsional; ini versi web WeCIC (halaman masuk), yang juga menautkan ketiga halaman publik.

### 5.7 Version

```text
1.0.0
```
Harus sama dengan `expo.version` di app.json.

### 5.8 Copyright

```text
2026 Apriansyah R.
```
18 karakter. Apple menambahkan simbol © sendiri. Jika akunnya Organization, ganti dengan nama badan hukum.

### 5.9 Routing App Coverage File, Game Center, In-App Purchases

Tidak dipakai, jadi biarkan kosong. What's New tidak tersedia untuk versi pertama.

### 5.10 Build

- Jalankan `eas init` dulu (H3), lalu `eas build -p ios --profile production` dan `eas submit -p ios`.
- `eas.json` memakai `appVersionSource: remote` dan `autoIncrement`, jadi build number dikelola EAS.
- Pilih build di bagian **Build**.
- app.json sudah memuat `usesNonExemptEncryption: false`, jadi ASC tidak menanyakan export compliance lagi.

---

## 6. App Review Information

Akun yang dipakai adalah **mode reviewer** di server produksi (keputusan pemilik). Username dan password diambil dari `.env` produksi; jangan pernah menulisnya di repo, chat, atau dokumen ini.

| Field | Isi |
|---|---|
| Sign-in required | **Centang** |
| User name | `[isi dari PORTAL_REVIEWER_USERNAME di server]` (bukan angka saja; username berbentuk NIM membuat mode reviewer mati) |
| Password | `[isi dari PORTAL_REVIEWER_PASSWORD di server]` |
| First name / Last name | `Apriansyah` / `TODO: nama belakang lengkap sesuai akun` |
| Phone number | `TODO: +62…`. Wajib format internasional dengan "+" dan kode negara; nomor tanpa format ini ditolak |
| Email | `developer@rizqis.com` |
| Attachment | Opsional: video singkat alur masuk dengan akun reviewer, layar Izin Notebook AI, dan Hapus akun. Tidak ada surat izin kampus (aplikasi unofficial) |

Yang perlu diketahui tentang mode reviewer sebelum submit:

- Login dengan username reviewer dan password yang benar membuka sesi `store-reviewer` yang dijawab dari fixture di `tests/Fixtures/portal` (Mahasiswa Contoh, NIM 20990000001). Portal kampus tidak pernah dihubungi.
- Password yang salah ditolak (401) tanpa menghubungi portal. Akun mahasiswa lain berjalan seperti biasa.
- Aksi tulis (absensi, unggah tugas atau ujian, ganti kata sandi) dan unduhan berkas tidak punya fixture, jadi aplikasi menampilkan "portal tidak dapat diakses". Ini dijelaskan di Notes.
- Sinkronisasi di background tidak bisa dinyalakan untuk akun ini (409, `available: false`), dan tidak ada password atau hash yang disimpan.
- Semua reviewer berbagi satu pemilik data. Persetujuan Notebook AI, notebook, dan hapus akun dari satu reviewer berlaku untuk reviewer berikutnya. Setelah hapus akun, login lagi tetap berjalan dengan data contoh.
- Notebook AI untuk reviewer tetap memanggil Gemini setelah persetujuan, dengan isi fixture saja.

Cek sebelum submit: masuk sekali dengan akun reviewer dari build produksi, buka Beranda, Akademik, Keuangan, dan Notebook AI.

### Notes (bahasa Inggris, maks 4.000 byte)

3.972 byte (dihitung 2026-11-20). Tidak ada TODO di dalamnya. Jika diubah, hitung ulang; sisa ruang sekitar 28 byte.

```text
ABOUT THE APP
WeCIC is an unofficial companion app for university students who use the campus academic portal https://my.cic.ac.id (Indonesia). It is built by an independent developer, is not published by the university, and is not affiliated with or endorsed by it. The app UI is in Indonesian.

HOW SIGN-IN WORKS
There is no sign-up. Students sign in with the student ID (NIM) and password of their existing portal account. The app sends them over HTTPS to our server (https://wecic.rizqis.com), which signs in to the portal on the student's behalf and returns only that student's own data. No social login, so 4.8 does not apply.

REVIEWER ACCOUNT
Use the credentials in Sign-In Information. This is a dedicated reviewer account on our production server. It never contacts the university portal: every screen is served from sanitized sample data (all names and contacts are placeholders). It does not expire; no 2FA or OTP. The server stays online during review.
Limits: actions that would change data on the real portal (attendance check-in, assignment or exam upload, portal password change, file downloads) are not performed, so the app reports that the portal cannot be reached ("portal tidak dapat diakses"). Background sync cannot be turned on for this account. All reviewers share the same sample data.

WHAT TO TRY
1. Sign in. Beranda (Home) shows today's classes.
2. Akademik: KRS, Nilai (grades), Transkrip, SKKM, Skripsi.
3. Lainnya (More): Keuangan (bills, view only, no payments), Pengumuman, Biodata, Notebook AI, Arsip, Antrean (outbox) and the Privasi section.
4. Absensi (Attendance): the camera scans the QR code a lecturer shows in class, or the student picks a photo or types the code. Any QR code shows the scanner working; with the reviewer account the check-in is not sent.

PRIVACY CONTROLS (Lainnya > Privasi)
- Background sync is off by default. Only if the student turns it on (at sign-in or here) does the server store the portal password, encrypted. Turning it off or signing out deletes it.
- Izin Notebook AI: the Google Gemini consent screen (below).
- Kebijakan privasi and Bantuan open https://wecic.rizqis.com/privasi and https://wecic.rizqis.com/bantuan.
- Hapus akun & data WeCIC: account deletion (below).

AI NOTEBOOK (Guideline 5.1.2(i))
Before any AI request the app shows a consent screen (Lainnya > Privasi > Izin Notebook AI; it also opens when the student first asks a question or uses Studio). It names Google Gemini, lists what is sent (questions, source text, PDF and image files), states that we use the free tier of the Gemini API so the data is shared with Google and may be used to improve Google's products, and links to the privacy policy. Buttons: "Setuju" (agree) and "Tidak sekarang" (not now). The server refuses every AI request without stored consent, so nothing is sent before the student agrees. Consent can be withdrawn on the same screen ("Tarik izin"). Declining does not affect any other feature. If an earlier review session already agreed, tap "Tarik izin" to see the screen again.

ACCOUNT DELETION (Guideline 5.1.1(v))
Lainnya > Privasi > "Hapus akun & data WeCIC". The student turns on an acknowledgement switch and confirms a final alert. The server then deletes the archive, files, notebooks, AI consent, notifications, push tokens, outbox and all sessions, and the app signs out. You may test it with the reviewer account: it ends only your own reviewer session (other reviewers stay signed in) and resets the shared sample notebooks and consent; signing in again afterwards works. Public page: https://wecic.rizqis.com/hapus-data

PERMISSIONS
- Camera: only to scan the attendance QR. No photo or video is stored or uploaded.
- Photos: the system picker opens one image the user chooses; the QR is decoded on the device and the image is not uploaded.
- Notifications: optional reminders. The app works fully without them.

No ads, no tracking, no in-app purchases. Contact: developer@rizqis.com
```

---

## 7. Export compliance, IDFA, dan EU Digital Services Act

### 7.1 Export compliance

- WeCIC hanya memakai HTTPS/TLS dari API sistem dan enkripsi Keychain bawaan iOS. Ini praktik yang lazim dianggap **exempt**.
- **Selesai 2026-11-20:** app.json memuat `ios.config.usesNonExemptEncryption: false`, yang ditulis sebagai `ITSAppUsesNonExemptEncryption = NO`. ASC tidak akan bertanya lagi di setiap build.
- Verifikasi di https://developer.apple.com/documentation/security/complying-with-encryption-export-regulations.

### 7.2 Advertising Identifier (IDFA)

Jawab **No**. Jangan tambahkan `NSUserTrackingUsageDescription`.

### 7.3 EU Digital Services Act: trader status

**Wajib dideklarasikan oleh semua developer**, termasuk yang tidak mendistribusikan aplikasinya di UE. ASC akan menanyakannya saat submit aplikasi baru pertama kali (Business > Compliance).

- **Trader:** alamat, telepon, dan email diverifikasi lalu **ditampilkan publik** di halaman produk UE.
- **Non-trader:** tidak ada kontak yang ditampilkan, dan pengguna UE diberi tahu bahwa hak perlindungan konsumen tidak berlaku.
- **TODO (keputusan developer, Apple tidak memberi nasihat hukum):** aplikasi gratis, nonkomersial, tanpa iklan, dan tanpa IAP umumnya cocok dengan **Non-trader**. Jika availability hanya Indonesia (§3), aplikasi tidak tampil di UE sama sekali.

---

## 8. Version Release

| Opsi | Rekomendasi |
|---|---|
| **Manually release this version** | **Pilih ini.** Setelah disetujui, status menjadi *Pending Developer Release*. Kamu bisa memastikan server produksi, halaman privasi, dan akun reviewer siap sebelum aplikasi dirilis |
| Automatically release this version | — |
| Automatically release after a date | — |
| Phased Release for Automatic Updates | Hanya relevan untuk update; tidak berlaku di versi pertama |

---

## 9. Status app.json dan eas.json untuk build App Store

Diperiksa 2026-11-20. Folder `ios/` dan `android/` dibuat ulang oleh prebuild, jadi jangan diedit.

| Kunci | Status |
|---|---|
| `expo.version` = `1.0.0` | Sudah ada |
| `ios.config.usesNonExemptEncryption: false` | **Selesai 2026-11-20** |
| `ios.privacyManifests` (tanpa tracking, tipe data, alasan API) | **Selesai 2026-11-20**. Tipe datanya sama dengan label App Privacy (§4), termasuk Other Purposes untuk Other User Content |
| Plugin `expo-secure-store` dengan `faceIDPermission: false` | **Selesai 2026-11-20**. `NSFaceIDUsageDescription` tidak ada lagi |
| Plugin `expo-image-picker` tanpa `cameraPermission: false` | **Selesai 2026-11-20**. Izin kamera tidak lagi diblokir |
| `android.allowBackup: false` dan `android.blockedPermissions` | **Selesai 2026-11-20** |
| `buildNumber` / `versionCode` | Tidak ditulis di app.json. `eas.json` memakai `appVersionSource: remote` dan `autoIncrement` di profil `production` |
| `EXPO_PUBLIC_API_URL` | **Selesai 2026-11-20**. `https://wecic.rizqis.com` di profil `production` dan `preview` |
| `extra.eas.projectId` | **Belum.** Jalankan `eas init` (H3) |
| `NSLocalNetworkUsageDescription` | Berasal dari `expo-dev-client`. Pastikan tidak muncul di Info.plist build produksi |

---

## 10. Checklist sebelum menekan "Add for Review"

**Kebijakan dan izin**
- [x] Disclaimer unofficial di aplikasi (layar masuk, footer Lainnya), halaman web, dan baris pertama deskripsi; teks toko tidak menyebut nama universitas
- [ ] Keputusan pemilik soal risiko nama "WeCIC" dan warna biru kampus (H1)
- [ ] Jawaban Content Rights jujur (§2.4)

**Akun dan server**
- [x] Mode reviewer dibangun (2026-11-20)
- [ ] `PORTAL_REVIEWER_USERNAME` dan `PORTAL_REVIEWER_PASSWORD` terisi di produksi, `config:cache`, fixture ter-deploy, dan login reviewer dicoba dari build produksi (H2)
- [ ] Server produksi HTTPS menyala, migrasi dijalankan, dan Reverb memakai `wss` (H5)

**Fitur di aplikasi**
- [x] Layar persetujuan Gemini di Notebook AI, dengan opsi menolak dan menarik izin (3)
- [x] Opsi "Hapus akun & data WeCIC" ada di aplikasi dan menghapus data server (4b)
- [x] Tautan kebijakan privasi dan bantuan bisa dibuka dari dalam aplikasi (4a)
- [x] Sinkronisasi di background opt-in, mati secara bawaan (4g)
- [ ] Push berfungsi di build produksi: `eas init` sudah dijalankan dan entitlement `aps-environment` bernilai production (H3)
- [ ] Notifikasi bersifat opsional, dan aplikasi tetap bisa dipakai jika izinnya ditolak (5.1.2)
- [ ] Layar baru (Izin Notebook AI, Hapus akun, Privasi di Lainnya) dicek di perangkat atau simulator, mode terang dan gelap

**Konfigurasi build**
- [x] Perubahan app.json di §9 sudah diterapkan
- [ ] Build EAS cloud atau `npx expo prebuild --clean`, izin kamera dicek di build (H6)
- [ ] Build diunggah dan dipilih di halaman versi

**Halaman web**
- [x] `https://wecic.rizqis.com/privasi`, `/bantuan`, dan `/hapus-data` dibangun
- [ ] Ketiganya bisa dibuka publik di produksi setelah deploy dan `npm run build`
- [ ] Klaim retensi Sentry, log 14 hari, dan hosting dikonfirmasi (H7)

**Listing di ASC**
- [ ] Screenshot placeholder sudah diganti tangkapan asli dari akun reviewer, untuk `id` dan `en` (H4)
- [ ] Name, subtitle, promo, deskripsi, keywords, dan copyright sudah diisi untuk `id` dan `en-US`
- [ ] Age rating sudah disimpan dengan hasil 4+
- [ ] App Privacy sudah dipublikasikan (§4) dan cocok dengan privacy manifest
- [ ] Pricing: Free; availability: Indonesia; Mac dan Vision Pro dimatikan

**Review dan rilis**
- [ ] Contact App Review lengkap dengan nomor +62, username dan password reviewer dari server, Notes disalin dari §6
- [ ] Deklarasi DSA sudah diisi
- [ ] Version Release: Manual

## Sumber

- https://developer.apple.com/help/app-store-connect/reference/app-information/app-information
- https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information
- https://developer.apple.com/help/app-store-connect/reference/app-information/screenshot-specifications
- https://developer.apple.com/help/app-store-connect/manage-app-information/set-an-app-age-rating
- https://developer.apple.com/help/app-store-connect/reference/app-information/age-ratings-values-and-definitions
- https://developer.apple.com/app-store/app-privacy-details/
- https://developer.apple.com/help/app-store-connect/manage-app-information/overview-of-export-compliance
- https://developer.apple.com/help/app-store-connect/manage-compliance-information/manage-european-union-digital-services-act-trader-requirements
- https://developer.apple.com/programs/enroll/
- https://developer.apple.com/app-store/review/guidelines/
- https://developer.apple.com/support/offering-account-deletion-in-your-app/
