<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';

function payment_table_columns(string $table): array
{
    try {
        $stmt = db()->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

function payment_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

function payment_find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function load_payment_row(int $paymentId): ?array
{
    try {
        $stmt = db()->prepare(
            'SELECT id, order_id, method, amount, status, created_at
             FROM payments
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();
        return $payment ?: null;
    } catch (PDOException $exception) {
        return null;
    }
}

function payment_has_proof(int $paymentId): bool
{
    if (!payment_table_exists('payment_proofs')) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id FROM payment_proofs WHERE payment_id = :payment_id LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

function update_order_payment_status(int $orderId, string $status): void
{
    $orderColumns = payment_table_columns('orders');
    if (!$orderColumns) {
        return;
    }

    $statusColumn = payment_find_column($orderColumns, ['payment_status', 'payment_state']);
    if (!$statusColumn) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'UPDATE orders SET ' . $statusColumn . ' = :status WHERE id = :order_id'
        );
        $stmt->execute([
            'status' => $status,
            'order_id' => $orderId,
        ]);
    } catch (PDOException $exception) {
        return;
    }
}

function update_payment_status(int $paymentId, string $status): bool
{
    try {
        $stmt = db()->prepare('UPDATE payments SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $paymentId,
        ]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $exception) {
        return false;
    }
}

function auto_set_payment_status(int $paymentId, ?string $trigger = null): bool
{
    $payment = load_payment_row($paymentId);
    if (!$payment) {
        return false;
    }

    $method = strtolower((string) ($payment['method'] ?? ''));
    $status = strtolower((string) ($payment['status'] ?? ''));
    $orderId = (int) $payment['order_id'];

    if (in_array($method, ['cod', 'pickup_cash'], true)) {
        if (!in_array($status, ['verified', 'rejected', 'unpaid'], true)) {
            $updated = update_payment_status($paymentId, 'unpaid');
            if ($updated && $orderId > 0) {
                update_order_payment_status($orderId, 'unpaid');
            }
            return $updated;
        }
        return false;
    }

    if ($method === 'bank_transfer') {
        if ($trigger === 'proof_uploaded' || payment_has_proof($paymentId)) {
            if (!in_array($status, ['verified', 'rejected', 'pending_proof'], true)) {
                $updated = update_payment_status($paymentId, 'pending_proof');
                if ($updated && $orderId > 0) {
                    update_order_payment_status($orderId, 'pending_proof');
                }
                return $updated;
            }
        }
    }

    return false;
}

function record_payment_proof(int $paymentId, string $proofPath, ?string $uploadedAt = null): bool
{
    if (!payment_table_exists('payment_proofs')) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO payment_proofs (payment_id, proof_path, uploaded_at)
             VALUES (:payment_id, :proof_path, :uploaded_at)'
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'proof_path' => $proofPath,
            'uploaded_at' => $uploadedAt ?: gmdate('Y-m-d H:i:s'),
        ]);
        auto_set_payment_status($paymentId, 'proof_uploaded');
        return true;
    } catch (PDOException $exception) {
        return false;
    }
}

function add_payment_review(int $paymentId, int $reviewedByUserId, string $decision, string $reason): void
{
    if (!payment_table_exists('payment_reviews')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO payment_reviews (payment_id, reviewed_by_user_id, decision, reason, reviewed_at)
             VALUES (:payment_id, :reviewed_by_user_id, :decision, :reason, :reviewed_at)'
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'reviewed_by_user_id' => $reviewedByUserId,
            'decision' => $decision,
            'reason' => $reason,
            'reviewed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $exception) {
        return;
    }
}

function add_earnings_entry(int $paymentId): void
{
    if (!payment_table_exists('earnings_entries')) {
        return;
    }

    $payment = load_payment_row($paymentId);
    if (!$payment) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id FROM earnings_entries WHERE payment_id = :payment_id LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $insert = db()->prepare(
            'INSERT INTO earnings_entries (payment_id, order_id, amount, method, created_at)
             VALUES (:payment_id, :order_id, :amount, :method, :created_at)'
        );
        $insert->execute([
            'payment_id' => $paymentId,
            'order_id' => (int) $payment['order_id'],
            'amount' => (float) $payment['amount'],
            'method' => $payment['method'],
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $exception) {
        return;
    }
}

function load_payment_notification_context(int $paymentId): ?array
{
    $orderColumns = payment_table_columns('orders');
    if (!$orderColumns) {
        return null;
    }

    $clientColumn = payment_find_column($orderColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
    if (!$clientColumn) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT o.' . $clientColumn . ' AS client_user_id, o.order_number, p.amount, p.method
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             WHERE p.id = :payment_id
             LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'client_user_id' => (int) $row['client_user_id'],
            'order_number' => trim((string) ($row['order_number'] ?? '')),
            'amount' => (float) ($row['amount'] ?? 0),
            'method' => strtolower((string) ($row['method'] ?? '')),
        ];
    } catch (PDOException $exception) {
        return null;
    }
}

function finalize_payment_review(int $paymentId, int $reviewedByUserId, string $decision, string $reason): bool
{
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['verified', 'rejected'], true)) {
        return false;
    }

    $payment = load_payment_row($paymentId);
    if (!$payment) {
        return false;
    }

    $updated = update_payment_status($paymentId, $decision);
    if (!$updated) {
        return false;
    }

    add_payment_review($paymentId, $reviewedByUserId, $decision, $reason);
    update_order_payment_status((int) $payment['order_id'], $decision);

    if ($decision === 'verified') {
        add_earnings_entry($paymentId);
    }

    $context = load_payment_notification_context($paymentId);
    if ($context && $context['client_user_id'] > 0) {
        $orderLabel = $context['order_number'] !== '' ? $context['order_number'] : 'Order #' . $payment['order_id'];
        $methodLabel = strtoupper(str_replace('_', ' ', $context['method']));
        $title = $decision === 'verified' ? 'Payment verified' : 'Payment rejected';
        $body = sprintf(
            '%s payment for %s (₱%s) has been %s.',
            $methodLabel !== '' ? $methodLabel : 'Payment',
            $orderLabel,
            number_format($context['amount'], 2),
            $decision
        );
        create_notification(
            $context['client_user_id'],
            $decision === 'verified' ? 'payment_verified' : 'payment_rejected',
            $title,
            $body,
            'payment',
            $paymentId
        );
    }

    return true;
}

function mark_cod_received(int $paymentId, int $reviewedByUserId, string $reason = 'COD received.'): bool
{
    $payment = load_payment_row($paymentId);
    if (!$payment) {
        return false;
    }

    $method = strtolower((string) ($payment['method'] ?? ''));
    if (!in_array($method, ['cod', 'pickup_cash'], true)) {
        return false;
    }

    return finalize_payment_review($paymentId, $reviewedByUserId, 'verified', $reason);
}