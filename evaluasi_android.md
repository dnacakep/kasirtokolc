# 🔍 Evaluasi Aplikasi Android Kasir LC
*Dibuat: Agustus 2026 — menggunakan skill android-development, ponytail-review, dan context-map*
*Diperbarui: Agustus 2026 — A–E selesai + bug #4–6 ikut dituntaskan (semua item evaluasi DONE)*

> Dokumen ini berisi hasil evaluasi menyeluruh aplikasi Android (`apk/`) dengan 3 lensa:
> 1. **Arsitektur** (skill android-development — pola Google/NowInAndroid)
> 2. **Over-engineering** (ponytail-review — apa yang bisa dihapus/disederhanakan)
> 3. **Bug & risiko** (code review manual)

---

## ✅ STATUS TERKINI: SEMUA ITEM EVALUASI TUNTAS + UI TEST HIJAU (Agustus 2026)

A–E (rencana aksi utama), bug #4–6, perampingan `DataRepository`, **dan upgrade Espresso** semuanya telah **dikerjakan, lolos build, dan terverifikasi di emulator** (Pixel_9):

| Langkah | Status | Bukti Verifikasi |
|---------|--------|------------------|
| **A. Hapus dead code** (Models.kt + MockData + ProductRow) | ✅ Selesai | `model/Models.kt` (393 baris) dihapus; 0 referensi `com.kasirlc.model` tersisa; build hijau |
| **B. Hash password** | ✅ Selesai | `util/PasswordHasher.kt` (SHA-256 + salt per-user, constant-time compare); `UserDao.login()` verifikasi via Kotlin default; auto-upgrade password legacy plaintext; DB berisi `sha256$...`, 0 sisa plaintext; **login berhasil** di emulator |
| **C. Migrasi aman + backup** | ✅ Selesai | `fallbackToDestructiveMigration` dihapus; backup otomatis 3 file (`db`/`wal`/`shm`) di `onOpen` dengan `PRAGMA wal_checkpoint(TRUNCATE)`; verifikasi: file utama backup 266 KB berisi data, WAL 0 byte |
| **D. Pecah AppViewModel** | ✅ Selesai | `AppViewModel.kt` jadi facade tipis + 4 manager (`AuthManager`, `ProductManager`, `SaleManager`, `SettingsManager`) di `ui/viewmodel/`; API screen 100% dipertahankan; app jalan tanpa crash |
| **E. Deep link cetak web→Android** | ✅ Selesai | `MainActivity.handlePrintDeepLink` + `printFromWeb` memproses teks nyata (log `KasirPrinter: Deep link diterima`); cetak via `PrintService.printRawText`; tanpa crash |
| **#4. Riwayat skema Room** | ✅ Selesai | `exportSchema = true` + KSP `room.schemaLocation` di build.gradle → `app/schemas/.../1.json` dihasilkan (identityHash tercatat); build hijau |
| **#5. Exception diam-diam** | ✅ Selesai | `getExpiringBatches` kini pakai flow `.catch` + `Log.w` (TAG `ProductManager`), fallback list kosong tanpa crash UI |
| **#6. Password demo di test** | ✅ Selesai | Semua `passwordHash` plaintext di androidTest diganti `PasswordHasher.hash(...)`; **70 test DAO PASSED** di emulator |
| **Upgrade Espresso** (untuk UI test di API 36/37) | ✅ Selesai | `espresso-core 3.7.0` + `ext.junit 1.3.0`; `InputManager.getInstance` error hilang; **29/29 UI test PASSED** di emulator API 37 |
| **Fix ZZWizardSetupTest** (scroll + rename class) | ✅ Selesai | Tombol "Selesai" step 4 di bawah layar → `performScrollTo()` sebelum klik; class disamakan dengan nama file (`ZZWizardSetupTest`) |

**Catatan untuk AI berikutnya:**
- Password legacy plaintext **tetap bisa login** (kompatibilitas) dan **otomatis di-hash ulang** saat login sukses (`AuthManager.login`).
- Backup berada di `Android/data/com.kasirlc/files/backup/` (3 file). Restore = kembalikan ketiganya ke folder `databases/`.
- `DatabaseSeeder` masih ada tapi **tidak dipanggil** dari kode app (alur nyata = wizard Setup). Dibiarkan karena belum mengganggu.
- **Schema Room sekarang diekspor** ke `apk/app/schemas/com.kasirlc.data.local.database.AppDatabase/1.json` (version 1). Saat skema berubah, buat `Migration(1, 2)` eksplisit dan commit `2.json`.
- Menjalankan test instrumented: `./gradlew connectedDebugAndroidTest` (butuh emulator hidup). Catatan: download dependency kadang gagal karena jaringan — cukup jalankan ulang, biasanya transien.
- **UI test butuh Espresso ≥ 3.6** di emulator API 36/37 (`InputManager.getInstance` di-hide sejak Android 15). Versi proyek: `espresso-core 3.7.0`.
- **Test isolation note:** saat menjalankan SEMUA class sekaligus (UI + DAO), kadang `ComprehensiveDaoTest.batch_countBySupplierId_returns_correct_count` gagal timeout (flaky, bukan bug kode — PASS saat dijalankan sendiri/kelompok DAO). Jalankan UI & DAO terpisah untuk hasil paling stabil.

---

## 📊 Ringkasan Nilai

| Aspek | Nilai (awal) | Nilai (sekarang) | Keterangan |
|-------|--------------|------------------|------------|
| Fungsionalitas | 🟢 Bagus | 🟢 **Bagus** | Semua fitur inti bekerja, user puas |
| Arsitektur | 🟡 Sedang | 🟢 **Bersih** | AppViewModel dipecah jadi 4 manager; DataRepository passthrough dihapus |
| Kebersihan kode | 🔴 Perlu perbaikan | 🟢 **Bersih** | Dead code Models.kt/MockData dihapus |
| Keamanan | 🟡 Perlu perhatian | 🟢 **Aman** | Password di-hash (SHA-256 + salt) |
| Risiko data | 🔴 Berbahaya | 🟢 **Aman** | Destructive migration dihapus + backup otomatis |

---

## 1️⃣ EVALUASI OVER-ENGINEERING (ponytail-review)

### 🔴 Temuan Besar: ~500 baris dead code di `model/Models.kt`

File `Models.kt` (393 baris) hampir **seluruhnya tidak terpakai**:

```
delete: model/Models.kt:L33-393 — data class Product, CartItem, Sale, SaleItem, StockBatch,
       StockAdjustment, Promotion, DailyReport, TopProduct, InvoiceSummary, Expense,
       MemberDebt, StockAlert, ExpiringBatch, PriceLabel + objek MockData (20 produk, 6
       transaksi, 5 member, dll). NONE di-referensikan dari file lain.
       Ganti: hapus semua, sisakan hanya yang benar-benar dipakai.
```

**Bukti:**
- `MockData` → **0 referensi** di seluruh codebase (grep `MockData` hanya muncul di Models.kt sendiri)
- Hanya `Product` yang diimpor ke file lain (`CommonComponents.kt` ProductRow)
- Semua screen pakai entity DAO (`ProductDao.ProductWithCategory`, `BatchWithDetails`, dll), **bukan** model ini
- `CartItem` → 0 penggunaan di UI (TransactionScreen pakai struktur sendiri)

### 🟡 Temuan Kecil

```
(SUDAH DIPERBAIKI) MainActivity.kt — handlePrintDeepLink kini memproses text dari
       kasirprinter://print?text=... secara nyata: verifikasi printerAddress dari store
       settings, cetak via PrintService.printRawText, plus log debug 'KasirPrinter'.

(SUDAH DIPERBAIKI) DataRepository.kt — DIHAPUS total (Agustus 2026). 18 getter passthrough
       + runInTransaction + singleton tidak lagi diperlukan: 4 manager (AuthManager,
       ProductManager, SaleManager, SettingsManager) kini menerima AppDatabase langsung
       (repo.xxxDao → db.xxxDao(), repo.runInTransaction → db.withTransaction).
       AppViewModel ambil db dari `application` (AndroidViewModel); MainActivity pakai
       AppDatabase.getInstance(context). -60+ baris; build + 70 DAO test + unit test PASSED.
```

### ✅ Yang SUDAH Ramping (jangan diubah)
- Struktur Room: entity + DAO terpisah rapi ✅
- Tidak ada dependency mencurigakan (build.gradle bersih, hanya yang dipakai) ✅
- Tanpa Hilt/Koin = bagus untuk ukuran app ini (ponytail setuju) ✅

**Skor ponytail-review:** `net: -500+ lines possible.`

---

## 2️⃣ EVALUASI ARSITEKTUR (skill android-development)

Perbandingan dengan pola Google/NowInAndroid yang disarankan skill:

| Pola yang disarankan | Kondisi saat ini | Penilaian |
|----------------------|------------------|-----------|
| Satu ViewModel per fitur/screen | `AppViewModel.kt` kini **facade tipis** + 4 manager terpisah: `AuthManager`, `ProductManager`, `SaleManager`, `SettingsManager` (semua di `ui/viewmodel/`) | 🟢 **Membaik** — god object dipecah |
| UDF (Unidirectional Data Flow) | ✅ Flow + StateFlow dipakai dengan benar | 🟢 |
| Repository pattern dengan bisnis logic | ~~`DataRepository`~~ **DIHAPUS** — manager akses `AppDatabase` langsung (`db.xxxDao()`, `db.withTransaction`) | 🟢 **Ramping** |
| Domain layer / Use Cases | Tidak ada (oke untuk ukuran ini — ponytail setuju) | 🟢 |
| Navigation Compose (type-safe) | Navigasi manual `when(currentPage)` di MainActivity | 🟡 (opsional di masa depan) |
| CollectAsStateWithLifecycle | Sebagian pakai `collectAsState()` biasa | 🟡 (opsional) |
| `fallbackToDestructiveMigration()` | **DIHAPUS** — kini gagal dengan pesan jelas saat migrasi belum dibuat + backup otomatis 3 file | 🟢 **Aman** |

### 💡 Rekomendasi yang sudah dieksekusi

1. ✅ ~~Ganti `fallbackToDestructiveMigration()`~~ → dihapus, backup otomatis + `PRAGMA wal_checkpoint(TRUNCATE)`.
2. ✅ ~~Pecah AppViewModel~~ → jadi 4 manager (API screen dipertahankan 100%).
3. ✅ ~~Hapus dead code Models.kt~~ → dihapus total.

---

## 3️⃣ BUG & RISIKO (code review)

| # | Severity | Temuan | Lokasi | Status |
|---|----------|--------|--------|--------|
| 1 | 🔴 **Kritis** | `fallbackToDestructiveMigration()` — update DB menghapus semua data | AppDatabase.kt | ✅ **Diperbaiki** (dihapus, backup otomatis) |
| 2 | 🔴 **Kritis** | Password **plaintext** dibandingkan string langsung | AppDatabase.kt, UserDao.kt | ✅ **Diperbaiki** (PasswordHasher + auto-upgrade) |
| 3 | 🟡 Sedang | Fitur cetak dari web (`kasirprinter://` deep link) dibaca tapi diabaikan | MainActivity.kt | ✅ **Diperbaiki** (diproses nyata) |
| 4 | 🟡 Sedang | `version = 1` + `exportSchema = false` — tidak ada riwayat schema | AppDatabase.kt | ✅ **Diperbaiki** (exportSchema=true + `1.json` di app/schemas) |
| 5 | 🟢 Ringan | `getExpiringBatches()` menelan exception diam-diam (catch kosong) | ProductManager.kt | ✅ **Diperbaiki** (flow `.catch` + `Log.w`) |
| 6 | 🟢 Ringan | Password seed & user demo (`admin123`) tersebar di test files | androidTest/ | ✅ **Diperbaiki** (di-hash via PasswordHasher; 70 test DAO PASSED) |

### ✅ Yang SUDAH Benar (dipertahankan)
- Validasi stok 3 lapis (scan, tombol +, sebelum bayar) ✅
- FIFO batch deduction dengan transaction ✅
- Poin member & hutang dihitung ulang di server logic ✅
- Approval workflow kasir→manajer ✅
- PrintService ESC/POS rapi (density, 58/80mm) ✅

---

## 🎯 RENCANA AKSI (urut dari paling berdampak)

| Langkah | Effort | Dampak | Status |
|---------|--------|--------|--------|
| **A. Hapus dead code** (Models.kt, MockData) | 🟢 15 menit | -500 baris, bersih, aman | ✅ Selesai |
| **B. Hash password** (SHA-256 + salt via `PasswordHasher.kt`) | 🟢 30 menit | Keamanan dasar | ✅ Selesai |
| **C. Ganti destructive migration** → backup aman + checkpoint WAL | 🟡 1-2 jam | **Selamatkan data** | ✅ Selesai |
| **D. Pecah AppViewModel** jadi 4 manager | 🟡 2-4 jam | Maintainability | ✅ Selesai |
| **E. Perbaiki/finish deep link cetak** | 🟡 1 jam | Fitur web→printer jalan | ✅ Selesai |

**Pekerjaan masa depan (opsional):**
- ~~Tambah Room Migration eksplisit + `exportSchema = true` saat skema berubah berikutnya~~ → **SELESAI**: exportSchema aktif, `1.json` tersedia. Tinggal buat `Migration(1, 2)` saat skema berubah.
- ~~Perbaiki `getExpiringBatches()` yang menelan exception~~ → **SELESAI** (flow `.catch` + log).
- ~~Bersihkan password demo di file androidTest~~ → **SELESAI** (di-hash; 70 test DAO PASSED).
- ~~Hapus `DataRepository` passthrough~~ → **SELESAI** (Agustus 2026): kelas dihapus total, manager + MainActivity akses `AppDatabase` langsung.
- ~~Upgrade Espresso~~ → **SELESAI** (Agustus 2026): `espresso-core 3.5.1 → 3.7.0` + `androidx.test.ext:junit 1.1.5 → 1.3.0`. Error `NoSuchMethodException: InputManager.getInstance` (Espresso lama vs emulator API 37) **hilang total** — **29/29 UI test PASSED** di emulator Pixel_9 API 37.
- Ide baru (bukan bug): navigasi type-safe Compose (opsional).

---

## 📌 Kesimpulan

Aplikasi secara **fungsional bagus dan dipakai dengan puas**. Semua item evaluasi telah dituntaskan:
1. ~~Dead code ~500 baris~~ → **dihapus**
2. ~~Password plaintext~~ → **di-hash (SHA-256 + salt, auto-upgrade legacy)**
3. ~~Destructive migration~~ → **dihapus, diganti backup otomatis 3-file + checkpoint WAL**
4. ~~God object AppViewModel~~ → **dipecah jadi 4 manager**
5. ~~Deep link cetak mati~~ → **berfungsi** (web → printer Bluetooth)
6. ~~Tanpa riwayat skema Room~~ → **exportSchema aktif, `1.json` di app/schemas**
7. ~~Exception diam-diam di getExpiringBatches~~ → **di-log + fallback aman**
8. ~~Password demo plaintext di test~~ → **di-hash, 70 test DAO PASSED**

Tidak ada bug tersisa dari daftar evaluasi. Pekerjaan selanjutnya bersifat penyempurnaan opsional (mis. merampingkan `DataRepository`, navigasi type-safe).
