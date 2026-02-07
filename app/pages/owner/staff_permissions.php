<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner']);

$currentUser = current_user();
$pageTitle = 'Staff Permissions';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_staff_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$columns = table_columns('shop_staff');
$hasStatus = in_array('status', $columns, true);
$hasCanQuote = in_array('can_quote', $columns, true);
$hasManageOrders = in_array('can_manage_orders', $columns, true);
$hasManageInventory = in_array('can_manage_inventory', $columns, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_permissions' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $updates = [];
        $params = [
            'staff_id' => $staffId,
            'shop_id' => $shop['id'],
        ];

        if ($hasStatus && isset($_POST['status'])) {
            $status = $_POST['status'] === 'disabled' ? 'disabled' : 'active';
            $updates[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($hasCanQuote) {
            $updates[] = 'can_quote = :can_quote';
            $params['can_quote'] = isset($_POST['can_quote']) ? 1 : 0;
        }
        if ($hasManageOrders) {
            $updates[] = 'can_manage_orders = :can_manage_orders';
            $params['can_manage_orders'] = isset($_POST['can_manage_orders']) ? 1 : 0;
        }
        if ($hasManageInventory) {
            $updates[] = 'can_manage_inventory = :can_manage_inventory';
            $params['can_manage_inventory'] = isset($_POST['can_manage_inventory']) ? 1 : 0;
        }

        if (!$updates) {
            $errors[] = 'Permissions columns are not available in the current database.';
        } else {
            try {
                $sql = sprintf(
                    'UPDATE shop_staff SET %s WHERE id = :staff_id AND shop_id = :shop_id',
                    implode(', ', $updates)
                );
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                flash_set('success', 'Staff permissions updated.');
                header('Location: /owner/staff/permissions');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to update staff permissions.';
            }
        }
    }
}

$staff = [];
if ($shop && !$errors) {
    $selectParts = [
        'ss.id',
        'ss.role',
        'u.fullname',
        'u.email',
    ];
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
        $errors[] = 'Unable to load staff list.';
    }
}

$isOwner = true;
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
        <h1 class="h4 mb-1">Staff Permissions</h1>
        <p class="text-muted mb-0">Toggle access for HR and employee accounts.</p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/owner_staff_nav.php'; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Staff member</th>
                        <th>Role</th>
                        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
                        <?php if ($hasCanQuote): ?><th>Quotes</th><?php endif; ?>
                        <?php if ($hasManageOrders): ?><th>Orders</th><?php endif; ?>
                        <?php if ($hasManageInventory): ?><th>Inventory</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff as $member): ?>
                    <?php $formId = 'permissions-form-' . $member['id']; ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($member['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($member['role']), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <?php if ($hasStatus): ?>
                            <td>
                                <select class="form-select form-select-sm" name="status" form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>">
                                    <option value="active" <?= ($member['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="disabled" <?= ($member['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasCanQuote): ?>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="can_quote" form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" <?= (int) ($member['can_quote'] ?? 0) === 1 ? 'checked' : '' ?>>
                                </div>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasManageOrders): ?>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="can_manage_orders" form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" <?= (int) ($member['can_manage_orders'] ?? 0) === 1 ? 'checked' : '' ?>>
                                </div>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasManageInventory): ?>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="can_manage_inventory" form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" <?= (int) ($member['can_manage_inventory'] ?? 0) === 1 ? 'checked' : '' ?>>
                                </div>
                            </td>
                        <?php endif; ?>
                        <td>
                            <form method="post" id="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_permissions">
                                <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string) $member['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$staff): ?>
                    <tr>
                        <td colspan="<?= 3 + ($hasStatus ? 1 : 0) + ($hasCanQuote ? 1 : 0) + ($hasManageOrders ? 1 : 0) + ($hasManageInventory ? 1 : 0) ?>" class="text-muted">
                            No staff accounts available yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
