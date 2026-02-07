<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';

require_role(['owner', 'hr']);

$pageTitle = 'Payroll Period Details';

$periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : 0;
$errors = [];
$period = null;
$entries = [];
$totals = [
    'base_pay' => 0,
    'overtime_pay' => 0,
    'bonus' => 0,
    'deductions' => 0,
    'net_pay' => 0,
];

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$periodColumns = get_table_columns(db(), 'payroll_periods');
$entryColumns = get_table_columns(db(), 'payroll_entries');
$usersColumns = get_table_columns(db(), 'users');
$employeeNameColumn = find_column($usersColumns, ['fullname', 'full_name', 'name']);

if (!$periodColumns || !$entryColumns) {
    $errors[] = 'Payroll data is not available yet.';
} elseif ($periodId <= 0) {
    $errors[] = 'Invalid payroll period.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT id, start_date, end_date, status
             FROM payroll_periods
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $periodId]);
        $period = $stmt->fetch();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payroll period.';
    }

    if (!$period) {
        $errors[] = 'Payroll period not found.';
    }
}

if (!$errors) {
    try {
        $selectParts = [
            'e.id',
            'e.user_id',
            'e.base_pay',
            'e.overtime_pay',
            'e.bonus',
            'e.deductions',
            'e.net_pay',
            'e.created_at',
        ];
        if ($employeeNameColumn) {
            $selectParts[] = 'u.' . $employeeNameColumn . ' AS employee_name';
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . '
            FROM payroll_entries e
            LEFT JOIN users u ON u.id = e.user_id
            WHERE e.period_id = :period_id
            ORDER BY e.created_at DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute(['period_id' => $periodId]);
        $entries = $stmt->fetchAll();

        foreach ($entries as $entry) {
            $totals['base_pay'] += (float) $entry['base_pay'];
            $totals['overtime_pay'] += (float) $entry['overtime_pay'];
            $totals['bonus'] += (float) $entry['bonus'];
            $totals['deductions'] += (float) $entry['deductions'];
            $totals['net_pay'] += (float) $entry['net_pay'];
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payroll entries.';
    }
}

require __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Payroll Period Details</h1>
        <?php if ($period): ?>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($period['start_date'], ENT_QUOTES, 'UTF-8') ?>
                –
                <?= htmlspecialchars($period['end_date'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/hr/payroll/periods">Back to periods</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors && $period): ?>
    <?php
    $status = strtolower((string) ($period['status'] ?? 'draft'));
    $badgeClass = $status === 'finalized' ? 'bg-success' : 'bg-secondary';
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Status</div>
                    <div class="h5 mb-0"><span class="badge <?= $badgeClass; ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></div>
                    <p class="text-muted small mb-0 mt-2">Owner review required before finalization.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total entries</div>
                    <div class="h5 mb-0"><?= htmlspecialchars((string) count($entries), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Net payroll</div>
                    <div class="h5 mb-0">₱<?= htmlspecialchars(number_format($totals['net_pay'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if ($entries): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Base</th>
                            <th>Overtime</th>
                            <th>Bonus</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars($entry['employee_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $entry['base_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $entry['overtime_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $entry['bonus'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= htmlspecialchars(number_format((float) $entry['deductions'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="fw-semibold">₱<?= htmlspecialchars(number_format((float) $entry['net_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No payroll entries yet for this period.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
