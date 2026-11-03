# MCSP RBS KPK Document Gallery

Galeri dokumen publik dengan satu akun admin. Dokumen publik hanya menampilkan judul, tanggal publikasi, tombol unduh, dan tautan langsung.


## Menjalankan lokal

PHP bawaan saat ini membatasi unggahan ke 2 MB. Jalankan server lokal dengan batas unggahan aplikasi:

```sh
php -d upload_max_filesize=32M -d post_max_size=32M -S localhost:8000 router.php
```

Buka `http://localhost:8000` untuk daftar publik atau `http://localhost:8000/admin.php` untuk panel admin.

## Hosting PHP

File `.user.ini` menetapkan `upload_max_filesize=32M` dan `post_max_size=32M`. Bila hosting tidak membaca `.user.ini`, atur kedua nilai tersebut di konfigurasi PHP/server. Pastikan folder `Files/` dan `data/` bisa ditulis oleh proses PHP, tetapi tidak dapat diakses langsung lewat web server. Konfigurasi Apache yang disertakan menolak akses tersebut; untuk Nginx, tambahkan aturan yang setara.

## Memperbarui di cPanel

Gunakan `MCSP-update.zip` untuk memperbarui aplikasi yang sudah berjalan. Paket ini hanya berisi kode dan aset, sehingga tidak menimpa folder `Files/`, folder `data/`, maupun `config.php` di server.

1. Buka File Manager cPanel dan masuk ke `public_html`.
2. Unggah `MCSP-update.zip` tanpa menghapus folder `MCSP` yang sudah ada.
3. Extract ZIP di dalam `public_html` dan pilih untuk menimpa file bila diminta.
4. Jangan hapus atau menimpa `public_html/MCSP/Files/`, `public_html/MCSP/data/`, dan `public_html/MCSP/config.php`.

Dokumen yang sudah dipublikasikan dan data publikasinya tetap tersedia setelah pembaruan. Fitur kredensial, pembatasan login, dan audit log akan membuat file baru di `data/` secara otomatis; folder tersebut harus tetap dapat ditulis oleh PHP.

## Preview Office

Preview DOCX, XLS, dan XLSX memakai Microsoft Office Online Viewer. Atur `public_base_url` di `config.php` ke URL HTTPS publik aplikasi, misalnya `https://murungrayakab.go.id/MCSP`. Microsoft harus dapat membuka `view.php` dari internet; preview Office tidak tersedia pada localhost. PDF, gambar, dan CSV dipreview langsung oleh browser.

Tombol `Copy link` menggunakan URL preview yang rapi, misalnya `/MCSP/dokumen/tahun-2026/nama-dokumen-pdf`, bukan URL unduhan dengan query string. Konfigurasi Apache di `.htaccess` diperlukan agar URL ini berfungsi pada hosting.

## Hosting Preview

Hosting tidak boleh mengirim `X-Frame-Options: DENY` atau `Content-Security-Policy` yang melarang iframe untuk `view.php`, karena endpoint tersebut menjadi isi popup preview dan sumber Microsoft Office Online Viewer. Aturan `.htaccess` sudah menonaktifkan kedua header hanya untuk `view.php`. Selain itu, `.htaccess` aplikasi menerbitkan `Content-Security-Policy` sendiri yang mengizinkan `view.officeapps.live.com` pada `frame-src`, sehingga CSP `public_html` root tidak memblokir preview Office. Jika hosting tetap memaksakan header tersebut, minta penyedia hosting mengizinkan iframe untuk `/MCSP/view.php`.
