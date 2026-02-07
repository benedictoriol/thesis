<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Approved Employees';
$errors = [];
$shop = load_shop_for_staff_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$columns = table_columns('shop_staff');
$hasPosition = in_array('position', $columns, true);
$hasStatus = in_array('status', $columns, true);
$hasHiredAt = in_array('hired_at', $columns, true);

$employees = [];
if ($shop && !$errors) {
    $selectParts = [
        'ss.id',
        'ss.created_at',
        'u.fullname',
        'u.email',
    ];
    if ($hasPosition) {
        $selectParts[] = 'ss.position';
    }
    if ($hasStatus) {
        $selectParts[] = 'ss.status';
    }
    if ($hasHiredAt) {
        $selectParts[] = 'ss.hired_at';
    }

    $filters = ['ss.shop_id = :shop_id', "ss.role = 'employee'"];
    if ($hasStatus) {
        $filters[] = "ss.status = 'active'";
    }

    $sql = sprintf(
        'SELECT %s
         FROM shop_staff ss
         JOIN users u ON u.id = ss.user_id
         WHERE %s
         ORDER BY u.fullname',
        implode(', ', $selectParts),
        implode(' AND ', $filters)
    );

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(['shop_id' => $shop['id']]);
        $employees = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load approved employees right now.';
    }
}

$isOwner = $currentUser['role'] === 'owner';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$staffNavItems = [
    ['label' => 'Staff overview', 'path' => '/owner/staff'],
    ['label' => 'Create HR account', 'path' => '/owner/staff/create-hr', 'owner_only' => true],
    ['label' => 'Permissions', 'path' => '/owner/staff/permissions', 'owner_only' => true],
    ['label' => 'Hiring applicants', 'path' => '/owner/hiring/applicants'],
    ['label' => 'Approved employees', 'path' => '/owner/staff/approved-employees'],
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Approved Employees</h1>
        <p class="text-muted mb-0">Review employees who are active and ready for assignments.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/owner/hiring/applicants">View applicants</a>
        <?php if ($isOwner): ?>
            <a class="btn btn-outline-primary btn-sm" href="/owner/staff/permissions">Manage permissions</a>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/owner_staff_nav.php'; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <?php if ($hasPosition): ?><th>Position</th><?php endif; ?>
                        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
                        <th>Hired</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($hasPosition): ?>
                            <td><?= htmlspecialchars($employee['position'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasStatus): ?>
                            <td>
                                <span class="badge bg-success"><?= htmlspecialchars(ucfirst($employee['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($employee['hired_at'] ?? $employee['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$employees): ?>
                    <tr>
                        <td colspan="<?= 3 + ($hasPosition ? 1 : 0) + ($hasStatus ? 1 : 0) ?>" class="text-muted">
                            No approved employees yet. Convert approved applicants to staff accounts.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
