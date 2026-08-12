Kasir Minimarket PHP Native
===========================

Cara instalasi singkat:
1. Import file `database/kasir.sql` ke MySQL.
2. Jalankan `database/view_penjualan.sql` dan `database/trigger_stok.sql`.
3. Salin folder proyek ini ke `htdocs` lalu akses `http://localhost/kasirtokolc/pages/login.php`.
4. Masuk menggunakan akun default:
   - Username: admin
   - Password: admin123
5. Tambahkan data barang, stok, dan mulai transaksi.
   - Pilih pemasok langsung di menu `Stok > Tambah Batch` (barang tidak lagi terikat ke pemasok tunggal).
6. Setelah transaksi tersimpan, gunakan tombol `Cetak Struk` yang muncul di halaman kasir untuk mencetak atau mengunduh struk.
7. Atur identitas toko (nama, alamat, logo) melalui menu `Pengaturan Toko` agar muncul pada struk dan label harga.
8. Untuk menambah banyak barang sekaligus, gunakan fitur import CSV di menu `Data Barang`.
   - Unduh contoh format di `backup/contoh_import_barang.csv`.
   - Minimal kolom `barcode` dan `nama` harus terisi. Kolom lain bersifat opsional.
   - Barcode yang sama akan memperbarui data produk, sedangkan barcode baru akan dibuat otomatis.

Struktur folder utama:
- config: koneksi database, konfigurasi aplikasi, helper autentikasi.
- includes: layout header, sidebar, footer, dan fungsi utilitas.
- pages: halaman utama (dashboard, transaksi, laporan, dll).
- actions: endpoint pemrosesan form (CRUD barang, stok, transaksi, dll).
- assets: gaya CSS dan script JS sederhana.
- print_template: template HTML struk & label.
-Database: skrip SQL untuk inisialisasi dan view.

Pastikan ekstensi PHP `pdo_mysql` aktif. Untuk cetak struk thermal, integrasikan library `php-escpos` sesuai dokumentasi resmi.
