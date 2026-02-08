<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';
require_once __DIR__ . '/../handlers/inventory_handler.php';
require_once __DIR__ . '/../handlers/catalog_handler.php';

function load_active_shop_ids(): array
{
    $shopColumns = get_table_columns(db(), 'shops');
    if (!$shopColumns) {
        return [];
    }

    $ownerColumn = find_column($shopColumns, ['owner_user_id', 'owner_id', 'user_id']);
    if (!$ownerColumn) {
        return [];
    }

    $stmt = db()->query('SELECT id, ' . $ownerColumn . ' AS owner_user_id FROM shops');
    return $stmt->fetchAll() ?: [];
}

function load_shop_recipients(int $shopId, ?int $ownerUserId): array
{
    $recipients = [];
    if ($ownerUserId && $ownerUserId > 0) {
        $recipients[] = $ownerUserId;
    }

    $staffColumns = get_table_columns(db(), 'shop_staff');
    if ($staffColumns) {
        $roleColumn = find_column($staffColumns, ['role']);
        $statusColumn = find_column($staffColumns, ['status']);
        $userIdColumn = find_column($staffColumns, ['user_id']);
        $shopIdColumn = find_column($staffColumns, ['shop_id']);
        if ($roleColumn && $statusColumn && $userIdColumn && $shopIdColumn) {
            $stmt = db()->prepare(
                'SELECT ' . $userIdColumn . ' AS user_id
                 FROM shop_staff
                 WHERE ' . $shopIdColumn . ' = :shop_id
                 AND ' . $roleColumn . ' = :role
                 AND ' . $statusColumn . ' = :status'
            );
            $stmt->execute([
                'shop_id' => $shopId,
                'role' => 'hr',
                'status' => 'active',
            ]);
            foreach ($stmt->fetchAll() as $row) {
                $recipients[] = (int) $row['user_id'];
            }
        }
    }

    return array_values(array_unique(array_filter($recipients)));
}

function should_send_low_stock_notification(int $userId, string $body): bool
{
    $notificationColumns = get_table_columns(db(), 'notifications');
    if (!$notificationColumns) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT id
         FROM notifications
         WHERE user_id = :user_id
         AND type = :type
         AND body = :body
         AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => 'low_stock',
        'body' => $body,
    ]);

    return !$stmt->fetchColumn();
}

try {
    $shops = load_active_shop_ids();
    if (!$shops) {
        fwrite(STDERR, "No shops found.\n");
        exit(0);
    }

    $alertsSent = 0;
    foreach ($shops as $shop) {
        $shopId = (int) ($shop['id'] ?? 0);
        if ($shopId <= 0) {
            continue;
        }

        $materials = load_inventory_materials($shopId);
        if (!$materials) {
            continue;
        }

        $alerts = [];
        foreach ($materials as $material) {
            $stock = (float) ($material['stock_qty'] ?? 0);
            $reorderLevel = (float) ($material['reorder_level'] ?? 0);
            if ($reorderLevel > 0 && $stock < $reorderLevel) {
                $alerts[] = [
                    'name' => (string) ($material['name'] ?? ''),
                    'unit' => (string) ($material['unit'] ?? ''),
                    'stock' => $stock,
                    'reorder_level' => $reorderLevel,
                ];
            }
        }

        if (!$alerts) {
            continue;
        }

        $recipients = load_shop_recipients($shopId, isset($shop['owner_user_id']) ? (int) $shop['owner_user_id'] : null);
        if (!$recipients) {
            continue;
        }

        foreach ($alerts as $alert) {
            $materialName = $alert['name'] !== '' ? $alert['name'] : 'Material';
            $unit = $alert['unit'] !== '' ? ' ' . $alert['unit'] : '';
            $body = sprintf(
                '%s is below reorder level (%.2f%s left, reorder at %.2f%s).',
                $materialName,
                $alert['stock'],
                $unit,
                $alert['reorder_level'],
                $unit
            );
            $title = 'Low stock alert';

            foreach ($recipients as $recipientId) {
                if (!should_send_low_stock_notification($recipientId, $body)) {
                    continue;
                }
                create_notification(
                    $recipientId,
                    'low_stock',
                    $title,
                    $body,
                    null,
                    null
                );
                $alertsSent++;
            }
        }
    }

    printf("Sent %d low-stock alerts.\n", $alertsSent);
} catch (Throwable $exception) {
    fwrite(STDERR, "Failed to send low-stock alerts.\n");
    exit(1);
}