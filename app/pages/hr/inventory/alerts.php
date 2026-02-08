<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../handlers/catalog_handler.php';
require_once __DIR__ . '/../../../handlers/inventory_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Inventory Alerts';
$errors = [];
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$materials = [];
if (!$errors) {
    try {
        $materials = load_inventory_materials((int) $shop['id']);
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load inventory alerts right now.';
    }
}

$alerts = [];
foreach ($materials as $material) {
    $stock = (float) ($material['stock_qty'] ?? 0);
    $reorderLevel = (float) ($material['reorder_level'] ?? 0);
    if ($reorderLevel > 0 && $stock < $reorderLevel) {
        $alerts[] = [
            'name' => $material['name'] ?? '',
            'unit' => $material['unit'] ?? '',
            'stock' => $stock,
            'reorder_level' => $reorderLevel,
            'status' => $material['status'] ?? 'active',
        ];
    }
}

require __DIR__ . '/../../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Low Stock Alerts</h1>
        <p class="text-muted mb-0">Materials below their reorder level.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/hr/inventory">Back to inventory</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0">Materials needing reorder</h2>
                <span class="badge bg-warning text-dark"><?= count($alerts) ?> alerts</span>
            </div>
            <?php if (!$alerts): ?>
                <p class="text-muted mb-0">All materials are above their reorder levels.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Material</th>
                            <th>Current stock</th>
                            <th>Reorder level</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($alerts as $alert): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($alert['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($alert['stock'], 2) ?> <?= htmlspecialchars($alert['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($alert['reorder_level'], 2) ?> <?= htmlspecialchars($alert['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge <?= $alert['status'] === 'inactive' ? 'bg-secondary' : 'bg-success' ?>">
                                        <?= htmlspecialchars(ucfirst($alert['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/app_footer.php'; ?>
