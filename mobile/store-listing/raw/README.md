# Tangkapan layar mentah

Simpan tangkapan layar asli aplikasi (bukan mockup) di folder ini dengan nama persis seperti di bawah, lalu jalankan ulang generator dari root repo:

```bash
node mobile/store-listing/tools/generate-assets.mjs
```

| File | Layar | Isi yang ideal |
|---|---|---|
| `01-beranda.png` | Tab Beranda | Jadwal hari ini + status absensi |
| `02-absensi.png` | Absensi (pemindai QR / form kode) | Kamera pemindai terbuka atau form kode absensi |
| `03-tugas.png` | Tugas | Daftar tugas dengan tenggat |
| `04-nilai.png` | Nilai | IPK/IPS dan KHS satu semester |
| `05-keuangan.png` | Keuangan | Tagihan dan riwayat pembayaran |
| `06-notifikasi.png` | Notifikasi | Beberapa notifikasi pengingat |

Cara mengambil di simulator iOS (iPhone 17 Pro Max = 6.9", hasil 1320×2868):

```bash
xcrun simctl io booted screenshot 01-beranda.png
```

Di Android: tombol Power + Volume Turun, atau `adb exec-out screencap -p > 01-beranda.png`.

Sebelum mengambil:

- Pakai akun demo/akun sendiri, lalu **sensor data pribadi** (nama, NIM, nominal tagihan) atau pakai data contoh. Jangan unggah data mahasiswa lain.
- Jam status bar idealnya 09:41, baterai penuh, sinyal penuh (iOS: `xcrun simctl status_bar booted override --time 9:41 --batteryLevel 100 --cellularBars 4`).
- Mode terang atau gelap, pilih satu dan konsisten untuk semua layar.
