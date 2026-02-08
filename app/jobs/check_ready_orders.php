<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../handlers/production_handler.php';

if (!table_exists('job_tickets')) {
    fwrite(STDERR, "Job tickets are not available.\n");
    exit(1);
}

try {
    $stmt = db()->query(
        "SELECT jt.order_id
         FROM job_tickets jt
         GROUP BY jt.order_id
         HAVING SUM(CASE WHEN jt.status <> 'done' THEN 1 ELSE 0 END) = 0"
    );
    $orderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$orderIds) {
        printf("Marked 0 orders ready.\n");
        exit(0);
    }

    $updatedCount = 0;
    foreach ($orderIds as $orderId) {
        $before = fetch_order_status($orderId);
        if ($before !== 'in_progress') {
            continue;
        }

        mark_order_ready_if_complete($orderId, null);
        $after = fetch_order_status($orderId);
        if ($after === 'ready' && $before !== $after) {
            $updatedCount++;
        }
    }

    printf("Marked %d orders ready.\n", $updatedCount);
} catch (Throwable $exception) {
    fwrite(STDERR, "Failed to check ready orders.\n");
    exit(1);
}
