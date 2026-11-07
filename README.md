# SIMPUN

**Sistem Informasi Manajemen Publikasi Untuk Naskah**

Aplikasi web ringan berbasis PHP untuk mempublikasikan arsip dokumen resmi instansi agar transparan dan mudah diakses publik: telusur per folder, cari nama dokumen/folder, filter tahun publikasi dan jenis file, urutkan dokumen, preview dalam popup, salin tautan bersih, statistik dilihat/diunduh, dan panel administrasi satu akun. Tanpa basis data — semua metadata disimpan sebagai berkas PHP di folder `data/`.

Nama aplikasi adalah **SIMPUN**; kepanjangannya **Sistem Informasi Manajemen Publikasi Untuk Naskah**. Identitas dan logo dapat disesuaikan dari dashboard untuk kebutuhan instansi lain.

## Konfigurasi Identitas

| Kunci | Keterangan |
|---|---|
| `app_name` | Nama aplikasi/instansi — muncul di judul tab, masthead, dan panel admin |
| `app_headline` | Judul besar halaman depan |
| `app_subtitle` | Kalimat subjudul halaman depan |
| `app_logo` | Nama berkas logo (dibaca dari root aplikasi); dipakai sebagai favicon bila berkas tersedia |
| `theme_style` | Tema global: `hud`, `classic`, atau `standart` |
| `theme_mode` | Mode default: `dark` atau `light`; pengunjung dapat mengubah preferensi lokal browser |

Administrator memilih gaya dan mode default di **Identitas & Tampilan Aplikasi**. Tersedia **Futuristic HUD**, **Classic Drive**, dan **Standart** (aksen visual terinspirasi macOS) dengan lebar explorer dan susunan responsif yang sama. Pengunjung dapat mengubah mode Terang/Gelap untuk browser mereka sendiri, tetapi tidak dapat mengganti gaya global. Preferensi mode publik disimpan lokal; pengaturan admin menentukan default. Seluruh font di-host lokal beserta lisensi SIL OFL; desain didokumentasikan di `DESIGN.md`.

Semua gaya memakai layout explorer full-width yang sama dan tetap menyesuaikan ukuran jendela. Perbedaannya ada pada warna, tipografi, permukaan, tombol, dan aksen visual—bukan lebar atau susunan kolom.

## Menjalankan Lokal

1. Salin `config.example.php` menjadi `config.php`, lalu sesuaikan identitas, kredensial admin, dan `public_base_url` (URL HTTPS publik, misal `https://domain.tld/portal`).
2. Password bawaan contoh: `ubah-password-ini`. Segera ganti lewat menu **Pengaturan Akun** setelah login, atau isi `admin_password_hash` sendiri dengan `password_hash()` PHP.
3. Batas unggah 2 MB bawaan PHP bisa diangkat lewat parameter server:

```sh
php -d upload_max_filesize=32M -d post_max_size=32M -S localhost:8000 router.php
```

Buka `http://localhost:8000` untuk portal SIMPUN dan `http://localhost:8000/login` untuk masuk admin. Setelah login, tombol **Dashboard** tampil pada halaman publik. Atur gaya dan mode default lewat **Identitas Aplikasi**.

## Struktur & Penyimpanan

- `Files/` — dokumen yang diunggah admin (tidak boleh diakses langsung lewat web)
- `data/` — registri publikasi (`documents.php`), visibilitas dan PIN folder (`folder_visibility.php`, `folder_pins.php`), statistik, kredensial, audit log; dibuat otomatis dan harus dapat ditulis PHP
- URL bersih `/dokumen/folder/nama-dokumen-slug` dan `/folder/nama-folder` ditangani oleh aturan `RewriteRule` di `.htaccess`
- Pengaturan identitas dan tema global tersimpan di `data/app_settings.php`; mode awal adalah Futuristic HUD Dark.

## Visibilitas dan Berbagi Tautan

- Dokumen memiliki status **Publik** (tampil di explorer), **Akses via Tautan** (tidak terdaftar tetapi dapat dibuka lewat link), atau **Privat** (hanya admin). Folder hanya memiliki status Publik dan Akses via Tautan. Subfolder mewarisi pembatasan folder induk.
- Admin dapat mengubah akses folder lama lewat **Kelola Folder → Atur Akses** dan menyalin link folder maupun link dokumen dari panel admin. Folder Akses via Tautan dapat dibuka melalui URL `/folder/...` meskipun tidak tampil di beranda.
- PIN enam digit **opsional** hanya untuk status Akses via Tautan, baik pada folder maupun dokumen. PIN folder melindungi semua turunannya, termasuk akses lewat link dokumen langsung, pratinjau, unduhan, dan statistik. Dokumen dengan PIN sendiri tetap memerlukan PIN dokumennya. Lima PIN salah memblokir percobaan selama lima menit.
- ZIP explorer tetap hanya mengandung dokumen Publik; folder Akses via Tautan tidak dapat diunduh sebagai ZIP, termasuk oleh admin. Berkas Privat tidak pernah menjadi publik karena link folder dibagikan.

## Hosting cPanel / Apache

1. Unggah seluruh isi aplikasi ke subfolder di `public_html`, misal `public_html/portal/`, lalu sesuaikan `public_base_url` dengan `https://domain.tld/portal`.
2. Pastikan folder `Files/` dan `data/` dapat ditulis proses PHP, tetapi tidak boleh diakses langsung lewat web (`.htaccess` bawaan sudah menolak akses tersebut).
3. `.user.ini` menetapkan `upload_max_filesize=32M` dan `post_max_size=32M`; bila hosting tidak membacanya, atur kedua nilai tersebut lewat konfigurasi PHP hosting.
4. Butuh PHP 8.0+.

## Preview Office & Header Keamanan

- Preview DOCX/XLS/XLSX memakai Microsoft Office Online Viewer: Microsoft harus dapat membuka `view.php` dari internet, sehingga preview Office hanya berjalan di domain HTTPS publik. PDF, gambar, dan CSV dipreview langsung oleh browser.
- `.htaccess` aplikasi menerbitkan `Content-Security-Policy` sendiri (memuat `view.officeapps.live.com` pada `frame-src`) sehingga CSP `public_html` root tidak memblokir preview, dan menonaktifkan `X-Frame-Options`/CSP hanya untuk `view.php`.
- Tombol `Copy link` memakai URL bersih, misal `/portal/dokumen/2026/nama-dokumen-pdf`.
- Preview cepat: `view.php` menghantar berkas dengan potongan besar dan dukungan `ETag`/`304`, sehingga pembukaan ulang dokumen yang sama bersifat instan.

## Kontribusi

1. `git clone https://github.com/LodriX/Publikasi-doc.git`
2. `cp config.example.php config.php` lalu sesuaikan.
3. Jalankan langkah "Menjalankan Lokal".
