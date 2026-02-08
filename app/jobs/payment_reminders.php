<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';

function reminder_table_columns(string $table): array
{
    try {
        $stmt = db()->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

function reminder_find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$reminderDays = (int) (getenv('PAYMENT_REMINDER_DAYS') ?: 7);
$cooldownDays = (int) (getenv('PAYMENT_REMINDER_COOLDOWN_DAYS') ?: $reminderDays);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutoff = $now->modify(sprintf('-%d days', max($reminderDays, 0)));
$cooldownCutoff = $now->modify(sprintf('-%d days', max($cooldownDays, 0)));

$orderColumns = reminder_table_columns('orders');
$clientColumn = reminder_find_column($orderColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);

if (!$clientColumn) {
    fwrite(STDERR, "Unable to determine client column for orders.\n");
    exit(1);
}

try {
    $stmt = db()->prepare(
        'SELECT p.id, p.order_id, p.amount, p.method, p.status, p.created_at,
                o.' . $clientColumn . ' AS client_user_id, o.order_number
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         WHERE p.status IN (\'unpaid\', \'pending_proof\')
         AND p.created_at < :cutoff'
    );
    $stmt->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s')]);
    $payments = $stmt->fetchAll();
} catch (PDOException $exception) {
    fwrite(STDERR, "Failed to fetch unpaid payments.\n");
    exit(1);
}

if (!$payments) {
    printf("Sent 0 payment reminders.\n");
    exit(0);
}

$paymentIds = array_map(static fn(array $row) => (int) $row['id'], $payments);
$placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
$lastReminders = [];

try {
    $reminderStmt = db()->prepare(
        'SELECT link_id, MAX(created_at) AS last_sent
         FROM notifications
         WHERE type = ?
         AND link_type = ?
         AND link_id IN (' . $placeholders . ')
         GROUP BY link_id'
    );
    $params = array_merge(['payment_reminder', 'payment'], $paymentIds);
    $reminderStmt->execute($params);
    foreach ($reminderStmt->fetchAll() as $row) {
        $lastReminders[(int) $row['link_id']] = $row['last_sent'];
    }
} catch (PDOException $exception) {
    $lastReminders = [];
}

$sentCount = 0;

foreach ($payments as $payment) {
    $paymentId = (int) $payment['id'];
    $clientId = (int) $payment['client_user_id'];

    if ($clientId <= 0) {
        continue;
    }

    $lastSent = $lastReminders[$paymentId] ?? null;
    if ($lastSent) {
        $lastSentAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastSent, new DateTimeZone('UTC'));
        if ($lastSentAt && $lastSentAt >= $cooldownCutoff) {
            continue;
        }
    }

    $orderLabel = trim((string) ($payment['order_number'] ?? ''));
    if ($orderLabel === '') {
        $orderLabel = 'Order #' . (int) $payment['order_id'];
    }
    $methodLabel = strtoupper(str_replace('_', ' ', (string) ($payment['method'] ?? 'payment')));
    $amountLabel = number_format((float) $payment['amount'], 2);
    $title = 'Payment reminder';
    $body = sprintf(
        'Reminder: %s for %s (₱%s) is still marked as %s.',
        $methodLabel,
        $orderLabel,
        $amountLabel,
        strtoupper((string) ($payment['status'] ?? 'unpaid'))
    );

    create_notification(
        $clientId,
        'payment_reminder',
        $title,
        $body,
        'payment',
        $paymentId
    );

    $sentCount++;
}

printf("Sent %d payment reminders.\n", $sentCount);
