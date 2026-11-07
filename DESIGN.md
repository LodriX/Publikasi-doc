# SIMPUN — Sistem Desain

SIMPUN (Sistem Informasi Manajemen Publikasi Untuk Naskah) menyeimbangkan keteraturan arsip dengan identitas visual yang dapat dipilih administrator. Tiga gaya visual tersedia dalam dua mode warna dan diterapkan konsisten di explorer publik, detail berkas, login, dan dashboard.

## Identitas

- **Nama**: SIMPUN
- **Kepanjangan**: Sistem Informasi Manajemen Publikasi Untuk Naskah
- **Warna merek**: Deep Slate `#0B131A`, Electric Cyan `#22D3EE`, Clear White `#F8FAFC`.
- Logo aplikasi dan ikon sentuh disimpan lokal di `logo.png` dan `apple-touch-icon.png`.

## Model tema global

Administrator memilih gaya dan mode default melalui **Admin → Identitas Aplikasi**. Gaya disimpan di `data/app_settings.php` dan diterapkan global. Pengunjung hanya mengganti mode Terang/Gelap lokal per browser; pilihan tidak mengubah gaya atau preferensi orang lain. Dokumen HTML menerima `data-theme-style` (`hud`, `classic`, `standart`) dan `data-theme` (`dark`, `light`) saat dirender. Default instalasi adalah `hud` + `dark`.

## Gaya visual

### Futuristic HUD

Gaya bawaan dan paling ekspresif: ruang kerja gelap, cyan elektrik, telemetry, grid titik, dan aksen teknis pada kartu serta panel. Mode terang menggunakan permukaan Cyber-Frost.

### Classic Drive

Gaya arsip yang tenang: bidang datar, aksen biru, tipografi sans yang jelas, batas ringan, dan bayangan minimum. Mode terang terasa seperti lemari arsip dokumen; mode gelap memakai slate berkontras tinggi.

### Standart

Tema bernama **Standart**, dengan aksen visual terinspirasi macOS: tipografi sistem, sudut membulat, kedalaman bayangan halus, dan permukaan translucent bila browser mendukung `backdrop-filter`. Tema ini menggunakan layout explorer dan lebar responsif yang sama seperti tema lain.

Semua tema menghormati `prefers-reduced-motion`, menyediakan focus ring yang terlihat, dan memakai token bersama untuk hierarki status, format dokumen, serta pesan.

## Arsitektur CSS

- `assets/tokens.css`: token semantik bersama, enam kombinasi gaya/mode, warna merek, tipografi, radius, dan bayangan.
- `assets/themes.css`: penyesuaian struktural tema pada masthead, kartu, panel, tombol, dan navigasi.
- `assets/base.css`: reset, latar, fokus keyboard, dan tipografi global.
- `assets/public.css`: explorer, detail, viewer, drawer inspektor.
- `assets/admin.css`: login dan seluruh panel administrasi, termasuk pemilih tema.
- `assets/type.css`: font lokal beserta atribusi/lisensi.

Tema diterapkan server-side agar mode yang dipilih admin konsisten pada setiap halaman dan tidak berkedip sebelum CSS tampil. Input radio admin mendapat pratinjau langsung pada halaman pengaturan sebelum disimpan; halaman publik hanya menerima nilai yang tersimpan.

## Perbedaan presentasi

- Semua tema memakai struktur explorer full-width dan perilaku responsif yang sama. Tema hanya memengaruhi warna, tipografi, latar, bentuk permukaan, dan detail tombol/kartu.

## Footer aplikasi

Footer bersama ditampilkan di halaman publik, detail dokumen, gerbang PIN, login, dan admin. Identitasnya mengambil nama dan subjudul aktif; kredit mengarah ke `lodrix.id`, sedangkan tombol GitHub mengarah ke repositori `LodriX/Publikasi-doc`. Tampilan mengikuti mode warna dan gaya aktif: bilah teknis HUD, bilah arsip datar Classic, atau panel transparan Standart. Pada layar kecil, konten ditumpuk agar tautan tetap mudah disentuh.

Footer memakai layout flex pada halaman publik dan admin: pada halaman pendek ia terdorong ke dasar viewport, sedangkan pada halaman panjang ia tetap berada setelah konten dan ikut ter-scroll. Footer bukan elemen fixed; panel Standart mempertahankan jarak bawah 24px.
