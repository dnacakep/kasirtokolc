<?php

declare(strict_types=1);

/**
 * Helper utilities for managing cash drawer sessions.
 */

/**
 * Ensure the cash_drawer_sessions table exists.
 */
function ensure_cash_drawer_schema(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS cash_drawer_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            opening_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_notes TEXT NULL,
            closed_by INT NULL,
            closing_amount DECIMAL(12,2) DEFAULT NULL,
            expected_closing_amount DECIMAL(12,2) DEFAULT NULL,
            closing_difference DECIMAL(12,2) DEFAULT NULL,
            closing_notes TEXT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_closed_at (closed_at),
            INDEX idx_user_closed (user_id, closed_at),
            CONSTRAINT fk_cash_drawer_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_cash_drawer_closed_by FOREIGN KEY (closed_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S01') {
            throw $exception;
        }
    }

    $initialized = true;
}

/**
 * Fetch an open cash drawer session for the given user.
 */
function fetch_open_cash_session(PDO $pdo, int $userId): ?array
{
    ensure_cash_drawer_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM cash_drawer_sessions
        WHERE user_id = :user_id
          AND closed_at IS NULL
        ORDER BY opened_at DESC
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

/**
 * Fetch the most recent cash drawer session (open or closed).
 */
function fetch_latest_cash_session(PDO $pdo): ?array
{
    ensure_cash_drawer_schema($pdo);

    $stmt = $pdo->query("
        SELECT *
        FROM cash_drawer_sessions
        ORDER BY opened_at DESC
        LIMIT 1
    ");

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

/**
 * Fetch a cash drawer session by its ID.
 */
function fetch_cash_session_by_id(PDO $pdo, int $sessionId): ?array
{
    ensure_cash_drawer_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM cash_drawer_sessions
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $sessionId]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

/**
 * Calculate cash movement totals for a session.
 *
 * @return array{
 *     cash_sales: float,
 *     cash_expenses: float,
 *     expected_balance: float
 * }
 */
function summarize_cash_session(PDO $pdo, array $session, ?string $overrideEnd = null): array
{
    ensure_cash_drawer_schema($pdo);

    $start = $session['opened_at'];
    $end = $overrideEnd ?? ($session['closed_at'] ?? date('Y-m-d H:i:s'));

    $cashSalesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(grand_total), 0) AS total_sales
        FROM sales
        WHERE cashier_id = :cashier_id
          AND payment_method = 'cash'
          AND created_at BETWEEN :start AND :end
    ");
    $cashSalesStmt->execute([
        ':cashier_id' => $session['user_id'],
        ':start' => $start,
        ':end' => $end,
    ]);
    $cashSales = (float) $cashSalesStmt->fetchColumn();

    $cashExpensesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_expenses
        FROM expenses
        WHERE created_by = :user_id
          AND created_at BETWEEN :start AND :end
    ");
    $cashExpensesStmt->execute([
        ':user_id' => $session['user_id'],
        ':start' => $start,
        ':end' => $end,
    ]);
    $cashExpenses = (float) $cashExpensesStmt->fetchColumn();

    $expected = (float) $session['opening_amount'] + $cashSales - $cashExpenses;

    return [
        'cash_sales' => $cashSales,
        'cash_expenses' => $cashExpenses,
        'expected_balance' => $expected,
    ];
}

/**
 * Close a cash drawer session with the counted amount and computed expectation.
 */
function close_cash_session(PDO $pdo, int $sessionId, float $countedAmount, float $expectedAmount, int $closedBy, ?string $notes = null, ?string $closedAt = null): void
{
    ensure_cash_drawer_schema($pdo);

    $closedAt = $closedAt ?? date('Y-m-d H:i:s');
    $difference = $countedAmount - $expectedAmount;

    $stmt = $pdo->prepare("
        UPDATE cash_drawer_sessions
        SET closing_amount = :closing_amount,
            expected_closing_amount = :expected_amount,
            closing_difference = :difference,
            closed_by = :closed_by,
            closing_notes = :notes,
            closed_at = :closed_at
        WHERE id = :id
    ");
    $stmt->execute([
        ':closing_amount' => $countedAmount,
        ':expected_amount' => $expectedAmount,
        ':difference' => $difference,
        ':closed_by' => $closedBy,
        ':notes' => $notes,
        ':closed_at' => $closedAt,
        ':id' => $sessionId,
    ]);
}

/**
 * Insert a new cash drawer session.
 *
 * @return int The new session ID.
 */
function create_cash_session(PDO $pdo, int $userId, float $openingAmount, ?string $notes = null, ?string $openedAt = null): int
{
    ensure_cash_drawer_schema($pdo);

    $openedAt = $openedAt ?? date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO cash_drawer_sessions (user_id, opening_amount, opening_notes, opened_at)
        VALUES (:user_id, :opening_amount, :notes, :opened_at)
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':opening_amount' => $openingAmount,
        ':notes' => $notes,
        ':opened_at' => $openedAt,
    ]);

    return (int) $pdo->lastInsertId();
}
