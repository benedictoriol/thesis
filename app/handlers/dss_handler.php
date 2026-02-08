<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/audit.php';
require_once __DIR__ . '/../includes/dss_helpers.php';

function dss_get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
    } catch (PDOException $exception) {
        return [];
    }
}

function dss_metrics_tables_ready(): bool
{
    return dss_table_exists('shop_metrics')
        && dss_table_exists('shops')
        && dss_table_exists('orders');
}

function dss_collect_shop_metrics(): array
{
    $shopIds = db()->query('SELECT id FROM shops')->fetchAll(PDO::FETCH_COLUMN);
    if (!$shopIds) {
        return [];
    }

    $metrics = [];
    foreach ($shopIds as $shopId) {
        $metrics[(int) $shopId] = [
            'shop_id' => (int) $shopId,
            'avg_rating' => 0.0,
            'review_count' => 0,
            'completion_rate' => 0.0,
            'avg_turnaround_days' => 0.0,
            'cancellation_rate' => 0.0,
        ];
    }

    if (dss_table_exists('reviews')) {
        $rows = db()->query('SELECT shop_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM reviews GROUP BY shop_id')
            ->fetchAll();
        foreach ($rows as $row) {
            $shopId = (int) $row['shop_id'];
            if (!isset($metrics[$shopId])) {
                continue;
            }
            $metrics[$shopId]['avg_rating'] = (float) ($row['avg_rating'] ?? 0);
            $metrics[$shopId]['review_count'] = (int) ($row['review_count'] ?? 0);
        }
    }

    if (dss_table_exists('orders')) {
        $rows = db()->query(
            "SELECT shop_id,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status IN ('cancelled', 'rejected') THEN 1 ELSE 0 END) AS cancelled_count,
                    COUNT(*) AS total_count
             FROM orders
             GROUP BY shop_id"
        )->fetchAll();

        foreach ($rows as $row) {
            $shopId = (int) $row['shop_id'];
            if (!isset($metrics[$shopId])) {
                continue;
            }
            $total = (int) ($row['total_count'] ?? 0);
            $completed = (int) ($row['completed_count'] ?? 0);
            $cancelled = (int) ($row['cancelled_count'] ?? 0);

            $metrics[$shopId]['completion_rate'] = $total > 0 ? ($completed / $total) * 100 : 0.0;
            $metrics[$shopId]['cancellation_rate'] = $total > 0 ? ($cancelled / $total) * 100 : 0.0;
        }
    }

    if (dss_table_exists('order_status_logs')) {
        $rows = db()->query(
            "SELECT o.shop_id,
                    AVG(TIMESTAMPDIFF(HOUR, o.created_at, osl.completed_at) / 24) AS avg_turnaround_days
             FROM orders o
             INNER JOIN (
                 SELECT order_id, MAX(created_at) AS completed_at
                 FROM order_status_logs
                 WHERE status = 'completed'
                 GROUP BY order_id
             ) osl ON osl.order_id = o.id
             GROUP BY o.shop_id"
        )->fetchAll();

        foreach ($rows as $row) {
            $shopId = (int) $row['shop_id'];
            if (!isset($metrics[$shopId])) {
                continue;
            }
            $metrics[$shopId]['avg_turnaround_days'] = (float) ($row['avg_turnaround_days'] ?? 0);
        }
    }

    return $metrics;
}

function dss_persist_shop_metrics(array $metrics): int
{
    if (!$metrics) {
        return 0;
    }

    $stmt = db()->prepare(
        'INSERT INTO shop_metrics
            (shop_id, avg_rating, review_count, completion_rate, avg_turnaround_days, cancellation_rate, updated_at)
         VALUES
            (:shop_id, :avg_rating, :review_count, :completion_rate, :avg_turnaround_days, :cancellation_rate, :updated_at)
         ON DUPLICATE KEY UPDATE
            avg_rating = VALUES(avg_rating),
            review_count = VALUES(review_count),
            completion_rate = VALUES(completion_rate),
            avg_turnaround_days = VALUES(avg_turnaround_days),
            cancellation_rate = VALUES(cancellation_rate),
            updated_at = VALUES(updated_at)'
    );

    $updatedAt = gmdate('Y-m-d H:i:s');
    $count = 0;

    foreach ($metrics as $row) {
        $stmt->execute([
            'shop_id' => (int) $row['shop_id'],
            'avg_rating' => (float) $row['avg_rating'],
            'review_count' => (int) $row['review_count'],
            'completion_rate' => (float) $row['completion_rate'],
            'avg_turnaround_days' => (float) $row['avg_turnaround_days'],
            'cancellation_rate' => (float) $row['cancellation_rate'],
            'updated_at' => $updatedAt,
        ]);
        $count += $stmt->rowCount();
    }

    return $count;
}

function dss_update_rankings(): array
{
    $metricsColumns = dss_get_table_columns(db(), 'shop_metrics');
    $availabilityColumns = dss_get_table_columns(db(), 'shop_availability');
    $shopColumns = dss_get_table_columns(db(), 'shops');
    $productColumns = dss_get_table_columns(db(), 'products');

    $weights = dss_load_weights();
    $bounds = dss_load_metric_bounds($metricsColumns);
    $scoreInfo = dss_build_score_sql(
        $weights,
        $bounds,
        $metricsColumns,
        $availabilityColumns,
        null,
        null
    );

    $scoreSql = $scoreInfo['sql'];
    $params = $scoreInfo['params'];

    $summary = [
        'score_sql' => $scoreSql,
        'shops_updated' => 0,
        'products_updated' => 0,
        'top_shops' => [],
    ];

    if ($scoreSql === '0') {
        return $summary;
    }

    if (in_array('dss_score', $shopColumns, true)) {
        $shopJoins = [];
        if ($metricsColumns) {
            $shopJoins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = s.id';
        }
        if ($availabilityColumns) {
            $shopJoins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = s.id';
        }
        $shopJoinSql = $shopJoins ? "\n" . implode("\n", $shopJoins) : '';

        $shopSql = "UPDATE shops s\n$shopJoinSql\nSET s.dss_score = $scoreSql";
        if (in_array('status', $shopColumns, true)) {
            $shopSql .= "\nWHERE s.status = 'active'";
        }

        $stmt = db()->prepare($shopSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $summary['shops_updated'] = $stmt->rowCount();
    }

    if (in_array('dss_score', $productColumns, true)) {
        $productJoins = [
            'LEFT JOIN shops s ON s.id = p.shop_id',
        ];
        if ($metricsColumns) {
            $productJoins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = p.shop_id';
        }
        if ($availabilityColumns) {
            $productJoins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = p.shop_id';
        }
        $productJoinSql = implode("\n", $productJoins);

        $productSql = "UPDATE products p\n$productJoinSql\nSET p.dss_score = $scoreSql";
        if (in_array('status', $productColumns, true)) {
            $productSql .= "\nWHERE p.status = 'active'";
        }

        $stmt = db()->prepare($productSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $summary['products_updated'] = $stmt->rowCount();
    }

    $shopJoins = [];
    if ($metricsColumns) {
        $shopJoins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = s.id';
    }
    if ($availabilityColumns) {
        $shopJoins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = s.id';
    }
    $shopJoinSql = $shopJoins ? "\n" . implode("\n", $shopJoins) : '';

    $topSql = "SELECT s.id, s.name, $scoreSql AS score\nFROM shops s\n$shopJoinSql";
    if (in_array('status', $shopColumns, true)) {
        $topSql .= "\nWHERE s.status = 'active'";
    }
    $topSql .= "\nORDER BY score DESC, s.id DESC\nLIMIT 5";

    $stmt = db()->prepare($topSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    $summary['top_shops'] = $stmt->fetchAll();

    return $summary;
}

function dss_claim_recalculation_job(): ?array
{
    if (!dss_table_exists('dss_recalculation_jobs')) {
        return null;
    }

    $stmt = db()->query(
        "SELECT id, triggered_by
         FROM dss_recalculation_jobs
         WHERE status = 'queued'
         ORDER BY created_at ASC
         LIMIT 1"
    );
    $job = $stmt->fetch();

    if (!$job) {
        return null;
    }

    $update = db()->prepare('UPDATE dss_recalculation_jobs SET status = :status WHERE id = :id');
    $update->execute([
        'status' => 'processing',
        'id' => (int) $job['id'],
    ]);

    return [
        'id' => (int) $job['id'],
        'triggered_by' => (int) $job['triggered_by'],
    ];
}

function dss_complete_recalculation_job(int $jobId, string $status): void
{
    if (!dss_table_exists('dss_recalculation_jobs')) {
        return;
    }

    $stmt = db()->prepare(
        'UPDATE dss_recalculation_jobs
         SET status = :status, completed_at = :completed_at
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'completed_at' => gmdate('Y-m-d H:i:s'),
        'id' => $jobId,
    ]);
}

function dss_log_recalculation(?int $actorUserId, int $metricsRows, array $metrics, array $rankingSummary): void
{
    $meta = [
        'metrics_rows' => $metricsRows,
        'shops_with_metrics' => count($metrics),
        'ranking_summary' => $rankingSummary,
    ];

    try {
        audit_log($actorUserId, 'dss_recompute', 'dss', null, $meta);
    } catch (Throwable $exception) {
        return;
    }
}