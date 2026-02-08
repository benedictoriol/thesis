<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../handlers/dss_handler.php';

if (!dss_metrics_tables_ready()) {
    fwrite(STDERR, "DSS metric tables are not available.\n");
    exit(1);
}

$job = dss_claim_recalculation_job();
$actorUserId = $job['triggered_by'] ?? null;

try {
    $metrics = dss_collect_shop_metrics();
    $stored = dss_persist_shop_metrics($metrics);
    $rankingSummary = dss_update_rankings();

    dss_log_recalculation($actorUserId, $stored, $metrics, $rankingSummary);

    if ($job) {
        dss_complete_recalculation_job((int) $job['id'], 'completed');
    }

    printf("Stored %d shop metric rows.\n", $stored);
    printf("Ranking update summary: shops=%d products=%d.\n", (int) $rankingSummary['shops_updated'], (int) $rankingSummary['products_updated']);
} catch (Throwable $exception) {
    if ($job) {
        dss_complete_recalculation_job((int) $job['id'], 'failed');
    }
    fwrite(STDERR, "Failed to recompute DSS metrics.\n");
    exit(1);
}