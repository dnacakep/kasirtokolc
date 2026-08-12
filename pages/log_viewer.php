<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

require_role(ROLE_MANAJER);

$pdo = get_db_connection();
$logDir = __DIR__ . '/../storage/logs';
$logFile = $logDir . '/inventory.log';

// Handle download
if (isset($_GET['download']) && $_GET['download'] === '1') {
    if (file_exists($logFile) && is_readable($logFile)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.log"');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        exit;
    }
    redirect_with_message('/index.php?page=log_viewer', 'File log tidak ditemukan.', 'error');
}

// Handle clear log
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    verify_csrf_token($_GET['csrf_token'] ?? '');
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    redirect_with_message('/index.php?page=log_viewer', 'Log berhasil dibersihkan.');
}

// Read log entries
$logEntries = [];
if (file_exists($logFile) && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        // Reverse to show newest first, limit to 500
        $lines = array_reverse($lines);
        $lines = array_slice($lines, 0, 500);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['timestamp'])) {
                $logEntries[] = $entry;
            } else {
                // Plain text line (legacy or error)
                $logEntries[] = [
                    'timestamp' => '',
                    'type' => 'text',
                    'data' => ['message' => $line],
                ];
            }
        }
    }
}

$logSize = file_exists($logFile) ? filesize($logFile) : 0;

?>
<section class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
        <h2>Log Aplikasi</h2>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="?page=log_viewer&download=1" class="button" style="text-decoration:none;">📥 Download Log</a>
            <a href="?page=log_viewer&clear=1&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>"
               class="button secondary"
               onclick="return confirm('Hapus semua log?')">🗑️ Hapus Log</a>
        </div>
    </div>

    <p class="muted">
        File: <code>storage/logs/inventory.log</code> &middot;
        Ukuran: <?= $logSize > 0 ? number_format($logSize / 1024, 1) : 0 ?> KB &middot;
        Menampilkan <?= count($logEntries) ?> entri terbaru
    </p>

    <?php if (empty($logEntries)): ?>
        <div class="alert info">Belum ada entri log. Log akan tercatat saat ada aktivitas stok, transaksi, atau penyesuaian.</div>
    <?php else: ?>
        <div style="margin-top:1rem;background:#1e1e2e;color:#cdd6f4;border-radius:8px;padding:1rem;font-family:'Courier New',monospace;font-size:12px;overflow-x:auto;max-height:70vh;overflow-y:auto;">
            <?php foreach ($logEntries as $entry): ?>
                <div style="margin-bottom:4px;padding:4px 0;border-bottom:1px solid #313244;">
                    <span style="color:#89b4fa;">[<?= sanitize($entry['timestamp']) ?>]</span>
                    <span style="color:#a6e3a1;font-weight:bold;"><?= sanitize($entry['type']) ?></span>
                    <?php if (!empty($entry['data'])): ?>
                        <span style="color:#cdd6f4;">
                            <?php foreach ((array) $entry['data'] as $key => $value): ?>
                                <?php if (is_scalar($value)): ?>
                                    &nbsp;<?= sanitize((string) $key) ?>=<?= sanitize((string) $value) ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
