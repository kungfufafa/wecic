# Menjalankan MyCIC di production

Panduan untuk server production dan staging. Untuk menjalankan di lokal, lihat [README](../README.md).

## Prasyarat server
- PHP 8.4 dan Composer 2, dengan ekstensi `pcntl`, `posix` (Horizon), `redis` (phpredis, `REDIS_CLIENT=phpredis`), dan driver PDO database yang dipakai.
- Node.js 22 dan npm, hanya saat `npm run build`; runtime tidak memakai Node.
- Redis yang persisten (RDB atau AOF) dengan `maxmemory-policy noeviction`.
- MySQL atau PostgreSQL. SQLite cukup untuk lokal, tetapi beberapa worker Horizon menulis bersamaan.
- Supervisor (atau systemd) untuk `php artisan horizon`, dan cron untuk `php artisan schedule:run` tiap menit.
- Akses internet ke portal kampus (`CAMPUS_*` di `.env`).

## Queue & scheduler
Kerja ke portal kampus yang berjalan di belakang layar memakai queue Redis yang dijalankan [Laravel Horizon](https://laravel.com/docs/horizon). Tanpa Horizon, dashboard tertahan di status "menyinkronkan", sesi portal kedaluwarsa, notifikasi tidak pernah dibuat, dan arsip materi tidak pernah selesai.

Horizon menjalankan tiga antrean, masing-masing dengan supervisor sendiri (`config/horizon.php`). Satu worker hanya menjalankan satu job pada satu waktu dan tidak pernah menyelanya, jadi keep-alive sesi dan kerja yang sedang ditunggu mahasiswa tidak boleh berbagi worker dengan sinkronisasi arsip yang bisa berjalan 30 menit:

| Antrean | Timeout (detik) | Proses | Isi |
| --- | --- | --- | --- |
| `sessions` | 300 | 1 | Keep-alive sesi (`RefreshPortalSessionJob`), menyambungkan sesi offline (`ResumeOfflineSessionJob`), mengakhiri sesi yang idle (`ExpirePortalSessionJob`). Harus selalu cepat. |
| `default` | 600 | 1; di production naik otomatis sampai 4 saat antre | Semua kerja lain yang ditunggu mahasiswa, sesuai urutan masuk: refresh snapshot (`RefreshPortalDataJob` sampai 600 detik), pengiriman antrean offline (`FlushPortalOutboxJob`, `SendPortalOutboxJob`), pemindaian notifikasi (`ScanPortalNotificationsJob`), pengingat (`SendAwayRemindersJob`), push (`SendPortalPushNotificationsJob`), ingest notebook (`IngestNotebookSourceJob`), dan job lain tanpa antrean khusus. |
| `background` | 1800 | 1 | Sinkronisasi arsip (`SyncPortalArchiveJob` sampai 1800 detik), arsip materi & berkas (`ArchiveCourseMaterialsJob`, `ArchivePortalFilesJob`), sinkronisasi notebook mata kuliah (`SyncCourseNotebookJob`). |

Unggahan tugas dan ujian (`ProcessPortalAssignmentUploadJob`, `ProcessPortalExamUploadJob`) tidak pernah masuk queue: proses web menjalankannya tepat setelah respons dikirim, dan pengiriman antrean offline menjalankannya langsung di dalam job-nya.

### Horizon di bawah Supervisor
Di production, jaga `php artisan horizon` tetap hidup dengan Supervisor (atau systemd). Contoh `/etc/supervisor/conf.d/mycic-horizon.conf`:

```ini
[program:mycic-horizon]
process_name=%(program_name)s
command=php /path/ke/mycic/artisan horizon
directory=/path/ke/mycic
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1830
redirect_stderr=true
stdout_logfile=/path/ke/mycic/storage/logs/horizon.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mycic-horizon
```

- Setelah deploy, jalankan `php artisan horizon:terminate`. Karena `fast_termination` aktif, Supervisor langsung menyalakan Horizon baru dengan kode baru, sementara worker lama menyelesaikan job yang sedang berjalan. Tanpa itu, Horizon lama menunggu worker paling lambat (crawl arsip, sampai 30 menit) dan selama itu tidak ada keep-alive sesi yang berjalan.
- `sudo supervisorctl stop mycic-horizon` selesai dalam hitungan detik, tetapi worker yang sedang menjalankan job tetap jalan sampai job-nya selesai (sampai 30 menit untuk crawl arsip). Sebelum maintenance yang butuh semua job berhenti (mematikan Redis atau database, mematikan server), tunggu sampai `pgrep -af 'horizon:[w]ork'` tidak menampilkan apa-apa.
- Antrean `default` berjalan sesuai urutan masuk, jadi saat ramai refresh dashboard bisa menunggu pemindaian notifikasi atau ingest notebook yang masuk lebih dulu. Karena itu di production Horizon menambah prosesnya sampai 4 (`maxProcesses` di `config/horizon.php`). Bila batch `portal:sync-archives` (sampai `PORTAL_BACKGROUND_SYNC_BATCH_SIZE`, default 10, tiap 15 menit) sering belum selesai saat batch berikutnya tiba, naikkan `maxProcesses` supervisor `background` atau kecilkan batch-nya.
- Beberapa proses berarti beberapa job menulis ke database bersamaan. Di SQLite itu bisa sesekali memunculkan galat `database is locked`, jadi production sebaiknya memakai MySQL atau PostgreSQL. Di lokal tiap antrean tetap satu proses.
- Horizon butuh ekstensi PHP `redis` (phpredis, sesuai `REDIS_CLIENT=phpredis`), `pcntl`, dan `posix`; batas waktu job juga hanya berlaku dengan `pcntl`.
- Redis harus menyimpan datanya (RDB atau AOF) dan memakai `maxmemory-policy noeviction`. Job tertunda seperti keep-alive sesi, pemeriksaan sesi idle, dan percobaan menyambungkan sesi offline hanya ada di Redis; kalau Redis kehilangan data saat restart atau membuang key karena memori penuh, rantai itu berhenti dan mahasiswa harus login ulang.
- `REDIS_QUEUE_RETRY_AFTER` (default 1900 detik) harus lebih besar dari `$timeout` job terpanjang (`SyncPortalArchiveJob`: 1800 detik) dan dari timeout setiap supervisor. Kalau lebih kecil, worker kedua mengambil job yang masih berjalan: job dengan `$tries = 1` (sebagian besar, termasuk `SyncPortalArchiveJob`) ditandai gagal padahal masih berjalan, dan job dengan `$tries` lebih besar berjalan dua kali. `tests/Unit/QueueJobConfigurationTest.php` memeriksa batas ini dan antrean setiap job. Akibatnya, job yang tertinggal saat worker mati mendadak (kehabisan memori, `SIGKILL`) baru diambil lagi sekitar 32 menit kemudian: keep-alive sesi (`$tries = 3`) dicoba lagi, job dengan `$tries = 1` ditandai gagal. Hentikan Horizon dengan `horizon:terminate`, bukan `kill -9`.
- Pindah dari queue `database` (sekali, saat pertama memasang Horizon), urutannya:
  1. Siapkan Redis, lalu isi `QUEUE_CONNECTION=redis`, `REDIS_*`, dan `PORTAL_MAINTAINERS` di `.env`.
  2. Deploy kode baru dan muat ulang PHP-FPM. Mulai saat itu job baru masuk ke Redis.
  3. Pasang dan jalankan program `mycic-horizon` di atas, supaya job baru langsung dikerjakan.
  4. Hentikan worker database lama: `sudo supervisorctl stop "mycic-queue-sessions:*" "mycic-queue-default:*" "mycic-queue-background:*"`, hapus program itu dari konfigurasi Supervisor, lalu `sudo supervisorctl reread && sudo supervisorctl update`. Tunggu sampai `pgrep -af 'queue:[w]ork'` tidak menampilkan apa-apa: biasanya beberapa detik, sampai 30 menit bila ada crawl arsip yang sedang berjalan. Langkah ini harus selesai dulu, karena di SQLite worker lama bisa mengambil job yang sedang dipindahkan.
  5. Jalankan `php artisan migrate --force`. Migrasinya menjalankan `php artisan portal:move-old-queue-jobs`, yang memindahkan job yang masih menunggu di tabel `jobs` (termasuk nama antrean lama `campus-*`, tetap dengan waktu jatuh temponya) ke Redis dan mengarahkan job gagal ke Redis supaya `queue:retry` tetap jalan. Perintah ini aman dijalankan lagi kapan saja; ia hanya memindahkan yang masih tersisa.

### Reverb (realtime di browser dan aplikasi, opsional)
`BROADCAST_CONNECTION=reverb` di `.env.example` mengirim event sesi (status sinkronisasi, progres upload, notifikasi, pemberitahuan sesi) ke browser dan ke aplikasi mobile lewat WebSocket. Aplikasi mobile tidak membawa alamat Reverb di build-nya: server memberitahukannya saat login token dan di `GET /api/v1/auth/session` (`data.realtime`, dari `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`), jadi `REVERB_HOST` harus alamat publik yang bisa dijangkau HP, bukan `127.0.0.1`. Reverb **opsional**: `PortalBroadcastService` hanya mencatat warning bila broadcast gagal, browser dan aplikasi tetap polling untuk operasi dan membaca ulang layar saat dibuka, dan aplikasi yang tertutup tetap hanya menerima push. Pilih salah satu di production:

1. **Menjalankan Reverb.** Jaga `php artisan reverb:start` tetap hidup dengan program Supervisor kedua, misalnya `/etc/supervisor/conf.d/mycic-reverb.conf`:

   ```ini
   [program:mycic-reverb]
   process_name=%(program_name)s
   command=php /path/ke/mycic/artisan reverb:start
   directory=/path/ke/mycic
   user=www-data
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   redirect_stderr=true
   stdout_logfile=/path/ke/mycic/storage/logs/reverb.log
   ```

   Server mendengarkan di `REVERB_SERVER_HOST:REVERB_SERVER_PORT` (default `0.0.0.0:8080`). Browser menghubungi `REVERB_HOST`, `REVERB_PORT`, dan `REVERB_SCHEME`, yang ikut terpanggang ke bundel Vite lewat `VITE_REVERB_*`, jadi letakkan Reverb di belakang nginx dengan TLS (lihat [dokumentasi Reverb](https://laravel.com/docs/reverb#production)), isi ketiganya dengan alamat publiknya (mis. `REVERB_HOST=mycic.example.ac.id`, `REVERB_PORT=443`, `REVERB_SCHEME=https`), lalu `npm run build`. Setelah deploy jalankan `php artisan reverb:restart` supaya Supervisor menyalakan Reverb dengan kode baru.

   Pengaturan yang wajib di production:

   - **Hanya lewat nginx.** Isi `REVERB_SERVER_HOST=127.0.0.1` supaya port 8080 tidak terbuka ke internet; nginx yang meneruskan `wss://` ke sana. Tanpa TLS, nama channel (berisi ID sesi Laravel) dan isi event lewat tanpa enkripsi.
   - **Tanpa `--debug`.** Mode debug mencatat setiap pesan, termasuk nama channel dan isi event, ke log. Pakai perintah Supervisor di atas apa adanya.
   - **Batasi origin.** Isi `REVERB_ALLOWED_ORIGINS` dengan host situs (mis. `mycic.example.ac.id`; beberapa host dipisah koma) supaya halaman di situs lain tidak bisa membuka socket ke Reverb. Setelah mengubahnya, pastikan aplikasi mobile masih tersambung (Reverb menolak socket yang origin-nya tidak cocok).
   - **Batasi pesan per socket.** `REVERB_APP_RATE_LIMITING_ENABLED=true` (bawaan 60 pesan per 60 detik, `REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS` dan `REVERB_APP_RATE_LIMIT_DECAY_SECONDS`) menahan klien yang membanjiri server.
   - **Tanda tangan channel sudah dibatasi.** `POST /broadcasting/auth` (browser) dan `POST /api/v1/broadcasting/auth` (aplikasi) melayani paling banyak `CAMPUS_CHANNEL_AUTH_RATE_LIMIT_ATTEMPTS` (bawaan 30) permintaan per menit per sesi kampus, lalu menjawab 429.
2. **Tidak menjalankan Reverb.** Isi `BROADCAST_CONNECTION=null` supaya setiap event tidak mencoba menghubungi server yang tidak ada, kosongkan `REVERB_APP_KEY` (dengannya `VITE_REVERB_APP_KEY` ikut kosong dan browser tidak mencoba WebSocket sama sekali; chip menunjukkan "Pembaruan otomatis belum aktif"; aplikasi mobile menerima `data.realtime: null` dan tidak menyambung), lalu `npm run build`.

Tanpa salah satunya, `BROADCAST_CONNECTION=reverb` dengan Reverb yang tidak berjalan membuat setiap event gagal (tercatat sebagai warning di log) dan browser terus mencoba menyambung.

### Dasbor Horizon & Log Viewer
- `/horizon` – job yang sedang berjalan, yang gagal (bisa di-retry dari sana), waktu tunggu tiap antrean, dan grafik throughput.
- `/log-viewer` – membaca, mencari, mengunduh, dan menghapus log di `storage/logs` dari browser ([opcodesio/log-viewer](https://github.com/opcodesio/log-viewer)).
- Di `APP_ENV=local` keduanya terbuka untuk siapa saja. Di environment lain hanya untuk mahasiswa yang sedang login online ke MyCIC (bukan login offline, dan sesinya belum berakhir) dengan NIM yang tercantum di `PORTAL_MAINTAINERS` (dipisah koma); bila kosong, tidak ada yang bisa masuk.

### Cron scheduler (wajib)
Cron tetap wajib, juga ketika Supervisor dipakai:

```cron
* * * * * cd /path/ke/mycic && php artisan schedule:run >> /dev/null 2>&1
```

Jadwal di `routes/console.php`:

| Jadwal | Perintah | Fungsi |
| --- | --- | --- |
| Setiap 5 menit | `horizon:snapshot` | Menyimpan metrik antrean untuk grafik di dasbor Horizon. |
| Setiap 5 menit | `portal:recover-uploads` | Menutup unggahan yang terhenti di tengah jalan (mis. proses web mati) dan menghapus berkas sementaranya. |
| Setiap 5 menit | `portal:send-outbox` | Antrean offline yang tidak dikirim sesi mana pun (mis. sesi offline sudah habis sebelum portal pulih): yang lewat tenggat atau `portal.outbox.retention_days` ditandai kedaluwarsa dan dinotifikasikan, sisanya dikirim dengan sesi online mahasiswa atau login latar dengan password tersimpan (paling sering tiap 15 menit per mahasiswa). |
| Setiap 10 menit | `portal:remind-away` | Mengantre pengingat tenggat dan "kelas sudah dimulai" untuk mahasiswa yang aplikasinya tertutup, dari snapshot tersimpan saja (`portal.mobile.away_reminders`). |
| Setiap 15 menit | `portal:sync-archives` | Mengantre sinkronisasi arsip di background untuk mahasiswa yang jadwalnya sudah tiba (`portal.background_sync`). |
| Harian | `model:prune` | Menghapus notifikasi yang melewati masa simpan dan menghapus kredensial tersimpan akun offline yang login online terakhirnya melewati `portal.background_sync.credential_days` (default 30 hari). Juga memangkas token bearer aplikasi mobile yang lama tidak dipakai dan token push yang tidak didaftarkan ulang (`portal.mobile`). |

### Arsip & sinkronisasi di background
- Setiap login online menyimpan hash password (login offline saat portal mati) dan, bila sinkronisasi di background aktif, kredensial terenkripsi dengan `APP_KEY`. Mahasiswa bisa mematikannya sendiri di halaman Arsip; password tersimpan langsung dihapus.
- `PORTAL_BACKGROUND_SYNC_ENABLED`, `PORTAL_BACKGROUND_SYNC_INTERVAL` (menit), `PORTAL_BACKGROUND_SYNC_CREDENTIAL_DAYS`, `PORTAL_BACKGROUND_SYNC_BATCH_SIZE`, `PORTAL_BACKGROUND_SYNC_COURSES_ENABLED`, `PORTAL_BACKGROUND_SYNC_COURSES_INTERVAL` mengatur sinkronisasi; `PORTAL_ARCHIVE_ENABLED`, `PORTAL_ARCHIVE_OFFLINE_LOGIN`, `PORTAL_ARCHIVE_FILES_ENABLED` mengatur arsip, login offline, dan arsip berkas materi (lihat `config/portal.php`).
- Mengganti `APP_KEY` tanpa `APP_PREVIOUS_KEYS` membuat kredensial tersimpan tidak bisa dibaca: MyCIC menghapusnya pada sinkronisasi berikutnya dan menyimpannya lagi saat mahasiswa login online.
- Semua data per mahasiswa (versi arsip, berkas materi, akses offline, notifikasi, notebook, token push) disimpan dengan kunci pemilik `student_…`, yaitu HMAC NIM dengan `PORTAL_OWNER_KEY` (kosong = `APP_KEY`, sama seperti sebelumnya). **Sebelum merotasi `APP_KEY`, isi `PORTAL_OWNER_KEY` dengan nilai `APP_KEY` yang sekarang**, supaya kunci pemilik tidak ikut berubah. Bila terlanjur berubah, data dengan kunci lama dipindahkan ke kunci baru saat mahasiswa itu login berikutnya (online, offline, atau sinkronisasi background), asalkan secret lamanya ada di `APP_PREVIOUS_KEYS` atau `PORTAL_PREVIOUS_OWNER_KEYS` (dipisah koma).
- Sinkronisasi di background bersifat opt-in per mahasiswa: password portal hanya disimpan setelah mahasiswa menyalakannya (login dengan `background_sync: true` atau `PATCH /api/v1/archive/background-sync` dengan `password`); mematikannya atau logout langsung menghapusnya. Migrasi `replace_background_sync_opt_out_with_opt_in` menghapus kredensial yang tersimpan sebelum perubahan ini.
- Status setiap sinkronisasi tercatat di akun (`last_background_sync_*`); sinkronisasi yang sedang berjalan bertanda `running` dan dibaca gagal bila melewati batas waktunya.

### Akun reviewer App Store & Google Play
Reviewer toko aplikasi tidak punya akun kampus, jadi server menyediakan satu akun reviewer yang juga berlaku di production:

- Isi `PORTAL_REVIEWER_USERNAME` dan `PORTAL_REVIEWER_PASSWORD` (password panjang dan acak), lalu `php artisan config:cache`. Keduanya kosong = mode reviewer mati. Username yang berupa angka saja (bentuk NIM) diabaikan, supaya tidak pernah menutupi login mahasiswa sungguhan.
- Login dengan username dan password itu, baik dari aplikasi (`POST /api/v1/auth/token`) maupun dari web (`POST /api/auth/login`), membuka sesi mahasiswa contoh (`store-reviewer`). Semua halaman portal sesi itu dijawab dari halaman contoh yang sudah disanitasi di `tests/Fixtures/portal` (tanpa data orang sungguhan), jadi folder itu harus ikut ter-deploy. Portal kampus tidak pernah dihubungi: login, keep-alive, logout, sinkronisasi, arsip, notifikasi, dan notebook sesi reviewer semuanya membaca fixture. Aksi tulis (absensi, unggah tugas/ujian, ganti password) dan unduhan berkas tidak punya fixture dan ditolak seperti portal yang tidak bisa dihubungi.
- Username reviewer dengan password lain ditolak (401) tanpa menghubungi portal. Username lain berjalan seperti biasa.
- Sesi reviewer tidak menyimpan password atau hash-nya (tidak ada login offline dan sinkronisasi di background untuknya). Semua reviewer berbagi data contoh yang sama, termasuk notebook yang mereka buat.
- Cantumkan username dan password ini di App Store Connect (App Review Information > Sign-in required) dan di Google Play Console (App content > App access). Ganti password setelah review selesai bila perlu.

## Konfigurasi `.env` Penting
| Key | Fungsi |
| --- | --- |
| `APP_URL`, `APP_ENV`, `APP_DEBUG` | URL aplikasi & mode debug. Pastikan `APP_URL` sama dengan domain UI Anda agar cookie sesi valid. |
| `DB_*` | Konfigurasi database utama (SQLite default). |
| `CACHE_STORE`, `SESSION_DRIVER` | Gunakan `database` agar sejalan dengan konfigurasi repo & migrasi bawaan. |
| `QUEUE_CONNECTION`, `REDIS_*`, `REDIS_QUEUE_RETRY_AFTER` | `redis`: Horizon menjalankan queue dari Redis. `REDIS_QUEUE_RETRY_AFTER` harus di atas 1800 detik (default 1900). |
| `LOG_STACK`, `LOG_DAILY_DAYS` | `daily` dan `14`: log diputar per hari dan dihapus setelah 14 hari, sesuai masa simpan log yang dijanjikan kebijakan privasi (`/privasi`) dan halaman hapus data (`/hapus-data`). Jangan pakai `single`, yang tidak pernah menghapus log. Tambahkan `sentry_logs` bila perlu (`daily,sentry_logs`). |
| `PORTAL_MAINTAINERS` | NIM (dipisah koma) yang boleh membuka `/horizon` dan `/log-viewer` di luar `APP_ENV=local`. |
| `PORTAL_REVIEWER_USERNAME`, `PORTAL_REVIEWER_PASSWORD` | Akun reviewer App Store / Google Play yang dilayani dari fixture tanpa menghubungi portal (lihat di atas). Kosong = mati. |
| `CAMPUS_BASE_URL`, `CAMPUS_LOGIN_URL`, `CAMPUS_LOGIN_ACTION`, `CAMPUS_DASHBOARD_URL`, `CAMPUS_PROFILE_URL`, `CAMPUS_CLASSES_URL`, `CAMPUS_CLASS_DETAIL_URL` | URL halaman kampus tempat scraping dilakukan. Sesuaikan jika portal berubah. |
| `CAMPUS_SIPANDAI_ASSIGNMENT_URL`, `CAMPUS_SIPANDAI_ATTENDANCE_URL` | Base URL aksi SIPANDAI untuk upload tugas dan validasi kehadiran; tambahkan parameter dinamis (`courses_id`, `id`, `pertemuan`, `course_id`) saat runtime. |
| `CAMPUS_REQUEST_TIMEOUT`, `CAMPUS_REQUEST_DELAY`, `CAMPUS_SESSION_REFRESH_INTERVAL` | Atur timeout HTTP, jeda antar request (ms), serta interval refresh sesi (detik). |
| `CAMPUS_SESSION_PREFIX`, `CAMPUS_AUTO_RELOGIN` | Prefix cache sesi dan flag relogin otomatis menggunakan kredensial terenkripsi. |
| `CAMPUS_SESSION_IDLE_TIMEOUT` | Detik tanpa aktivitas sebelum sesi kampus diakhiri dan keep-alive berhenti (default 7200). |
| `CAMPUS_CACHE_PROFILE_TTL`, `CAMPUS_CACHE_DYNAMIC_TTL`, `CAMPUS_CACHE_HTML_TTL` | TTL cache resource untuk profil, keuangan, dan halaman daftar kelas/jadwal. |

## Observability & Debugging
- `storage/logs/laravel-YYYY-MM-DD.log` – log utama (`LOG_STACK=daily`, disimpan 14 hari); baca di `/log-viewer`, atau gunakan `tail -f` / `php artisan pail` untuk live streaming.
- `php artisan tinker` – debugging session/cache secara langsung (`Cache::get('cic_session_<token>')`).
- Endpoint `/api/support/*` membantu memeriksa struktur HTML terbaru tanpa membuka UI lama.
- Job yang gagal terlihat dan bisa di-retry di `/horizon`; `php artisan queue:failed` & `php artisan queue:retry` juga tetap bisa dipakai.
- Dokumentasi API otomatis tersedia melalui Dedoc Scramble (`/docs/api`) saat `APP_ENV=local` atau akses dibuka.
