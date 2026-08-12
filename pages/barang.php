<?php

if (!function_exists('ensure_csrf_token')) {
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../includes/fungsi.php';
}

ensure_csrf_token();

$pdo = get_db_connection();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editProduct = null;
$tieredPrices = [];

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editProduct = $stmt->fetch();
}

if ($editProduct) {
    $tieredPrices = fetch_tiered_prices($pdo, (int) $editProduct['id']);
}

$nextBatchCode = null;
if (!$editProduct) {
    try {
        $autoIncrementStmt = $pdo->query("
            SELECT AUTO_INCREMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'product_batches'
            LIMIT 1
        ");
        $nextId = (int) ($autoIncrementStmt->fetchColumn() ?: 0);
        if ($nextId > 0) {
            $nextBatchCode = sprintf('BATCH-%06d', $nextId);
        }
    } catch (Throwable $e) {
        $nextBatchCode = 'BATCH-' . date('ymdHis');
    }

    if (!$nextBatchCode) {
        $nextBatchCode = 'BATCH-' . date('ymdHis');
    }
}

?>

<section class="card">
    <h2><?= $editProduct ? 'Edit Barang' : 'Tambah Barang' ?></h2>
    <form id="product-form" data-mode="<?= $editProduct ? 'edit' : 'create' ?>" method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/actions/<?= $editProduct ? 'edit_barang.php' : 'tambah_barang.php' ?>">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($editProduct): ?>
            <input type="hidden" name="id" value="<?= $editProduct['id'] ?>">
        <?php endif; ?>

        <div class="grid-2">
            <div class="form-group">
                <label for="barcode">Barcode</label>
                <div class="barcode-input-wrapper">
                    <input type="text" id="barcode" name="barcode" pattern="[0-9]*" inputmode="numeric" value="<?= sanitize($editProduct['barcode'] ?? '') ?>" autocomplete="off">
                    <button class="button secondary scan-button" type="button" data-scan-target="barcode" aria-label="Scan barcode menggunakan kamera">
                        <span aria-hidden="true">&#128247;</span>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="name">Nama Barang</label>
                <input type="text" id="name" name="name" required value="<?= sanitize($editProduct['name'] ?? '') ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="category_id">Kategori</label>
                <select id="category_id" name="category_id">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= ($editProduct['category_id'] ?? null) == $category['id'] ? 'selected' : '' ?>>
                            <?= sanitize($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="unit">Satuan</label>
                <input type="text" id="unit" name="unit" value="<?= sanitize($editProduct['unit'] ?? '') ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="stock_minimum">Stok Minimum</label>
                <input type="number" id="stock_minimum" name="stock_minimum" min="0" inputmode="numeric" value="<?= sanitize((string) ($editProduct['stock_minimum'] ?? 0)) ?>">
            </div>
            <div class="form-group">
                <label for="points_reward">Poin Member per Rp (opsional)</label>
                <input type="number" id="points_reward" name="points_reward" min="0" step="1" inputmode="numeric" value="<?= sanitize((string) ($editProduct['points_reward'] ?? 0)) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="image">Foto Barang (opsional)</label>
            <?php if (!empty($editProduct['image_path'])): ?>
                <div class="product-image-preview">
                    <img src="<?= BASE_URL . '/' . $editProduct['image_path'] ?>" alt="Foto <?= sanitize($editProduct['name'] ?? 'Barang') ?>">
                    <label class="checkbox remove-image-checkbox">
                        <input type="checkbox" name="remove_image" value="1">
                        <span>Hapus foto saat ini</span>
                    </label>
                </div>
            <?php endif; ?>
            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" capture="environment">
            <p class="muted" style="margin-top:0.5rem;">Ukuran maksimum 10 MB. Pada perangkat mobile, tombol ini dapat membuka kamera belakang.</p>
        </div>

        <div class="form-group">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"><?= sanitize($editProduct['description'] ?? '') ?></textarea>
        </div>

        <fieldset class="form-fieldset">
            <legend>Harga Grosir (Paket / Karton)</legend>
            <p class="muted">
                Opsional. Atur harga paket, misalnya 1 karton isi 10 pcs seharga Rp 9.500.
                Saat transaksi qty melewati kelipatan paket, total dihitung: (jumlah paket x harga paket) + (sisa pcs x harga satuan).
            </p>

            <table class="table table-compact" id="tiered-price-table">
                <thead>
                <tr>
                    <th>Qty per Paket</th>
                    <th>Harga Paket</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="tiered-price-body">
                <?php if (!$tieredPrices): ?>
                    <tr class="tier-empty-row">
                        <td colspan="3" class="muted" style="text-align:center;">Belum ada harga grosir.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tieredPrices as $tier): ?>
                        <tr class="tier-row">
                            <td><input type="number" name="tier_min_qty[]" min="2" step="1" value="<?= (int) ($tier['min_qty'] ?? 0) ?>" required></td>
                            <td><input type="number" name="tier_price[]" min="0" step="0.01" value="<?= sanitize((string) ($tier['price'] ?? 0)) ?>" required></td>
                            <td><button type="button" class="button secondary small tier-remove">Hapus</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button type="button" class="button secondary small" id="tier-add">Tambah Harga Grosir</button>
            </div>
        </fieldset>

        <?php if (!$editProduct): ?>
        <fieldset class="form-fieldset">
            <legend>Stok Awal</legend>
            <div class="grid-2">
                <div class="form-group">
                    <label for="supplier_id">Pemasok</label>
                    <select id="supplier_id" name="supplier_id" required>
                        <option value="">-- Pilih Pemasok --</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['id'] ?>"><?= sanitize($supplier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="batch_code">Kode Batch</label>
                    <input type="text" id="batch_code" name="batch_code" value="<?= sanitize($nextBatchCode) ?>" readonly required>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="stock_initial">Jumlah Masuk</label>
                    <input type="number" id="stock_initial" name="stock_initial" min="1" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label for="purchase_price">Harga Beli (per item)</label>
                    <input type="number" id="purchase_price" name="purchase_price" min="0" step="0.01" inputmode="decimal" required>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="sell_price">Harga Jual (per item)</label>
                    <input type="number" id="sell_price" name="sell_price" min="0" step="0.01" inputmode="decimal" required>
                    <p class="muted" id="sell_price_hint" data-default-hint="Harga rekomendasi otomatis: untung maksimal 10% atau Rp 500 dan dibulatkan ke kelipatan Rp 100."></p>
                </div>
                <div class="form-group">
                    <label for="expiry_date">Tgl. Kadaluarsa (opsional)</label>
                    <input type="text" id="expiry_date" name="expiry_date" maxlength="6" inputmode="numeric" pattern="\d*" placeholder="ddmmyy">
                </div>
                <div class="form-group">
                    <label for="received_at">Tgl. Masuk</label>
                    <input type="date" id="received_at" name="received_at" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </fieldset>
        <?php endif; ?>

        <button class="button" type="submit"><?= $editProduct ? 'Perbarui' : 'Simpan' ?></button>
    </form>
</section>

<script>
    (function() {
        const form = document.getElementById('product-form');
        const SELL_PRICE_PERCENT_LIMIT = 0.10;
        const SELL_PRICE_ABSOLUTE_LIMIT = 500;
        const SELL_PRICE_ROUNDING = 100;
        const rupiahFormatter = typeof Intl !== 'undefined' ? new Intl.NumberFormat('id-ID') : null;
        const purchasePriceInput = document.getElementById('purchase_price');
        const sellPriceInput = document.getElementById('sell_price');
        const sellPriceHint = document.getElementById('sell_price_hint');
        const setupSellPriceSuggestion = () => {
            if (!purchasePriceInput || !sellPriceInput) {
                return;
            }

            const baseHint = sellPriceHint?.dataset.defaultHint || '';
            let lastAutoPrice = '';
            let manualOverride = false;

            const formatRupiah = (value) => {
                if (!Number.isFinite(value)) {
                    return '';
                }
                return rupiahFormatter ? rupiahFormatter.format(Math.round(value)) : String(Math.round(value));
            };

            const roundUp = (value) => {
                if (!Number.isFinite(value) || value <= 0) {
                    return null;
                }
                return Math.ceil(value / SELL_PRICE_ROUNDING) * SELL_PRICE_ROUNDING;
            };

            const calculateSuggestion = (purchaseValue) => {
                if (!Number.isFinite(purchaseValue) || purchaseValue <= 0) {
                    return null;
                }
                const percentageMargin = purchaseValue * SELL_PRICE_PERCENT_LIMIT;
                const allowedMargin = Math.min(percentageMargin, SELL_PRICE_ABSOLUTE_LIMIT);
                return roundUp(purchaseValue + allowedMargin);
            };

            const updateHint = (suggestion) => {
                if (!sellPriceHint) {
                    return;
                }
                if (!suggestion) {
                    sellPriceHint.textContent = baseHint;
                    return;
                }
                const prefix = baseHint ? `${baseHint} ` : '';
                sellPriceHint.textContent = `${prefix}Rekomendasi saat ini: Rp ${formatRupiah(suggestion)}.`;
            };

            const applySuggestion = () => {
                const purchaseValue = purchasePriceInput.valueAsNumber;
                const suggestion = calculateSuggestion(purchaseValue);
                updateHint(suggestion);

                if (!suggestion) {
                    if (!manualOverride) {
                        sellPriceInput.value = '';
                        lastAutoPrice = '';
                    }
                    return;
                }

                if (manualOverride && sellPriceInput.value !== '' && sellPriceInput.value !== lastAutoPrice) {
                    return;
                }

                sellPriceInput.value = String(suggestion);
                lastAutoPrice = sellPriceInput.value;
                manualOverride = false;
            };

            purchasePriceInput.addEventListener('input', applySuggestion);
            purchasePriceInput.addEventListener('change', applySuggestion);

            sellPriceInput.addEventListener('input', () => {
                if (sellPriceInput.value === '' || sellPriceInput.value === lastAutoPrice) {
                    manualOverride = false;
                } else {
                    manualOverride = true;
                }
            });

            applySuggestion();
        };

        if (!form) {
            return;
        }

        setupSellPriceSuggestion();

        // Tiered pricing UI
        const tierAddBtn = document.getElementById('tier-add');
        const tierBody = document.getElementById('tiered-price-body');

        const ensureTierEmptyRow = () => {
            if (!tierBody) {
                return;
            }
            const hasRows = Boolean(tierBody.querySelector('.tier-row'));
            const emptyRow = tierBody.querySelector('.tier-empty-row');
            if (!hasRows && !emptyRow) {
                const tr = document.createElement('tr');
                tr.className = 'tier-empty-row';
                tr.innerHTML = '<td colspan="3" class="muted" style="text-align:center;">Belum ada harga grosir.</td>';
                tierBody.appendChild(tr);
            }
            if (hasRows && emptyRow) {
                emptyRow.remove();
            }
        };

        const bindTierRemove = (row) => {
            const btn = row.querySelector('.tier-remove');
            if (!btn) {
                return;
            }
            btn.addEventListener('click', () => {
                row.remove();
                ensureTierEmptyRow();
            });
        };

        if (tierBody) {
            tierBody.querySelectorAll('.tier-row').forEach(bindTierRemove);
            ensureTierEmptyRow();
        }

        if (tierAddBtn && tierBody) {
            tierAddBtn.addEventListener('click', () => {
                const emptyRow = tierBody.querySelector('.tier-empty-row');
                if (emptyRow) {
                    emptyRow.remove();
                }

                const tr = document.createElement('tr');
                tr.className = 'tier-row';
                tr.innerHTML = `
                    <td><input type="number" name="tier_min_qty[]" min="2" step="1" value="10" required></td>
                    <td><input type="number" name="tier_price[]" min="0" step="0.01" value="" required></td>
                    <td><button type="button" class="button secondary small tier-remove">Hapus</button></td>
                `;
                tierBody.appendChild(tr);
                bindTierRemove(tr);
            });
        }

        const barcodeInput = document.getElementById('barcode');
        let barcodeCheckTimeout;

        const checkBarcodeExistence = async (barcode, excludeId = 0) => {
            if (!barcode) {
                return { exists: false };
            }
            const response = await fetch(`<?= BASE_URL ?>/actions/check_barcode.php?barcode=${encodeURIComponent(barcode)}&exclude_id=${excludeId}`);
            return response.json();
        };

        if (barcodeInput) {
            const currentProductId = form.querySelector('input[name="id"]')?.value || 0;

            barcodeInput.addEventListener('input', () => {
                const digitsOnly = barcodeInput.value.replace(/\D+/g, '');
                if (barcodeInput.value !== digitsOnly) {
                    barcodeInput.value = digitsOnly;
                }
                clearTimeout(barcodeCheckTimeout);
                barcodeCheckTimeout = setTimeout(async () => {
                    const barcode = barcodeInput.value.trim();
                    if (barcode) {
                        const result = await checkBarcodeExistence(barcode, currentProductId);
                        if (result.exists) {
                            const productName = result.product?.name ? ` oleh produk "${result.product.name}"` : ' oleh produk lain';
                            const barcodeParams = new URLSearchParams({
                                page: 'stok',
                                barcode,
                            });
                            if (result.product?.id) {
                                barcodeParams.set('product_id', result.product.id);
                            }
                            alert(`Barcode ini sudah digunakan${productName}. Anda akan diarahkan ke menu tambah stok untuk barang tersebut.`);
                            window.location.href = `<?= BASE_URL ?>/index.php?${barcodeParams.toString()}`;
                            return;
                        }
                    }
                }, 500);
            });
        }

        const focusField = (field) => {
            if (!field) {
                return;
            }
            field.focus({ preventScroll: false });
            if (field.select) {
                field.select();
            }
            if (typeof field.showPicker === 'function' && field.type === 'date') {
                try {
                    field.showPicker();
                } catch (e) {
                    // showPicker tidak tersedia di browser ini.
                }
            }
        };

        const mode = form.dataset.mode || 'create';
        const stepSelectors = mode === 'edit'
            ? [
                '#name',
                '#category_id',
                '#barcode',
                '#unit',
                '#stock_minimum',
                '#points_reward',
                '#description',
            ]
            : [
                '#barcode',
                '#name',
                '#category_id',
                '#unit',
                '#stock_minimum',
                '#points_reward',
                '#description',
                '#supplier_id',
                '#stock_initial',
                '#purchase_price',
                '#sell_price',
                '#expiry_date',
                '#received_at',
            ];

        const wizardFields = stepSelectors
            .map((selector) => {
                const element = form.querySelector(selector);
                return element ? { element, selector } : null;
            })
            .filter(Boolean);

        if (wizardFields.length > 0) {
            const firstField = wizardFields[0].element;
            setTimeout(() => focusField(firstField), 50);
        }

        wizardFields.forEach((item, index) => {
            const field = item.element;
            field.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                if (field.tagName === 'TEXTAREA' && event.shiftKey) {
                    return;
                }

                event.preventDefault();

                const nextItem = wizardFields[index + 1];
                if (nextItem) {
                    focusField(nextItem.element);
                } else {
                    form.requestSubmit();
                }
            });

            if (field.tagName === 'SELECT') {
                field.addEventListener('change', () => {
                    const nextItem = wizardFields[index + 1];
                    if (nextItem) {
                        focusField(nextItem.element);
                    }
                });
            }
        });

        const expiryInput = document.getElementById('expiry_date');
        if (expiryInput) {
            expiryInput.addEventListener('input', () => {
                expiryInput.value = expiryInput.value.replace(/\D+/g, '').slice(0, 6);
            });
        }

        const isValidDate = (year, month, day) => {
            const date = new Date(year, month - 1, day);
            return (
                date.getFullYear() === year &&
                date.getMonth() === month - 1 &&
                date.getDate() === day
            );
        };

        form.addEventListener('submit', (event) => {
            if (!expiryInput) {
                return;
            }

            const rawValue = expiryInput.value.trim();
            if (rawValue === '') {
                expiryInput.value = '';
                expiryInput.setCustomValidity('');
                return;
            }

            const digits = rawValue.replace(/\D+/g, '');
            if (digits.length === 6) {
                const day = parseInt(digits.slice(0, 2), 10);
                const month = parseInt(digits.slice(2, 4), 10);
                const yearTwoDigit = parseInt(digits.slice(4, 6), 10);
                const year = 2000 + yearTwoDigit;

                if (!isValidDate(year, month, day)) {
                    expiryInput.setCustomValidity('Format kadaluarsa tidak valid. Gunakan ddmmyy.');
                    event.preventDefault();
                    expiryInput.reportValidity();
                    return;
                }

                expiryInput.value = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                expiryInput.setCustomValidity('');
                return;
            }

            if (/^\d{4}-\d{2}-\d{2}$/.test(rawValue)) {
                expiryInput.value = rawValue;
                expiryInput.setCustomValidity('');
                return;
            }

            expiryInput.setCustomValidity('Format kadaluarsa tidak valid. Gunakan ddmmyy.');
            event.preventDefault();
            expiryInput.reportValidity();
        });
    })();
</script>

<style>
.product-image-preview {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.product-image-preview img {
    max-width: 120px;
    border-radius: 0.5rem;
    border: 1px solid var(--border-subtle);
    background: #fafafa;
    object-fit: cover;
}

.product-image-preview .remove-image-checkbox {
    margin-top: 0.35rem;
}
</style>

<section class="card" style="margin-top:1.5rem;">
    <h2>Kelola Data Barang</h2>
    <p class="muted">
        Gunakan halaman <strong>Daftar Barang</strong> untuk mengelola stok, impor CSV, dan melakukan edit cepat.
        Halaman ini fokus pada tambah/edit detail barang agar loading tetap ringan.
    </p>
    <div style="display:flex; flex-wrap:wrap; gap:0.75rem;">
        <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=barang_list">Buka Daftar Barang</a>
        <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=stok">Manajemen Stok</a>
        <a class="button secondary" href="<?= BASE_URL ?>/index.php?page=label_harga">Cetak Label Harga</a>
    </div>
</section>

<?php
// Bagian AdminLTE lama dihapus agar daftar barang tidak tampil ganda.
?>
