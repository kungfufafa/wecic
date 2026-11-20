# API MyCIC

Klien mobile (token bearer, push, aplikasi Expo): lihat [mobile.md](mobile.md).

## Daftar Endpoint
Daftar lengkap dan selalu terbaru ada di dokumentasi Scramble (`/docs/api`, beserta skema request/response) dan di `php artisan route:list`. Gambaran singkatnya:

| Kelompok | Endpoint | Catatan |
| --- | --- | --- |
| Auth | `POST /api/auth/login`, `POST /api/auth/token`, `GET /api/auth/session`, `POST /api/auth/session/refresh`, `POST /api/auth/logout` | Membuat, memeriksa, memperpanjang, dan mengakhiri sesi kampus; `auth/token` untuk aplikasi mobile (lihat "Aplikasi Mobile"). |
| Perangkat & realtime | `POST /api/devices/push-token`, `DELETE /api/devices/push-token`, `POST /api/broadcasting/auth` | Token push Expo dan otorisasi channel Reverb untuk klien API. |
| Data kampus | `GET /api/dashboard`, `/api/profile`, `/api/class`, `/api/class/{id}`, `/api/meeting/{meeting_id}`, `/api/finance/*`, `/api/academic/*`, `/api/skkm`, `/api/thesis`, `/api/library`, `/api/announcement` | Snapshot per mahasiswa; data basi dikirim sambil disinkronkan ulang di queue. |
| Aksi | `POST /api/assignment`, `POST /api/attendance`, `POST /api/exam`, `GET /api/operations/{operation}` | Butuh portal online; hasilnya dilacak sebagai operasi. |
| Notifikasi | `GET /api/notifications`, `GET /api/notifications/unread-count`, `POST /api/notifications/read` | Notifikasi tersimpan per mahasiswa. |
| Arsip | `GET /api/archive`, `/api/archive/{dataset}`, `/api/archive/versions/{version}`, `/api/archive/files`, `POST /api/archive/files/sync`, `GET`/`PATCH /api/archive/background-sync`, `DELETE /api/archive` | Riwayat versi, data janggal, berkas materi, dan sinkronisasi di background (opt-in: menyalakannya butuh `password`; mematikannya atau logout menghapus password tersimpan). |
| Notebook | `/api/notebooks/*` | Notebook mata kuliah berbasis Gemini. |
| Dukungan | `GET /api/support/parameter-help`, `GET /api/support/validate-parameters`, `GET /api/metadata/endpoints` | Bantuan parameter dan registri endpoint portal. |

### Versi & Format Error
- **Versi**: setiap endpoint di atas juga ada di `/api/v1/*` (mis. `POST /api/v1/auth/token`), dengan route, middleware, dan rate limit yang sama. Aplikasi mobile dan klien baru memakai `/api/v1`; `/api/*` tanpa versi tetap dilayani untuk aplikasi web dan isinya sama dengan v1. Perubahan yang memutus kompatibilitas masuk ke versi baru (`/api/v2`), bukan ke v1. Dokumentasi Scramble (`/docs/api`) menampilkan v1.
- URL di dalam payload (`download_url`, `cache_keys` event realtime dan operasi, contoh di bantuan parameter) memakai bentuk tanpa versi (`/api/...`) dan tetap berlaku. Klien v1 mencocokkan `cache_keys` dengan path request-nya tanpa `/v1`.
- **Format error**: setiap error di `/api/*` (semua versi) berupa JSON `{"status": "error", "message": "…"}` (plus `errors` untuk validasi), walaupun klien tidak mengirim `Accept: application/json`. Ini termasuk error yang tidak dijawab controller: endpoint tidak ada (404), metode salah (405), CSRF (419), body melebihi batas server (413), rate limit (429, dengan header `Retry-After`), dan error server (500, tanpa detail di luar mode debug). Pesan bawaan framework yang berbahasa Inggris ("Too Many Attempts.", "Server Error") diganti kata-kata yang sama dengan yang ditampilkan aplikasi web untuk status itu; pesan dari kode aplikasi tetap. Pengecualiannya `GET /api/support/download` yang dibuka sebagai halaman: navigasi tetap mendapat halaman atau redirect.

Halaman web (Blade) ada di `routes/web.php`: `/login`, `/dashboard`, `/class`, `/class/{id}`, `/meeting/{meeting_id}`, `/exam`, `/krs`, `/grades`, `/transcript`, `/finance`, `/announcement`, `/notifications`, `/archive`, `/notebook`, dan lainnya.
