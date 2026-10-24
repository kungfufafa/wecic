# MyCIC Campus Proxy & API

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
| PHP | 8.4.x | Pastikan extension curl, mbstring, openssl, sqlite, pdo diaktifkan. |
| Laravel Framework | 12.x | Struktur baru tanpa `app/Console/Kernel.php`, middleware diregistrasi di `bootstrap/app.php`. |
| Database | SQLite (default) / MySQL / PostgreSQL | Wajib menjalankan migrasi untuk tabel users, cache, jobs, dan sessions. |
| Cache, Queue, Session | Database drivers | Diatur lewat `.env`: `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`. |
| HTTP & Scraping | Guzzle 7 + Symfony DomCrawler 7 | Memberi cookies persistent, retry, validasi blank page, fallback selector. |
| Token mobile | Laravel Sanctum 4 | Hanya personal access token untuk aplikasi mobile (`POST /api/auth/token`); route SPA `sanctum/csrf-cookie` dimatikan. |
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
4. Queue worker berumur panjang di bawah Supervisor, satu program per kelompok antrean (disarankan), plus cron `php artisan schedule:run` setiap menit. Cron menjalankan worker cadangan, sinkronisasi arsip di background, pemulihan unggahan, dan pembersihan data (lihat [Menjalankan di Server](#menjalankan-di-server-queue--scheduler)).
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
- `php artisan queue:listen --tries=1 --timeout=0 --queue=campus-sessions,campus-refresh,campus-notifications,default,campus-background` – jalankan semua antrean dalam satu proses, urut prioritas (cukup untuk satu pengguna; production memisahkannya ke tiga worker, lihat [Menjalankan di Server](#menjalankan-di-server-queue--scheduler)). `--timeout=0` mematikan batas waktu proses listener; tiap job tetap dihentikan worker sesuai `$timeout` miliknya (sinkronisasi arsip boleh sampai 30 menit).
- `php artisan reverb:start` – server WebSocket untuk notifikasi realtime.
- `php artisan pail --timeout=0` – pantau log secara real time.
- Gunakan `composer run dev` untuk menjalankan server, queue listener, Reverb, log streamer, dan Vite secara paralel (menggunakan `concurrently`).
- `php artisan schedule:work` (opsional) – menjalankan scheduler secara lokal. Perhatikan: sinkronisasi arsip di background ikut berjalan dan masuk ke portal kampus memakai password mahasiswa yang tersimpan.
- `php artisan portal:capture-fixtures --session=<token sesi kampus> --meeting=<meeting_id>:<courses_id>[:<pertemuan>] --exam=<id ujian>` – simpan salinan halaman portal (pertemuan, form tugas, daftar & detail ujian) yang sudah disanitasi ke `tests/Fixtures/portal/real/` sebagai fixture tes. Hanya GET, tidak berjalan di production, dan berhenti bila sesi tidak memuat nama dan NIM mahasiswa. `PORTAL_RECORD_ACTION_RESPONSES=true` menyimpan jawaban portal atas absen, upload, dan hapus (tersanitasi) di `storage/app/portal-captures/`.

## Menjalankan Tes
- `php artisan test` – seluruh tes PHP. Host portal diarahkan ke `portal.invalid` dan setiap client portal dijaga handler tes, jadi tes tidak pernah menghubungi portal kampus.
- `npm run test:js` – tes JavaScript (runner bawaan Node, tanpa dependency tambahan).
- `npm run test:e2e` – tes browser Playwright memakai Google Chrome yang sudah terpasang. Server dijalankan dengan `APP_ENV=e2e`: semua `/api/*` di-mock dan route login palsu hanya ada di lingkungan itu.

## Menjalankan di Server (Queue & Scheduler)
Kerja ke portal kampus yang berjalan di belakang layar memakai queue `database`. Tanpa worker yang menjalankannya, dashboard tertahan di status "menyinkronkan", sesi portal kedaluwarsa, notifikasi tidak pernah dibuat, dan arsip materi tidak pernah selesai.

Worker dibagi menjadi tiga kelompok antrean. Satu worker hanya menjalankan satu job pada satu waktu dan tidak pernah menyela job yang sedang berjalan demi antrean yang lebih prioritas, jadi keep-alive sesi dan kerja yang sedang ditunggu mahasiswa tidak boleh berbagi worker dengan sinkronisasi arsip yang bisa berjalan 30 menit. Di dalam satu kelompok, nama antrean hanya menentukan urutan: worker mengosongkan antrean pertama dulu, baru berikutnya.

| Kelompok | Antrean (urut prioritas) | `--timeout` | Isi |
| --- | --- | --- | --- |
| Sesi | `campus-sessions` | 300 | Keep-alive sesi, menyambungkan sesi offline, mengakhiri sesi yang idle. Harus selalu cepat. |
| Ditunggu mahasiswa | `campus-refresh,campus-notifications,default` | 600 | Refresh snapshot (`RefreshPortalDataJob` sampai 600 detik) dan pengiriman antrean offline lebih dulu, lalu pemindaian notifikasi, pengingat, dan push, lalu ingest notebook. |
| Crawl background | `campus-background` | 1800 | Sinkronisasi arsip (`SyncPortalArchiveJob` sampai 1800 detik), arsip materi & berkas, sinkronisasi notebook mata kuliah. |

### Disarankan: worker Supervisor
Di production, jalankan worker berumur panjang di bawah Supervisor (atau systemd), satu program per kelompok. Contoh `/etc/supervisor/conf.d/mycic-queue.conf`:

```ini
[program:mycic-queue-sessions]
process_name=%(program_name)s_%(process_num)02d
command=php /path/ke/mycic/artisan queue:work database --queue=campus-sessions --tries=1 --timeout=300 --sleep=1 --max-time=3600
directory=/path/ke/mycic
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=330
redirect_stderr=true
stdout_logfile=/path/ke/mycic/storage/logs/queue-sessions.log

[program:mycic-queue-refresh]
process_name=%(program_name)s_%(process_num)02d
command=php /path/ke/mycic/artisan queue:work database --queue=campus-refresh,campus-notifications,default --tries=1 --timeout=600 --sleep=1 --max-time=3600
directory=/path/ke/mycic
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=630
redirect_stderr=true
stdout_logfile=/path/ke/mycic/storage/logs/queue-refresh.log

[program:mycic-queue-background]
process_name=%(program_name)s_%(process_num)02d
command=php /path/ke/mycic/artisan queue:work database --queue=campus-background --tries=1 --timeout=1800 --sleep=3 --max-time=3600
directory=/path/ke/mycic
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1830
redirect_stderr=true
stdout_logfile=/path/ke/mycic/storage/logs/queue-background.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start "mycic-queue-sessions:*" "mycic-queue-refresh:*" "mycic-queue-background:*"
```

- Crawl background punya antrean dan worker sendiri, jadi refresh snapshot saat login, pemindaian notifikasi, dan ingest notebook tidak menunggu sinkronisasi arsip yang bisa berjalan 30 menit. Setiap 15 menit `portal:sync-archives` mengantre sampai `PORTAL_BACKGROUND_SYNC_BATCH_SIZE` (default 10) `SyncPortalArchiveJob` di `campus-background`. Bila batch itu sering belum selesai saat batch berikutnya tiba, naikkan `numprocs` program `mycic-queue-background` (lihat syarat database di bawah) atau kecilkan `PORTAL_BACKGROUND_SYNC_BATCH_SIZE`.
- Worker paralel butuh database yang mengunci baris dengan `SKIP LOCKED` (MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+). Di SQLite, worker paralel bisa sesekali mencatat galat `database is locked`.
- `stopwaitsecs` lebih besar dari `--timeout`, jadi Supervisor menunggu job yang sedang berjalan selesai saat worker dihentikan.
- `--max-time=3600` membuat worker berhenti sendiri setiap jam setelah job-nya selesai (memori kembali bersih), lalu Supervisor langsung menyalakannya lagi.
- Batas waktu job (`--timeout` dan `$timeout` tiap job) hanya berlaku bila ekstensi PHP `pcntl` terpasang.
- Setelah deploy, jalankan `php artisan queue:restart` agar worker memuat kode baru setelah job yang sedang berjalan selesai.
- Jangan menjalankan `queue:work` tanpa `--queue`: perintah itu hanya memproses antrean `default`.

### Cron scheduler (wajib, sekaligus worker cadangan)
Cron tetap wajib, juga ketika Supervisor dipakai:

```cron
* * * * * cd /path/ke/mycic && php artisan schedule:run >> /dev/null 2>&1
```

Jadwal di `routes/console.php`:

| Jadwal | Perintah | Fungsi |
| --- | --- | --- |
| Setiap menit (background) | `queue:work --queue=campus-sessions --stop-when-empty --timeout=300 --tries=1 --max-time=60` | Worker cadangan kelompok sesi. |
| Setiap menit (background) | `queue:work --queue=campus-refresh,campus-notifications,default --stop-when-empty --timeout=600 --tries=1 --max-time=300` | Worker cadangan kelompok yang ditunggu mahasiswa. |
| Setiap menit (background) | `queue:work --queue=campus-background --stop-when-empty --timeout=1800 --tries=1 --max-time=300` | Worker cadangan crawl background. |
| Setiap 5 menit | `portal:recover-uploads` | Menutup unggahan yang terhenti di tengah jalan (mis. proses web mati) dan menghapus berkas sementaranya. |
| Setiap 5 menit | `portal:send-outbox` | Antrean offline yang tidak dikirim sesi mana pun (mis. sesi offline sudah habis sebelum portal pulih): yang lewat tenggat atau `portal.outbox.retention_days` ditandai kedaluwarsa dan dinotifikasikan, sisanya dikirim dengan sesi online mahasiswa atau login latar dengan password tersimpan (paling sering tiap 15 menit per mahasiswa). |
| Setiap 10 menit | `portal:remind-away` | Mengantre pengingat tenggat dan "kelas sudah dimulai" untuk mahasiswa yang aplikasinya tertutup, dari snapshot tersimpan saja (`portal.mobile.away_reminders`). |
| Setiap 15 menit | `portal:sync-archives` | Mengantre sinkronisasi arsip di background untuk mahasiswa yang jadwalnya sudah tiba (`portal.background_sync`). |
| Harian | `model:prune` | Menghapus notifikasi yang melewati masa simpan dan menghapus kredensial tersimpan akun offline yang login online terakhirnya melewati `portal.background_sync.credential_days` (default 30 hari). Juga memangkas token bearer aplikasi mobile yang lama tidak dipakai dan token push yang tidak didaftarkan ulang (`portal.mobile`). |

Ketiga worker terjadwal berjalan di background (tidak menahan jadwal lain), berhenti saat antreannya kosong atau setelah `--max-time`, dan dikunci `withoutOverlapping` yang kedaluwarsa tepat setelah waktu jalan terlamanya: 7 menit untuk sesi, 16 menit untuk kerja yang ditunggu mahasiswa, 36 menit untuk crawl background. Kalau server mati saat worker berjalan, worker berikutnya hanya tertahan selama itu, bukan 24 jam. Selama worker Supervisor hidup, worker terjadwal biasanya mendapati antrean kosong dan langsung berhenti; kalau worker Supervisor mati, worker terjadwal yang mengambil alih. Cron saja tanpa Supervisor tetap berfungsi, tetapi tiap kelompok hanya punya satu worker yang menjalankan job satu per satu. Keep-alive sesi, refresh snapshot, pemindaian notifikasi, dan ingest notebook tidak menunggu sinkronisasi arsip.

Antrean yang dipakai:

| Antrean | Job |
| --- | --- |
| `campus-sessions` | `RefreshPortalSessionJob` (keep-alive sesi), `ResumeOfflineSessionJob` (menyambungkan sesi offline), `ExpirePortalSessionJob` (mengakhiri sesi yang idle). |
| `campus-refresh` | `RefreshPortalDataJob`, `FlushPortalOutboxJob` dan `SendPortalOutboxJob` (mengirim antrean offline). |
| `campus-notifications` | `ScanPortalNotificationsJob`, `SendAwayRemindersJob`, `SendPortalPushNotificationsJob`. |
| `default` | `IngestNotebookSourceJob` dan job lain tanpa antrean khusus. |
| `campus-background` | `SyncPortalArchiveJob` (sinkronisasi di background, sampai 30 menit), `ArchiveCourseMaterialsJob`, `ArchivePortalFilesJob`, `SyncCourseNotebookJob`. |

Unggahan tugas dan ujian (`ProcessPortalAssignmentUploadJob`, `ProcessPortalExamUploadJob`) tidak pernah menjadi job di queue `database`: proses web menjalankannya tepat setelah respons dikirim, dan pengiriman antrean offline menjalankannya langsung di dalam job-nya.

`--timeout` tiap worker menjaga job tanpa `$timeout` sendiri agar tidak dihentikan pada 60 detik, dan harus tetap di bawah `retry_after`. `DB_QUEUE_RETRY_AFTER` (default 1900 detik) harus lebih besar dari `$timeout` job terpanjang (`SyncPortalArchiveJob`: 1800 detik) dan dari `--timeout` setiap worker. Kalau lebih kecil, worker kedua mengambil job yang masih berjalan, job itu ditandai gagal, dan sinkronisasi yang sama berjalan dua kali. `tests/Unit/QueueJobConfigurationTest.php` dan `tests/Unit/ScheduledQueueWorkerTest.php` memeriksa batas ini, antrean setiap job, dan jadwal worker. Akibatnya, job yang tertinggal saat worker mati mendadak (kehabisan memori, `SIGKILL` saat deploy) baru dicoba lagi sekitar 32 menit kemudian, termasuk keep-alive sesi di `campus-sessions`; hentikan worker dengan `php artisan queue:restart` saat deploy agar job yang sedang berjalan selesai dulu.

### Arsip & sinkronisasi di background
- Setiap login online menyimpan hash password (login offline saat portal mati) dan, bila sinkronisasi di background aktif, kredensial terenkripsi dengan `APP_KEY`. Mahasiswa bisa mematikannya sendiri di halaman Arsip; password tersimpan langsung dihapus.
- `PORTAL_BACKGROUND_SYNC_ENABLED`, `PORTAL_BACKGROUND_SYNC_INTERVAL` (menit), `PORTAL_BACKGROUND_SYNC_CREDENTIAL_DAYS`, `PORTAL_BACKGROUND_SYNC_BATCH_SIZE`, `PORTAL_BACKGROUND_SYNC_COURSES_ENABLED`, `PORTAL_BACKGROUND_SYNC_COURSES_INTERVAL` mengatur sinkronisasi; `PORTAL_ARCHIVE_ENABLED`, `PORTAL_ARCHIVE_OFFLINE_LOGIN`, `PORTAL_ARCHIVE_FILES_ENABLED` mengatur arsip, login offline, dan arsip berkas materi (lihat `config/portal.php`).
- Mengganti `APP_KEY` tanpa `APP_PREVIOUS_KEYS` membuat kredensial tersimpan tidak bisa dibaca: MyCIC menghapusnya pada sinkronisasi berikutnya dan menyimpannya lagi saat mahasiswa login online.
- Semua data per mahasiswa (versi arsip, berkas materi, akses offline, notifikasi, notebook, token push) disimpan dengan kunci pemilik `student_…`, yaitu HMAC NIM dengan `PORTAL_OWNER_KEY` (kosong = `APP_KEY`, sama seperti sebelumnya). **Sebelum merotasi `APP_KEY`, isi `PORTAL_OWNER_KEY` dengan nilai `APP_KEY` yang sekarang**, supaya kunci pemilik tidak ikut berubah. Bila terlanjur berubah, data dengan kunci lama dipindahkan ke kunci baru saat mahasiswa itu login berikutnya (online, offline, atau sinkronisasi background), asalkan secret lamanya ada di `APP_PREVIOUS_KEYS` atau `PORTAL_PREVIOUS_OWNER_KEYS` (dipisah koma).
- Status setiap sinkronisasi tercatat di akun (`last_background_sync_*`); sinkronisasi yang sedang berjalan bertanda `running` dan dibaca gagal bila melewati batas waktunya.

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
| `CAMPUS_SESSION_IDLE_TIMEOUT` | Detik tanpa aktivitas sebelum sesi kampus diakhiri dan keep-alive berhenti (default 7200). |
| `CAMPUS_CACHE_PROFILE_TTL`, `CAMPUS_CACHE_DYNAMIC_TTL`, `CAMPUS_CACHE_HTML_TTL` | TTL cache resource untuk profil, keuangan, dan halaman daftar kelas/jadwal. |

## Daftar Endpoint
Daftar lengkap dan selalu terbaru ada di dokumentasi Scramble (`/docs/api`, beserta skema request/response) dan di `php artisan route:list`. Gambaran singkatnya:

| Kelompok | Endpoint | Catatan |
| --- | --- | --- |
| Auth | `POST /api/auth/login`, `POST /api/auth/token`, `GET /api/auth/session`, `POST /api/auth/session/refresh`, `POST /api/auth/logout` | Membuat, memeriksa, memperpanjang, dan mengakhiri sesi kampus; `auth/token` untuk aplikasi mobile (lihat "Aplikasi Mobile"). |
| Perangkat & realtime | `POST /api/devices/push-token`, `DELETE /api/devices/push-token`, `POST /api/broadcasting/auth` | Token push Expo dan otorisasi channel Reverb untuk klien API. |
| Data kampus | `GET /api/dashboard`, `/api/profile`, `/api/class`, `/api/class/{id}`, `/api/meeting/{meeting_id}`, `/api/finance/*`, `/api/academic/*`, `/api/skkm`, `/api/thesis`, `/api/library`, `/api/announcement` | Snapshot per mahasiswa; data basi dikirim sambil disinkronkan ulang di queue. |
| Aksi | `POST /api/assignment`, `POST /api/attendance`, `POST /api/exam`, `GET /api/operations/{operation}` | Butuh portal online; hasilnya dilacak sebagai operasi. |
| Notifikasi | `GET /api/notifications`, `GET /api/notifications/unread-count`, `POST /api/notifications/read` | Notifikasi tersimpan per mahasiswa. |
| Arsip | `GET /api/archive`, `/api/archive/{dataset}`, `/api/archive/versions/{version}`, `/api/archive/files`, `POST /api/archive/files/sync`, `PATCH /api/archive/background-sync`, `DELETE /api/archive` | Riwayat versi, data janggal, berkas materi, dan sinkronisasi di background. |
| Notebook | `/api/notebooks/*` | Notebook mata kuliah berbasis Gemini. |
| Dukungan | `GET /api/support/parameter-help`, `GET /api/support/validate-parameters`, `GET /api/metadata/endpoints` | Bantuan parameter dan registri endpoint portal. |

### Versi & Format Error
- **Versi**: setiap endpoint di atas juga ada di `/api/v1/*` (mis. `POST /api/v1/auth/token`), dengan route, middleware, dan rate limit yang sama. Aplikasi mobile dan klien baru memakai `/api/v1`; `/api/*` tanpa versi tetap dilayani untuk aplikasi web dan isinya sama dengan v1. Perubahan yang memutus kompatibilitas masuk ke versi baru (`/api/v2`), bukan ke v1. Dokumentasi Scramble (`/docs/api`) menampilkan v1.
- URL di dalam payload (`download_url`, `cache_keys` event realtime dan operasi, contoh di bantuan parameter) memakai bentuk tanpa versi (`/api/...`) dan tetap berlaku. Klien v1 mencocokkan `cache_keys` dengan path request-nya tanpa `/v1`.
- **Format error**: setiap error di `/api/*` (semua versi) berupa JSON `{"status": "error", "message": "…"}` (plus `errors` untuk validasi), walaupun klien tidak mengirim `Accept: application/json`. Ini termasuk error yang tidak dijawab controller: endpoint tidak ada (404), metode salah (405), CSRF (419), body melebihi batas server (413), rate limit (429, dengan header `Retry-After`), dan error server (500, tanpa detail di luar mode debug). Pesan bawaan framework yang berbahasa Inggris ("Too Many Attempts.", "Server Error") diganti kata-kata yang sama dengan yang ditampilkan aplikasi web untuk status itu; pesan dari kode aplikasi tetap. Pengecualiannya `GET /api/support/download` yang dibuka sebagai halaman: navigasi tetap mendapat halaman atau redirect.

Halaman web (Blade) ada di `routes/web.php`: `/login`, `/dashboard`, `/class`, `/class/{id}`, `/meeting/{meeting_id}`, `/exam`, `/krs`, `/grades`, `/transcript`, `/finance`, `/announcement`, `/notifications`, `/archive`, `/notebook`, dan lainnya.

## Aplikasi Mobile (Token Bearer & Push)
Aplikasi native (Expo / React Native) memakai API yang sama dengan aplikasi web, tetapi lewat versi `/api/v1` (base URL `<APP_URL>/api/v1`, lihat "Versi & Format Error"), tanpa cookie dan tanpa CSRF.

1. **Login**: `POST /api/v1/auth/token` dengan `username`, `password`, dan opsional `device_name`. Alurnya sama dengan `POST /api/v1/auth/login`, termasuk login offline saat portal mati. Respons berisi `data.token` (personal access token Sanctum berbentuk `<id>|mycic_…`, hanya ditampilkan sekali; server hanya menyimpan hash-nya), `data.token_type: "Bearer"`, dan `data.channel` (channel realtime sesi ini). Simpan token di penyimpanan aman (mis. `expo-secure-store`).
2. **Request lain**: kirim header `Authorization: Bearer <token>` (dan sebaiknya `Accept: application/json`; error tetap JSON tanpanya) ke endpoint `/api/v1/*` mana pun. Request dengan token tidak membaca atau membuat cookie sesi dan tidak butuh token CSRF; request dengan cookie (aplikasi web) tetap wajib CSRF seperti sebelumnya.
3. **Masa berlaku**: token menunjuk sesi Laravel di server yang menyimpan sesi kampus, jadi berlaku selama sesi kampus aktif (batas idle `CAMPUS_SESSION_IDLE_TIMEOUT`, juga `SESSION_LIFETIME`). Setelah itu endpoint menjawab 401 dengan `meta.campus_session_valid: false` dan token otomatis dicabut; aplikasi cukup login lagi. `GET /api/v1/auth/session` dan `POST /api/v1/auth/session/refresh` bekerja dengan token. Login lagi dengan token lama yang masih berlaku menggantinya dengan token baru.
4. **Realtime**: Laravel Echo / pusher-js ke Reverb dengan `authEndpoint: <APP_URL>/api/v1/broadcasting/auth` dan header `Authorization: Bearer <token>`, lalu subscribe ke `private(data.channel)`. Hanya channel sesi token itu yang diizinkan; channel lain dijawab 403.
5. **Push**: setelah login (dan setiap aplikasi dibuka), daftarkan token Expo perangkat: `POST /api/v1/devices/push-token` dengan `token` (`ExponentPushToken[...]`) dan opsional `platform` (`ios`/`android`/`web`). Setiap notifikasi baru dikirim sebagai push lewat job antrean `campus-notifications` ke semua perangkat mahasiswa itu; `data.url` pada push adalah tautan MyCIC notifikasi itu. Token yang ditolak Expo (`DeviceNotRegistered`) dihapus otomatis; yang tidak didaftarkan ulang selama `PORTAL_PUSH_TOKEN_RETENTION_DAYS` hari dipangkas `model:prune`. Asal notifikasinya:
   - **Saat aplikasi dipakai** (sesi dipakai dalam `CAMPUS_SESSION_REFRESH_INTERVAL` terakhir): pemindaian sesi itu mengirim pengingat tenggat dan "absen sekarang" untuk kelas yang absensinya terbuka dan belum tercatat (dibaca langsung dari portal), plus perubahan data (nilai, tugas, pengumuman, KRS, tagihan).
   - **Saat aplikasi tertutup**: setiap 10 menit `portal:remind-away` mengirim pengingat tenggat yang mendekat (aturan dan kunci yang sama, jadi tidak terkirim dua kali) dan "kelas sudah dimulai" dari jadwal hari ini, hanya dari snapshot tersimpan, tanpa menghubungi portal. Tanpa portal, status absensi tidak diketahui, jadi pushnya hanya mengajak membuka MyCIC untuk mengecek dan mengirim absensi. Snapshot yang lebih tua dari `PORTAL_AWAY_REMINDER_MAX_DATA_AGE` detik (default 1 hari) tidak dipakai, supaya tenggat yang sudah dikerjakan tidak diingatkan.
   - **Perubahan data saat aplikasi tertutup** hanya ditemukan oleh sinkronisasi di background (setiap `PORTAL_BACKGROUND_SYNC_INTERVAL` menit, default 180) dan hanya untuk mahasiswa yang mengaktifkannya; sinkronisasi itu juga yang menjaga snapshot pengingat di atas tetap baru. Mahasiswa yang mematikan sinkronisasi di background hanya mendapat pengingat selama snapshot terakhirnya masih dalam batas umur.
6. **Logout**: `POST /api/v1/auth/logout` dengan token, sebaiknya dengan `push_token` perangkat: token bearer dicabut dan perangkat berhenti menerima push, walaupun sesinya sudah berakhir lebih dulu. `DELETE /api/v1/devices/push-token` hanya mematikan push tanpa logout.

Konfigurasi: `SANCTUM_TOKEN_PREFIX` (default `mycic_`; `sanctum.expiration` dibiarkan `null` karena masa berlaku token mengikuti sesi kampus), `EXPO_PUSH_URL`, `EXPO_ACCESS_TOKEN` (hanya bila push security Expo aktif), `EXPO_PUSH_TIMEOUT`, `PORTAL_MOBILE_TOKEN_RETENTION_DAYS`, `PORTAL_PUSH_TOKEN_RETENTION_DAYS`, `PORTAL_AWAY_REMINDERS_ENABLED`, `PORTAL_AWAY_REMINDER_MAX_DATA_AGE`. `SESSION_DRIVER` harus menyimpan sesi di server (`database`, `redis`, `file`), bukan `cookie`. Klien native tidak terkena CORS, jadi tidak ada konfigurasi CORS tambahan; aplikasi berbasis web view atau Expo web yang memanggil API dari origin lain perlu `config/cors.php` sendiri.

### Aplikasi Expo (`mobile/`)
Klien native resmi ada di `mobile/` (Expo SDK 58, Expo Router, TypeScript). Isinya: login token dengan "Tetap masuk" (NIM dan kata sandi disimpan di Keychain/Keystore lewat `expo-secure-store`, lalu dipakai untuk login ulang otomatis saat sesi kampus berakhir setelah idle 2 jam), klien `/api/v1` (`src/api/client.ts`), pendaftaran push Expo, dan tab Beranda (jadwal dan status absensi hari ini), Kelas, Notifikasi, serta Lainnya (profil dan keluar).

```bash
cd mobile && cp .env.example .env.local && npm install
npx expo start      # dev server; buka di development build atau Expo Go
npm test            # unit test (jest-expo)
npm run typecheck && npm run lint
```

- `EXPO_PUBLIC_API_URL` di `.env.local` adalah origin server MyCIC (tanpa `/api`). Simulator di Mac ini bisa memakai `http://mycic.test`; HP fisik butuh alamat yang bisa dijangkaunya.
- Push butuh perangkat fisik dan proyek EAS (`npx eas-cli@latest init`, yang mengisi `extra.eas.projectId` di `app.json`), serta development build atau build EAS. Push remote tidak jalan di Expo Go Android.
- `npx expo start --web` hanya untuk pratinjau tampilan: web tidak punya secure storage (login hilang saat tab ditutup) dan tidak menerima push.
- Bundle identifier iOS/Android dan ikon aplikasi belum diatur; atur di `app.json` sebelum build pertama ke store.

## Checklist Agar Aplikasi Tetap Bagus
### 1. Manajemen Session & Security
- Selalu gunakan `PortalSessionManager` untuk membuat, menyegarkan, dan memvalidasi sesi; jangan menyimpan cookie mentah.
- Pastikan worker menjalankan semua antrean (`campus-sessions`, `campus-refresh`, `campus-notifications`, `default`, `campus-background`): keep-alive sesi, sinkronisasi snapshot, antrean offline, notifikasi, dan sinkronisasi arsip berjalan di sana.
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
- Cron `php artisan schedule:run` wajib di server: scheduler menjalankan queue worker, sinkronisasi arsip, dan pembersihan notifikasi.
- Aktifkan `CAMPUS_REQUEST_DELAY` minimal 1000 ms untuk menghormati server kampus (rate limiting).

### 5. Testing & QA
- Tambahkan feature test untuk tiap endpoint API (`tests/Feature/*`) dengan memanfaatkan factory dan HTTP fakes.
- Gunakan mocking HTTP (mis. `Http::fake()` atau `GuzzleHttp\Handler\MockHandler`) untuk menguji extractor tanpa memanggil server kampus.
- Test tidak pernah menyentuh jaringan: `phpunit.xml` mengarahkan semua URL portal ke `https://portal.invalid` dan mengosongkan `GEMINI_API_KEY`, `tests/TestCase.php` menjawab setiap request klien portal sebagai portal yang tidak terjangkau (dan menggagalkan test yang menuju host asli), serta `Http::preventStrayRequests()` menolak panggilan `Http` yang tidak di-fake. Tulis URL portal di test sebagai `https://portal.invalid/...` atau `config('portal.base_url')`.
- Jalankan `php artisan test --filter=<NamaTest>` pada area yang diubah dan tawarkan opsi menjalankan seluruh suite.
- Pastikan style code mengikuti Laravel Pint: `vendor/bin/pint --dirty`.

## Observability & Debugging
- `storage/logs/laravel.log` – log utama; gunakan `tail -f` atau `php artisan pail` untuk live streaming.
- `php artisan tinker` – debugging session/cache secara langsung (`Cache::get('cic_session_<token>')`).
- Endpoint `/api/support/*` membantu memeriksa struktur HTML terbaru tanpa membuka UI lama.
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
