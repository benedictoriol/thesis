<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';

require_role(['employee']);

$pageTitle = 'Payslips';

$user = current_user();
$userId = $user ? (int) $user['id'] : 0;

$errors = [];
$payslips = [];

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

$entryColumns = get_table_columns(db(), 'payroll_entries');
$periodColumns = get_table_columns(db(), 'payroll_periods');

if (!$entryColumns || !$periodColumns) {
    $errors[] = 'Payslips are not available yet.';
} elseif ($userId <= 0) {
    $errors[] = 'Unable to load payslips for this account.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT e.id, e.base_pay, e.overtime_pay, e.bonus, e.deductions, e.net_pay, e.created_at,
                    p.start_date, p.end_date, p.status
             FROM payroll_entries e
             JOIN payroll_periods p ON p.id = e.period_id
             WHERE e.user_id = :user_id
             ORDER BY p.start_date DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $payslips = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payslips right now.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Payslips</h1>
        <p class="text-muted mb-0">View finalized payroll entries for each period.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if (!$errors && $payslips): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Base</th>
                        <th>Overtime</th>
                        <th>Bonus</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payslips as $payslip): ?>
                        <?php
                        $status = strtolower((string) ($payslip['status'] ?? 'draft'));
                        $badgeClass = $status === 'finalized' ? 'bg-success' : 'bg-secondary';
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($payslip['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                –
                                <?= htmlspecialchars($payslip['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><span class="badge <?= $badgeClass; ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>₱<?= htmlspecialchars(number_format((float) $payslip['base_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₱<?= htmlspecialchars(number_format((float) $payslip['overtime_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₱<?= htmlspecialchars(number_format((float) $payslip['bonus'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₱<?= htmlspecialchars(number_format((float) $payslip['deductions'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="fw-semibold">₱<?= htmlspecialchars(number_format((float) $payslip['net_pay'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($payslip['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$errors): ?>
            <p class="text-muted mb-0">No payslips available yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
