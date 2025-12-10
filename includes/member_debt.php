<?php

declare(strict_types=1);

/**
 * Utility helpers for member debt tracking.
 */

/**
 * Ensure the member_debts table exists.
 */
function ensure_member_debt_schema(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS member_debts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            member_id INT NOT NULL,
            principal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            admin_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('open','partial','paid') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_sale_id (sale_id),
            INDEX idx_member_status (member_id, status),
            CONSTRAINT fk_member_debts_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            CONSTRAINT fk_member_debts_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
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
 * Ensure the member_debt_payments table exists.
 */
function ensure_member_debt_payment_schema(PDO $pdo): void
{
    static $paymentsInitialized = false;

    if ($paymentsInitialized) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS member_debt_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            debt_id INT NOT NULL,
            member_id INT NOT NULL,
            sale_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_debt_paid_at (debt_id, paid_at),
            CONSTRAINT fk_debt_payments_debt FOREIGN KEY (debt_id) REFERENCES member_debts(id) ON DELETE CASCADE,
            CONSTRAINT fk_debt_payments_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_debt_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S01') {
            throw $exception;
        }
    }

    $paymentsInitialized = true;
}

/**
 * Insert or update a member debt record linked to a sale.
 */
function create_member_debt(PDO $pdo, int $saleId, int $memberId, float $principalAmount, float $adminFee): void
{
    ensure_member_debt_schema($pdo);

    $principal = round($principalAmount, 2);
    $admin = round($adminFee, 2);
    if ($principal <= 0 && $admin <= 0) {
        // No outstanding debt, nothing to store.
        return;
    }
    $total = round(max(0, $principal + $admin), 2);

    $stmt = $pdo->prepare("
        INSERT INTO member_debts (sale_id, member_id, principal_amount, admin_fee, total_amount)
        VALUES (:sale_id, :member_id, :principal, :admin, :total)
    ");

    try {
        $stmt->execute([
            ':sale_id' => $saleId,
            ':member_id' => $memberId,
            ':principal' => $principal,
            ':admin' => $admin,
            ':total' => $total,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }

        $updateStmt = $pdo->prepare("
            UPDATE member_debts
            SET principal_amount = :principal,
                admin_fee = :admin,
                total_amount = :total,
                updated_at = NOW()
            WHERE sale_id = :sale_id
        ");
        $updateStmt->execute([
            ':principal' => $principal,
            ':admin' => $admin,
            ':total' => $total,
            ':sale_id' => $saleId,
        ]);
    }
}

/**
 * Record a payment toward a member debt.
 *
 * @return array{paid: float, outstanding: float, status: string}
 */
function record_member_debt_payment(PDO $pdo, int $debtId, float $amount, int $userId, ?string $note = null): array
{
    ensure_member_debt_schema($pdo);
    ensure_member_debt_payment_schema($pdo);

    $payAmount = round($amount, 2);
    if ($payAmount <= 0) {
        throw new InvalidArgumentException('Nominal pembayaran harus lebih dari 0.');
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM member_debts WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $debtId]);
        $debt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$debt) {
            throw new RuntimeException('Data hutang tidak ditemukan.');
        }

        $total = (float) $debt['total_amount'];
        $paid = (float) $debt['paid_amount'];
        $outstanding = max(0, round($total - $paid, 2));

        if ($outstanding <= 0) {
            throw new RuntimeException('Hutang sudah lunas.');
        }

        $applied = min($payAmount, $outstanding);
        $newPaid = round($paid + $applied, 2);
        $newOutstanding = max(0, round($total - $newPaid, 2));

        $status = 'open';
        if ($newOutstanding <= 0) {
            $status = 'paid';
            $newOutstanding = 0.0;
        } elseif ($newPaid > 0) {
            $status = 'partial';
        }

        $insertPayment = $pdo->prepare("
            INSERT INTO member_debt_payments (debt_id, member_id, sale_id, amount, note, paid_at, created_by, created_at)
            VALUES (:debt_id, :member_id, :sale_id, :amount, :note, NOW(), :created_by, NOW())
        ");
        $insertPayment->execute([
            ':debt_id' => $debtId,
            ':member_id' => (int) $debt['member_id'],
            ':sale_id' => (int) $debt['sale_id'],
            ':amount' => $applied,
            ':note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
            ':created_by' => $userId,
        ]);

        $updateDebt = $pdo->prepare("
            UPDATE member_debts
            SET paid_amount = :paid_amount,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateDebt->execute([
            ':paid_amount' => $newPaid,
            ':status' => $status,
            ':id' => $debtId,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return [
            'paid' => $applied,
            'outstanding' => $newOutstanding,
            'status' => $status,
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch a member debt record by sale ID.
 */
function fetch_member_debt(PDO $pdo, int $saleId): ?array
{
    ensure_member_debt_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM member_debts
        WHERE sale_id = :sale_id
        LIMIT 1
    ");
    $stmt->execute([':sale_id' => $saleId]);

    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    return $debt ?: null;
}
