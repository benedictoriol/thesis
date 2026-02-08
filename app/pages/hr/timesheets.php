<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['owner', 'hr']);

$pageTitle = 'Timesheets';

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

$errors = [];
$timesheets = [];

$timesheetColumns = get_table_columns(db(), 'timesheets');
$usersColumns = get_table_columns(db(), 'users');

$employeeNameColumn = find_column($usersColumns, ['fullname', 'full_name', 'name']);

if (!$timesheetColumns) {
    $errors[] = 'Timesheets are not available yet.';
} else {
    try {
        $selectParts = [
            't.id',
            't.work_date',
            't.hours',
            't.notes',
            't.status',
            't.approved_by_user_id',
        ];
        if ($employeeNameColumn) {
            $selectParts[] = 'employee.' . $employeeNameColumn . ' AS employee_name';
            $selectParts[] = 'approver.' . $employeeNameColumn . ' AS approver_name';
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . '
            FROM timesheets t
            LEFT JOIN users employee ON employee.id = t.user_id
            LEFT JOIN users approver ON approver.id = t.approved_by_user_id
            ORDER BY t.work_date DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute();
        $timesheets = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load timesheets right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Timesheets</h1>
        <p class="text-muted mb-0">Track daily hours, approvals, and notes for each employee.</p>
    </div>
    <div class="text-muted small">Status flow: pending → approved</div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if (!$errors && $timesheets): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Notes</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($timesheets as $timesheet): ?>
                        <?php
                        $status = strtolower((string) ($timesheet['status'] ?? 'pending'));
                        $badgeClass = $status === 'approved' ? 'bg-success' : 'bg-warning text-dark';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($timesheet['work_date'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($timesheet['employee_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($timesheet['hours'] ?? '0', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= $badgeClass; ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($timesheet['approver_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($timesheet['notes'] ?? 'No notes', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$errors): ?>
            <p class="text-muted mb-0">No timesheets have been logged yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
