# 🗓️ Kalender Digital RSBL

Dashboard kalender digital berbasis **PHP + JavaScript** untuk menampilkan jam real-time, info cuaca BMKG, jadwal sholat, dan info hari libur nasional Indonesia dalam satu layar.

## ✨ Fitur Utama

- 🕒 **Jam digital real-time (WIB)** dengan update per detik.
- 📅 **Tanggal Indonesia lengkap** + progres hari dalam tahun.
- 🎌 **Hari libur nasional & cuti bersama** dari API `libur.deno.dev`.
- 🧩 **Fallback data libur** ke file lokal `2026.json` jika API gagal.
- 🌦️ **Prakiraan cuaca BMKG** + cache lokal otomatis.
- 🕌 **Jadwal sholat harian** (MyQuran, Banyuwangi).
- 📺 **Mode TV display** untuk layar besar (`?display=tv`).
- 🧪 **Mode debug tema** untuk test warna harian (`?debug=1`).

## 🧱 Teknologi

- `PHP` (server-side rendering + integrasi API)
- `Vanilla JavaScript` (UI live update)
- `HTML/CSS` (layout glassmorphism + responsive)
- `XAMPP/Apache` (local server)

## 📂 Struktur Proyek

```text
kalender/
├── index.php
├── 2026.json
├── .gitignore
└── assets/
    ├── rsbl.jpeg
    ├── cache/
    │   └── bmkg_forecast.json (otomatis dibuat)
    ├── css/
    │   ├── main.css
    │   └── tv.css
    └── js/
        └── app.js
```

## 🚀 Cara Menjalankan (XAMPP)

1. Clone/download project ke folder htdocs:
   - `C:\xampp\htdocs\kalender`
2. Jalankan **Apache** dari XAMPP Control Panel.
3. Buka browser:
   - `http://localhost/kalender/`

## ⚙️ Parameter URL

- `?display=tv` → Aktifkan tampilan TV/layar besar.
- `?debug=1` → Tampilkan panel tester tema harian.

Contoh:

- `http://localhost/kalender/?display=tv`
- `http://localhost/kalender/?debug=1`
- `http://localhost/kalender/?display=tv&debug=1`

## 🔌 Integrasi API

- 🎌 Libur nasional: `https://libur.deno.dev/api?year=YYYY`
- 🌦️ BMKG cuaca: `https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=35.10.16.1010`
- 🕌 Jadwal sholat: `https://api.myquran.com/v2/sholat/jadwal/1602/YYYY/M/D`

## 🛡️ Mekanisme Fallback

- Jika API libur gagal, aplikasi membaca `2026.json`.
- Jika API BMKG gagal/invalid, aplikasi memakai cache terakhir valid:
  - `assets/cache/bmkg_forecast.json`

## 🎨 Kustomisasi Cepat

- Ganti background utama dengan mengganti file:
  - `assets/rsbl.jpeg`
- Ubah zona waktu (default WIB):
  - di `index.php` pada `date_default_timezone_set('Asia/Jakarta')`
- Ubah lokasi jadwal sholat:
  - di script, konstanta `KOTA_ID_BANYUWANGI = 1602`
- Ubah lokasi cuaca BMKG:
  - parameter `adm4` pada URL BMKG di `index.php`

## 🧪 Catatan Pengembangan

- Proyek saat ini berfokus pada **display dashboard internal**.
- Sebagian style/script tersedia di folder `assets/`, tetapi implementasi utama aktif berada di `index.php`.

## 🤝 Kontribusi

Pull request sangat terbuka untuk:

- perbaikan UI/UX,
- optimasi performa,
- penambahan opsi multi-kota,
- dan perapihan struktur file.

## 📄 Lisensi

Belum ditentukan. Tambahkan `LICENSE` jika ingin dipublikasikan sebagai open-source.

## 👨‍💻 Dibuat Oleh

Dibuat oleh **Agung Wicax** - **IT RSUD Blambangan Banyuwangi**.
