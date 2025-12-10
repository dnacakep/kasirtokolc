<?php

declare(strict_types=1);

require_once __DIR__ . '/fungsi.php';

/**
 * Ensure the expense_requests table exists.
 */
function ensure_expense_request_schema(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expense_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                expense_date DATE NOT NULL,
                category VARCHAR(120) NOT NULL,
                description TEXT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decision_by INT NULL,
                decision_at DATETIME NULL,
                decision_notes TEXT NULL,
                INDEX idx_status (status),
                INDEX idx_expense_date (expense_date),
                INDEX idx_created_by (created_by),
                INDEX idx_decision_by (decision_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S01') {
            throw $exception;
        }
    }

    $initialized = true;
}

/**
 * Ensure the stock_adjustment_requests table exists.
 */
function ensure_stock_request_schema(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_adjustment_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                requested_quantity INT NOT NULL,
                reason TEXT NOT NULL,
                record_expense TINYINT(1) NOT NULL DEFAULT 0,
                metadata TEXT NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decision_by INT NULL,
                decision_at DATETIME NULL,
                decision_notes TEXT NULL,
                INDEX idx_status (status),
                INDEX idx_product_status (product_id, status),
                INDEX idx_created_by (created_by),
                INDEX idx_decision_by (decision_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S01') {
            throw $exception;
        }
    }

    $initialized = true;
}

function fetch_expense_requests(PDO $pdo, ?string $status = null, int $limit = 100): array
{
    ensure_expense_request_schema($pdo);

    $sql = "
        SELECT er.*, u.full_name AS created_by_name, approver.full_name AS decision_by_name
        FROM expense_requests er
        LEFT JOIN users u ON u.id = er.created_by
        LEFT JOIN users approver ON approver.id = er.decision_by
    ";

    $params = [];
    if ($status !== null) {
        $sql .= " WHERE er.status = :status";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY er.created_at DESC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int) $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_stock_adjustment_requests(PDO $pdo, ?string $status = null, int $limit = 100): array
{
    ensure_stock_request_schema($pdo);

    $sql = "
        SELECT sar.*, p.name AS product_name, requester.full_name AS created_by_name, approver.full_name AS decision_by_name
        FROM stock_adjustment_requests sar
        INNER JOIN products p ON p.id = sar.product_id
        LEFT JOIN users requester ON requester.id = sar.created_by
        LEFT JOIN users approver ON approver.id = sar.decision_by
    ";

    $params = [];
    if ($status !== null) {
        $sql .= " WHERE sar.status = :status";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY sar.created_at DESC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int) $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function decode_request_metadata(?string $metadata): array
{
    if (!$metadata) {
        return [];
    }

    $decoded = json_decode($metadata, true);

    return is_array($decoded) ? $decoded : [];
}
