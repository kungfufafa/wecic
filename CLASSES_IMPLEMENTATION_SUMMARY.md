# Implementasi Data Kelas dari Dua Endpoint

## 🎯 Masalah yang Diselesaikan
- Membutuhkan data kelas dari 2 endpoint berbeda:
  1. **SIPANDAI Dashboard** (`portal/sipandai/?mod=sipandai&act=dashboardmahasiswa`) - untuk join link
  2. **Student Course** (`portal/mahasiswa/?mod=student&act=course`) - untuk info ruang kelas
- Menggabungkan data dari kedua endpoint untuk mendapatkan informasi lengkap

## 🔧 Solusi yang Dibuat

### 1. Extractor Classes Baru
- **`SipandaiClassesExtractor.php`** - Khusus untuk endpoint SIPANDAI dashboard
- **`StudentCourseExtractor.php`** - Khusus untuk endpoint student course dengan fitur merge
- **`ClassesExtractor.php`** - Tetap dipertahankan untuk backward compatibility

### 2. API Endpoints Baru
- `GET /api/campus/sipandai-classes` - Data dari SIPANDAI (join link)
- `GET /api/campus/student-course` - Data dari student course (ruang kelas)
- `GET /api/campus/merged-classes` - Data gabungan dari kedua endpoint

### 3. Update UI
- **`/classes`** sekarang menggunakan endpoint `merged-classes`
- Menampilkan informasi ruang kelas jika tersedia
- Menampilkan sumber data yang digunakan
- Tampilan yang lebih informatif dengan icon ruang kelas

## 📱 Fitur Baru di UI Classes

### Informasi yang Ditampilkan:
1. **Data Mahasiswa** - Nama, NIM, Program Studi
2. **Informasi Akademik** - Semester, SKS, IPK
3. **Data Kelas Lengkap**:
   - Nama mata kuliah dan kode
   - Nama kelas (jika ada)
   - Jadwal (hari & waktu)
   - 📍 **Ruang kelas** (dari student course)
   - Dosen pengampuh
   - Kontak dosen (email & telepon)
   - 🔗 **Join link** (dari SIPANDAI)
   - Tombol aksi (Join Kelas & Lihat Detail)

### Visual Improvements:
- Icon ruang kelas dengan bangunan/gedung
- Informasi sumber data di bagian bawah
- Responsive design untuk mobile & desktop
- Loading states dan error handling

## 🚀 Cara Menggunakan

### Via Web Interface:
1. Login ke sistem: `http://mycic.test/login`
2. Akses halaman kelas: `http://mycic.test/classes`
3. Data akan otomatis digabungkan dari kedua endpoint

### Via API:
```javascript
// Ambil data gabungan (recommended)
fetch('/api/campus/merged-classes')
  .then(res => res.json())
  .then(data => {
    console.log('Data lengkap:', data);
    // Setiap kelas memiliki:
    // - class_room_info.room (ruang kelas)
    // - join_link.link (join ke kelas)
  });

// Atau ambil dari sumber tertentu
fetch('/api/campus/sipandai-classes') // hanya join link
fetch('/api/campus/student-course')   // hanya ruang kelas
```

## 📁 File yang Dimodifikasi

### Baru:
- `app/Services/Extractors/SipandaiClassesExtractor.php`
- `app/Services/Extractors/StudentCourseExtractor.php`
- `test_class_extraction.php`

### Dimodifikasi:
- `app/Http/Controllers/API/CampusDataController.php`
- `routes/api.php`
- `config/campus.php`
- `resources/views/classes.blade.php`

## 🧪 Testing

### Manual Testing:
```bash
# Test extraction (butuh login session dulu)
php test_class_extraction.php
```

### Automated Testing:
- Endpoint API dapat di-test dengan Postman atau curl
- UI dapat di-test dengan membuka `/classes` setelah login

## 🔍 Data Structure

### Response Merged Classes:
```json
{
  "status": "success",
  "data": {
    "student_info": { "nama": "...", "nim": "...", "program_studi": "..." },
    "classes": [
      {
        "course_info": { "code": "SI053025", "name": "Perancangan Aplikasi Mobile" },
        "class_room_info": { "class_name": "SI-EB-1/5", "room": "Ruang Lab 2" },
        "schedule": { "day": "Senin", "time": "12:30:00-14:30:00" },
        "lecturer_info": { "name": "Muhammad Hatta, M.Kom." },
        "join_link": { "link": "?mod=sipandai&act=download&id=3142&..." }
      }
    ],
    "semester_info": { "semester": "1" },
    "data_sources": {
      "sipandai_dashboard": true,
      "student_course": true
    }
  }
}
```

## ✅ Checklist Selesai

- [x] Buat extractor untuk SIPANDAI dashboard
- [x] Buat extractor untuk student course
- [x] Implementasi merge data
- [x] Update controller dengan 3 endpoint baru
- [x] Update routes API
- [x] Update konfigurasi URL
- [x] Update UI classes untuk menggunakan merged data
- [x] Tambahkan informasi ruang kelas di UI
- [x] Tambahkan sumber data information
- [x] Test dan format kode dengan PINT
- [x] Buat file testing manual
- [x] Dokumentasi lengkap

## 🎉 Hasil Akhir

Sekarang halaman `/classes` menampilkan data kelas lengkap dengan:
- ✅ Informasi ruang kelas (dari student course)
- ✅ Join link untuk akses kelas (dari SIPANDAI)
- ✅ Data mahasiswa dan akademik
- ✅ Responsive dan user-friendly
- ✅ Error handling dan loading states
- ✅ Informasi sumber data yang transparan

Solusi ini memberikan data lengkap dari kedua endpoint dalam satu tampilan yang terintegrasi!