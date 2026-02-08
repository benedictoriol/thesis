<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';
require_once __DIR__ . '/../../handlers/inventory_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Reports & Analytics';
$errors = [];

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$orderStatusTotals = [];
$earningsByMonth = [];
$quoteStats = [
    'total' => 0,
    'accepted' => 0,
];
$lowStockItems = [];
$productivitySummary = [
    'entries' => 0,
    'jobs_done' => 0,
    'avg_turnaround_hours' => 0,
    'late_jobs' => 0,
];
$payrollSummary = null;

if ($shop && !$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT status, COUNT(*) AS total
             FROM orders
             WHERE shop_id = :shop_id
             GROUP BY status'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $orderStatusTotals = $stmt->fetchAll();

        $stmt = db()->prepare(
            'SELECT DATE_FORMAT(p.created_at, "%Y-%m") AS month,
                    SUM(p.amount) AS total
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             WHERE o.shop_id = :shop_id
               AND o.status = "completed"
               AND (p.status = "verified" OR p.method IN ("cod", "pickup_cash"))
             GROUP BY month
             ORDER BY month DESC
             LIMIT 6'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $earningsByMonth = array_reverse($stmt->fetchAll());

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN q.status = "accepted" THEN 1 ELSE 0 END) AS accepted
             FROM quotes q
             JOIN quote_requests qr ON qr.id = q.quote_request_id
             WHERE qr.shop_id = :shop_id'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $quoteStats = $stmt->fetch() ?: $quoteStats;

        $materials = load_inventory_materials((int) $shop['id']);
        foreach ($materials as $material) {
            $stock = (float) ($material['stock_qty'] ?? 0);
            $reorderLevel = (float) ($material['reorder_level'] ?? 0);
            if ($reorderLevel > 0 && $stock < $reorderLevel) {
                $lowStockItems[] = [
                    'name' => $material['name'] ?? 'Unnamed material',
                    'stock' => $stock,
                    'unit' => $material['unit'] ?? '',
                    'reorder_level' => $reorderLevel,
                ];
            }
        }

        $rangeStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-30 days')
            ->format('Y-m-d');
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS entries,
                    COALESCE(SUM(jobs_done), 0) AS jobs_done,
                    COALESCE(AVG(avg_turnaround_hours), 0) AS avg_turnaround_hours,
                    COALESCE(SUM(late_jobs), 0) AS late_jobs
             FROM productivity_metrics
             WHERE shop_id = :shop_id
               AND metric_date >= :start_date'
        );
        $stmt->execute([
            'shop_id' => $shop['id'],
            'start_date' => $rangeStart,
        ]);
        $productivitySummary = $stmt->fetch() ?: $productivitySummary;

        $stmt = db()->prepare(
            'SELECT p.id,
                    p.start_date,
                    p.end_date,
                    p.status,
                    COALESCE(SUM(e.net_pay), 0) AS total_net_pay,
                    COUNT(e.id) AS entry_count
             FROM payroll_periods p
             LEFT JOIN payroll_entries e ON e.period_id = p.id
             WHERE p.shop_id = :shop_id
             GROUP BY p.id
             ORDER BY p.end_date DESC
             LIMIT 1'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $payrollSummary = $stmt->fetch() ?: null;
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load reporting data right now.';
    }
}

$quoteTotal = (int) ($quoteStats['total'] ?? 0);
$quoteAccepted = (int) ($quoteStats['accepted'] ?? 0);
$conversionRate = $quoteTotal > 0 ? ($quoteAccepted / $quoteTotal) * 100 : 0;

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Reports &amp; Analytics</h1>
        <p class="text-muted mb-0">Shop-level snapshots for orders, finance, inventory, and people operations.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Orders by status</h2>
                    <?php if (!$orderStatusTotals): ?>
                        <p class="text-muted mb-0">No orders yet.</p>
                    <?php endif; ?>
                    <?php foreach ($orderStatusTotals as $row): ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-capitalize"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Earnings by month</h2>
                    <?php if (!$earningsByMonth): ?>
                        <p class="text-muted mb-0">No earnings recorded yet.</p>
                    <?php endif; ?>
                    <?php foreach ($earningsByMonth as $row): ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span><?= htmlspecialchars($row['month'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong>₱<?= number_format((float) $row['total'], 2) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Quote conversion</h2>
                    <div class="display-6 fw-semibold mb-2">
                        <?= number_format($conversionRate, 1) ?>%
                    </div>
                    <p class="text-muted mb-2">
                        <?= htmlspecialchars((string) $quoteAccepted, ENT_QUOTES, 'UTF-8') ?> accepted out of
                        <?= htmlspecialchars((string) $quoteTotal, ENT_QUOTES, 'UTF-8') ?> quotes sent.
                    </p>
                    <span class="badge bg-light text-dark">Rolling total</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Inventory low stock list</h2>
                    <?php if (!$lowStockItems): ?>
                        <p class="text-muted mb-0">All tracked materials are above reorder levels.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                <tr>
                                    <th>Material</th>
                                    <th class="text-end">Stock</th>
                                    <th class="text-end">Reorder level</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end">
                                            <?= number_format($item['stock'], 2) ?>
                                            <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="text-end">
                                            <?= number_format($item['reorder_level'], 2) ?>
                                            <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted">Productivity summary (last 30 days)</h2>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Jobs completed</div>
                                <div class="h4 mb-0"><?= number_format((int) $productivitySummary['jobs_done']) ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Avg turnaround (hrs)</div>
                                <div class="h4 mb-0"><?= number_format((float) $productivitySummary['avg_turnaround_hours'], 1) ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Late jobs</div>
                                <div class="h4 mb-0"><?= number_format((int) $productivitySummary['late_jobs']) ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Metric entries</div>
                                <div class="h4 mb-0"><?= number_format((int) $productivitySummary['entries']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-muted">Payroll summary</h2>
            <?php if (!$payrollSummary): ?>
                <p class="text-muted mb-0">No payroll periods created yet.</p>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-muted small">Latest period</div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($payrollSummary['start_date'], ENT_QUOTES, 'UTF-8') ?> →
                            <?= htmlspecialchars($payrollSummary['end_date'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Status</div>
                        <div class="fw-semibold text-capitalize"><?= htmlspecialchars($payrollSummary['status'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Entries</div>
                        <div class="fw-semibold"><?= number_format((int) $payrollSummary['entry_count']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Total net pay</div>
                        <div class="fw-semibold">₱<?= number_format((float) $payrollSummary['total_net_pay'], 2) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
