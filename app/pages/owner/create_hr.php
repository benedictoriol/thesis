<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner']);

$currentUser = current_user();
$pageTitle = 'Create HR Account';
$errors = [];
$successMessage = flash_get('success');
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

$formValues = [
    'fullname' => '',
    'email' => '',
    'phone' => '',
    'position' => 'HR Specialist',
    'can_quote' => '1',
    'can_manage_orders' => '1',
    'can_manage_inventory' => '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_hr' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formValues['fullname'] = trim((string) ($_POST['fullname'] ?? ''));
        $formValues['email'] = trim((string) ($_POST['email'] ?? ''));
        $formValues['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $formValues['position'] = trim((string) ($_POST['position'] ?? ''));
        $formValues['can_quote'] = isset($_POST['can_quote']) ? '1' : '0';
        $formValues['can_manage_orders'] = isset($_POST['can_manage_orders']) ? '1' : '0';
        $formValues['can_manage_inventory'] = isset($_POST['can_manage_inventory']) ? '1' : '0';

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($formValues['fullname'] === '' || $formValues['email'] === '') {
            $errors[] = 'Full name and email are required.';
        }
        if ($password === '' || $passwordConfirm === '') {
            $errors[] = 'Password and confirmation are required.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $registration = register_user([
                'fullname' => $formValues['fullname'],
                'email' => $formValues['email'],
                'password' => $password,
                'phone' => $formValues['phone'],
            ], 'hr');

            if (!$registration['ok']) {
                $errors = array_merge($errors, $registration['errors'] ?? ['Unable to create HR account.']);
            } else {
                $insertColumns = ['shop_id', 'user_id', 'role', 'created_at'];
                $insertValues = [
                    'shop_id' => $shop['id'],
                    'user_id' => $registration['user_id'],
                    'role' => 'hr',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ];

                if ($hasPosition) {
                    $insertColumns[] = 'position';
                    $insertValues['position'] = $formValues['position'] !== '' ? $formValues['position'] : null;
                }
                if ($hasStatus) {
                    $insertColumns[] = 'status';
                    $insertValues['status'] = 'active';
                }
                if ($hasCanQuote) {
                    $insertColumns[] = 'can_quote';
                    $insertValues['can_quote'] = (int) $formValues['can_quote'];
                }
                if ($hasManageOrders) {
                    $insertColumns[] = 'can_manage_orders';
                    $insertValues['can_manage_orders'] = (int) $formValues['can_manage_orders'];
                }
                if ($hasManageInventory) {
                    $insertColumns[] = 'can_manage_inventory';
                    $insertValues['can_manage_inventory'] = (int) $formValues['can_manage_inventory'];
                }
                if ($hasHiredAt) {
                    $insertColumns[] = 'hired_at';
                    $insertValues['hired_at'] = gmdate('Y-m-d H:i:s');
                }

                try {
                    $placeholders = array_map(static fn(string $column) => ':' . $column, $insertColumns);
                    $sql = sprintf(
                        'INSERT INTO shop_staff (%s) VALUES (%s)',
                        implode(', ', $insertColumns),
                        implode(', ', $placeholders)
                    );
                    $stmt = db()->prepare($sql);
                    $stmt->execute($insertValues);

                    flash_set('success', 'HR account created and linked to your shop.');
                    redirect('/owner/staff');
                } catch (PDOException $exception) {
                    $errors[] = 'Unable to link HR account to the shop.';
                }
            }
        }
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

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Create HR Account</h1>
        <p class="text-muted mb-0">Invite HR staff to help with hiring and employee coordination.</p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/owner_staff_nav.php'; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_hr">
                <div class="col-md-6">
                    <label class="form-label" for="fullname">Full name</label>
                    <input class="form-control" id="fullname" name="fullname" value="<?= htmlspecialchars($formValues['fullname'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" id="email" name="email" type="email" value="<?= htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($formValues['phone'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <?php if ($hasPosition): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="position">Position</label>
                        <input class="form-control" id="position" name="position" value="<?= htmlspecialchars($formValues['position'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label" for="password">Temporary password</label>
                    <input class="form-control" id="password" name="password" type="password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password_confirm">Confirm password</label>
                    <input class="form-control" id="password_confirm" name="password_confirm" type="password" required>
                </div>
                <?php if ($hasCanQuote || $hasManageOrders || $hasManageInventory): ?>
                    <div class="col-12">
                        <label class="form-label">HR permissions</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php if ($hasCanQuote): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="can_quote" name="can_quote" <?= $formValues['can_quote'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="can_quote">Can prepare quotes</label>
                                </div>
                            <?php endif; ?>
                            <?php if ($hasManageOrders): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="can_manage_orders" name="can_manage_orders" <?= $formValues['can_manage_orders'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="can_manage_orders">Manage orders</label>
                                </div>
                            <?php endif; ?>
                            <?php if ($hasManageInventory): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="can_manage_inventory" name="can_manage_inventory" <?= $formValues['can_manage_inventory'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="can_manage_inventory">Manage inventory</label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Create HR account</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
