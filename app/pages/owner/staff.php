<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Staff Directory';
$errors = [];
$shop = load_shop_for_staff_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$columns = table_columns('shop_staff');
$hasPosition = in_array('position', $columns, true);
$hasStatus = in_array('status', $columns, true);
$hasCanQuote = in_array('can_quote', $columns, true);
$hasManageOrders = in_array('can_manage_orders', $columns, true);
$hasManageInventory = in_array('can_manage_inventory', $columns, true);
$hasHiredAt = in_array('hired_at', $columns, true);

$staff = [];
if ($shop && !$errors) {
    $selectParts = [
        'ss.id',
        'ss.user_id',
        'ss.role',
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
    if ($hasCanQuote) {
        $selectParts[] = 'ss.can_quote';
    }
    if ($hasManageOrders) {
        $selectParts[] = 'ss.can_manage_orders';
    }
    if ($hasManageInventory) {
        $selectParts[] = 'ss.can_manage_inventory';
    }
    if ($hasHiredAt) {
        $selectParts[] = 'ss.hired_at';
    }

    $sql = sprintf(
        'SELECT %s
         FROM shop_staff ss
         JOIN users u ON u.id = ss.user_id
         WHERE ss.shop_id = :shop_id
         ORDER BY ss.role, u.fullname',
        implode(', ', $selectParts)
    );

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(['shop_id' => $shop['id']]);
        $staff = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load staff at the moment.';
    }
}

$hrStaff = array_values(array_filter($staff, static fn(array $row) => $row['role'] === 'hr'));
$employees = array_values(array_filter($staff, static fn(array $row) => $row['role'] === 'employee'));
$activeEmployees = $employees;
if ($hasStatus) {
    $activeEmployees = array_values(array_filter(
        $employees,
        static fn(array $row) => ($row['status'] ?? '') === 'active'
    ));
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

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Staff Directory</h1>
        <p class="text-muted mb-0">Track HR accounts and approved employees for <?= htmlspecialchars($shop['name'] ?? 'your shop', ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
    <?php if ($isOwner): ?>
        <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
            <a class="btn btn-primary btn-sm" href="/owner/staff/create-hr">Create HR account</a>
            <a class="btn btn-outline-secondary btn-sm" href="/owner/staff/permissions">Manage permissions</a>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/owner_staff_nav.php'; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1">HR accounts</p>
                            <h2 class="h4 mb-0"><?= count($hrStaff) ?></h2>
                        </div>
                        <span class="badge bg-primary">HR</span>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Handle hiring, scheduling, and internal requests.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1">Approved employees</p>
                            <h2 class="h4 mb-0"><?= count($activeEmployees) ?></h2>
                        </div>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Employees currently approved to work on orders.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1">All staff</p>
                            <h2 class="h4 mb-0"><?= count($staff) ?></h2>
                        </div>
                        <span class="badge bg-dark">Total</span>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Combined HR and employee accounts.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 mb-1">HR Accounts</h2>
                    <p class="text-muted small mb-0">Partial access for hiring and staff operations.</p>
                </div>
                <a class="btn btn-outline-primary btn-sm" href="/owner/staff/create-hr">Add HR</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <?php if ($hasPosition): ?><th>Position</th><?php endif; ?>
                        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
                        <th>Permissions</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hrStaff as $member): ?>
                    <tr>
                        <td><?= htmlspecialchars($member['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($hasPosition): ?>
                            <td><?= htmlspecialchars($member['position'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasStatus): ?>
                            <td>
                                <span class="badge <?= ($member['status'] ?? '') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= htmlspecialchars(ucfirst($member['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <td class="small text-muted">
                            <?php
                            $permissionLabels = [];
                            if ($hasCanQuote && (int) $member['can_quote'] === 1) {
                                $permissionLabels[] = 'Quotes';
                            }
                            if ($hasManageOrders && (int) $member['can_manage_orders'] === 1) {
                                $permissionLabels[] = 'Orders';
                            }
                            if ($hasManageInventory && (int) $member['can_manage_inventory'] === 1) {
                                $permissionLabels[] = 'Inventory';
                            }
                            ?>
                            <?= $permissionLabels ? htmlspecialchars(implode(', ', $permissionLabels), ENT_QUOTES, 'UTF-8') : 'No permissions set' ?>
                        </td>
                        <td><?= htmlspecialchars($member['hired_at'] ?? $member['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$hrStaff): ?>
                    <tr>
                        <td colspan="<?= 4 + ($hasPosition ? 1 : 0) + ($hasStatus ? 1 : 0) ?>" class="text-muted">
                            No HR accounts yet. Create one to help manage hiring and staff tasks.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h6 mb-1">Approved Employees</h2>
                    <p class="text-muted small mb-0">Employees approved for staffing assignments.</p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="/owner/hiring/applicants">View applicants</a>
                    <a class="btn btn-outline-primary btn-sm" href="/owner/staff/approved-employees">Full list</a>
                </div>
            </div>
        </div>
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
                <?php foreach (array_slice($activeEmployees, 0, 5) as $member): ?>
                    <tr>
                        <td><?= htmlspecialchars($member['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php if ($hasPosition): ?>
                            <td><?= htmlspecialchars($member['position'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasStatus): ?>
                            <td>
                                <span class="badge <?= ($member['status'] ?? '') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= htmlspecialchars(ucfirst($member['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($member['hired_at'] ?? $member['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$activeEmployees): ?>
                    <tr>
                        <td colspan="<?= 3 + ($hasPosition ? 1 : 0) + ($hasStatus ? 1 : 0) ?>" class="text-muted">
                            No approved employees yet. Approve applicants to add them here.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>