# 📱 Kasir LC - Android Edition (Point of Sale)

Aplikasi Kasir (Point of Sale) berbasis Android yang dirancang untuk kecepatan, kemudahan penggunaan, dan fungsionalitas lengkap untuk manajemen minimarket/toko retail. Proyek ini merupakan pembangunan ulang (rewrite) dari versi web PHP Native ke arsitektur mobile modern untuk performa yang lebih optimal dan portabilitas tinggi.

---

## 🚀 Fitur Unggulan

### 1. 💵 Transaksi Cepat & Pintar
- **Scan Barcode:** Mendukung penggunaan kamera HP atau Barcode Scanner Bluetooth/USB.
- **Multi-Payment:** Pembayaran Tunai, Debit, dan Integrasi QRIS.
- **Sistem FIFO (First In First Out):** Pengurangan stok otomatis berdasarkan batch masuk tertua.
- **Harga Bertingkat:** Penyesuaian harga otomatis berdasarkan jumlah barang (Grosir/Eceran).

### 2. 📦 Manajemen Inventaris & Stok
- **Batch Tracking:** Kelola banyak batch untuk satu produk dengan harga beli/jual berbeda.
- **Notifikasi Stok & Kadaluarsa:** Peringatan otomatis untuk barang yang menipis atau mendekati tanggal expired.
- **Penyesuaian Stok:** Fitur retur dan penyesuaian stok dengan pencatatan alasan yang ketat.

### 3. 🧍‍♂️ Loyalitas & Member
- **Sistem Poin:** Akumulasi poin setiap transaksi.
- **Redeem Poin:** Potongan harga menggunakan poin member.
- **Database Member:** Pencatatan riwayat belanja pelanggan.

### 4. 📊 Laporan & Keuangan
- **Analitik Laba Bersih:** Perhitungan laba otomatis (Penjualan - Modal - Pengeluaran).
- **Laporan Performa Barang:** Identifikasi barang paling laku (Fast Moving) dan kurang laku.
- **Export Data:** Cetak laporan ke PDF atau Excel langsung dari perangkat.

### 5. 🏷️ Label & Struk
- **Cetak Struk Thermal:** Mendukung printer Bluetooth/USB ukuran 58mm/80mm.
- **Cetak Label Harga:** Generate label harga langsung untuk rak toko.

---

## 🛠️ Tech Stack (Android)

Untuk memastikan aplikasi ringan namun powerfull, berikut adalah teknologi yang digunakan:

- **Bahasa:** Kotlin
- **UI Framework:** Jetpack Compose (Modern & Declarative UI)
- **Database Lokal:** Room Database (untuk mode offline)
- **Networking:** Retrofit / Ktor (jika sinkronisasi cloud aktif)
- **DI Framework:** Hilt / Koin
- **Local Storage:** DataStore / SharedPreferences

---

## ⚙️ Persiapan Pengembangan

1. **Prasyarat:**
   - Android Studio (Versi terbaru Koala/Ladybug recommended).
   - JDK 17+.
   - Android SDK 24 (Android 7.0 Nougat) ke atas.

2. **Instalasi:**
   ```bash
   git clone https://github.com/username/kasir-lc-android.git
   cd kasir-lc-android
   ```

3. **Build APK:**
   - Buka proyek di Android Studio.
   - Tunggu proses Gradle Sync selesai.
   - Pilih menu `Build` > `Build Bundle(s) / APK(s)` > `Build APK(s)`.

---

## 📂 Struktur Folder (Android Architecture)

```text
app/
├── src/
│   ├── main/
│   │   ├── java/com/kasirlc/
│   │   │   ├── data/          # Repository, Room DB, API Service
│   │   │   ├── domain/        # Use Cases, Models
│   │   │   ├── ui/            # Screens, Components, ViewModels (MVI/MVVM)
│   │   │   └── util/          # Helpers, Constants
│   │   └── res/               # Drawable, Layout, Values
└── build.gradle.kts           # Dependencies
```

---

## 📜 Lisensi & Kontribusi
Proyek ini bersifat **Open Source**. Kontribusi dalam bentuk *Bug Report* atau *Pull Request* sangat diapresiasi.

**Dikembangkan oleh:** Muhammad Luthfianto (Limitless Design Studio)
**Kontak:** [luthfi@limitlessds.com](mailto:support@limitlessds.com)
