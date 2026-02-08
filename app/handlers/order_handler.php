<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';
require_once __DIR__ . '/../handlers/inventory_handler.php';

function order_table_columns(string $table): array
{
    try {
        $stmt = db()->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
    } catch (PDOException $exception) {
        return [];
    }
}

function order_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

function order_find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function normalize_order_status(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'accepted') {
        return 'in_progress';
    }

    return $status;
}

function transition_order_status(string $from, string $to): bool
{
    $from = normalize_order_status($from);
    $to = normalize_order_status($to);

    if ($from === '' || $to === '' || $from === $to) {
        return false;
    }

    $allowedTransitions = [
        'pending' => ['in_progress', 'rejected', 'cancelled'],
        'in_progress' => ['ready', 'cancelled'],
        'ready' => ['completed'],
    ];

    return in_array($to, $allowedTransitions[$from] ?? [], true);
}

function generate_order_number(int $orderId, ?string $createdAt = null): string
{
    $year = $createdAt ? gmdate('Y', strtotime($createdAt)) : gmdate('Y');
    return sprintf('CVT-%s-%06d', $year, $orderId);
}

function ensure_order_number(int $orderId): ?string
{
    $ordersColumns = order_table_columns('orders');
    if (!$ordersColumns || !in_array('order_number', $ordersColumns, true)) {
        return null;
    }

    $stmt = db()->prepare('SELECT order_number, created_at FROM orders WHERE id = :id');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $existing = trim((string) ($order['order_number'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $orderNumber = generate_order_number($orderId, $order['created_at'] ?? null);
    $update = db()->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id');
    $update->execute([
        'order_number' => $orderNumber,
        'id' => $orderId,
    ]);

    return $orderNumber;
}

function log_order_status(int $orderId, string $status, ?string $note, ?int $userId): void
{
    if (!order_table_exists('order_status_logs')) {
        return;
    }

    $logColumns = order_table_columns('order_status_logs');
    $logOrderIdColumn = order_find_column($logColumns, ['order_id']);
    $logStatusColumn = order_find_column($logColumns, ['status', 'order_status']);
    $logUserColumn = order_find_column($logColumns, ['changed_by_user_id', 'user_id']);
    $logNoteColumn = order_find_column($logColumns, ['note', 'remarks']);
    $logCreatedColumn = order_find_column($logColumns, ['created_at', 'created_on']);

    if (!$logOrderIdColumn || !$logStatusColumn) {
        return;
    }

    $fields = [$logOrderIdColumn, $logStatusColumn];
    $values = [':order_id', ':status'];
    $params = [
        'order_id' => $orderId,
        'status' => $status,
    ];

    if ($logUserColumn) {
        $fields[] = $logUserColumn;
        $values[] = ':user_id';
        $params['user_id'] = $userId;
    }
    if ($logNoteColumn) {
        $fields[] = $logNoteColumn;
        $values[] = ':note';
        $params['note'] = $note;
    }
    if ($logCreatedColumn) {
        $fields[] = $logCreatedColumn;
        $values[] = ':created_at';
        $params['created_at'] = gmdate('Y-m-d H:i:s');
    }

    $stmt = db()->prepare(
        'INSERT INTO order_status_logs (' . implode(', ', $fields) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);
}

function notify_client_order_status(int $orderId, string $status, ?string $note): void
{
    $ordersColumns = order_table_columns('orders');
    if (!$ordersColumns) {
        return;
    }

    $clientColumn = order_find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
    if (!$clientColumn) {
        return;
    }

    $select = ['id', "o.$clientColumn AS client_user_id", 'o.order_number'];
    $stmt = db()->prepare(
        'SELECT ' . implode(', ', $select) . '
         FROM orders o
         WHERE o.id = :order_id
         LIMIT 1'
    );
    $stmt->execute(['order_id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return;
    }

    $clientId = (int) $order['client_user_id'];
    if ($clientId <= 0) {
        return;
    }

    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    if ($orderNumber === '') {
        $orderNumber = 'Order #' . $orderId;
    }

    $statusLabel = ucwords(str_replace('_', ' ', normalize_order_status($status)));
    $title = 'Order status updated';
    $body = sprintf('%s is now %s.', $orderNumber, $statusLabel);
    if ($note) {
        $body .= ' ' . $note;
    }

    create_notification(
        $clientId,
        'order_status_changed',
        $title,
        $body,
        'order',
        $orderId
    );
}

function update_order_status(
    int $orderId,
    string $from,
    string $to,
    ?int $userId = null,
    ?string $note = null
): bool {
    if (!transition_order_status($from, $to)) {
        return false;
    }

    $ordersColumns = order_table_columns('orders');
    $statusColumn = order_find_column($ordersColumns, ['status', 'order_status']);
    if (!$statusColumn) {
        return false;
    }

    $normalizedTo = normalize_order_status($to);

    try {
        $stmt = db()->prepare('UPDATE orders SET ' . $statusColumn . ' = :status WHERE id = :order_id');
        $stmt->execute([
            'status' => $normalizedTo,
            'order_id' => $orderId,
        ]);

        log_order_status($orderId, $normalizedTo, $note, $userId);
        notify_client_order_status($orderId, $normalizedTo, $note);
        if ($normalizedTo === 'in_progress') {
            consume_materials($orderId);
        }
        return true;
    } catch (PDOException $exception) {
        return false;
    }
}