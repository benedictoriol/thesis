<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Earnings Dashboard';
$errors = [];
$earningsSummary = [
    'order_count' => 0,
    'total_earnings' => 0.0,
    'cash_earnings' => 0.0,
    'non_cash_earnings' => 0.0,
];
$earningRows = [];

$rangeOptions = [
    'daily' => 'Today',
    'weekly' => 'This week',
    'monthly' => 'This month',
    'yearly' => 'This year',
    'all' => 'All time',
];
$range = strtolower(trim((string) ($_GET['range'] ?? 'monthly')));
if (!array_key_exists($range, $rangeOptions)) {
    $range = 'monthly';
}

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$rangeStart = null;
$rangeEnd = null;
$rangeLabel = $rangeOptions[$range] ?? 'This month';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

function earnings_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

switch ($range) {
    case 'daily':
        $rangeStart = $now->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+1 day');
        break;
    case 'weekly':
        $rangeStart = $now->modify('monday this week')->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+1 week');
        break;
    case 'monthly':
        $rangeStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+1 month');
        break;
    case 'yearly':
        $rangeStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+1 year');
        break;
    case 'all':
    default:
        $rangeStart = null;
        $rangeEnd = null;
        break;
}

if (!$errors) {
    try {
        $params = [
            'shop_id' => $shop['id'],
            'completed' => 'completed',
            'verified' => 'verified',
        ];

        if (earnings_table_exists('earnings_entries')) {
            $filters = 'o.shop_id = :shop_id AND o.status = :completed';

        if ($rangeStart instanceof DateTimeImmutable) {
                $filters .= ' AND e.created_at >= :range_start';
                $params['range_start'] = $rangeStart->format('Y-m-d H:i:s');
            }
            if ($rangeEnd instanceof DateTimeImmutable) {
                $filters .= ' AND e.created_at < :range_end';
                $params['range_end'] = $rangeEnd->format('Y-m-d H:i:s');
            }

            $stmt = db()->prepare(
                'SELECT
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(e.amount), 0) AS total_earnings,
                    COALESCE(SUM(CASE WHEN e.method IN (\'cod\', \'pickup_cash\') THEN e.amount ELSE 0 END), 0) AS cash_earnings,
                    COALESCE(SUM(CASE WHEN e.method NOT IN (\'cod\', \'pickup_cash\') THEN e.amount ELSE 0 END), 0) AS non_cash_earnings
                 FROM earnings_entries e
                 JOIN orders o ON o.id = e.order_id
                 WHERE ' . $filters
            );
            $stmt->execute($params);
            $summary = $stmt->fetch();
            if ($summary) {
                $earningsSummary = [
                    'order_count' => (int) ($summary['order_count'] ?? 0),
                    'total_earnings' => (float) ($summary['total_earnings'] ?? 0),
                    'cash_earnings' => (float) ($summary['cash_earnings'] ?? 0),
                    'non_cash_earnings' => (float) ($summary['non_cash_earnings'] ?? 0),
                ];
            }

            $stmt = db()->prepare(
                'SELECT
                    o.id,
                    o.created_at AS order_created_at,
                    u.fullname AS client_name,
                    e.amount,
                    e.method,
                    e.created_at AS entry_created_at
                 FROM earnings_entries e
                 JOIN orders o ON o.id = e.order_id
                 JOIN users u ON u.id = o.client_user_id
                 WHERE ' . $filters . '
                 ORDER BY e.created_at DESC, o.id DESC
                 LIMIT 25'
            );
            $stmt->execute($params);
            $earningRows = $stmt->fetchAll();
        } else {
            $filters = 'o.shop_id = :shop_id AND o.status = :completed AND (p.status = :verified OR p.method IN (\'cod\', \'pickup_cash\'))';

            if ($rangeStart instanceof DateTimeImmutable) {
                $filters .= ' AND p.created_at >= :range_start';
                $params['range_start'] = $rangeStart->format('Y-m-d H:i:s');
            }
            if ($rangeEnd instanceof DateTimeImmutable) {
                $filters .= ' AND p.created_at < :range_end';
                $params['range_end'] = $rangeEnd->format('Y-m-d H:i:s');
            }

        $stmt = db()->prepare(
                'SELECT
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(p.amount), 0) AS total_earnings,
                    COALESCE(SUM(CASE WHEN p.method IN (\'cod\', \'pickup_cash\') THEN p.amount ELSE 0 END), 0) AS cash_earnings,
                    COALESCE(SUM(CASE WHEN p.method NOT IN (\'cod\', \'pickup_cash\') THEN p.amount ELSE 0 END), 0) AS non_cash_earnings
                 FROM orders o
                 JOIN payments p ON p.order_id = o.id
                 WHERE ' . $filters
            );
            $stmt->execute($params);
            $summary = $stmt->fetch();
            if ($summary) {
                $earningsSummary = [
                    'order_count' => (int) ($summary['order_count'] ?? 0),
                    'total_earnings' => (float) ($summary['total_earnings'] ?? 0),
                    'cash_earnings' => (float) ($summary['cash_earnings'] ?? 0),
                    'non_cash_earnings' => (float) ($summary['non_cash_earnings'] ?? 0),
                ];
            }

            $stmt = db()->prepare(
                'SELECT
                    o.id,
                    o.created_at AS order_created_at,
                    u.fullname AS client_name,
                    p.amount,
                    p.method,
                    p.status AS payment_status,
                    p.created_at AS payment_created_at
                 FROM orders o
                 JOIN payments p ON p.order_id = o.id
                 JOIN users u ON u.id = o.client_user_id
                 WHERE ' . $filters . '
                 ORDER BY p.created_at DESC, o.id DESC
                 LIMIT 25'
            );
            $stmt->execute($params);
            $earningRows = $stmt->fetchAll();
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load earnings right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Earnings Dashboard</h1>
        <p class="text-muted mb-0">Overview of completed orders with verified payments or cash-on-delivery receipts.</p>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
        <label for="range" class="small text-muted">Range</label>
        <select id="range" name="range" class="form-select form-select-sm">
            <?php foreach ($rangeOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $range ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
    </form>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="alert alert-info">
        Showing <strong><?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?></strong> earnings for
        <strong><?= htmlspecialchars($shop['name'] ?? 'your shop', ENT_QUOTES, 'UTF-8') ?></strong>.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total earnings</div>
                    <div class="fs-4 fw-semibold">₱<?= htmlspecialchars(number_format($earningsSummary['total_earnings'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">Completed + verified payments</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Completed orders</div>
                    <div class="fs-4 fw-semibold"><?= (int) $earningsSummary['order_count'] ?></div>
                    <div class="text-muted small">Payments verified</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Cash / COD received</div>
                    <div class="fs-4 fw-semibold">₱<?= htmlspecialchars(number_format($earningsSummary['cash_earnings'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">COD & pickup cash</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Non-cash earnings</div>
                    <div class="fs-4 fw-semibold">₱<?= htmlspecialchars(number_format($earningsSummary['non_cash_earnings'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">Bank transfers, etc.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Refunds & adjustments</h2>
            <p class="text-muted mb-0">No refund or adjustment ledger is tracked yet. Add entries to the earnings ledger to reflect returns or manual adjustments.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h6">Recent earnings</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Client</th>
                            <th>Payment</th>
                            <th>Method</th>
                            <th>Verified at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$earningRows): ?>
                            <tr>
                                <td colspan="5" class="text-muted">No earnings recorded for this range yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($earningRows as $row): ?>
                            <tr>
                                <td>#<?= (int) $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $row['amount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-uppercase"><?= htmlspecialchars(str_replace('_', ' ', (string) ($row['method'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['entry_created_at'] ?? $row['payment_created_at'] ?? $row['order_created_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
require __DIR__ . '/../../includes/app_footer.php';
?>