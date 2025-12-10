<?php

declare(strict_types=1);

const INVENTORY_LOG_DIR = __DIR__ . '/../storage/logs';
const INVENTORY_LOG_FILE = INVENTORY_LOG_DIR . '/inventory.log';
const INVENTORY_LOG_LAST_PRUNE_FILE = INVENTORY_LOG_DIR . '/inventory_prune.cache';

/**
 * Append a JSON log entry to the inventory log file and prune items older than six months.
 *
 * @param string $type  Short label describing the event, e.g. "auto_conversion"
 * @param array  $data  Arbitrary key/value details to persist alongside the log.
 */
function inventory_log(string $type, array $data = []): void
{
    if (!is_dir(INVENTORY_LOG_DIR)) {
        mkdir(INVENTORY_LOG_DIR, 0775, true);
    }

    inventory_log_maybe_prune();

    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => $type,
        'data' => $data,
    ];

    $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = sprintf(
            '{"timestamp":"%s","type":"%s","data":"[unserializable]"}',
            $entry['timestamp'],
            $type
        );
    }

    file_put_contents(INVENTORY_LOG_FILE, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Remove log entries older than six months to keep the log lightweight.
 *
 * To avoid excessive work this runs at most once per 24 hours.
 */
function inventory_log_maybe_prune(): void
{
    if (!file_exists(INVENTORY_LOG_FILE)) {
        return;
    }

    $now = time();
    $lastPrune = file_exists(INVENTORY_LOG_LAST_PRUNE_FILE)
        ? (int) file_get_contents(INVENTORY_LOG_LAST_PRUNE_FILE)
        : 0;

    if ($lastPrune && ($now - $lastPrune) < 86400) {
        return;
    }

    $cutoff = (new DateTimeImmutable('-6 months'))->getTimestamp();
    $inputHandle = fopen(INVENTORY_LOG_FILE, 'r');
    if ($inputHandle === false) {
        return;
    }

    $tempFile = INVENTORY_LOG_FILE . '.tmp';
    $outputHandle = fopen($tempFile, 'w');
    if ($outputHandle === false) {
        fclose($inputHandle);
        return;
    }

    while (($line = fgets($inputHandle)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $keep = true;
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && isset($decoded['timestamp'])) {
            $timestamp = DateTime::createFromFormat('Y-m-d H:i:s', (string) $decoded['timestamp']);
            if ($timestamp instanceof DateTime) {
                if ($timestamp->getTimestamp() < $cutoff) {
                    $keep = false;
                }
            }
        }

        if ($keep) {
            fwrite($outputHandle, $trimmed . PHP_EOL);
        }
    }

    fclose($inputHandle);
    fclose($outputHandle);

    // Replace original log atomically where possible.
    if (file_exists($tempFile)) {
        @unlink(INVENTORY_LOG_FILE);
        if (!@rename($tempFile, INVENTORY_LOG_FILE)) {
            $contents = file_get_contents($tempFile);
            if ($contents !== false) {
                file_put_contents(INVENTORY_LOG_FILE, $contents, LOCK_EX);
            }
            @unlink($tempFile);
        }
    }

    file_put_contents(INVENTORY_LOG_LAST_PRUNE_FILE, (string) $now, LOCK_EX);
}
