<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../includes/staff_helpers.php';

function productivity_tables_ready(): bool
{
    return table_exists('job_tickets')
        && table_exists('orders')
        && table_exists('productivity_metrics');
}

function compute_productivity_metrics(DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $shopId = null): array
{
    $filters = 'jt.status = :status AND jt.finished_at >= :start_date AND jt.finished_at < :end_date';
    $params = [
        'status' => 'done',
        'start_date' => $startDate->format('Y-m-d H:i:s'),
        'end_date' => $endDate->format('Y-m-d H:i:s'),
        'late_threshold' => 24,
    ];

    if ($shopId !== null) {
        $filters .= ' AND o.shop_id = :shop_id';
        $params['shop_id'] = $shopId;
    }

    $stmt = db()->prepare(
        'SELECT o.shop_id,
                jt.assigned_to_user_id AS user_id,
                DATE(jt.finished_at) AS metric_date,
                COUNT(*) AS jobs_done,
                AVG(CASE
                        WHEN jt.started_at IS NOT NULL AND jt.finished_at IS NOT NULL
                            THEN TIMESTAMPDIFF(MINUTE, jt.started_at, jt.finished_at) / 60
                        ELSE NULL
                    END) AS avg_turnaround_hours,
                SUM(CASE
                        WHEN jt.started_at IS NOT NULL
                             AND jt.finished_at IS NOT NULL
                             AND TIMESTAMPDIFF(HOUR, jt.started_at, jt.finished_at) > :late_threshold
                            THEN 1
                        ELSE 0
                    END) AS late_jobs
         FROM job_tickets jt
         JOIN orders o ON o.id = jt.order_id
         WHERE ' . $filters . '
         GROUP BY o.shop_id, jt.assigned_to_user_id, DATE(jt.finished_at)'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function persist_productivity_metrics(array $rows, DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $shopId = null): int
{
    if (!$rows) {
        return 0;
    }

    $deleteFilters = 'metric_date >= :start_date AND metric_date < :end_date';
    $deleteParams = [
        'start_date' => $startDate->format('Y-m-d'),
        'end_date' => $endDate->format('Y-m-d'),
    ];

    if ($shopId !== null) {
        $deleteFilters .= ' AND shop_id = :shop_id';
        $deleteParams['shop_id'] = $shopId;
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $deleteStmt = $pdo->prepare('DELETE FROM productivity_metrics WHERE ' . $deleteFilters);
        $deleteStmt->execute($deleteParams);

        $insertStmt = $pdo->prepare(
            'INSERT INTO productivity_metrics (
                shop_id,
                user_id,
                metric_date,
                jobs_done,
                avg_turnaround_hours,
                late_jobs,
                created_at
            ) VALUES (
                :shop_id,
                :user_id,
                :metric_date,
                :jobs_done,
                :avg_turnaround_hours,
                :late_jobs,
                :created_at
            )'
        );

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $inserted = 0;

        foreach ($rows as $row) {
            $insertStmt->execute([
                'shop_id' => (int) $row['shop_id'],
                'user_id' => (int) $row['user_id'],
                'metric_date' => $row['metric_date'],
                'jobs_done' => (int) $row['jobs_done'],
                'avg_turnaround_hours' => (float) ($row['avg_turnaround_hours'] ?? 0),
                'late_jobs' => (int) ($row['late_jobs'] ?? 0),
                'created_at' => $now,
            ]);
            $inserted++;
        }

        $pdo->commit();
        return $inserted;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 0;
    }
}

function compute_step_turnaround_metrics(DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $shopId = null): array
{
    $filters = 'jt.status = :status
        AND jt.started_at IS NOT NULL
        AND jt.finished_at IS NOT NULL
        AND jt.finished_at >= :start_date
        AND jt.finished_at < :end_date';
    $params = [
        'status' => 'done',
        'start_date' => $startDate->format('Y-m-d H:i:s'),
        'end_date' => $endDate->format('Y-m-d H:i:s'),
    ];

    if ($shopId !== null) {
        $filters .= ' AND o.shop_id = :shop_id';
        $params['shop_id'] = $shopId;
    }

    $stmt = db()->prepare(
        'SELECT o.shop_id,
                jt.assigned_to_user_id AS user_id,
                jt.step,
                COUNT(*) AS tickets_done,
                AVG(TIMESTAMPDIFF(MINUTE, jt.started_at, jt.finished_at) / 60) AS avg_turnaround_hours
         FROM job_tickets jt
         JOIN orders o ON o.id = jt.order_id
         WHERE ' . $filters . '
         GROUP BY o.shop_id, jt.assigned_to_user_id, jt.step'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function compute_productivity_leaderboard(DateTimeImmutable $startDate, DateTimeImmutable $endDate, int $limit = 10, ?int $shopId = null): array
{
    $filters = 'jt.status = :status AND jt.finished_at >= :start_date AND jt.finished_at < :end_date';
    $params = [
        'status' => 'done',
        'start_date' => $startDate->format('Y-m-d H:i:s'),
        'end_date' => $endDate->format('Y-m-d H:i:s'),
        'late_threshold' => 24,
    ];

    if ($shopId !== null) {
        $filters .= ' AND o.shop_id = :shop_id';
        $params['shop_id'] = $shopId;
    }

    $limit = max(1, $limit);
    $stmt = db()->prepare(
        'SELECT o.shop_id,
                jt.assigned_to_user_id AS user_id,
                COUNT(*) AS jobs_done,
                AVG(CASE
                        WHEN jt.started_at IS NOT NULL AND jt.finished_at IS NOT NULL
                            THEN TIMESTAMPDIFF(MINUTE, jt.started_at, jt.finished_at) / 60
                        ELSE NULL
                    END) AS avg_turnaround_hours,
                SUM(CASE
                        WHEN jt.started_at IS NOT NULL
                             AND jt.finished_at IS NOT NULL
                             AND TIMESTAMPDIFF(HOUR, jt.started_at, jt.finished_at) > :late_threshold
                            THEN 1
                        ELSE 0
                    END) AS late_jobs
         FROM job_tickets jt
         JOIN orders o ON o.id = jt.order_id
         WHERE ' . $filters . '
         GROUP BY o.shop_id, jt.assigned_to_user_id
         ORDER BY jobs_done DESC, avg_turnaround_hours ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function build_performance_flags(array $leaderboardRows): array
{
    $flags = [];

    foreach ($leaderboardRows as $row) {
        $userId = (int) $row['user_id'];
        $userFlags = [];

        $avgTurnaround = (float) ($row['avg_turnaround_hours'] ?? 0);
        $lateJobs = (int) ($row['late_jobs'] ?? 0);
        $jobsDone = (int) ($row['jobs_done'] ?? 0);

        if ($jobsDone === 0) {
            $userFlags[] = 'no_completed_jobs';
        }

        if ($avgTurnaround > 24) {
            $userFlags[] = 'slow_turnaround';
        }

        if ($lateJobs > 0) {
            $userFlags[] = 'late_jobs';
        }

        if ($userFlags) {
            $flags[$userId] = $userFlags;
        }
    }

    return $flags;
}
