<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/flash.php';
require_once __DIR__ . '/../../../handlers/catalog_handler.php';
require_once __DIR__ . '/../../../handlers/inventory_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Inventory Stock Out';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$materials = [];
$transactions = [];

if (!$errors) {
    try {
        $materialsStmt = db()->prepare(
            'SELECT id, name, unit
             FROM materials
             WHERE shop_id = :shop_id
             ORDER BY name ASC'
        );
        $materialsStmt->execute(['shop_id' => $shop['id']]);
        $materials = $materialsStmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load materials right now.';
    }
}

$formValues = [
    'material_id' => '',
    'qty' => '',
    'ref_type' => 'order',
    'ref_id' => '',
    'reason' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_out' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formValues['material_id'] = trim((string) ($_POST['material_id'] ?? ''));
        $formValues['qty'] = trim((string) ($_POST['qty'] ?? ''));
        $formValues['ref_type'] = $_POST['ref_type'] ?? 'order';
        $formValues['ref_id'] = trim((string) ($_POST['ref_id'] ?? ''));
        $formValues['reason'] = trim((string) ($_POST['reason'] ?? ''));

        if ($formValues['material_id'] === '' || !ctype_digit($formValues['material_id'])) {
            $errors[] = 'Select a material.';
        }

        if ($formValues['qty'] === '' || !is_numeric($formValues['qty']) || (float) $formValues['qty'] <= 0) {
            $errors[] = 'Quantity must be a positive number.';
        }

        $refTypeOptions = ['order', 'waste', 'manual'];
        if (!in_array($formValues['ref_type'], $refTypeOptions, true)) {
            $formValues['ref_type'] = 'manual';
        }

        if (!$errors) {
            $materialId = (int) $formValues['material_id'];
            $currentStock = load_material_stock((int) $shop['id'], $materialId);
            $requestedQty = (float) $formValues['qty'];

            if ($requestedQty > $currentStock) {
                $errors[] = 'Stock-out quantity exceeds available stock.';
            }

            if (!$errors) {
                $saved = insert_material_transaction([
                    'shop_id' => $shop['id'],
                    'material_id' => $materialId,
                    'type' => 'OUT',
                    'qty' => $requestedQty,
                    'unit_cost' => null,
                    'supplier_id' => null,
                    'ref_type' => $formValues['ref_type'],
                    'ref_id' => $formValues['ref_id'] === '' ? null : $formValues['ref_id'],
                    'reason' => $formValues['reason'] === '' ? null : $formValues['reason'],
                ]);

                if ($saved) {
                    flash_set('success', 'Stock-out recorded successfully.');
                    header('Location: /hr/inventory/stock-out');
                    exit;
                }

                $errors[] = 'Unable to save stock-out transaction.';
            }
        }
    }
}

if (!$errors) {
    try {
        $transactionsStmt = db()->prepare(
            'SELECT t.id,
                    t.qty,
                    t.ref_type,
                    t.ref_id,
                    t.reason,
                    t.created_at,
                    m.name AS material_name,
                    m.unit AS material_unit
             FROM material_transactions t
             JOIN materials m ON m.id = t.material_id
             WHERE t.shop_id = :shop_id
               AND t.type = "OUT"
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 10'
        );
        $transactionsStmt->execute(['shop_id' => $shop['id']]);
        $transactions = $transactionsStmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load stock-out history right now.';
    }
}

require __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Stock Out</h1>
        <p class="text-muted mb-0">Record material usage or waste. Stock-out cannot exceed available stock.</p>
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
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">New stock-out</h2>
                    <?php if (!$materials): ?>
                        <p class="text-muted mb-0">Add materials first to record stock-out transactions.</p>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="stock_out">
                            <div class="mb-3">
                                <label class="form-label" for="stock_material">Material</label>
                                <select class="form-select" id="stock_material" name="material_id" required>
                                    <option value="">Select a material</option>
                                    <?php foreach ($materials as $material): ?>
                                        <option value="<?= (int) $material['id'] ?>" <?= (string) $material['id'] === $formValues['material_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($material['unit'], ENT_QUOTES, 'UTF-8') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="stock_qty">Quantity</label>
                                <input class="form-control" id="stock_qty" name="qty" value="<?= htmlspecialchars($formValues['qty'], ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="stock_ref_type">Reference type</label>
                                <select class="form-select" id="stock_ref_type" name="ref_type">
                                    <option value="order" <?= $formValues['ref_type'] === 'order' ? 'selected' : '' ?>>Order</option>
                                    <option value="waste" <?= $formValues['ref_type'] === 'waste' ? 'selected' : '' ?>>Waste</option>
                                    <option value="manual" <?= $formValues['ref_type'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="stock_ref_id">Reference ID (optional)</label>
                                <input class="form-control" id="stock_ref_id" name="ref_id" value="<?= htmlspecialchars($formValues['ref_id'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="stock_reason">Reason / notes</label>
                                <textarea class="form-control" id="stock_reason" name="reason" rows="2"><?= htmlspecialchars($formValues['reason'], ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit">Save stock-out</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Recent stock-out transactions</h2>
                    <?php if (!$transactions): ?>
                        <p class="text-muted mb-0">No stock-out activity recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Material</th>
                                    <th>Quantity</th>
                                    <th>Ref</th>
                                    <th>Date</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <?= htmlspecialchars($transaction['material_name'], ENT_QUOTES, 'UTF-8') ?>
                                            <div class="text-muted small"><?= htmlspecialchars($transaction['material_unit'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= number_format((float) $transaction['qty'], 2) ?></td>
                                        <td>
                                            <div class="text-muted small">
                                                <?= htmlspecialchars(strtoupper($transaction['ref_type'] ?? 'manual'), ENT_QUOTES, 'UTF-8') ?>
                                                <?= $transaction['ref_id'] ? '#' . htmlspecialchars($transaction['ref_id'], ENT_QUOTES, 'UTF-8') : '' ?>
                                            </div>
                                            <div class="small"><?= htmlspecialchars($transaction['reason'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($transaction['created_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
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

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
