# Portal Publikasi & Transparansi Dokumen

Aplikasi web ringan berbasis PHP untuk mempublikasikan arsip dokumen resmi instansi agar transparan dan mudah diakses publik: telusur per folder, preview dalam popup, salin tautan bersih, statistik dilihat/diunduh, dan panel administrasi satu akun. Tanpa basis data — semua metadata disimpan sebagai berkas PHP di folder `data/`.

Identitas aplikasi (nama, tagline, logo) diarahkan sepenuhnya dari `config.php`, sehingga aplikasi ini dapat digunakan instansi mana pun. Nilai bawaan kode memakai identitas MCSP RBS KPK Kabupaten Murung Raya sebagai contoh pemakai.

## Konfigurasi Identitas

| Kunci | Keterangan |
|---|---|
| `app_name` | Nama aplikasi/instansi — muncul di judul tab, kicker halaman, dan panel admin |
| `app_headline` | Judul besar halaman depan |
| `app_subtitle` | Kalimat subjudul halaman depan |
| `app_logo` | Nama berkas logo (dibaca dari root aplikasi); dipakai sebagai favicon juga |

## Menjalankan Lokal

1. Salin `config.example.php` menjadi `config.php`, lalu sesuaikan identitas, kredensial admin, dan `public_base_url` (URL HTTPS publik, misal `https://domain.tld/portal`).
2. Password bawaan contoh: `ubah-password-ini`. Segera ganti lewat menu **Pengaturan Akun** setelah login, atau isi `admin_password_hash` sendiri dengan `password_hash()` PHP.
3. Batas unggah 2 MB bawaan PHP bisa diangkat lewat parameter server:

```sh
php -d upload_max_filesize=32M -d post_max_size=32M -S localhost:8000 router.php
```

Buka `http://localhost:8000` untuk daftar publik dan `http://localhost:8000/admin.php` untuk panel admin.

## Struktur & Penyimpanan

- `Files/` — dokumen yang diunggah admin (tidak boleh diakses langsung lewat web)
- `data/` — registri publikasi (`documents.php`), statistik, kredensial, audit log; dibuat otomatis dan harus dapat ditulis PHP
- Ulangan URL bersih `/dokumen/folder/nama-dokumen-slug` ditangani oleh aturan `RewriteRule` di `.htaccess`

## Hosting cPanel / Apache

1. Unggah seluruh isi aplikasi ke subfolder di `public_html`, misal `public_html/MCSP/`, lalu sesuaikan `public_base_url` dengan `https://domain.tld/MCSP`.
2. Pastikan folder `Files/` dan `data/` dapat ditulis proses PHP, tetapi tidak boleh diakses langsung lewat web (`.htaccess` bawaan sudah menolak akses tersebut).
3. `.user.ini` menetapkan `upload_max_filesize=32M` dan `post_max_size=32M`; bila hosting tidak membacanya, atur kedua nilai tersebut lewat konfigurasi PHP hosting.
4. Butuh PHP 8.0+.

## Preview Office & Header Keamanan

- Preview DOCX/XLS/XLSX memakai Microsoft Office Online Viewer: Microsoft harus dapat membuka `view.php` dari internet, sehingga preview Office hanya berjalan di domain HTTPS publik. PDF, gambar, dan CSV dipreview langsung oleh browser.
- `.htaccess` aplikasi menerbitkan `Content-Security-Policy` sendiri (memuat `view.officeapps.live.com` pada `frame-src`) sehingga CSP `public_html` root tidak memblokir preview, dan menonaktifkan `X-Frame-Options`/CSP hanya untuk `view.php`.
- Tombol `Copy link` memakai URL bersih, misal `/MCSP/dokumen/2026/nama-dokumen-pdf`.
- Preview cepat: `view.php` menghantar berkas dengan potongan besar dan dukungan `ETag`/`304`, sehingga pembukaan ulang dokumen yang sama bersifat instan.

## Kontribusi

1. `git clone https://github.com/LodriX/Publikasi-doc.git`
2. `cp config.example.php config.php` lalu sesuaikan.
3. Jalankan langkah "Menjalankan Lokal".
