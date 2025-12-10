# kasirtokolc

Aplikasi kasir ringan untuk minimarket pribadi. Dibuat tanpa framework agar setipis mungkin, berjalan di jaringan lokal (tidak diekspos ke internet), sehingga tidak ada lapisan keamanan tambahan yang rumit.

## Latar Belakang
- Butuh sistem manajemen minimarket sederhana tanpa biaya lisensi/berlangganan.
- Alternatif aplikasi yang dicoba sebelumnya terlalu berat karena fitur berlebihan.
- Fokus pada kebutuhan inti operasional di jaringan lokal saja.

## Fitur Utama
- Autentikasi sederhana untuk akses aplikasi.
- Manajemen barang: tambah/edit/hapus, kategori, stok masuk/keluar, penyesuaian stok, label harga/barcode.
- Kasir & transaksi: pencatatan penjualan, cetak struk, metode pembayaran, transaksi hutang member.
- Member & pemasok: data member, hutang/piutang, data pemasok.
- Laporan & performa: laporan penjualan, stok menipis/kadaluarsa, performa barang, ekspor data (termasuk CSV/Excel).
- Notifikasi & pengingat: stok menipis, kadaluarsa, dan aktivitas yang perlu perhatian.
- Setup awal: konfigurasi toko, database, dan timezone melalui wizard lokal.

## Keamanan & Lingkungan
- Dipakai hanya di jaringan lokal; tidak didesain untuk akses publik.
- Tanpa hardening/keamanan tambahan—harap pasang di jaringan yang terpercaya saja.

## Teknologi
- PHP tanpa framework, HTML, CSS, dan JS vanilla untuk performa ringan.
- MySQL/MariaDB sebagai basis data.

## Menjalankan Secara Singkat
1. Siapkan web server + PHP + MySQL (contoh: XAMPP/LAMPP) di jaringan lokal.
2. Langsung buka dan inisiasi pengaturan awal.
3. Gunakan HTTPS agar bisa mengakses kamera. kamu harus mengubah settingan XAMPP/LAMPP jika ingin menggunakan fitur scan barcode barang.

## Donasi
Jika proyek ini membantu, silakan dukung lewat QR berikut:

![QR Donasi](qr.png)
