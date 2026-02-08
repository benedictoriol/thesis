<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';

require_role(['owner', 'hr']);

$pageTitle = 'Payroll Periods';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

$errors = [];
$periods = [];
$entryStats = [];

$periodColumns = get_table_columns(db(), 'payroll_periods');
$entryColumns = get_table_columns(db(), 'payroll_entries');

if (!$periodColumns) {
    $errors[] = 'Payroll periods are not available yet.';
} else {
    try {
        $stmt = db()->prepare(
            'SELECT id, start_date, end_date, status
             FROM payroll_periods
             ORDER BY start_date DESC'
        );
        $stmt->execute();
        $periods = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payroll periods.';
    }
}

if ($periods && $entryColumns) {
    try {
        $stmt = db()->query(
            'SELECT period_id, COUNT(*) AS entry_count, COALESCE(SUM(net_pay), 0) AS net_total
             FROM payroll_entries
             GROUP BY period_id'
        );
        foreach ($stmt->fetchAll() as $row) {
            $entryStats[(int) $row['period_id']] = $row;
        }
    } catch (PDOException $exception) {
        $entryStats = [];
    }
}

require __DIR__ . '/../../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Payroll Periods</h1>
        <p class="text-muted mb-0">Manage draft and finalized payroll windows.</p>
    </div>
    <div class="text-muted small">Owner approval required before finalization.</div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if (!$errors && $periods): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Entries</th>
                        <th>Net Total</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($periods as $period): ?>
                        <?php
                        $periodId = (int) $period['id'];
                        $status = strtolower((string) ($period['status'] ?? 'draft'));
                        $badgeClass = $status === 'finalized' ? 'bg-success' : 'bg-secondary';
                        $stats = $entryStats[$periodId] ?? ['entry_count' => 0, 'net_total' => 0];
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($period['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                –
                                <?= htmlspecialchars($period['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><span class="badge <?= $badgeClass; ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars((string) $stats['entry_count'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₱<?= htmlspecialchars(number_format((float) $stats['net_total'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="/hr/payroll/periods/<?= htmlspecialchars((string) $periodId, ENT_QUOTES, 'UTF-8') ?>">
                                    View period
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$errors): ?>
            <p class="text-muted mb-0">No payroll periods created yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../includes/app_footer.php'; ?>
