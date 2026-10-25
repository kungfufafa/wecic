# Prompt Generate UI MyCIC

Gunakan dokumen ini sebagai prompt komprehensif untuk menghasilkan UI MyCIC yang mendukung fitur dalam codebase Laravel ini. Output yang dihasilkan harus berupa antarmuka aplikasi akademik mahasiswa yang **mobile-first**, responsif ke tablet dan desktop, profesional, simpel, modern, dan konsisten dengan konteks desain proyek.

## Prompt Utama

Anda adalah product designer dan frontend engineer senior. Rancang dan implementasikan UI aplikasi MyCIC, sebuah portal akademik modern untuk mahasiswa CIC yang membungkus portal kampus lama. UI harus terasa akademik, modern, dependable, cepat dipindai, dan membantu mahasiswa menyelesaikan tugas akademik rutin tanpa gesekan.

Bangun UI sebagai aplikasi web **mobile-first** dengan Laravel Blade, **Tailwind CSS v4 + design system custom MyCIC**, Vite, dan vanilla JavaScript. Jangan membuat landing page marketing. Layar pertama setelah login adalah pengalaman aplikasi yang fungsional.

**Stack UI resmi:** Tailwind CSS v4 sebagai satu-satunya fondasi styling. Token desain dan komponen reusable didefinisikan di `resources/css/app.css` (`@theme` + class `mycic-*`). **Jangan menambah class Bootstrap legacy** (`btn`, `card`, `row`, `col-*`, `d-flex`, `navbar`, dll.) — gunakan utility Tailwind dan komponen `mycic-*` saja.

**Arah visual:** terasa seperti aplikasi konsumen modern (Grab, Gojek, Amazon mobile) — konten di depan, navigasi intuitif, touch feedback jelas, brand konsisten — tetapi dengan tone akademik yang tenang dan profesional.

**Prinsip inti desain:** mulai dari layar ponsel terkecil, lalu tingkatkan pengalaman secara progresif di tablet dan desktop. Semua fitur kritis harus berfungsi penuh di mobile; ruang tambahan di layar besar hanya memperkaya tata letak, kepadatan, dan efisiensi — bukan menambah fitur eksklusif desktop.

UI harus mendukung seluruh fitur berikut:

1. Autentikasi dan sesi portal:
   - Login menggunakan username atau NIM dan password portal kampus.
   - Toggle visibilitas password, validasi input, loading state, dan pesan error.
   - Logout dari menu akun.
   - Cek sesi aktif, refresh sesi, redirect ke login saat sesi kedaluwarsa.
   - Ubah password dengan field password lama, password baru, konfirmasi, validasi mismatch, toggle visibilitas, loading, sukses, dan gagal.

2. Dashboard mahasiswa:
   - Ringkasan mata kuliah aktif, IPK, total SKS, dan tagihan.
   - Jadwal kuliah hari ini dengan waktu, kode kelas, nama mata kuliah, dosen, ruangan, materi/pertemuan, status presensi, dan akses detail meeting.
   - Shortcut absensi hari ini dengan input kode, scan QR kamera, upload gambar QR, submit presensi, status sudah tercatat, status belum tersedia, dan error handling.
   - Daftar tugas aktif atau pending assignments dengan deadline, kelas, meeting, tombol ke detail, dan state kosong.

3. Kelas dan jadwal:
   - Daftar kelas gabungan dari SIPANDAI dan jadwal mahasiswa.
   - Ringkasan total kelas, identitas mahasiswa, semester, dan sumber data.
   - Card/list kelas berisi kode mata kuliah, nama, dosen, hari, jam, kelas, ruangan, dan tombol buka kelas.
   - Panel daftar pertemuan yang bisa dibuka/tutup per kelas.
   - Detail kelas berisi ringkasan kelas, dosen, SKS, jumlah materi, jumlah presensi, daftar materi/pertemuan, histori presensi, dan histori tugas.

4. Detail pertemuan:
   - Header pertemuan dengan topik utama, tanggal, jam mulai, dosen, dan status absensi.
   - Materi utama dengan lampiran, tombol buka dan download.
   - Instruksi tugas dengan deadline dan lampiran tugas.
   - Form presensi dengan input kode, scan QR kamera, upload gambar QR, submit, status berhasil, dan status sudah tercatat.
   - Upload tugas PDF dengan catatan opsional.
   - State tugas sudah diupload, tombol lihat file, download, hapus tugas, nilai jika tersedia, dan konfirmasi sebelum aksi destruktif.

5. Ujian:
   - Daftar ujian responsive: card di mobile, tabel di tablet/desktop.
   - Ringkasan total ujian, ujian terjadwal, dan entri yang perlu ditinjau.
   - Detail ujian dengan status, jumlah berkas, kesiapan upload, nama field file, alert portal, persyaratan, link portal, lampiran soal, download soal, dan metadata form.
   - Upload jawaban ujian dengan file input, field override dinamis dari form asli, loading state, sukses, gagal, dan state upload belum tersedia.
   - Rekaman jawaban atau submission memory, termasuk ID hapus, file, tanggal upload, sumber, terakhir terlihat, URL hapus, dan warning jika tombol hapus tidak tersedia di portal.

6. Keuangan:
   - Riwayat pembayaran dengan tanggal, keterangan, nominal, state kosong, dan state gagal.
   - Di mobile, tabel harus berubah menjadi kartu/list yang mudah dibaca tanpa horizontal scroll yang mengganggu.

7. Profil:
   - Data pribadi: nama, NIM, program studi, angkatan, status, tempat/tanggal lahir, jenis kelamin.
   - Kontak: alamat, kota, provinsi, kode pos, telepon, HP, email.
   - Pendidikan: NISN, tingkat pendidikan, asal sekolah, alamat sekolah, kota sekolah, provinsi sekolah.
   - Foto profil jika tersedia.

8. Support, metadata, dan utilitas integrasi:
   - Desain dapat menyediakan layar atau panel developer/support opsional untuk metadata endpoint, parameter help, validasi parameter, dan download file portal.
   - UI harus tetap menjaga bahasa parameter upstream penting seperti `id`, `dosen_id`, `course_id`, `courses_id`, `courseid`, `modulesid`, `pertemuan`, `submission_id`, dan `exam_id` agar tidak membingungkan alur portal.

9. PWA, cache, sinkronisasi, dan offline:
   - Tampilkan chip status online/offline.
   - Tampilkan strip status sinkronisasi dan tombol refresh data server.
   - Dukung pull-to-refresh di mobile.
   - Saat offline, tampilkan data cache terakhir jika tersedia, beri penanda bahwa data bisa stale, dan nonaktifkan aksi yang membutuhkan internet.
   - Gunakan toast/notifikasi untuk success, error, warning, dan info.
   - Pertahankan halaman protected yang bisa dibuka dari cache service worker jika memungkinkan.

---

## Konteks Produk dan Visual

- Pengguna utama: mahasiswa CIC yang mengakses MyCIC di ponsel dan laptop di sela kelas.
- Use case utama: cek jadwal, cek tugas, absensi, upload tugas, upload ujian, cek profil, cek pembayaran, dan menjaga sesi portal tetap aktif.
- Tone UI: akademik, modern, dependable, presisi, tenang, dan operasional.
- Arah visual: light-mode-first dengan canvas mineral terang, panel putih, border cool-gray, royal blue untuk aksi utama, dan gold sebagai aksen terbatas.
- Hindari UI yang terlalu dekoratif, landing page, dark-first treatment, gradient berlebihan, nested cards, heavy shadow, card stack yang ramai, dan elemen penting yang hanya muncul di desktop.
- Hindari tampilan admin dashboard generik — MyCIC harus terasa seperti **aplikasi mahasiswa**, bukan panel back-office.

---

## Referensi Kanonis UI — Halaman Login

**Sumber kebenaran visual:** `resources/views/auth/login.blade.php` + komponen global di `resources/css/app.css`.

Semua halaman baru dan refactor harus mengikuti pola yang sama dengan form login — bukan membuat variasi field/button/heading terpisah. Class khusus `mycic-auth-*` hanya untuk layout split-screen login (showcase biru, panel form); seluruh primitif interaktif memakai class global `mycic-*`.

### Token & Radius

| Token | Nilai | Pemakaian |
| --- | --- | --- |
| `--radius-control` | `0.375rem` (`rounded-md`) | Input, button, toggle |
| `--radius-card` | `0.5rem` | Card, alert, metric |
| `--shadow-brand` | `0 1px 2px rgb(2 67 132 / 20%)` | Tombol primary/destructive |
| Font | Inter 400/500/600 via Bunny Fonts | Semua layout (`app` + `guest`) |
| Brand wordmark | `mycic-brand-wordmark` | Logo `myCIC` — warna `text-brand`, bukan `text-ink` |

### Pola Typography

| Elemen | Class | Contoh login |
| --- | --- | --- |
| Judul halaman | `mycic-page-heading` | "Masuk ke myCIC" |
| Subjudul | `mycic-page-subheading` | Deskripsi singkat di bawah judul |
| Judul section (dalam halaman) | `mycic-section-title` | "Jadwal hari ini" di dashboard |
| Teks legal/footer form | `mycic-legal-text` | Kebijakan di bawah form login |

### Pola Form (wajib di semua form)

```html
<form class="mycic-form">
  <div class="mycic-field">
    <label for="field-id" class="mycic-label">Label field</label>
    <div class="mycic-input-wrap">
      <!-- opsional: icon kiri -->
      <svg class="mycic-input-icon" ...></svg>
      <input id="field-id" class="mycic-input mycic-input-with-icon" />
      <!-- opsional: toggle password -->
      <button type="button" class="mycic-input-toggle" aria-label="Tampilkan password"></button>
    </div>
    <p class="mycic-field-hint">Hint opsional di bawah field.</p>
  </div>

  <button type="submit" class="mycic-btn-primary mycic-btn-block min-h-12">CTA utama</button>
</form>
```

**Aturan form dari login:**
- Label: `text-ink` + `font-medium` — **bukan** muted.
- Input: `bg-surface/60`, fokus → `bg-panel` + ring brand halus.
- Placeholder hanya jika membantu format (contoh NIM `12345678`), jangan duplikasi label.
- Hint field: `mycic-field-hint` untuk konteks tambahan.
- CTA submit: `mycic-btn-primary` + `mycic-btn-block` + `min-h-12` pada form utama.
- Toggle password: `mycic-input-toggle` di dalam `mycic-input-wrap`, input pakai `mycic-input-with-toggle`.

### Pola Button

| Varian | Class | Catatan |
| --- | --- | --- |
| Primary | `mycic-btn-primary` | `font-semibold`, `bg-brand`, `--shadow-brand` |
| Secondary | `mycic-btn-secondary` | Border + panel |
| Full-width CTA | `mycic-btn-primary mycic-btn-block` | Form mobile & sticky action |
| Ghost / destructive | `mycic-btn-ghost` / `mycic-btn-destructive` | Aksi sekunder / hapus |

**Jangan** membuat tombol primary dengan `bg-ink` atau tanpa shadow brand.

### Checklist Konsistensi per Halaman Baru

1. Font Inter dimuat di layout.
2. Wordmark/header brand memakai `mycic-brand-wordmark`.
3. Judul halaman memakai `mycic-page-heading` + `mycic-page-subheading`.
4. Form memakai `mycic-form` → `mycic-field` → `mycic-label` + `mycic-input`.
5. Satu CTA primary dominan per section (`mycic-btn-primary`).
6. Radius kontrol `rounded-md` — hindari `rounded-xl` atau pill button.
7. Class `mycic-auth-*` **hanya** untuk shell login split-screen.

---

## Stack Teknologi UI

| Layer | Teknologi | Catatan |
| --- | --- | --- |
| CSS | Tailwind CSS v4 | Dimuat via Vite: `resources/css/app.css` |
| Design tokens | `@theme` di `app.css` | Warna, font, radius, shadow, easing |
| Komponen | Class `mycic-*` di `@layer components` | Button, card, input, badge, nav, dll. |
| Template | Laravel Blade | Named route, partials, stacks |
| JS | Vanilla JS (`resources/js/app.js`) | Toast, modal, QR scanner, sync, cache |
| Bundler | Vite 7 | `npm run dev` / `npm run build` |

**Larangan:**
- Jangan campur Tailwind dengan framework CSS lain pada elemen yang sama.
- Jangan load framework CSS/UI kit baru (Bootstrap, DaisyUI, dll.) tanpa persetujuan.
- Jangan pakai inline style kecuali untuk nilai dinamis dari JS.

**Implementasi interaktif:**
- Modal QR, dropdown akun, dan toast diimplementasikan dengan vanilla JS + class Tailwind.
- Preflight Tailwind aktif penuh via `@import 'tailwindcss'`.

---

## Sistem Desain Mobile-First

### Filosofi

1. **Mobile-first CSS:** Tulis gaya dasar untuk layar kecil, lalu tambahkan enhancement dengan breakpoint `md:` (tablet) dan `lg:` (desktop). Jangan menulis desktop dulu lalu override ke mobile.
2. **Progressive enhancement:** Fitur dan layout yang memanfaatkan ruang horizontal hanya ditambahkan saat viewport cukup lebar.
3. **Content-first:** Prioritaskan informasi yang paling sering dibutuhkan mahasiswa di bagian atas dan area yang mudah dijangkau.
4. **Touch-first, pointer-aware:** Semua interaksi harus berfungsi tanpa hover; hover hanya memperkaya pengalaman di desktop.

### Breakpoint Resmi

Gunakan breakpoint Tailwind v4 berikut secara konsisten di seluruh halaman:

| Token | Min-width | Rentang tipikal | Perilaku UI |
| --- | --- | --- | --- |
| Base (default) | — | `320px – 767px` | Single column, bottom navigation, card/list, tombol aksi full-width jika perlu, touch-first. |
| `sm:` | `640px` | `640px – 767px` | Card lebih lapang, form pendek boleh dua kolom, grid 2 kolom untuk metric ringkas. |
| `md:` (tablet) | `768px` | `768px – 1023px` | Dua kolom terbatas, tabel untuk data lebar, top navigation aktif, sidebar aksi opsional. |
| `lg:` (desktop) | `1024px` | `1024px – 1279px` | Layout 2–3 kolom, panel aksi permanen, tabel penuh, header/navigation permanen. |
| `xl:` | `1280px` | `1280px+` | Max-width konten `1200px–1320px`, sentralkan layout, jangan biarkan konten melebar tanpa batas. |

Contoh pola Tailwind yang benar:

```html
<!-- Mobile: 1 kolom, Desktop: 3 kolom -->
<div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">

<!-- Mobile: bottom nav visible, Desktop: top nav visible -->
<nav class="fixed inset-x-0 bottom-0 md:static md:top-0">

<!-- Mobile: full-width button, Desktop: auto width -->
<button class="w-full md:w-auto min-h-11">
```

### Token Warna (Tailwind `@theme`)

Token didefinisikan di `resources/css/app.css` dan dipakai sebagai utility Tailwind (`bg-brand`, `text-ink-muted`, dll.):

| Token CSS | Utility Tailwind | Nilai | Penggunaan |
| --- | --- | --- | --- |
| `--color-canvas` | `bg-canvas` | `#F6F8FB` | Background halaman |
| `--color-panel` | `bg-panel` | `#FFFFFF` | Card, modal, panel konten |
| `--color-surface` | `bg-surface` | `#F3F6FA` | Hover ringan, skeleton |
| `--color-surface-tint` | `bg-surface-tint` | `#EDF4FB` | Highlight info, chip aktif |
| `--color-ink` | `text-ink` | `#101828` | Heading, label utama |
| `--color-ink-muted` | `text-ink-muted` | `#475467` | Deskripsi, metadata |
| `--color-ink-subtle` | `text-ink-subtle` | `#667085` | Caption, placeholder |
| `--color-brand` | `bg-brand`, `text-brand` | `#0352A1` | CTA utama, nav aktif |
| `--color-brand-hover` | `bg-brand-hover` | `#2167AC` | Hover tombol primary |
| `--color-brand-pressed` | `bg-brand-pressed` | `#024384` | Pressed state |
| `--color-accent` | `bg-accent` | `#FFC300` | Highlight terbatas |
| `--color-accent-soft` | `bg-accent-soft` | `#FFF2BF` | Background perhatian |
| `--color-success` | `text-success` | `#16A34A` | Status berhasil |
| `--color-border` | `border-border` | `#D6DEE8` | Border card, input |
| `--color-border-light` | `border-border-light` | `#E6EBF2` | Divider halus |

**Aturan palet:**
- Primary blue hanya untuk aksi utama dan navigasi aktif — jangan jadikan warna dominan seluruh layar.
- Gold sebagai aksen sekunder, bukan warna tombol utama.
- Hindari menambah warna di luar token tanpa alasan fungsional (status, alert).
- Jaga kontras teks terhadap background minimal **4.5:1** (WCAG AA) untuk body, **3:1** untuk teks besar (≥18px bold / ≥24px regular).

### Skala Spasi (Whitespace)

Gunakan skala spacing konsisten untuk menciptakan ritme visual yang tenang:

| Token | Nilai | Penggunaan |
| --- | --- | --- |
| `xs` | `4px` / `gap-1` | Jarak icon–label dalam chip |
| `sm` | `8px` / `gap-2` | Padding badge, jarak inline kecil |
| `md` | `12px` / `gap-3` | Padding tombol kecil, jarak form field |
| `base` | `16px` / `gap-4` | Padding card mobile, jarak antar elemen dalam section |
| `lg` | `24px` / `gap-6` | Padding section, jarak antar card |
| `xl` | `32px` / `gap-8` | Margin antar section besar |
| `2xl` | `48px` / `gap-12` | Padding halaman desktop, breathing room hero ringkas |

**Aturan whitespace:**
- Gunakan `gap` pada flex/grid, bukan margin berulang antar anak.
- Beri ruang napas di mobile — jangan memadatkan UI demi menampung lebih banyak data.
- Section baru selalu dipisahkan dengan spacing yang konsisten, bukan hanya border.
- Padding horizontal halaman: `16px` mobile, `24px` tablet, `32px` desktop.

### Skala Radius dan Elevation

| Elemen | Radius | Shadow |
| --- | --- | --- |
| Button, input | `8px` / `rounded-lg` | Tidak ada |
| Card, panel | `12px` / `rounded-xl` | `shadow-sm` atau border saja |
| Modal, dropdown | `16px` / `rounded-2xl` | `shadow-md` |
| Chip, badge | `9999px` / `rounded-full` | Tidak ada |

Hindari `shadow-lg` atau nested shadow — gunakan border dan whitespace untuk hierarki.

---

## Tipografi Responsif dan Scalable

### Font

- Gunakan **Inter Variable** sebagai font utama jika mengikuti design system proyek.
- Weight: `400` body, `510` emphasis UI, `590` strong emphasis/heading.
- Aktifkan `font-feature-settings` untuk angka tabular pada data numerik (IPK, SKS, nominal).

### Skala Tipografi Fluid

Gunakan ukuran yang menyesuaikan viewport agar tetap terbaca di semua perangkat:

| Level | Mobile (base) | Tablet (`md:`) | Desktop (`lg:`) | Line-height | Penggunaan |
| --- | --- | --- | --- | --- | --- |
| Display | `24px` | `28px` | `32px` | `1.2` | Judul halaman utama |
| Heading 1 | `20px` | `22px` | `24px` | `1.25` | Judul section |
| Heading 2 | `18px` | `18px` | `20px` | `1.3` | Sub-judul, card title |
| Body | `16px` | `16px` | `16px` | `1.5` | Teks utama, form label |
| Body small | `14px` | `14px` | `14px` | `1.45` | Deskripsi sekunder |
| Caption | `12px` | `12px` | `13px` | `1.4` | Timestamp, metadata — **bukan** satu-satunya tempat info kritis |

Implementasi fluid dengan `clamp()` jika menulis CSS custom:

```css
.page-title {
  font-size: clamp(1.25rem, 1rem + 1.5vw, 2rem);
  line-height: 1.25;
}
```

**Aturan tipografi:**
- Body text **minimal 16px** di semua perangkat — jangan mengecilkan teks utama di mobile.
- Batasi panjang baris teks utama **65–75 karakter** (`max-w-prose` atau `max-w-2xl`).
- Jangan gunakan ukuran heading hero pada panel dashboard atau card kecil.
- Teks panjang (nama mata kuliah, URL, nama file) harus `break-words` / `truncate` dengan tooltip di desktop.
- Hierarki cukup dari perbedaan size + weight + color — hindari terlalu banyak level heading.

---

## Prinsip Desain Modern (Simpel & Profesional)

### 1. Whitespace yang Cukup

- Setiap section punya breathing room — UI akademik harus terasa tenang, bukan padat seperti spreadsheet.
- Kelompokkan informasi terkait dalam satu card/panel; pisahkan grup berbeda dengan gap, bukan menumpuk border.
- Jangan isi seluruh lebar layar dengan konten — gunakan `max-w-7xl mx-auto` di desktop.

### 2. Hierarki Visual yang Jelas

Urutan perhatian pengguna di setiap halaman:

1. **Status/aksi kritis** — deadline, status absensi, tombol submit, alert error.
2. **Identitas konteks** — nama mata kuliah, tanggal pertemuan, judul ujian.
3. **Data pendukung** — dosen, ruangan, metadata, histori.
4. **Aksi sekunder** — download, lihat detail, collapse panel.

Gunakan kombinasi: ukuran teks → weight → warna → posisi untuk menegaskan hierarki. Satu halaman hanya punya **satu** CTA primary yang dominan.

### 3. Navigasi Intuitif

| Viewport | Pola navigasi |
| --- | --- |
| Mobile (`< 768px`) | Bottom navigation tetap: Dashboard, Kelas, Ujian, Profil, Keuangan. Header ringkas dengan judul halaman + menu akun. |
| Tablet (`768px – 1023px`) | Top navigation horizontal dengan label. Bottom nav boleh disembunyikan. Breadcrumb opsional di halaman detail. |
| Desktop (`≥ 1024px`) | Top navigation permanen + menu akun dropdown. Sidebar opsional untuk halaman detail. Bottom nav tidak ditampilkan. |

**Aturan navigasi:**
- Active state jelas: warna primary blue + indikator (border bawah atau background tinted).
- Maksimal 5 item di bottom nav — jangan tambah menu tersembunyi untuk fitur utama.
- Menu akun selalu menyediakan: Profil, Ubah Password, Keluar.
- Tombol back/to-parent harus tersedia di halaman detail (meeting, exam, class).
- Jangan mengubah posisi navigasi antar breakpoint tanpa transisi yang konsisten.

### 4. Komponen UI Konsisten (`mycic-*`)

Gunakan class komponen dari `resources/css/app.css`. Jangan buat variasi baru tanpa menambahkannya ke design system.

| Komponen | Class | Contoh |
| --- | --- | --- |
| Brand wordmark | `mycic-brand-wordmark` | Logo myCIC di header |
| Judul halaman | `mycic-page-heading` + `mycic-page-subheading` | Login, ubah password |
| Container halaman | `mycic-container` | Wrapper konten utama |
| Card | `mycic-card` | Panel konten statis |
| Card tap | `mycic-card-interactive` | List kelas, item ujian |
| Form wrapper | `mycic-form` | `gap-5` antar field |
| Field group | `mycic-field` + `mycic-label` + `mycic-field-hint` | Semua form |
| Input + icon/toggle | `mycic-input-wrap` + `mycic-input` + modifier | Login, password |
| Button primary | `mycic-btn-primary` | Submit, aksi utama (brand + shadow) |
| Button secondary | `mycic-btn-secondary` | Download, batal |
| Button ghost | `mycic-btn-ghost` | Link-style action |
| Button destructive | `mycic-btn-destructive` | Hapus (dengan konfirmasi) |
| Button full-width | `mycic-btn-primary mycic-btn-block min-h-12` | CTA form utama |
| Badge | `mycic-badge-success` / `-warning` / `-danger` / `-info` / `-neutral` | Status |
| Chip | `mycic-chip` | Online/offline, tag |
| Alert | `mycic-alert-info` / `-warning` / `-danger` / `-success` | Pesan inline |
| Empty state | `mycic-empty` | Tidak ada data |
| Skeleton | `mycic-skeleton` | Loading placeholder |
| Page header | `mycic-page-header` | Header sticky |
| Bottom nav | `mycic-bottom-nav` + item classes | Navigasi mobile |
| Sticky action | `mycic-sticky-action` | Submit di detail meeting/exam |
| Metric card | `mycic-metric` | IPK, SKS, tagihan di dashboard |

Contoh markup:

```html
<div class="mycic-page">
  <header class="mycic-page-header">
    <div class="mycic-container flex h-14 items-center justify-between">
      <a href="#" class="mycic-brand-wordmark">myCIC</a>
    </div>
  </header>

  <main class="mycic-container space-y-4 py-4">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="mycic-metric">
        <span class="mycic-metric-value">3.45</span>
        <span class="mycic-metric-label">IPK</span>
      </div>
    </div>

    <div class="mycic-card-interactive">
      <h2 class="text-subtitle text-ink">Algoritma dan Pemrograman</h2>
      <p class="mt-1 text-sm text-ink-muted">Senin, 08:00 · Lab 2</p>
    </div>
  </main>

  <nav class="mycic-bottom-nav">
    <div class="flex">
      <a href="#" class="mycic-bottom-nav-item mycic-bottom-nav-item-active">Dashboard</a>
    </div>
  </nav>
</div>
```

### 5. Pola Consumer App (Grab / Gojek / Amazon)

Terapkan pola berikut agar UI terasa seperti aplikasi modern, bukan website admin:

| Pola | Implementasi |
| --- | --- |
| Bottom navigation | `mycic-bottom-nav` di mobile, 5 item maks, icon + label kecil |
| Content-first | Header tipis, data utama langsung terlihat tanpa scroll |
| Card list | `mycic-card-interactive` dengan tap feedback, bukan tabel di mobile |
| Sticky CTA | `mycic-sticky-action` untuk submit absensi/upload di layar detail |
| Metric ringkas | `mycic-metric` grid 2 kolom mobile, 4 kolom desktop |
| Pull-to-refresh | Strip indikator di atas konten (sudah ada di layout) |
| Safe area | `env(safe-area-inset-bottom)` pada bottom nav dan sticky action |
| Backdrop blur | Header dan bottom nav: `bg-panel/95 backdrop-blur-sm` |
| Satu CTA dominan | Satu `mycic-btn-primary` paling menonjol per section |
| List row | `mycic-list-row` untuk data key-value di profil dan detail |

---

## Spesifikasi Layout per Breakpoint

### Mobile (base – 767px)

- Satu kolom untuk dashboard, kelas, meeting, ujian, finance, profile, dan password.
- Bottom navigation untuk menu utama.
- Header mobile ringkas — maksimal 56px tinggi, tidak memakan ruang vertikal berlebihan.
- Primary action mudah dijangkau ibu jari (zona bawah layar atau sticky footer).
- Touch target minimal **44×44px** untuk semua elemen interaktif.
- Form upload, scan QR, submit absensi, dan submit tugas jelas, besar, tidak bergantung hover.
- Tabel berubah menjadi card/list dengan label per field.
- Hindari horizontal scroll kecuali konten teknis yang benar-benar tidak bisa dipecah.
- Sticky action area pada detail meeting atau exam jika aksi utama sering dipakai.
- Padding bottom konten: cukup untuk bottom nav (min. `80px`).

### Tablet (768px – 1023px)

- Layout dua kolom untuk detail kelas, meeting, exam, dan profile.
- Konten utama kiri (60–65%), panel aksi/status kanan (35–40%).
- Tabel untuk daftar ujian dan pembayaran jika tetap terbaca tanpa scroll horizontal.
- Top navigation menggantikan bottom nav.
- Grid metric dashboard: 2×2 atau 4 kolom tergantung konten.
- Touch target tetap minimal 44px meskipun layout lebih padat.
- Form bisa memakai dua kolom untuk field pendek (tanggal + waktu).

### Desktop (1024px+)

- Grid metric dashboard: 4 kolom.
- Dua panel sejajar untuk jadwal/tugas di dashboard.
- Detail kelas/meeting/exam: konten utama 8 kolom, aksi 4 kolom (grid 12).
- Navigasi atas permanen; bottom nav disembunyikan.
- Tabel penuh dengan header sticky untuk data panjang.
- Max-width konten `1200px–1320px`, centered.
- Manfaatkan hover state sebagai enhancement (bukan requirement).
- Sidebar aksi boleh sticky saat scroll konten panjang.

### Grid Responsif

Gunakan pola grid yang menyesuaikan otomatis:

```html
<!-- Kartu berulang -->
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">

<!-- Detail halaman -->
<div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
  <main class="lg:col-span-8">...</main>
  <aside class="lg:col-span-4 lg:sticky lg:top-20">...</aside>
</div>

<!-- Auto-fit untuk card fleksibel -->
<div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))">
```

---

## Spesifikasi Interaksi Responsif

### Animasi dan Transisi

Gunakan animasi halus yang meningkatkan feedback tanpa mengganggu:

| Interaksi | Durasi | Easing | Properti |
| --- | --- | --- | --- |
| Hover/focus button | `150ms` | `ease-out` | `background-color`, `border-color` |
| Active/pressed button | `100ms` | `ease-in` | `transform: scale(0.98)` |
| Card tap (mobile) | `100ms` | `ease-in-out` | `background-color` ke secondary surface |
| Modal open/close | `200ms` | `ease-out` | `opacity`, `transform` |
| Toast enter/exit | `250ms` | `ease-out` | `opacity`, `translateY` |
| Skeleton pulse | `1.5s` | `ease-in-out` | `opacity` infinite |
| Collapse/expand panel | `200ms` | `ease-out` | `height` atau `grid-template-rows` |
| Page section fade-in | `300ms` | `ease-out` | `opacity` (hanya saat lazy load) |

**Aturan animasi:**
- Hormati `prefers-reduced-motion: reduce` — nonaktifkan animasi dekoratif, pertahankan feedback instan.
- Jangan animasikan layout shift yang mengganggu (hindari animasi width/height pada konten utama).
- Loading state gunakan skeleton, bukan spinner fullscreen kecuali aksi submit.

### Feedback Sentuhan (Touch Feedback)

Semua elemen interaktif di mobile harus memberi respons visual saat disentuh:

```html
<button class="min-h-11 min-w-11 transition-colors duration-150
               active:scale-[0.98] active:bg-[#024384]
               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0352A1]">
```

| Elemen | Feedback sentuhan |
| --- | --- |
| Button primary | Background gelap (`#024384`) + scale 0.98 saat active |
| Button secondary | Background secondary surface saat active |
| Card clickable | Background secondary surface saat active, cursor pointer |
| List item | Highlight baris saat tap |
| Tab/nav item | Background tinted + warna primary |
| Toggle/checkbox | Ripple atau background change, area tap ≥ 44px |
| Icon button | Background circle saat active, min 44×44px |

**Aturan touch:**
- Jangan andalkan `:hover` sebagai satu-satunya indikator interaktif.
- Beri jarak antar tombol minimal 8px agar tidak salah tap.
- Sticky action bar di bagian bawah untuk aksi submit di halaman form panjang.
- Pull-to-refresh di mobile dengan indikator visual saat gesture aktif.

### State Interaksi Komponen

| State | Perilaku visual |
| --- | --- |
| Default | Warna token normal |
| Hover (desktop) | Background/border sedikit lebih gelap |
| Focus | Ring 2px `#0352A1`, offset 2px |
| Active/pressed | Scale 0.98 + background pressed |
| Disabled | Opacity 0.5, `cursor-not-allowed`, tidak ada active feedback |
| Loading | Spinner inline di tombol, teks berubah, tombol disabled |
| Error | Border merah + pesan inline di bawah field |

---

## Standar Aksesibilitas (WCAG 2.1 AA)

UI harus memenuhi standar aksesibilitas berikut di **semua perangkat**:

### Perceivable (Dapat Dirasakan)

| Kriteria | Standar | Implementasi |
| --- | --- | --- |
| 1.1.1 Non-text Content | Semua icon/gambar punya alternatif | `aria-label` pada icon button, `alt` pada gambar profil |
| 1.3.1 Info and Relationships | Struktur semantik | Gunakan `<main>`, `<nav>`, `<section>`, heading berurutan (h1→h2→h3) |
| 1.4.3 Contrast (Minimum) | Rasio 4.5:1 teks normal, 3:1 teks besar | Verifikasi semua kombinasi warna token |
| 1.4.4 Resize Text | Teks bisa diperbesar 200% tanpa kehilangan fungsi | Gunakan unit relatif (`rem`), jangan fixed height pada container teks |
| 1.4.10 Reflow | Konten reflow di 320px lebar tanpa scroll horizontal | Kecuali tabel data yang sudah diubah ke card di mobile |
| 1.4.11 Non-text Contrast | UI component contrast 3:1 | Border input, focus ring, icon button |

### Operable (Dapat Dioperasikan)

| Kriteria | Standar | Implementasi |
| --- | --- | --- |
| 2.1.1 Keyboard | Semua fungsi via keyboard | Tab order logis, Enter/Space untuk activate |
| 2.4.3 Focus Order | Urutan fokus logis | DOM order = visual order |
| 2.4.7 Focus Visible | Fokus terlihat jelas | Ring 2px primary blue, jangan `outline: none` tanpa pengganti |
| 2.5.5 Target Size | Min 44×44px touch target | Semua button, link, checkbox, nav item |
| 2.5.2 Pointer Cancellation | Up-event trigger aksi | Gunakan `click`, bukan `mousedown` untuk aksi |

### Understandable (Dapat Dipahami)

| Kriteria | Standar | Implementasi |
| --- | --- | --- |
| 3.3.1 Error Identification | Error diidentifikasi jelas | Pesan error inline dalam Bahasa Indonesia, dekat field |
| 3.3.2 Labels or Instructions | Label eksplisit | Setiap input punya `<label>`, placeholder bukan pengganti label |
| 3.2.4 Consistent Identification | Komponen konsisten | Tombol submit selalu di posisi sama, icon yang sama = fungsi sama |

### Robust (Robust)

| Kriteria | Standar | Implementasi |
| --- | --- | --- |
| 4.1.2 Name, Role, Value | ARIA benar | `role="alert"` untuk toast error, `aria-expanded` untuk collapse, `aria-current="page"` untuk nav aktif |
| 4.1.3 Status Messages | Perubahan status diumumkan | `aria-live="polite"` untuk toast, `role="status"` untuk loading |

### Checklist Aksesibilitas per Komponen

- [ ] Login form: label, error, password toggle dengan `aria-label`
- [ ] Bottom/top nav: `aria-current="page"`, label teks tidak hanya icon
- [ ] QR modal: fokus trap, tutup dengan Escape, `aria-modal="true"`
- [ ] File upload: label, format yang diterima, error file type
- [ ] Toast: `role="alert"` (error) atau `role="status"` (success/info)
- [ ] Tabel desktop: `<th scope="col">`, responsive card di mobile tetap punya label
- [ ] Skeleton: `aria-busy="true"` pada container loading
- [ ] Offline banner: `role="status"`, tidak menutupi elemen interaktif

---

## Konsistensi Merek di Seluruh Platform

### Identitas Visual

- Logo MyCIC dan nama aplikasi konsisten di header/login.
- Theme color PWA: `#0352A1` (selaraskan dengan primary blue).
- Bahasa UI: Bahasa Indonesia formal-santai, sesuai konteks mahasiswa.
- Ilustrasi/icon: gunakan SVG inline outline, stroke width 2, ukuran 20–24px, `aria-hidden="true"` jika dekoratif.

### Pola yang Harus Konsisten di Semua Halaman

| Aspek | Standar |
| --- | --- |
| Header halaman | Judul kiri, aksi kanan, tinggi konsisten |
| Status badge | Warna semantik sama di semua halaman |
| Tombol submit | Posisi bawah form atau sticky footer |
| Empty state | Format: icon + judul + deskripsi + CTA opsional |
| Error state | Alert merah inline + tombol retry jika relevan |
| Loading | Skeleton di area konten, bukan overlay fullscreen |
| Toast | Posisi kanan atas, auto-dismiss 4–6 detik |
| Card data | Label kecil di atas, nilai besar di bawah |

### Larangan Konsistensi

- Jangan gunakan pola button berbeda untuk aksi yang sama (misal: "Simpan" hijau di satu halaman, biru di halainya).
- Jangan tampilkan informasi yang sama dalam format berbeda tanpa alasan (misal: status absensi sebagai badge di satu tempat, teks biasa di tempat lain).
- Jangan mengubah urutan field form antar halaman yang serupa.

---

## Persyaratan Responsivitas Detail

### Layout

- Semua halaman harus valid di: `320px`, `375px`, `390px`, `430px`, `768px`, `1024px`, `1280px`, `1440px`.
- Gunakan `gap` untuk jarak antar elemen, bukan margin berulang.
- Gunakan `minmax()` dan `auto-fit`/`auto-fill` untuk grid kartu.
- Gunakan `max-w-7xl mx-auto px-4 md:px-6 lg:px-8` pada kontainer halaman.

### Konten

- Progressive disclosure untuk metadata teknis, daftar pertemuan, form override, dan detail portal.
- Data penting (status absensi, deadline, upload readiness, session status) terlihat tanpa membuka menu tersembunyi.
- Empty state membantu pengguna memahami kondisi data, bukan hanya menampilkan "kosong".

### Tabel

- Ujian dan keuangan: card di mobile, tabel di `md:` ke atas.
- Card mobile menampilkan label per field: Kode, Mata kuliah, Jadwal, Status, Aksi, Tanggal, Keterangan, Jumlah.
- Tabel desktop: header sticky, zebra striping opsional, kolom aksi tetap visible.

### Form dan Aksi

- Field login, password, QR code, upload tugas, upload ujian usable di 320px.
- Tombol submit punya loading state dan disabled state saat request berjalan.
- Aksi offline-required disabled saat offline dengan alasan singkat.
- Aksi destruktif (hapus tugas) wajib konfirmasi modal.

### QR dan Media

- Modal scan QR cocok di 320px tanpa overflow.
- Video scanner aspect ratio 1:1, status terbaca jelas.
- Upload gambar QR sebagai fallback saat kamera tidak tersedia.

### Offline dan Cache

- Tampilkan data cache terakhir dengan label jujur ("Data terakhir diperbarui ...").
- Nonaktifkan submit login, absensi, tugas, ujian, password, logout server, sync saat offline.
- Saat koneksi kembali, tampilkan notice dan trigger refresh data.

---

## Standar Interaksi Pengguna

- Semua request memberi feedback: loading, success, error, warning, atau info.
- Toast untuk pesan singkat; alert inline untuk status yang perlu tetap terlihat.
- Validasi client-side sebelum submit, tetap tampilkan error dari API.
- Bahasa Indonesia yang jelas, singkat, sesuai konteks mahasiswa.
- Jangan ulangi informasi yang sudah jelas terlihat di layar.
- Skeleton/loading placeholder untuk data dashboard, kelas, meeting, ujian, finance, profile.
- Cached content lebih dulu jika ada, lalu refresh dari server.
- Keyboard navigation, focus state, dan aria label untuk tombol icon, password toggle, QR scanner, menu.
- Jangan membuat interaksi yang bergantung pada hover saja.
- Klik/tap pada card kelas atau exam membawa ke detail; aksi sekunder tetap jelas.
- File download membuka/mengunduh dengan label file yang masuk akal.
- Status badge konsisten: success (berhasil/tercatat), warning (perlu perhatian), danger (gagal/absen), info (aktif/tersedia), secondary (netral).

---

## Kriteria Keberhasilan

UI dianggap berhasil jika memenuhi **semua** kriteria berikut:

### 1. Cakupan Fitur dan Route

Semua halaman web di route proyek terwakili:

- `/login`
- `/dashboard`
- `/profile`
- `/finance`
- `/password`
- `/class`
- `/class/{id}`
- `/meeting/{meeting_id}`
- `/exam`
- `/exam/{id}`

Semua endpoint API penting memiliki pengalaman UI atau state pendukung:

- Auth: login, session check, refresh, logout, password form/change.
- Data: dashboard, profile, class, class detail, meeting detail, LMS class, schedule class, finance payments.
- Actions: pending assignment, upload/delete assignment, attendance today, mark attendance, exam list/detail/upload.
- Support: download file, parameter help, validate parameters, endpoint metadata.

### 2. Tidak Ada Fitur Kritis Eksklusif Desktop

Semua fitur utama harus dapat diselesaikan end-to-end di mobile 320px:

- [ ] Login dan logout
- [ ] Membaca dashboard dan ringkasan
- [ ] Membuka kelas dan meeting
- [ ] Mengirim absensi (manual, QR, upload gambar)
- [ ] Upload dan hapus tugas
- [ ] Upload jawaban ujian
- [ ] Membaca riwayat pembayaran
- [ ] Melihat dan mengedit profil
- [ ] Mengubah password

### 3. Verifikasi per Perangkat

#### Mobile (320px – 767px)

| Kriteria | Pass | Fail |
| --- | --- | --- |
| Layout | Single column, tidak ada overflow horizontal | Teks/button terpotong atau scroll horizontal |
| Navigasi | Bottom nav accessible, tidak menutupi konten | Navigasi hilang atau tertutup keyboard |
| Touch target | Semua interaktif ≥ 44×44px | Target terlalu kecil atau terlalu rapat |
| Typography | Body ≥ 16px, terbaca tanpa zoom | Teks terlalu kecil atau kontras rendah |
| Form | Semua field dan submit usable | Field terpotong atau submit tidak terjangkau |
| Tabel | Berubah ke card/list | Tabel memaksa scroll horizontal |
| Modal QR | Muat di 320px, bisa ditutup | Overflow atau tidak bisa dismiss |
| Offline | Banner jelas, aksi disabled | Aksi offline tidak diblokir |
| Performa | Skeleton loading, tidak blank | Layar putih saat loading/error |

#### Tablet (768px – 1023px)

| Kriteria | Pass | Fail |
| --- | --- | --- |
| Layout | Dua kolom di halaman detail, grid metric | Layout masih single column tanpa alasan |
| Navigasi | Top nav aktif, bottom nav hidden | Dua navigasi tumpang tindih |
| Tabel | Tabel readable tanpa scroll horizontal | Kolom terpotong atau overflow |
| Touch | Target tetap ≥ 44px | Layout terlalu rapat untuk touch |
| Grid | Card 2 kolom, metric 2–4 kolom | Card terlalu lebar atau terlalu sempit |
| Spacing | Padding meningkat dari mobile | Spacing sama dengan mobile (terasa sempit) |

#### Desktop (1024px+)

| Kriteria | Pass | Fail |
| --- | --- | --- |
| Layout | Multi-kolom, max-width centered | Konten melebar tanpa batas |
| Navigasi | Top nav permanen, bottom nav hidden | Bottom nav masih tampil |
| Tabel | Tabel penuh, header sticky | Data terlalu renggang atau terpotong |
| Sidebar | Panel aksi sticky di halaman detail | Aksi utama hilang saat scroll |
| Hover | Enhancement visual pada hover | Fitur hanya bisa diakses via hover |
| Density | Informasi lebih padat tanpa mengorbankan scanability | Terlalu kosong atau terlalu padat |
| Grid | Dashboard 4 kolom metric, 2 panel sejajar | Layout identik dengan tablet |

### 4. Semua State Utama Dirancang

- [ ] Loading (skeleton)
- [ ] Empty (pesan + ilustrasi)
- [ ] Success (toast/inline)
- [ ] Error (alert + retry)
- [ ] Offline (banner + disabled aksi)
- [ ] Stale cache (label timestamp)
- [ ] Session expired (redirect login)
- [ ] Upload available/unavailable
- [ ] Already submitted
- [ ] Delete unavailable

### 5. Aksesibilitas WCAG 2.1 AA

- [ ] Kontras teks ≥ 4.5:1 (normal) / 3:1 (large)
- [ ] Focus state terlihat di semua interaktif
- [ ] Touch target ≥ 44×44px
- [ ] Label form eksplisit (bukan placeholder-only)
- [ ] Icon button punya `aria-label`
- [ ] Dialog/modal: focus trap, Escape dismiss, `aria-modal`
- [ ] Heading hierarchy benar (tidak skip level)
- [ ] `prefers-reduced-motion` dihormati
- [ ] Status messages via `aria-live`

### 6. Performa dan PWA

- [ ] Tidak memuat gambar berat tanpa kebutuhan
- [ ] Service worker/offline fallback dihormati
- [ ] Cache state tidak menyesatkan pengguna
- [ ] UI tidak blank saat API gagal jika cache tersedia
- [ ] Animasi tidak menyebabkan jank di perangkat mid-range

### 7. Kualitas Visual Lintas Breakpoint

- [ ] Tidak ada overlap teks di semua viewport
- [ ] Tidak ada button terpotong
- [ ] Tidak ada nested card tanpa alasan
- [ ] Tidak ada tabel horizontal scroll di mobile
- [ ] Bottom nav tidak menutupi konten terakhir
- [ ] Sticky header/sync strip tidak mengganggu area klik
- [ ] Warna primary dan accent tidak berlebihan
- [ ] Loading, empty, error state konsisten di semua halaman
- [ ] Transisi antar breakpoint tidak menyebabkan layout jump

---

## Panduan Pengujian Responsivitas

### Viewport Wajib

| Perangkat simulasi | Ukuran | Fokus uji |
| --- | --- | --- |
| Small phone | `320 × 568` | Layout minimum, touch target, overflow |
| iPhone standard | `375 × 667` | Navigasi, form, readability |
| Large phone | `390 × 844` | Dashboard, card list, sticky action |
| Large Android | `430 × 932` | Grid 1–2 kolom, bottom nav |
| Tablet portrait | `768 × 1024` | Dua kolom, top nav, tabel |
| Tablet landscape | `1024 × 768` | Transisi ke desktop layout |
| Laptop | `1280 × 800` | Max-width, sidebar, tabel penuh |
| Desktop | `1440 × 900` | Density, hover, multi-panel |

### Browser

- Chrome/Chromium (DevTools device mode).
- Safari/WebKit (PWA, kamera, file input, viewport mobile).
- Firefox.
- Edge (jika target pengguna Windows).

### Alur Utama

1. Login valid, invalid, dan portal tidak bisa diakses.
2. Session expired dari halaman protected → redirect login.
3. Dashboard: cache dulu, lalu refresh server.
4. Pull-to-refresh di mobile.
5. Mode offline: cache tersedia dan tanpa cache.
6. Submit absensi: manual, kamera QR, upload gambar QR.
7. Upload tugas PDF dan hapus tugas.
8. Buka materi, download lampiran, link portal.
9. Daftar ujian: card mobile, tabel desktop.
10. Detail ujian: download soal, upload jawaban, field override.
11. Riwayat pembayaran: card mobile, tabel desktop.
12. Profil dengan dan tanpa foto.
13. Ubah password: sukses, mismatch, gagal API.
14. Logout dan pembersihan cache halaman protected.

### Edge Case

- Nama mata kuliah/dosen sangat panjang.
- URL lampiran panjang.
- Tidak ada jadwal hari ini / tugas pending / ujian / riwayat pembayaran.
- Kamera tidak tersedia.
- File upload salah tipe.
- Request API lambat.
- Koneksi terputus saat submit.
- Rotasi orientasi mobile ↔ landscape.
- Zoom teks 200% (aksesibilitas).
- Keyboard terbuka di mobile (form tidak tertutup).

### Kualitas Visual

- Tidak ada overlap teks di 320px.
- Tidak ada button terpotong.
- Tidak ada card di dalam card kecuali benar-benar diperlukan.
- Tidak ada tabel horizontal scroll di mobile.
- Bottom navigation tidak menutupi konten terakhir.
- Sticky header/sync strip tidak mengganggu area klik.
- Warna primary dan accent tidak berlebihan.
- Loading, empty, dan error state konsisten di semua halaman.
- Animasi halus dan tidak mengganggu di perangkat low-end.

---


## Batasan Implementasi

- Pertahankan struktur Laravel dan route yang sudah ada.
- **Wajib** gunakan Tailwind CSS v4 + token `@theme` di `resources/css/app.css`.
- **Wajib** gunakan class `mycic-*` untuk komponen; tambah ke `app.css` jika butuh variasi baru.
- **Jangan** tambah class Bootstrap legacy pada kode baru.
- Preflight Tailwind aktif penuh — jangan load framework CSS lain.
- Jangan menambah dependency UI baru (Bootstrap, Alpine, dll.) tanpa persetujuan.
- Gunakan named route di Blade jika membuat link server-side.
- Gunakan response API yang sudah ada, termasuk envelope `status`, `message`, `data`, dan `meta`.
- Pertahankan parameter upstream sesuai konteks agar upload, absensi, dan download tetap benar.
- Jangan menyimpan kredensial atau token di UI selain mekanisme session/cache yang sudah disediakan aplikasi.
- Tulis CSS mobile-first: base styles untuk mobile, enhance dengan `md:` dan `lg:`.
- Hormati `prefers-reduced-motion` untuk semua animasi.
- Setelah migrasi, ekstrak pola berulang ke partial Blade (`resources/views/components/`).
