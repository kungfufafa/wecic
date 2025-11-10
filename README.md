# MyCIC Campus Proxy & API

Portal modern berbasis Laravel 12 yang bertugas menjadi _wrapper_ untuk aplikasi kampus CIC yang lama. Aplikasi ini menjaga sesi login mahasiswa tetap hidup, men-scrape halaman HTML kampus, dan mengeksposnya kembali sebagai REST API serta dashboard web yang lebih ramah pengguna.

## Gambaran Singkat
MyCIC memadukan tiga lapisan utama:
- **Autentikasi & Session** – `App\Services\PortalAuthService` menganalisis form login kampus dan mengeksekusi proses login, sedangkan `App\Services\PortalSessionManager` menyimpan cookie terenkripsi di cache database, memperbarui sesi via job `RefreshPortalSessionJob`, dan otomatis relogin jika sesi drop.
- **Scraping & Transformasi** – `App\Services\PortalScrapingService` menjadi fondasi untuk extractor khusus (`ProfileExtractor`, `ClassesExtractor`, `DashboardExtractor`, `SipandaiClassesExtractor`, `StudentCourseExtractor`) yang mengubah HTML menjadi struktur data yang bersih.
- **API & UI** – `App\Http\Controllers\API` mengekspos REST API berformat standar (`status`, `message`, `data`, `meta`), sementara route web + komponen Blade + Tailwind v4 menghadirkan dashboard modern yang memakai `ToastService` dan utilitas JS di `resources/js/app.js`.

## Fitur Utama
- Login terpusat yang menjaga sesi kampus tetap aktif tanpa blank page.
- API kampus modern (`/api/campus/*`) lengkap dengan validasi `FormRequest` dan response envelope konsisten.
- Ekstraksi data dashboard, profil, jadwal, kelas, SIPANDAI, serta detail meeting lengkap dengan fallback selector.
- Sistem cache berlapis (profile, data dinamis, HTML) berbasis database cache store untuk menekan beban scraping.
- Middleware `portal.session` dan job `RefreshPortalSessionJob` demi pengalaman pengguna yang tidak perlu login ulang.
- UI responsif berbasis Tailwind CSS v4 + Vite dengan komponen toast dan indikator status sesi real time.
- Paket pendukung seperti Guzzle 7, Symfony DomCrawler 7, Dedoc Scramble (auto API docs), Laravel Pail (live log), dan Laravel Boost toolings.

## Arsitektur Singkat
| Lapisan | Lokasi | Fungsi |
| --- | --- | --- |
| Autentikasi & Session | `app/Services/PortalAuthService.php`, `app/Services/PortalSessionManager.php`, `app/Jobs/RefreshPortalSessionJob.php` | Login ke portal kampus, menyimpan cookie terenkripsi di cache database, memvalidasi kesehatan sesi, dan menjadwalkan auto refresh. |
| Scraping Base & Extractor | `app/Services/PortalScrapingService.php`, `app/Services/Extractors/*` | Menyediakan HTTP client dengan retry, validasi HTML, caching dan parser DomCrawler untuk setiap jenis data. |
| API Controller & Validasi | `app/Http/Controllers/API`, `app/Http/Requests/ClassDetailRequest.php` | Menyajikan endpoint dashboard, profil, kelas, detail kelas/meeting, SIPANDAI, serta auth API dengan validasi parameter ketat. |
| Middleware & Routing | `bootstrap/app.php`, `app/Http/Middleware/PortalSessionMiddleware.php`, `app/Http/Middleware/VerifyCsrfTokenMiddleware.php`, `routes/web.php`, `routes/api.php` | Alias middleware `portal.session`, pengecualian CSRF untuk API, pemisahan web & API routes, dan debug endpoint. |
| UI & Assets | `resources/views`, `resources/js`, `resources/css`, `vite.config.js` | Template Blade untuk login/dashboard/kelas, JS util untuk toast & session refresh, Tailwind v4 @import, serta bundling Vite. |
| Observability & Tools | `storage/logs`, `composer.json` scripts, `config/logging.php`, `config/portal.php`, `config/scramble.php` | Logging terstruktur, konfigurasi kampus, cache TTL, request delay, serta dokumentasi API otomatis Scramble. |

## Teknologi & Dependensi
| Teknologi | Versi | Catatan |
| --- | --- | --- |
| PHP | 8.4.x | Pastikan extension curl, mbstring, openssl, sqlite, pdo diaktifkan. |
| Laravel Framework | 12.x | Struktur baru tanpa `app/Console/Kernel.php`, middleware diregistrasi di `bootstrap/app.php`. |
| Database | SQLite (default) / MySQL / PostgreSQL | Wajib menjalankan migrasi untuk tabel users, cache, jobs, dan sessions. |
| Cache, Queue, Session | Database drivers | Diatur lewat `.env`: `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`. |
| HTTP & Scraping | Guzzle 7 + Symfony DomCrawler 7 | Memberi cookies persistent, retry, validasi blank page, fallback selector. |
| Frontend | Vite 7, Tailwind CSS 4, Vanilla JS | `resources/css/app.css` mengimpor Tailwind v4. Gunakan `npm run dev`/`npm run build`. |
| Observability | Laravel Pail, Dedoc Scramble | Pail untuk live log streaming, Scramble menyajikan dok API (`/docs/api`). |

## Struktur Direktori Penting
- `app/Services/*` – session manager, auth client, scraping base, extractor khusus, toast service.
- `app/Http/Controllers/API` – auth API, campus data API, debug helper.
- `app/Http/Middleware` – penjaga sesi kampus + pengecualian CSRF untuk API.
- `resources/views` – halaman login, dashboard, profile, kelas, class-detail, meeting-detail, layout + komponen toast.
- `config/portal.php` – semua URL kampus, timeout, retry, TTL cache, security flag.
- `routes/api.php` + `routes/web.php` – definisi endpoint API dan halaman web.
- `database/migrations` – users + sessions + cache + jobs table (queue database driver).

## Prasyarat
1. PHP 8.4.* + Composer 2.7+.
2. Node.js 20 LTS + npm 10 (Tailwind v4 & Vite memerlukan ESM).
3. Database yang kompatibel (SQLite cukup untuk lokal).
4. Eksekusi queue berbasis database (`php artisan queue:work`) agar job refresh sesi berjalan.
5. Akses internet ke portal kampus untuk scraping (set `CAMPUS_*` sesuai lingkungan).

## Instalasi Cepat
```bash
git clone <repo-url> mycic
cd mycic
composer run setup        # composer install, salin .env, generate key, migrate, npm install, npm run build
```

Jika ingin langkah manual:
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run dev               # atau npm run build untuk production assets
```

## Menjalankan Lingkungan Pengembangan
- `php artisan serve` – jalankan server aplikasi.
- `npm run dev` – jalankan Vite + Tailwind watcher.
- `php artisan queue:listen --queue=campus-sessions,default --tries=1` – refresh sesi kampus tepat waktu.
- `php artisan pail --timeout=0` – pantau log secara real time.
- Gunakan `composer run dev` untuk menjalankan server, queue listener, log streamer, dan Vite secara paralel (menggunakan `concurrently`).

## Konfigurasi `.env` Penting
| Key | Fungsi |
| --- | --- |
| `APP_URL`, `APP_ENV`, `APP_DEBUG` | URL aplikasi & mode debug. Pastikan `APP_URL` sama dengan domain UI Anda agar cookie sesi valid. |
| `DB_*` | Konfigurasi database utama (SQLite default). |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | Gunakan `database` agar sejalan dengan konfigurasi repo & migrasi bawaan. |
| `CAMPUS_BASE_URL`, `CAMPUS_LOGIN_URL`, `CAMPUS_LOGIN_ACTION`, `CAMPUS_DASHBOARD_URL`, `CAMPUS_PROFILE_URL`, `CAMPUS_CLASSES_URL`, `CAMPUS_CLASS_DETAIL_URL` | URL halaman kampus tempat scraping dilakukan. Sesuaikan jika portal berubah. |
| `CAMPUS_SIPANDAI_ASSIGNMENT_URL`, `CAMPUS_SIPANDAI_ATTENDANCE_URL` | Base URL aksi SIPANDAI untuk upload tugas dan validasi kehadiran; tambahkan parameter dinamis (`courses_id`, `id`, `pertemuan`, `course_id`) saat runtime. |
| `CAMPUS_REQUEST_TIMEOUT`, `CAMPUS_REQUEST_DELAY`, `CAMPUS_SESSION_REFRESH_INTERVAL` | Atur timeout HTTP, jeda antar request (ms), serta interval refresh sesi (detik). |
| `CAMPUS_SESSION_PREFIX`, `CAMPUS_AUTO_RELOGIN` | Prefix cache sesi dan flag relogin otomatis menggunakan kredensial terenkripsi. |
| `CAMPUS_CACHE_PROFILE_TTL`, `CAMPUS_CACHE_DYNAMIC_TTL`, `CAMPUS_CACHE_HTML_TTL`, `CAMPUS_CACHE_FAILED_TTL` | Menentukan TTL cache untuk profil, data dinamis, HTML mentah, dan request gagal. |

## Daftar Endpoint Saat Ini

### 1. API Publik (tanpa middleware)
| Method | Endpoint | Controller | Catatan |
| --- | --- | --- | --- |
| `POST` | `/api/campus/login` | `API\PortalAuthController@login` | Membuat sesi kampus baru dan menyimpan token di cache. |
| `POST` | `/api/campus/refresh-session` | `API\PortalAuthController@refreshSession` | Perpanjang sesi aktif atau trigger auto relogin. |
| `GET` | `/api/campus/check-session` | `API\PortalAuthController@checkSession` | Digunakan front-end untuk polling status sesi. |
| `POST` | `/api/campus/logout` | `API\PortalAuthController@logout` | Hapus sesi dari cache & logout portal. |

### 2. API Terproteksi `portal.session`
Semua route berikut berada di `routes/api.php` (prefiks `/api/campus` + middleware `web` + `portal.session`):

| Method | Endpoint | Controller@method | Data yang Dikembalikan |
| --- | --- | --- | --- |
| `GET` | `/api/campus/dashboard` | `API\PortalDataController@dashboard` | Ringkasan dashboard + recent activity. |
| `GET` | `/api/campus/profile` | `API\PortalDataController@profile` | Profil mahasiswa + user info. |
| `GET` | `/api/campus/classes` | `API\PortalDataController@classes` | Daftar kelas SIPANDAI + summary. |
| `GET` | `/api/campus/class-detail` | `API\PortalDataController@classDetail` | Detail kelas / meeting (validasi `ClassDetailRequest`). |
| `GET` | `/api/campus/class-detail-by-id` | `API\PortalDataController@classDetailById` | Variasi pengambilan detail berdasarkan ID. |
| `GET` | `/api/campus/meeting-detail` | `API\PortalDataController@meetingDetail` | Detail pertemuan dari SIPANDAI. |
| `GET` | `/api/campus/meeting-detail-by-id` | `API\PortalDataController@meetingDetailById` | Detail meeting menggunakan identifier alternatif. |
| `GET` | `/api/campus/sipandai-classes` | `API\PortalDataController@sipandaiClasses` | Data kelas khusus SIPANDAI dashboard. |
| `GET` | `/api/campus/student-course` | `API\PortalDataController@studentCourse` | Data kelas dari endpoint student course (ruangan, jadwal). |
| `GET` | `/api/campus/merged-classes` | `API\PortalDataController@mergedClasses` | Gabungan SIPANDAI + student course untuk konsumsi UI. |
| `POST` | `/api/campus/assignments/upload` | `API\PortalActionController@uploadAssignment` | Upload tugas SIPANDAI dengan pengambilan form dinamis dan unggah file multipart. |
| `POST` | `/api/campus/attendance/mark` | `API\PortalActionController@markAttendance` | Tandai kehadiran pertemuan SIPANDAI mengikuti parameter dinamis (`course_id`, `id`, `pertemuan`). |

### 3. API Support (middleware `web`)
Endpoint pendukung integrasi berada di bawah prefix `/api/campus/support` dan `/api/campus/metadata`:

| Endpoint | Deskripsi |
| --- | --- |
| `GET /api/campus/support/session` | Mengecek status sesi kampus (preview token + user_info). |
| `GET /api/campus/support/parameter-help` | Dokumentasi mapping parameter `id`, `dosen_id`, `course_id`. |
| `GET /api/campus/support/validate-parameters` | Validasi cepat parameter sebelum memanggil extractor. |
| `GET /api/campus/metadata/endpoints` | Daftar endpoint yang dicatat di `PortalEndpointRegistry` (bisa `?key=profile`). |

### 4. Halaman Web (middleware `portal.session` kecuali login)
| Method | Endpoint | Deskripsi |
| --- | --- | --- |
| `GET` | `/login` | Halaman login Blade dengan toast & validasi client-side. |
| `POST` | `/login` | Eksekusi login via `WebLoginController`. |
| `POST` | `/logout` | Logout UI (hapus session token & redirect ke login). |
| `GET` | `/dashboard` | Dashboard UI modern (mengonsumsi `/api/campus/dashboard`). |
| `GET` | `/profile` | Halaman profil yang memakai API internal. |
| `GET` | `/classes` | Daftar kelas dengan status meeting. |
| `GET` | `/classes/{classId}` | Detail kelas spesifik. |
| `GET` | `/meetings/{meetingId}` | Detail pertemuan SIPANDAI. |
| `GET` | `/test-toast/{type}` | Endpoint uji `ToastService` (success, error, warning, info). |

Daftar di atas mencakup seluruh route dari `routes/web.php` dan `routes/api.php`, jadi bisa dijadikan referensi cepat saat integrasi ataupun debugging.

## Checklist Agar Aplikasi Tetap Bagus
### 1. Manajemen Session & Security
- Selalu gunakan `PortalSessionManager` untuk membuat, menyegarkan, dan memvalidasi sesi; jangan menyimpan cookie mentah.
- Pastikan queue worker untuk `campus-sessions` aktif agar job `RefreshPortalSessionJob` berjalan sebelum `expires_at`.
- Simpan kredensial kampus menggunakan enkripsi bawaan Laravel (`encrypt()`), tidak pernah hardcode di kode atau config.
- Aktifkan `auto_relogin` hanya di lingkungan aman dan audit log (`storage/logs/laravel.log`) untuk setiap relogin.
- Terapkan rate limiting di layer API Gateway / middleware tambahan saat menuju produksi.

### 2. Scraping & Transformasi Data
- Gunakan `PortalScrapingService` agar setiap request mendapat retry, delay, dan validasi blank page otomatis.
- Tambahkan fallback selector pada extractor ketika struktur HTML bisa berubah (bandingkan `extractClassesList`, `extractCourseMaterials`, dsb).
- Selalu periksa cache sebelum melakukan scraping berat (`cacheResponse`, `getCachedResponse`) agar tidak menekan server kampus.
- Log setiap kegagalan scraping lewat `logScrapingError` untuk memudahkan debugging.

### 3. API & Pengalaman Pengguna
- Pertahankan struktur response `{ status, message, data, meta }` agar front-end dan klien eksternal konsisten.
- Validasi parameter kompleks menggunakan Form Request (`ClassDetailRequest`) daripada inline validation.
- Gunakan `PortalSessionMiddleware` di semua route yang butuh data kampus untuk mencegah request tanpa sesi.
- Komunikasikan status sesi di UI dengan `ToastService::sessionExpired()` dan polling `/api/campus/check-session`.

### 4. Performa & Observabilitas
- Gunakan `CACHE_STORE=database` atau Redis untuk menyimpan cache HTML/data dengan TTL berbeda (profil 1 jam, data dinamis 15 menit, HTML 5 menit, kegagalan 1 menit).
- Simpan log penting melalui `Log::info/warning/error` (lihat `PortalAuthService`, `PortalSessionManager`) agar mudah dianalisis via Laravel Pail.
- Jalankan `php artisan schedule:work` atau cron untuk job berkala (mis. warm-up cache) apabila ditambahkan.
- Aktifkan `CAMPUS_REQUEST_DELAY` minimal 1000 ms untuk menghormati server kampus (rate limiting).

### 5. Testing & QA
- Tambahkan feature test untuk tiap endpoint API (`tests/Feature/*`) dengan memanfaatkan factory dan HTTP fakes.
- Gunakan mocking HTTP (mis. `Http::fake()` atau `GuzzleHttp\Handler\MockHandler`) untuk menguji extractor tanpa memanggil server kampus.
- Jalankan `php artisan test --filter=<NamaTest>` pada area yang diubah dan tawarkan opsi menjalankan seluruh suite.
- Pastikan style code mengikuti Laravel Pint: `vendor/bin/pint --dirty`.

## Observability & Debugging
- `storage/logs/laravel.log` – log utama; gunakan `tail -f` atau `php artisan pail` untuk live streaming.
- `php artisan tinker` – debugging session/cache secara langsung (`Cache::get('cic_session_<token>')`).
- Endpoint `/api/campus/support/*` membantu memeriksa struktur HTML terbaru tanpa membuka UI lama.
- Gunakan `php artisan queue:failed` & `php artisan queue:retry` untuk menangani job refresh sesi yang gagal.
- Dokumentasi API otomatis tersedia melalui Dedoc Scramble (`/docs/api`) saat `APP_ENV=local` atau akses dibuka.

## Pengujian & Format Kode
```bash
php artisan test                         # Jalankan seluruh test PHPUnit
php artisan test tests/Feature/...php    # Jalankan file tertentu
vendor/bin/pint --dirty                  # Format file yang berubah
```

Sebelum membuat PR, biasakan untuk:
1. Menjalankan test minimal terkait perubahan.
2. Menjalankan Pint agar konsisten dengan konvensi proyek.
3. Memastikan `npm run build` sukses bila ada perubahan UI.

## Langkah Berikutnya yang Direkomendasikan
1. **Tambah Feature Test & Mock** – liputi `PortalDataController` dan `PortalSessionManager` agar regresi terdeteksi cepat.
2. **Perkuat Observability** – kirim log krusial ke channel terpisah (Stackdriver/Logtail) dan tambahkan metrik queue.
3. **Rate Limiting & Circuit Breaker** – tambahkan middleware untuk membatasi request eksternal ketika server kampus bermasalah.
4. **Dokumentasi API** – publikasikan versi HTML/Markdown dari Scramble agar tim front-end bisa mengonsumsi API tanpa membaca kode.

Dengan mengikuti panduan di atas, aplikasi proxy kampus ini akan tetap stabil, mudah dirawat, dan memberikan pengalaman terbaik bagi mahasiswa maupun pengembang yang melanjutkannya. Selamat berkarya! 🙌
