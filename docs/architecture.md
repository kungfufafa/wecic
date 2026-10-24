# Arsitektur MyCIC

Portal modern berbasis Laravel 12 yang bertugas menjadi _wrapper_ untuk aplikasi kampus CIC yang lama. Aplikasi ini menjaga sesi login mahasiswa tetap hidup, men-scrape halaman HTML kampus, dan mengeksposnya kembali sebagai REST API serta dashboard web yang lebih ramah pengguna.

## Gambaran Singkat
MyCIC memadukan tiga lapisan utama:
- **Autentikasi & Session** – `App\Services\PortalAuthService` (stateless) menganalisis form login kampus dan mengeksekusi login, sedangkan `App\Services\PortalSessionManager` menyimpan cookie di cache, memvalidasi sesi secara lokal (idle timeout), dan menjaga sesi upstream lewat keep-alive `RefreshPortalSessionJob` (unik per sesi; relogin otomatis bila diaktifkan).
- **Data (snapshot-first)** – `App\Services\PortalDatasetRepository` menyajikan setiap endpoint dari snapshot milik mahasiswa: snapshot basi tetap dikirim sambil satu `RefreshPortalDataJob` menyinkronkan ulang, snapshot kosong dibangun sekali. Cara membangun tiap dataset ada di `PortalDatasetBuilder`; halaman upstream hasil parse disimpan di `PortalDataCache`.
- **Scraping & Transformasi** – `App\Services\PortalScrapingService` menjadi fondasi untuk extractor khusus (`ProfileExtractor`, `ClassesExtractor`, `DashboardExtractor`, `SipandaiClassesExtractor`, `StudentCourseExtractor`) yang mengubah HTML menjadi struktur data yang bersih.
- **API & UI** – `App\Http\Controllers\API` mengekspos REST API berformat standar (`status`, `message`, `data`, `meta`), sementara route web + komponen Blade + Tailwind v4 menghadirkan dashboard modern yang memakai `ToastService` dan utilitas JS di `resources/js/app.js`.

## Fitur Utama
- Login terpusat yang menjaga sesi kampus tetap aktif tanpa blank page.
- API kampus modern (`/api/*`) lengkap dengan validasi `FormRequest` dan response envelope konsisten.
- Ekstraksi data dashboard, profil, jadwal, kelas, SIPANDAI, serta detail meeting lengkap dengan fallback selector.
- Snapshot per dataset (stale-while-revalidate) + cache resource per mahasiswa dengan TTL di `config/portal.php` dan invalidasi eksplisit setelah aksi (upload/absen/hapus).
- Middleware `portal.session` (validasi lokal) dan keep-alive `RefreshPortalSessionJob` agar sesi kampus tidak putus selama mahasiswa aktif.
- UI responsif berbasis Tailwind CSS v4 + Vite dengan komponen toast dan indikator status sesi real time.
- Paket pendukung seperti Guzzle 7, Symfony DomCrawler 7, Dedoc Scramble (auto API docs), Laravel Pail (live log), dan Laravel Boost toolings.

## Arsitektur Singkat
| Lapisan | Lokasi | Fungsi |
| --- | --- | --- |
| Autentikasi & Session | `app/Services/PortalAuthService.php`, `app/Services/PortalSessionManager.php`, `app/Jobs/RefreshPortalSessionJob.php` | Login ke portal kampus, menyimpan cookie di cache, validasi sesi lokal + idle timeout, dan keep-alive upstream. |
| Data & Sinkronisasi | `app/Services/PortalDatasetRepository.php`, `app/Services/PortalDatasetBuilder.php`, `app/Services/PortalSnapshotStore.php`, `app/Jobs/RefreshPortalDataJob.php` | Snapshot-first read, sinkronisasi background yang benar-benar fresh, single-flight, dan invalidasi setelah aksi. |
| Scraping Base & Extractor | `app/Services/PortalScrapingService.php`, `app/Services/PortalDataCache.php`, `app/Services/Extractors/*` | HTTP client dengan retry, memo halaman per request/job, deteksi sesi kedaluwarsa (halaman login), cache resource, dan parser DomCrawler. |
| API Controller & Validasi | `app/Http/Controllers/API`, `app/Http/Requests/ClassDetailRequest.php` | Menyajikan endpoint dashboard, profil, kelas, detail kelas/meeting, SIPANDAI, serta auth API dengan validasi parameter ketat. |
| Middleware & Routing | `bootstrap/app.php`, `app/Http/Middleware/PortalSessionMiddleware.php`, `app/Http/Middleware/VerifyCsrfTokenMiddleware.php`, `routes/web.php`, `routes/api.php` | Alias middleware `portal.session`, pengecualian CSRF untuk API, pemisahan web & API routes, dan debug endpoint. |
| UI & Assets | `resources/views`, `resources/js`, `resources/css`, `vite.config.js` | Template Blade untuk login/dashboard/kelas, JS util untuk toast & session refresh, Tailwind v4 @import, serta bundling Vite. |
| Observability & Tools | `storage/logs`, `composer.json` scripts, `config/logging.php`, `config/portal.php`, `config/scramble.php` | Logging terstruktur, konfigurasi kampus, cache TTL, request delay, serta dokumentasi API otomatis Scramble. |

## Teknologi & Dependensi
| Teknologi | Versi | Catatan |
| --- | --- | --- |
| PHP | 8.4.x | Pastikan extension curl, mbstring, openssl, sqlite, pdo, redis (phpredis), pcntl, dan posix diaktifkan. |
| Laravel Framework | 12.x | Struktur baru tanpa `app/Console/Kernel.php`, middleware diregistrasi di `bootstrap/app.php`. |
| Database | SQLite (default) / MySQL / PostgreSQL | Wajib menjalankan migrasi untuk tabel users, cache, jobs, dan sessions. |
| Cache, Session | Database drivers | Diatur lewat `.env`: `CACHE_STORE=database`, `SESSION_DRIVER=database`. |
| Queue | Redis + Laravel Horizon 5 | `QUEUE_CONNECTION=redis`; Horizon menjalankan tiga antrean (`config/horizon.php`) dan menyediakan dasbor `/horizon`. |
| HTTP & Scraping | Guzzle 7 + Symfony DomCrawler 7 | Memberi cookies persistent, retry, validasi blank page, fallback selector. |
| Token mobile | Laravel Sanctum 4 | Hanya personal access token untuk aplikasi mobile (`POST /api/auth/token`); route SPA `sanctum/csrf-cookie` dimatikan. |
| Frontend | Vite 7, Tailwind CSS 4, Vanilla JS | `resources/css/app.css` mengimpor Tailwind v4. Gunakan `npm run dev`/`npm run build`. |
| Observability | Laravel Pail, Log Viewer, Horizon, Dedoc Scramble | Pail untuk live log streaming, Log Viewer (`/log-viewer`) untuk membaca log dari browser, dasbor Horizon (`/horizon`) untuk antrean, Scramble menyajikan dok API (`/docs/api`). |

## Struktur Direktori Penting
- `app/Services/*` – session manager, auth client, scraping base, extractor khusus, toast service.
- `app/Http/Controllers/API` – auth API, campus data API, debug helper.
- `app/Http/Middleware` – penjaga sesi kampus + pengecualian CSRF untuk API.
- `resources/views` – halaman login, dashboard, profile, kelas, class-detail, meeting-detail, layout + komponen toast.
- `config/portal.php` – semua URL kampus, timeout, retry, TTL cache, security flag.
- `routes/api.php` + `routes/web.php` – definisi endpoint API dan halaman web.
- `database/migrations` – users + sessions + cache + jobs table (bekas queue database; job yang tersisa dipindah ke Redis oleh `portal:move-old-queue-jobs`).

## Alat pengembangan
- `php artisan serve` – jalankan server aplikasi.
- `npm run dev` – jalankan Vite + Tailwind watcher.
- `php artisan horizon:listen` – jalankan Horizon (ketiga antrean) dan restart worker otomatis saat kode berubah. Butuh Redis lokal (`QUEUE_CONNECTION=redis`) dan Node dengan paket `chokidar` dari `devDependencies`. Dasbornya di `/horizon`, log di `/log-viewer`.
- `php artisan reverb:start` – server WebSocket untuk pembaruan realtime di browser (opsional; lihat [Reverb](deployment.md#reverb-realtime-di-browser-opsional)). Tanpanya, aplikasi tetap jalan: browser memakai polling dan menampilkan "Pembaruan otomatis tidak tersedia".
- `php artisan pail --timeout=0` – pantau log secara real time.
- Gunakan `composer run dev` untuk menjalankan server, Horizon, Reverb, log streamer, dan Vite secara paralel (menggunakan `concurrently`).
- `php artisan schedule:work` (opsional) – menjalankan scheduler secara lokal. Perhatikan: sinkronisasi arsip di background ikut berjalan dan masuk ke portal kampus memakai password mahasiswa yang tersimpan.
- `php artisan portal:capture-fixtures --session=<token sesi kampus> --meeting=<meeting_id>:<courses_id>[:<pertemuan>] --exam=<id ujian>` – simpan salinan halaman portal (pertemuan, form tugas, daftar & detail ujian) yang sudah disanitasi ke `tests/Fixtures/portal/real/` sebagai fixture tes. Hanya GET, tidak berjalan di production, dan berhenti bila sesi tidak memuat nama dan NIM mahasiswa. `PORTAL_RECORD_ACTION_RESPONSES=true` menyimpan jawaban portal atas absen, upload, dan hapus (tersanitasi) di `storage/app/portal-captures/`.

## Checklist Agar Aplikasi Tetap Bagus
### 1. Manajemen Session & Security
- Selalu gunakan `PortalSessionManager` untuk membuat, menyegarkan, dan memvalidasi sesi; jangan menyimpan cookie mentah.
- Pastikan Horizon berjalan (ketiga antrean `sessions`, `default`, `background`): keep-alive sesi, sinkronisasi snapshot, antrean offline, notifikasi, dan sinkronisasi arsip berjalan di sana.
- Simpan kredensial kampus menggunakan enkripsi bawaan Laravel (`encrypt()`), tidak pernah hardcode di kode atau config.
- Aktifkan `auto_relogin` hanya di lingkungan aman dan audit log (`storage/logs/laravel.log`) untuk setiap relogin.
- Terapkan rate limiting di layer API Gateway / middleware tambahan saat menuju produksi.

### 2. Scraping & Transformasi Data
- Gunakan `PortalScrapingService` agar setiap request mendapat retry, delay, dan validasi blank page otomatis.
- Tambahkan fallback selector pada extractor ketika struktur HTML bisa berubah (bandingkan `extractClassesList`, `extractCourseMaterials`, dsb).
- Bungkus hasil parse halaman dengan `rememberResource()` dan sajikan data endpoint lewat `PortalDatasetRepository::read()`; setelah aksi yang mengubah portal, panggil `PortalDatasetRepository::invalidate()`.
- Log setiap kegagalan scraping lewat `logScrapingError` untuk memudahkan debugging.

### 3. API & Pengalaman Pengguna
- Pertahankan struktur response `{ status, message, data, meta }` agar front-end dan klien eksternal konsisten.
- Validasi parameter kompleks menggunakan Form Request (`ClassDetailRequest`) daripada inline validation.
- Gunakan `PortalSessionMiddleware` di semua route yang butuh data kampus untuk mencegah request tanpa sesi.
- Komunikasikan status sesi di UI lewat `GET /api/auth/session` dan event realtime sesi (Reverb).

### 4. Performa & Observabilitas
- TTL cache resource ada di `portal.cache.resources`, jendela segar snapshot di `portal.datasets.*.fresh_for`; gunakan `CACHE_STORE=database` atau Redis.
- Simpan log penting melalui `Log::info/warning/error` (lihat `PortalAuthService`, `PortalSessionManager`) agar mudah dianalisis via Laravel Pail.
- Cron `php artisan schedule:run` wajib di server: scheduler menjalankan sinkronisasi arsip, metrik Horizon, dan pembersihan notifikasi.
- Aktifkan `CAMPUS_REQUEST_DELAY` minimal 1000 ms untuk menghormati server kampus (rate limiting).

### 5. Testing & QA
- Tambahkan feature test untuk tiap endpoint API (`tests/Feature/*`) dengan memanfaatkan factory dan HTTP fakes.
- Gunakan mocking HTTP (mis. `Http::fake()` atau `GuzzleHttp\Handler\MockHandler`) untuk menguji extractor tanpa memanggil server kampus.
- Test tidak pernah menyentuh jaringan: `phpunit.xml` mengarahkan semua URL portal ke `https://portal.invalid` dan mengosongkan `GEMINI_API_KEY`, `tests/TestCase.php` menjawab setiap request klien portal sebagai portal yang tidak terjangkau (dan menggagalkan test yang menuju host asli), serta `Http::preventStrayRequests()` menolak panggilan `Http` yang tidak di-fake. Tulis URL portal di test sebagai `https://portal.invalid/...` atau `config('portal.base_url')`.
- Jalankan `php artisan test --filter=<NamaTest>` pada area yang diubah dan tawarkan opsi menjalankan seluruh suite.
- Pastikan style code mengikuti Laravel Pint: `vendor/bin/pint --dirty`.

## Langkah Berikutnya yang Direkomendasikan
1. **Tambah Feature Test & Mock** – liputi `PortalDataController` dan `PortalSessionManager` agar regresi terdeteksi cepat.
2. **Perkuat Observability** – kirim log krusial ke channel terpisah (Stackdriver/Logtail) dan aktifkan notifikasi Horizon (`LongWaitDetected`) ke email atau Slack.
3. **Rate Limiting & Circuit Breaker** – tambahkan middleware untuk membatasi request eksternal ketika server kampus bermasalah.
4. **Dokumentasi API** – publikasikan versi HTML/Markdown dari Scramble agar tim front-end bisa mengonsumsi API tanpa membaca kode.

Dengan mengikuti panduan di atas, aplikasi proxy kampus ini akan tetap stabil, mudah dirawat, dan memberikan pengalaman terbaik bagi mahasiswa maupun pengembang yang melanjutkannya. Selamat berkarya! 🙌
