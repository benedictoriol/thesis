<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../handlers/productivity_handler.php';

if (!productivity_tables_ready()) {
    fwrite(STDERR, "Productivity tables are not available.\n");
    exit(1);
}

$options = [];
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }
    [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
    if ($key !== '') {
        $options[$key] = $value;
    }
}

$timezone = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $timezone);
$defaultStart = $now->modify('-30 days')->setTime(0, 0, 0);
$defaultEnd = $now->modify('+1 day')->setTime(0, 0, 0);

$startInput = $options['start'] ?? null;
$endInput = $options['end'] ?? null;
$shopInput = $options['shop'] ?? null;
$limitInput = $options['limit'] ?? null;

$startDate = $defaultStart;
$endDate = $defaultEnd;
$shopId = null;
$limit = 10;

if ($startInput) {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $startInput, $timezone);
    if ($parsed instanceof DateTimeImmutable) {
        $startDate = $parsed->setTime(0, 0, 0);
    }
}

if ($endInput) {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $endInput, $timezone);
    if ($parsed instanceof DateTimeImmutable) {
        $endDate = $parsed->modify('+1 day')->setTime(0, 0, 0);
    }
}

if ($shopInput !== null && is_numeric($shopInput)) {
    $shopId = (int) $shopInput;
}

if ($limitInput !== null && is_numeric($limitInput)) {
    $limit = max(1, (int) $limitInput);
}

if ($startDate >= $endDate) {
    fwrite(STDERR, "Invalid date range provided.\n");
    exit(1);
}

try {
    $metrics = compute_productivity_metrics($startDate, $endDate, $shopId);
    $inserted = persist_productivity_metrics($metrics, $startDate, $endDate, $shopId);

    $stepMetrics = compute_step_turnaround_metrics($startDate, $endDate, $shopId);
    $leaderboard = compute_productivity_leaderboard($startDate, $endDate, $limit, $shopId);
    $flags = build_performance_flags($leaderboard);

    printf(
        "Stored %d productivity rows for %s to %s.\n",
        $inserted,
        $startDate->format('Y-m-d'),
        $endDate->modify('-1 day')->format('Y-m-d')
    );

    printf("Computed %d step averages.\n", count($stepMetrics));
    printf("Leaderboard entries: %d.\n", count($leaderboard));

    if ($flags) {
        foreach ($flags as $userId => $userFlags) {
            printf("User %d flags: %s\n", $userId, implode(', ', $userFlags));
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Failed to recompute productivity metrics.\n");
    exit(1);
}
