# Product Requirements Document: MCSP RBS KPK Document Portal

## 1. Ringkasan Produk

MCSP RBS KPK Document Portal adalah aplikasi web ringan berbasis PHP untuk mempublikasikan dokumen publik MCSP RBS KPK Kabupaten Murung Raya. Publik dapat menelusuri, mempreview, menyalin tautan, dan mengunduh dokumen. Satu administrator mengelola unggahan, folder, status publikasi, dan tanggal publikasi.

## 2. Tujuan

- Menyediakan pusat dokumen publik yang rapi, mudah dicari secara visual, dan dapat diakses dari browser.
- Menampilkan struktur folder dokumen tanpa membuka akses langsung ke filesystem server.
- Memberikan tautan dokumen yang rapi dan dapat dibagikan.
- Menyediakan panel admin sederhana tanpa database untuk mengelola publikasi dokumen.
- Menjaga tampilan profesional, modern, dan tepercaya dengan identitas visual biru.

## 3. Pengguna

### Publik

- Melihat daftar dokumen yang telah dipublikasikan.
- Membuka folder dan subfolder.
- Membuka preview, menyalin tautan, atau mengunduh dokumen.

### Administrator

- Login ke panel admin menggunakan satu akun.
- Mengunggah dokumen, menentukan folder, membuat subfolder, dan mengatur tanggal publikasi.
- Mempublikasikan file yang telah ada di folder `Files/`.
- Melihat dokumen terbit dan belum terbit.
- Mengubah tanggal publikasi atau menghapus dokumen.

## 4. Ruang Lingkup

### Format File Didukung

- PDF
- JPG / JPEG
- PNG
- DOCX
- XLS / XLSX
- CSV

### Batas File

- Maksimum unggahan: 30 MB per file.
- Konfigurasi PHP minimum: `upload_max_filesize=32M` dan `post_max_size=32M`.

### Di Luar Ruang Lingkup

- Multi-user atau manajemen peran.
- Upload publik tanpa login.
- Edit isi dokumen melalui browser.
- Pencarian teks di dalam isi dokumen.
- Konversi dokumen Office di server.

## 5. Kebutuhan Fungsional

### 5.1 Daftar Dokumen Publik

- Menampilkan judul `MCSP RBS KPK`.
- Menampilkan subjudul `Dokumen Publikasi MCSP RBS KPK Kabupaten Murung Raya`.
- Hanya menampilkan dokumen yang sudah dipublikasikan admin.
- Menampilkan judul dokumen dan ekstensi file sesuai nama publik yang dikelola admin.
- Pada halaman publik, nomor awal file tetap tampil pada nama file dan juga digunakan sebagai badge. Badge memakai nomor asli dari nama file, bukan nomor urut daftar.
- Menampilkan seluruh teks `Tanggal publikasi: DD/Bulan/YYYY` dalam Bahasa Indonesia dengan penekanan tebal, ukuran file, serta statistik `Dilihat` dan `Diunduh`.
- Mengurutkan dokumen dalam folder berdasarkan nomor di awal nama file, misalnya `1.`, `2.`, `10.`.
- File tanpa nomor awal tampil setelah file bernomor dan diurutkan berdasarkan nama file.

### 5.2 Folder dan Subfolder

- Membaca folder dan subfolder secara rekursif dari `Files/`.
- Menampilkan folder dalam bentuk pohon collapsible.
- Semua folder dan subfolder di dalam `Files/` tertutup secara default dan dapat dibuka pengguna.
- Folder tertutup menampilkan petunjuk `Klik untuk melihat daftar`.
- Menampilkan jumlah dokumen pada setiap folder sebagai elemen yang tetap utuh pada header folder, termasuk pada layar ponsel.
- Menampilkan folder hanya jika terdapat dokumen publik di dalamnya atau di subfoldernya.
- Mendukung nama folder numerik seperti `2026` serta nama folder seperti `Tahun 2026`.

### 5.3 Aksi Dokumen

- `Preview`: membuka preview dalam popup pada halaman daftar, dengan tombol `Tutup Preview`.
- `Download`: mengunduh file asli dengan nama file publik.
- Setiap klik `Preview` mencatat satu statistik dilihat; setiap unduhan yang dimulai mencatat satu statistik diunduh.
- `Copy link`: menyalin URL detail dokumen yang rapi, bukan URL query download. Halaman detail menyediakan tombol `Kembali ke Arsip`.
- `Buka di tab baru`: membuka file asli untuk PDF/gambar atau Office Viewer untuk format Office.
- Pada mobile, tombol `Preview`, `Download`, dan `Copy link` harus selalu sejajar dalam tiga kolom.

### 5.4 Halaman Detail Dokumen

- Menggunakan URL rapi seperti:
  ```text
  /MCSP/dokumen/nama-folder/nama-dokumen
  ```
- Menampilkan nama file, direktori, tanggal publikasi, ukuran file, preview, download, dan copy link.
- URL tidak valid atau dokumen belum terbit menghasilkan respons `404`.

### 5.5 Preview Dokumen

- PDF: ditampilkan melalui viewer browser.
- JPG/JPEG/PNG: ditampilkan sebagai gambar penuh.
- CSV: ditampilkan melalui browser.
- DOCX/XLS/XLSX: ditampilkan melalui Microsoft Office Online Viewer ketika aplikasi diakses dari domain HTTPS publik.
- Jika preview tidak tampil sempurna, tampilkan fallback `Buka dokumen di tab baru` dan tombol download.
- Endpoint preview mendukung byte-range (`206 Partial Content`) untuk PDF besar.
- Endpoint preview mengirim `Cache-Control: no-store` dan URL versi file untuk menghindari cache preview lama.

### 5.6 Panel Admin

- Login menggunakan satu akun admin.
- Session memakai cookie `HttpOnly` dan `SameSite=Lax`.
- Dashboard menampilkan ringkasan:
  - Dokumen terbit.
  - Dokumen belum terbit.
  - Folder aktif.
  - Total ukuran dokumen publik.
- Dashboard statistik menampilkan total view, total download, dan lima dokumen terpopuler.
- Setiap menu admin menampilkan satu fungsi utama: dashboard statistik, upload, dokumen terbit, file belum terbit, riwayat aktivitas, atau pengaturan akun.
- Upload file dengan validasi ekstensi, MIME type, dan batas 30 MB.
- Menentukan folder tujuan dari daftar folder yang tersedia.
- Membuat subfolder baru dalam folder tujuan.
- Memublikasikan file yang sudah ada di `Files/`.
- Tanggal publikasi dapat diisi manual. Jika kosong, sistem memakai tanggal Last Modified file sebagai fallback.
- Menampilkan dokumen terbit dan belum terbit dalam pohon folder collapsible.
- Mengubah tanggal publikasi atau menghapus dokumen.
- Mengubah username administrator dan/atau password dari dashboard dengan konfirmasi password saat ini.
- Menampilkan 30 aktivitas admin terbaru, termasuk login, logout, pengelolaan dokumen, dan perubahan akun.

## 6. Aturan Data

- File fisik disimpan di `Files/`.
- Data publikasi disimpan di `data/documents.php`.
- Riwayat aktivitas admin disimpan di `data/admin_audit.log`.
- Statistik view dan download disimpan di `data/document_stats.php`.
- Nama file unggahan disimpan secara acak di server untuk keamanan, tetapi nama asli disimpan sebagai nama publik.
- Dokumen dianggap publik jika memiliki catatan di `data/documents.php`.
- Jika `published_at` kosong, tanggal publikasi dihitung dari `filemtime()` file.
- Tidak menampilkan metadata internal file seperti penulis dokumen, metadata PDF/Office, atau ukuran teknis selain ukuran file yang dibutuhkan pengguna.

## 7. Keamanan

- Akses langsung ke `Files/` dan `data/` ditolak melalui `.htaccess`.
- File publik hanya dilayani lewat `download.php` dan `view.php` setelah validasi path serta status publikasi.
- Mencegah path traversal dan symbolic link traversal.
- Aksi admin memakai token CSRF.
- Password admin disimpan sebagai hash.
- Kredensial admin yang diperbarui disimpan di `data/admin_credentials.php` dan tetap dilindungi dari akses publik.
- Perubahan kredensial admin memakai CSRF, verifikasi password saat ini, serta regenerasi session.
- Login dibatasi setelah lima kegagalan dari alamat IP yang sama dalam 15 menit.
- Logout memakai permintaan `POST` dengan token CSRF.
- File unggahan diuji berdasarkan ekstensi dan MIME type.
- `view.php` mengizinkan inline preview dan tidak boleh menerima `X-Frame-Options: DENY` dari hosting.

## 8. Desain dan UX

- Gaya visual: portal pemerintah modern, tepercaya, dan profesional.
- Warna utama: navy, biru royal, biru muda, putih, dan abu kebiruan.
- Halaman publik memakai hero/header biru dan struktur arsip dokumen yang bersih.
- Lambang Kabupaten Murung Raya digunakan sebagai favicon dan identitas visual pada portal publik serta dashboard admin.
- Dashboard admin memakai sidebar biru, kartu ringkasan, dan panel manajemen terstruktur.
- Menggunakan inline SVG atau elemen CSS sederhana, tanpa ketergantungan frontend eksternal.
- Responsif untuk desktop dan ponsel.

## 9. Kebutuhan Teknis dan Deployment

- PHP minimum 8.1.
- Ekstensi PHP `fileinfo` wajib aktif.
- Aplikasi ditempatkan di:
  ```text
  public_html/MCSP/
  ```
- URL publik yang ditargetkan:
  ```text
  https://murungrayakab.go.id/MCSP/
  ```
- `config.php` harus memiliki `public_base_url` sesuai URL HTTPS publik.
- Folder `Files/` dan `data/` harus dapat ditulis proses PHP.
- Permission yang direkomendasikan: folder `755`, file `644`; gunakan `775` pada `Files/` dan `data/` bila diperlukan.
- Apache harus mengaktifkan `.htaccess` dan `mod_headers`.
- Microsoft Office Online Viewer membutuhkan domain publik HTTPS dan akses Microsoft ke `view.php`.

## 10. Kriteria Penerimaan

1. Publik hanya melihat dokumen yang sudah dipublikasikan.
2. Dokumen dalam folder dan subfolder tampil sesuai struktur folder.
3. Folder numerik tidak menghasilkan error.
4. File bernomor tampil dengan urutan numerik yang benar.
5. Preview PDF besar dapat dimuat pada browser modern.
6. Copy link membuka halaman detail dokumen, bukan mengunduh file.
7. Download menghasilkan file dengan nama publik yang benar.
8. Input tanggal kosong memakai Last Modified file sebagai tanggal publikasi.
9. Upload file di atas 30 MB atau format tidak didukung ditolak.
10. Aksi admin membutuhkan login dan CSRF token yang valid.
11. Preview, download, dan copy link tetap sejajar pada tampilan mobile.
12. Akses langsung ke file internal di `Files/` dan data aplikasi di `data/` ditolak.
