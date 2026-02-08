<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/flash.php';
require_once __DIR__ . '/../../../handlers/catalog_handler.php';
require_once __DIR__ . '/../../../handlers/inventory_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Inventory Materials';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$materialsColumns = get_table_columns(db(), 'materials');
if (!$materialsColumns) {
    $errors[] = 'Materials are not available right now.';
}

$formValues = [
    'name' => '',
    'unit' => '',
    'reorder_level' => '',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_material' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formValues['name'] = trim((string) ($_POST['name'] ?? ''));
        $formValues['unit'] = trim((string) ($_POST['unit'] ?? ''));
        $formValues['reorder_level'] = trim((string) ($_POST['reorder_level'] ?? ''));
        $formValues['status'] = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($formValues['name'] === '') {
            $errors[] = 'Material name is required.';
        }

        if ($formValues['unit'] === '') {
            $errors[] = 'Unit is required.';
        }

        if ($formValues['reorder_level'] !== '' && !is_numeric($formValues['reorder_level'])) {
            $errors[] = 'Reorder level must be a number.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO materials (shop_id, name, unit, reorder_level, status)
                     VALUES (:shop_id, :name, :unit, :reorder_level, :status)'
                );
                $stmt->execute([
                    'shop_id' => $shop['id'],
                    'name' => $formValues['name'],
                    'unit' => $formValues['unit'],
                    'reorder_level' => $formValues['reorder_level'] === '' ? null : (float) $formValues['reorder_level'],
                    'status' => $formValues['status'],
                ]);
                flash_set('success', 'Material added successfully.');
                redirect('/hr/inventory/materials');
            } catch (PDOException $exception) {
                $errors[] = 'Unable to save the material right now.';
            }
        }
    }
}

$materials = [];
if (!$errors) {
    try {
        $materials = load_inventory_materials((int) $shop['id']);
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load materials right now.';
    }
}

require __DIR__ . '/../../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Materials</h1>
        <p class="text-muted mb-0">Track material inventory levels and reorder points.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/hr/inventory">Back to inventory</a>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Add material</h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_material">
                        <div class="mb-3">
                            <label class="form-label" for="material_name">Material name</label>
                            <input class="form-control" id="material_name" name="name" value="<?= htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="material_unit">Unit</label>
                            <input class="form-control" id="material_unit" name="unit" value="<?= htmlspecialchars($formValues['unit'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., pcs, rolls, kg" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="material_reorder">Reorder level</label>
                            <input class="form-control" id="material_reorder" name="reorder_level" value="<?= htmlspecialchars($formValues['reorder_level'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Optional">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="material_status">Status</label>
                            <select class="form-select" id="material_status" name="status">
                                <option value="active" <?= $formValues['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit">Save material</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Current materials</h2>
                    <?php if (!$materials): ?>
                        <p class="text-muted mb-0">No materials recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Unit</th>
                                    <th>Stock</th>
                                    <th>Reorder level</th>
                                    <th>Status</th>
                                    <th>Alert</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($materials as $material): ?>
                                    <?php
                                    $stock = (float) ($material['stock_qty'] ?? 0);
                                    $reorderLevel = (float) ($material['reorder_level'] ?? 0);
                                    $isLow = $reorderLevel > 0 && $stock < $reorderLevel;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($material['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format($stock, 2) ?></td>
                                        <td><?= $reorderLevel > 0 ? number_format($reorderLevel, 2) : '—' ?></td>
                                        <td>
                                            <span class="badge <?= ($material['status'] ?? '') === 'inactive' ? 'bg-secondary' : 'bg-success' ?>">
                                                <?= htmlspecialchars(ucfirst($material['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isLow): ?>
                                                <span class="badge bg-warning text-dark">Low stock</span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
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
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/app_footer.php'; ?>
