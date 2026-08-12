# 📋 Ringkasan Proyek Kasir LC (TERBARU — Agustus 2026)

> Dokumen ini adalah **sumber kebenaran ringkas** proyek. Tujuan: AI baru bisa memahami proyek dan langsung bekerja **tanpa membaca seluruh kode dari awal**. Baca dulu file ini, lalu gunakan `context-map` untuk menemukan file spesifik per tugas.

## 📌 Tentang Proyek

Aplikasi **Kasir LC (Point of Sale)** untuk minimarket/toko retail. Terdiri dari **2 versi**:

1. **Web (PHP Native)** — `C:\xampp8.2\htdocs\kasir.lc\` — versi original (full fitur)
2. **Android (Kotlin + Jetpack Compose)** — `C:\xampp8.2\htdocs\kasir.lc\apk\` — versi baru, dipakai di HP kasir via USB debug

Keduanya memakai **database MySQL yang sama**: `tokolc` @ `localhost:3306` (user: `tokolc`). Android tidak sync langsung — lihat bagian "Masalah Diketahui".

---

# 🐘 BAGIAN 1: Web PHP Native

## Struktur Folder

```
kasir.lc/
├── config/                # Koneksi DB & konfigurasi inti
│   ├── app_config.php     # Constants: BASE_URL, APP_NAME, ROLE_KASIR/MANAJER/ADMIN, SESSION_TIMEOUT
│   ├── auth.php           # login_user, require_role, verify_csrf_token, current_user, remember-me
│   ├── db.php             # get_db_connection() — PDO MySQL
│   ├── error_handler.php  # app_initialize_error_handling()
│   └── timezone.php       # Asia/Jakarta
├── includes/              # Layout & fungsi utilitas
│   ├── fungsi.php         # Helper inti: format_rupiah, sanitize, redirect_with_message, guard_post,
│   │                      #   ensure_csrf_token, fetch_low_stock_items_v2, fetch_expiring_items,
│   │                      #   fetch_pending_labels, fetch_tiered_prices, resolve_tiered_price,
│   │                      #   store_product_image/remove_product_image, ensure_*_schema, dll.
│   ├── header.php / sidebar.php / topbar.php / footer.php   # Layout
│   ├── activity_logger.php  # inventory_log() — log aktivitas stok
│   ├── stock_utils.php      # upsert_product_conversion, ensure_child_stock (konversi satuan)
│   ├── cash_drawer.php      # create/fetch/close_cash_session, summarize_cash_session
│   ├── member_debt.php      # create_member_debt, record_member_debt_payment
│   ├── approval_helpers.php # ensure_stock_request_schema, ensure_expense_request_schema,
│   │                        #   fetch_stock_adjustment_requests, fetch_expense_requests
│   └── integrity_guard.php  # verify_product_stock_integrity
├── pages/                 # Halaman UI (semua lewat index.php?page=xxx)
│   ├── login.php, dashboard.php, transaksi.php, barang.php, barang_list.php,
│   ├── kategori.php, stok.php, stok_cepat.php, stok_masuk.php, stok_penyesuaian.php,
│   ├── stok_riwayat.php, label_harga.php, invoices.php, laporan.php, member.php,
│   ├── hutang_member.php, pemasok.php, promo.php, pengeluaran.php, toko.php,
│   ├── user.php, notifikasi.php, performa_barang.php, cash_drawer_open.php,
│   └── 403.php / 404.php / logout.php
├── actions/               # Endpoint POST (semua require_role + guard_post + CSRF)
│   ├── transaksi_simpan.php   # Simpan transaksi (FIFO, poin, hutang, invoice code)
│   ├── login.php              # Login + remember-me + buka laci kasir utk role kasir
│   ├── tambah_barang.php / edit_barang.php (foto + tiered prices)
│   ├── tambah_stok.php / kurangi_stok.php (approval utk kasir) / update_stok_massal.php
│   ├── decide_stock_adjustment.php / decide_pengeluaran.php  # Approval manajer
│   ├── export_laporan.php / export_dataset.php / export_dataset_excel.php
│   ├── import_barang.php (CSV), bayar_hutang.php, check_barcode.php, search_member.php
│   ├── print_struk.php / print_labels.php / print_labels_by_category.php /
│   │   print_labels_manual.php / print_semua_barang.php / print_stok_menipis.php / label_cetak.php
│   ├── cash_drawer_open.php, cek_notifikasi.php, update_notif.php
│   ├── simpan_toko.php, simpan_user.php, simpan_member.php, simpan_promo.php,
│   │   simpan_pengeluaran (tambah_pengeluaran.php), kategori_simpan/hapus, pemasok_simpan/hapus
│   ├── toggle_barang_status.php, hapus_stok_kadaluarsa.php
│   └── fix_schema.php / fix_sales_payment_method.php  # Perbaikan DB on-demand
├── assets/                # css/style.css, js/app.js, js/scanner.js
├── print_template/        # struk_template.php, label_template.html, stok_menipis_template.php, semua_barang_template.php
├── database/              # kasir.sql (schema), view_penjualan.sql, trigger_stok.sql
├── backup/                # contoh_import_barang.csv
└── storage/logs/          # Log aplikasi
```

## Tabel Database (MySQL `tokolc`)

| Tabel | Isi |
|-------|-----|
| `users` | Akun & role (`kasir`/`manajer`/`adminsuper`), password_hash, last_login_at |
| `auth_tokens` | Token remember-me (selector + hashed validator) |
| `categories` / `suppliers` / `store_settings` | Master kategori, pemasok, identitas toko (termasuk logo_path) |
| `products` | Barang: barcode, name, category_id, unit, stock_minimum, points_reward, image_path, is_active |
| `product_batches` | Batch stok: purchase_price, sell_price, stock_in, stock_remaining, expiry_date, label_printed, supplier_id |
| `product_conversions` | Konversi satuan (1 dus = 24 pcs) |
| `tiered_prices` | Harga grosir bertingkat (min_qty → price) per produk |
| `promotions` | Promo item/transaksi |
| `sales` / `sale_items` | Transaksi + detail item (invoice_code, payment_method, discount, points_used/earned, member_id) |
| `members` / `member_points` / `member_debts` / `member_debt_payments` | Member, poin, hutang & pembayaran |
| `expenses` / `expense_requests` | Pengeluaran + pengajuan (approval) |
| `stock_adjustments` / `stock_adjustment_requests` | Penyesuaian stok + pengajuan (approval) |
| `cash_drawer_sessions` | Sesi buka/tutup laci kasir |
| `stock_audit_logs` | Log audit stok |
| `view_penjualan_ringkas` | View ringkasan penjualan |

## Role & Hak Akses

| Role | Level | Akses |
|------|-------|-------|
| `kasir` | 1 | Transaksi, barang, stok, label, struk. **Tidak bisa** laporan/pengaturan/pengguna |
| `manajer` | 5 | Semua kecuali manajemen pengguna; **menyetujui** pengajuan stok & pengeluaran |
| `adminsuper` | 10 | Full akses (auto-approve penyesuaian stok) |

## Alur Penting Web

- **Auth:** `config/auth.php` → `login_user()`, CSRF token per sesi, session timeout 30 hari. Role kasir wajib buka laci kasir (cash drawer) sebelum transaksi.
- **Invoice:** `INV-YYMMDD-XXXX` (4 hex acak) atau `DGT-YYMMDD-XXXX` jika ada item digital (product id negatif).
- **Stok FIFO:** pengurangan selalu dari batch tertua (`received_at ASC`), modal dihitung dari `purchase_price` batch.
- **Approval stok:** kasir mengajukan → `stock_adjustment_requests` (pending) → manajer/admin setujui di `stok_penyesuaian.php` → baru stok berkurang + opsi catat pengeluaran.
- **Laporan (export CSV):** ringkasan (total penjualan/modal/pengeluaran/laba kotor/laba bersih/poin), laporan harian, detail transaksi, detail barang terjual.

---

# 📱 BAGIAN 2: Android App (Kotlin + Jetpack Compose)

## Tech Stack

| Komponen | Teknologi |
|----------|-----------|
| Bahasa | Kotlin |
| UI | Jetpack Compose (Material3) |
| Database | Room (SQLite, mirror struktur MySQL) |
| DI/VM | ViewModel manual (tanpa Hilt/Koin) |
| Kamera | CameraX + MLKit barcode |
| Printer | Bluetooth SPP (ESC/POS) |
| Min/Target SDK | 24 / 34 |

## Struktur Folder Android

```
apk/app/src/main/java/com/kasirlc/
├── MainActivity.kt            # Entry point + navigasi (route: "dashboard","transaksi","barang",
│                              #   "stok","stok_cepat","label_harga","invoices","laporan","member",
│                              #   "hutang","pemasok","promo","pengeluaran","kategori","pengaturan",
│                              #   "pengguna","notifikasi","performa_barang")
├── KasirLCApplication.kt
├── model/Models.kt            # Data class (Product, CartProduct, LowStockItem, dll)
├── data/
│   ├── local/database/        # AppDatabase.kt (Room), Converters.kt
│   ├── local/entity/          # 21 entity (Product, ProductBatch, Sale, SaleItem, TieredPrice,
│   │                          #   Promotion, Expense, ExpenseRequest, StockAdjustment(+Request),
│   │                          #   Member(+Debt/Payment/Points), CashDrawerSession, AuthToken, dll)
│   ├── local/dao/             # 16 DAO (ProductDao, SaleDao, ProductBatchDao, TieredPriceDao, ...)
│   └── repository/DataRepository.kt   # Repository tunggal semua operasi DB
├── ui/
│   ├── viewmodel/AppViewModel.kt      # SATU-SATUNYA ViewModel — semua state & fungsi di sini
│   └── screens/               # 22 Screen:
│       LoginScreen, SetupScreen, DashboardScreen, TransactionScreen, ProductsScreen,
│       StockScreen, StockCepatScreen, InvoicesScreen, LabelHargaScreen, SettingsScreen,
│       BluetoothPrinterScreen, BarcodeScannerScreen, CategoriesScreen, SuppliersScreen,
│       MembersScreen, MemberDebtsScreen, PromoScreen, ExpensesScreen, ReportsScreen,
│       PerformaBarangScreen, NotificationsScreen, PenggunaScreen
└── util/
    ├── PrintService.kt        # ESC/POS Bluetooth: printReceipt, printPriceLabel, printLowStockReport
    ├── ReceiptGenerator.kt    # Generate struk PNG (preview/galeri)
    └── DebugConfig.kt
```

## Fitur Android (status per Agustus 2026)

### ✅ Berfungsi
- **Transaksi:** scan barcode (kamera + scanner BT), cari barang, digital services (id negatif), multi-payment (Tunai/Debit/QRIS/Hutang), poin member, struk thermal
- **Validasi stok 3 lapis:** (1) saat scan/tambah barang — Toast jika stok habis; (2) tombol `+` di keranjang — Toast + tombol abu-abu jika melebihi stok; (3) sebelum bayar — validasi ulang semua item
- **Label harga:** antrian cetak, nama toko di pojok kiri, **harga grosir (tiered prices)** jika ada, bagian bawah hanya tanggal (batch dihapus), `escDensity(12)` agar cetak tajam
- **Cetak stok menipis:** tombol Cetak di menu Notifikasi → laporan thermal utk catatan belanja (`printLowStockReport`)
- **Login:** username kosong (tanpa default "admin"), logo & nama toko tampil di layar login
- **Manajemen:** barang (foto, tier pricing, kategori), stok (FIFO batch, approval), invoice + cetak ulang, member & poin & hutang, pengeluaran (approval), laporan, pengaturan toko & printer BT

### ⚠️ Masalah Diketahui
- **Tidak ada sinkronisasi** antara Room (Android) dan MySQL (web) — data harus diinput manual/dua kali
- Backup DB hanya ke internal storage
- `AppViewModel.kt` sangat besar (semua state di satu file) — rentan konflik saat edit besar

### 🔄 Roadmap / Ide Selanjutnya
- Sinkronisasi Android ↔ MySQL (queue offline-first)
- Notifikasi push stok menipis
- Dark mode, export PDF
- Integrasi payment gateway QRIS dinamis

## Detail Penting Printer Thermal

- **PrintService.kt** = satu-satunya pengendali ESC/POS (58mm = 32 char/baris, 80mm = 48 char/baris)
- `escDensity(12)` dipanggil saat init → cetak maksimal tajam (kecepatan dikorbankan)
- `escDivider()` menyertakan `\n` di akhir (pernah bug: tidak ganti baris)
- Address printer tersimpan di `StoreSettingsEntity.printerAddress`
- Format label terbaru: **Nama Toko (kiri) → Nama Produk → Harga Besar → Harga Grosir → Tanggal**

---

# 🛠️ Skill AI Terpasang (`.agents/skills/`)

| Skill | Kegunaan |
|-------|----------|
| `android-development` | Panduan arsitektur Android (Kotlin, Compose, MVVM, Room) |
| `php-pro` | Best practice & keamanan PHP modern |
| `web-development` | Best practice web umum |
| `ponytail` | Gaya coding minimalis — solusi paling simpel (YAGNI) |
| `ponytail-review` | Review memburu over-engineering |
| `context-map` | **Peta file relevan sebelum mengubah kode** (hemat token) |
| `generate-custom-instructions-from-codebase` | Instruksi migrasi/refactoring (banding 2 versi) |

**Cara pakai hemat token:** baca `ringkasan.md` ini → panggil `context-map` → edit hanya file yang relevan → build/verifikasi.

# ⚡ Perubahan Terakhir yang Sudah Dikerjakan (Agustus 2026)

- **Web:** export laporan diperkaya (laba kotor/bersih + harian + detail barang), approval pengajuan stok & pengeluaran, cash drawer untuk kasir, remember-me, import CSV barang, foto barang, tiered prices, print stok menipis & semua barang
- **Android:** validasi stok 3 lapis, cetak stok menipis ke thermal, label harga baru (toko kiri + harga grosir + tanggal), username login dikosongkan, density cetak maksimal

---

## 📞 Kontak & Kredensial

- **Web (server AAPANEL):** `https://192.168.11.198:2020/` — server web live memakai AAPANEL (bukan XAMPP)
- **PC ini:** hanya untuk pengembangan & build APK (proyek ada di `C:\xampp8.2\htdocs\kasir.lc\`, tapi MySQL/XAMPP tidak dijalankan di sini)
- **Database:** `tokolc` / `S4W5ThKxtynDZztL` @ `localhost:3306`
- **FTP:** `ftp_kasir_lc` / `4622a3ae216fc8`
- **Login default web:** `admin` / `admin123`
- **Developer:** Muhammad Luthfianto (Limitless Design Studio)
- **Catatan:** kredensial di `profil.md` — jangan commit ke repo publik

> 💡 **Untuk AI baru:** Mulai dari sini. Jangan baca semua file. Gunakan `context-map` untuk memetakan file yang relevan dengan tugas Anda, lalu baca file tersebut saja.
