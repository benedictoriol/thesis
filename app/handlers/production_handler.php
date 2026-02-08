<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../handlers/order_handler.php';
require_once __DIR__ . '/../includes/staff_helpers.php';

function normalize_production_item_type(string $itemType): string
{
    $normalized = strtolower(trim($itemType));
    $normalized = str_replace(['-', '_'], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    return $normalized;
}

function production_steps_for_item_type(string $itemType): array
{
    $normalized = normalize_production_item_type($itemType);

    if ($normalized === 'logo embroidery' || $normalized === 'logo' || $normalized === 'embroidery logo') {
        return ['digitizing', 'stitching', 'qc', 'packing'];
    }

    if ($normalized === 'tshirt embroidery' || $normalized === 't shirt embroidery' || $normalized === 'tshirt') {
        return ['digitizing', 'hooping', 'stitching', 'trimming', 'qc', 'packing'];
    }

    return [];
}

function fetch_order_context(int $orderId): ?array
{
    $ordersColumns = order_table_columns('orders');
    $shopIdColumn = order_find_column($ordersColumns, ['shop_id', 'vendor_id']);
    if (!$shopIdColumn) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT o.id, o.' . $shopIdColumn . ' AS shop_id, s.owner_user_id
         FROM orders o
         JOIN shops s ON s.id = o.' . $shopIdColumn . '
         WHERE o.id = :order_id
         LIMIT 1'
    );
    $stmt->execute(['order_id' => $orderId]);

    return $stmt->fetch() ?: null;
}

function resolve_default_employee_for_step(int $shopId, string $step): ?int
{
    if (table_exists('shop_step_defaults')) {
        try {
            $stmt = db()->prepare(
                'SELECT user_id
                 FROM shop_step_defaults
                 WHERE shop_id = :shop_id
                 AND step = :step
                 LIMIT 1'
            );
            $stmt->execute([
                'shop_id' => $shopId,
                'step' => $step,
            ]);
            $userId = (int) $stmt->fetchColumn();
            if ($userId > 0) {
                return $userId;
            }
        } catch (PDOException $exception) {
            return null;
        }
    }

    try {
        $stmt = db()->prepare(
            'SELECT ss.user_id
             FROM shop_staff ss
             WHERE ss.shop_id = :shop_id
             AND ss.role = :role
             AND ss.status = :status
             AND ss.position = :position
             LIMIT 1'
        );
        $stmt->execute([
            'shop_id' => $shopId,
            'role' => 'employee',
            'status' => 'active',
            'position' => $step,
        ]);
        $userId = (int) $stmt->fetchColumn();
        if ($userId > 0) {
            return $userId;
        }
    } catch (PDOException $exception) {
        return null;
    }

    return null;
}

function resolve_fallback_assignee(int $shopId, ?int $ownerUserId): ?int
{
    if ($ownerUserId && $ownerUserId > 0) {
        return $ownerUserId;
    }

    try {
        $stmt = db()->prepare(
            'SELECT user_id
             FROM shop_staff
             WHERE shop_id = :shop_id
             AND role = :role
             AND status = :status
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'shop_id' => $shopId,
            'role' => 'employee',
            'status' => 'active',
        ]);
        $userId = (int) $stmt->fetchColumn();
        if ($userId > 0) {
            return $userId;
        }
    } catch (PDOException $exception) {
        return null;
    }

    return null;
}

function generate_tickets(int $orderId, string $itemType): array
{
    if ($orderId <= 0 || !table_exists('job_tickets')) {
        return [];
    }

    $steps = production_steps_for_item_type($itemType);
    if (!$steps) {
        return [];
    }

    $orderContext = fetch_order_context($orderId);
    if (!$orderContext) {
        return [];
    }

    $existingSteps = [];
    try {
        $stmt = db()->prepare('SELECT step FROM job_tickets WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        $existingSteps = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $exception) {
        return [];
    }

    $newTicketIds = [];
    $assigneeFallback = resolve_fallback_assignee((int) $orderContext['shop_id'], (int) $orderContext['owner_user_id']);

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            'INSERT INTO job_tickets (order_id, step, assigned_to_user_id, status, started_at, finished_at)
             VALUES (:order_id, :step, :assigned_to_user_id, :status, :started_at, :finished_at)'
        );

        foreach ($steps as $step) {
            if (in_array($step, $existingSteps, true)) {
                continue;
            }

            $assignee = resolve_default_employee_for_step((int) $orderContext['shop_id'], $step) ?? $assigneeFallback;
            if (!$assignee) {
                continue;
            }

            $insert->execute([
                'order_id' => $orderId,
                'step' => $step,
                'assigned_to_user_id' => $assignee,
                'status' => 'queued',
                'started_at' => null,
                'finished_at' => null,
            ]);

            $newTicketIds[] = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [];
    }

    return $newTicketIds;
}

function fetch_order_status(int $orderId): ?string
{
    $ordersColumns = order_table_columns('orders');
    $statusColumn = order_find_column($ordersColumns, ['status', 'order_status']);
    if (!$statusColumn) {
        return null;
    }

    $stmt = db()->prepare('SELECT ' . $statusColumn . ' AS order_status FROM orders WHERE id = :order_id');
    $stmt->execute(['order_id' => $orderId]);
    $status = $stmt->fetchColumn();

    return $status !== false ? strtolower((string) $status) : null;
}

function mark_order_in_production(int $orderId, ?int $userId = null): void
{
    $status = fetch_order_status($orderId);
    if (!$status || $status === 'in_progress') {
        return;
    }

    if ($status === 'pending') {
        update_order_status($orderId, $status, 'in_progress', $userId, 'Order moved to production.');
    }
}

function mark_order_ready_if_complete(int $orderId, ?int $userId = null): void
{
    $status = fetch_order_status($orderId);
    if (!$status || $status !== 'in_progress') {
        return;
    }

    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM job_tickets
         WHERE order_id = :order_id
         AND status <> 'done'"
    );
    $stmt->execute(['order_id' => $orderId]);
    $remaining = (int) $stmt->fetchColumn();

    if ($remaining === 0) {
        update_order_status($orderId, $status, 'ready', $userId, 'All production tickets completed.');
    }
}

function apply_ticket_status_updates(int $orderId, string $ticketStatus, ?int $userId = null): void
{
    if (!table_exists('job_tickets')) {
        return;
    }

    if ($ticketStatus === 'working') {
        mark_order_in_production($orderId, $userId);
    }

    if ($ticketStatus === 'done') {
        mark_order_ready_if_complete($orderId, $userId);
    }
}