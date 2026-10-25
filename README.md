# MyCIC

MyCIC adalah aplikasi Laravel yang berdiri di depan portal mahasiswa CIC (`https://my.cic.ac.id`). Ia login ke portal atas nama mahasiswa, menjaga sesi itu tetap hidup, membaca halaman HTML portal, lalu menyajikannya sebagai API JSON (`/api`, `/api/v1`) dan dashboard web. Penggunanya mahasiswa CIC, lewat browser atau aplikasi mobile MyCIC. Dokumen ini untuk developer yang ingin menjalankannya di lokal dan membuat pull request pertama.

## Prasyarat

Wajib:

- PHP 8.4 dan Composer 2. Ekstensi PHP di luar bawaan yang harus aktif: `pcntl`, `posix`, `redis` (phpredis), dan `pdo_sqlite`. `composer install` memeriksa ekstensi lainnya.
- Node.js 22 dan npm.
- Redis yang berjalan di `127.0.0.1:6379`.
- Akses internet ke Packagist, GitHub, dan registry npm saat `composer run setup`.
- Akun mahasiswa CIC dan akses internet ke `https://my.cic.ac.id` untuk login. Tanpa keduanya aplikasi tetap terbuka, tetapi login gagal; pakai [mode demo](#mode-demo-tanpa-akun-cic) untuk melihat aplikasi dengan data contoh.

Tidak perlu dipasang: database. `composer run setup` membuat `database/database.sqlite` sendiri.

Opsional:

- Google Chrome, hanya untuk tes browser (`npm run test:e2e`).
- `GEMINI_API_KEY` di `.env`, hanya untuk fitur notebook.
- Reverb (pembaruan realtime di browser) ikut terpasang lewat Composer dan dijalankan oleh `composer run dev`. Tidak perlu dipasang terpisah.

## Menjalankan di lokal

Jalankan perintah berikut satu per satu.

1. Clone repo.

   ```bash
   git clone https://github.com/apriansyahrs/mycic.git
   ```

2. Masuk ke direktorinya.

   ```bash
   cd mycic
   ```

3. Pastikan Redis menjawab.

   ```bash
   redis-cli ping
   ```

   Jawabannya `PONG`. Kalau tidak, jalankan Redis dulu.

4. Pasang dependensi PHP dan Node, salin `.env.example` ke `.env`, buat `APP_KEY`, jalankan migrasi, dan build aset.

   ```bash
   composer run setup
   ```

5. Jalankan server aplikasi, Horizon, Reverb, log, dan Vite dalam satu terminal.

   ```bash
   composer run dev
   ```

   Port yang dipakai: 8000 untuk server aplikasi, 8080 untuk Reverb, 5173 untuk Vite. Baris ``WARN  Unable to respect the `PHP_CLI_SERVER_WORKERS` environment variable without the `--no-reload` flag. Only creating a single server.`` dari `server` adalah peringatan `php artisan serve` yang boleh diabaikan.

6. Buka http://127.0.0.1:8000 di browser, alamat yang dicetak `server`. Halaman login MyCIC tampil; `APP_URL` di `.env` boleh tetap `http://localhost`. Masuk dengan NIM dan password portal CIC.

`Ctrl+C` di terminal `composer run dev` menghentikan semuanya.

### Mode demo tanpa akun CIC

Developer tanpa akun mahasiswa CIC bisa masuk dan menjelajah MyCIC dengan data contoh. Mode demo menjawab setiap halaman portal dari salinan HTML portal yang sudah disanitasi di `tests/Fixtures/portal` (hasil `php artisan portal:capture-fixtures`), jadi portal kampus `https://my.cic.ac.id` tidak pernah dihubungi: tidak perlu akun, tidak perlu internet ke portal.

1. Di `.env`, ubah `PORTAL_DEMO=false` menjadi `PORTAL_DEMO=true`. Kalau barisnya belum ada (`.env` dibuat sebelum mode demo ada), tambahkan baris `PORTAL_DEMO=true`.

2. Jalankan aplikasi seperti biasa.

   ```bash
   composer run dev
   ```

3. Buka http://127.0.0.1:8000. Di bawah form login ada tombol **Masuk sebagai mahasiswa demo**; klik, dan dashboard terbuka sebagai `Mahasiswa Contoh` (NIM `20990000001`). Form login biasa tetap ada, tetapi tidak bisa dipakai: tidak ada fixture halaman login.

Halaman yang punya data: dashboard, profil, kelas dan detail kelas, pertemuan, pengumuman, keuangan, SKKM, KRS dan perwalian, nilai (KHS), skripsi, ujian, perpustakaan dan repositori skripsi. Detail kelas selalu halaman yang sama (Enterprise Resource Planning) apa pun kelasnya, dan pertemuan yang tidak ada rekamannya membuka rekaman pertemuan pertama di `tests/Fixtures/portal/real`. Aksi tulis (absensi, unggah tugas dan ujian, ganti password, SKKM) dan halaman yang tidak punya fixture (transkrip cetak, kartu ujian, unduh berkas) gagal dengan pesan portal tidak dapat dihubungi; itu memang tidak didukung.

Mode demo hanya berlaku saat `APP_ENV=local`. Di `testing`, `staging`, `production`, dan `e2e` flag ini diabaikan: tidak ada rute `/__demo/session` dan klien portal tetap menghubungi portal sungguhan (`tests/Feature/PortalDemoModeTest.php` menjaganya). Sesi demo adalah milik mahasiswa fiktif `demo-mahasiswa`, jadi arsip dan snapshot yang terbentuk tidak pernah bercampur dengan data mahasiswa sungguhan. Matikan lagi dengan `PORTAL_DEMO=false` sebelum login dengan akun CIC sungguhan. Kodenya ada di `bootstrap/demo.php`, `routes/demo.php`, dan `app/Services/PortalDemoFixtures.php`.

## Menjalankan tes, Pint, dan Larastan

Semua pemeriksaan yang dijalankan CI, sekaligus dan dari yang tercepat: Pint, tes JavaScript, Larastan, lalu tes PHP. Berhenti pada pemeriksaan pertama yang gagal.

```bash
composer run check
```

Perintah di bawah ini menjalankan tiap bagiannya sendiri-sendiri.

Tes PHP tidak pernah menghubungi portal kampus; `phpunit.xml` mengarahkan semua URL portal ke `https://portal.invalid`.

Seluruh tes PHP:

```bash
php artisan test
```

Satu file tes:

```bash
php artisan test tests/Feature/PortalEndpointsCommandTest.php
```

Satu nama tes, class atau method:

```bash
php artisan test --filter=test_an_unknown_endpoint_fails
```

Nama yang tidak ada hanya mencetak `INFO  No tests found.` dan tetap keluar dengan status 0.

Tes JavaScript:

```bash
npm run test:js
```

Tes browser, butuh Google Chrome yang sudah terpasang, dan menjalankan `npm run build` lebih dulu:

```bash
npm run test:e2e
```

Rapikan format file yang berubah:

```bash
vendor/bin/pint --dirty
```

Periksa format tanpa mengubah apa pun, seperti yang dijalankan CI:

```bash
vendor/bin/pint --test
```

Analisis statis dengan Larastan (PHPStan level 5). Error yang sudah ada saat Larastan dipasang tercatat di `phpstan-baseline.neon`, jadi hanya error baru yang dilaporkan:

```bash
composer run analyse
```

Setelah memperbaiki error yang tercatat di baseline, buat ulang baseline-nya. Larastan gagal kalau baseline masih memuat error yang sudah tidak ada:

```bash
composer run analyse:baseline
```

## Cara berkontribusi

1. Buat branch dari `main`. Sebagian besar pesan merge di `git log` memakai nama branch berawalan `feature/` dan `fix/`.

   ```bash
   git switch -c feature/nama-perubahan main
   ```

2. Buat perubahan. Setiap perubahan kode disertai tes baru atau tes yang diperbarui.

3. Sebelum push, jalankan `vendor/bin/pint --dirty` lalu `composer run check`. Kalau ada perubahan UI, pastikan `npm run build` sukses.

4. Tulis pesan commit dengan awalan jenis perubahan seperti commit yang sudah ada: `feat:`, `fix:`, `refactor:`, `chore:`, `docs:`, atau `ci:`, boleh dengan scope, misalnya `fix(scraping):`.

5. Push branch dan buka pull request ke `main` di GitHub.

Workflow `.github/workflows/tests.yml` terdaftar untuk menjalankan PHPUnit, tes JavaScript, Pint, Larastan, dan Playwright pada setiap pull request. TODO: perlu dikonfirmasi: apakah GitHub Actions aktif untuk repo ini; pada 2026-10-23 push ke `main` tidak membuat run apa pun.

## Production

Lihat [docs/deployment.md](docs/deployment.md).
