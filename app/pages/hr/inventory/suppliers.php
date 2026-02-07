<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/flash.php';
require_once __DIR__ . '/../../../handlers/catalog_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Inventory Suppliers';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$supplierColumns = get_table_columns(db(), 'suppliers');
if (!$supplierColumns) {
    $errors[] = 'Suppliers are not available right now.';
}

$formValues = [
    'name' => '',
    'contact' => '',
    'address_text' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_supplier' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formValues['name'] = trim((string) ($_POST['name'] ?? ''));
        $formValues['contact'] = trim((string) ($_POST['contact'] ?? ''));
        $formValues['address_text'] = trim((string) ($_POST['address_text'] ?? ''));
        $formValues['notes'] = trim((string) ($_POST['notes'] ?? ''));

        if ($formValues['name'] === '') {
            $errors[] = 'Supplier name is required.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO suppliers (shop_id, name, contact, address_text, notes)
                     VALUES (:shop_id, :name, :contact, :address_text, :notes)'
                );
                $stmt->execute([
                    'shop_id' => $shop['id'],
                    'name' => $formValues['name'],
                    'contact' => $formValues['contact'] ?: null,
                    'address_text' => $formValues['address_text'] ?: null,
                    'notes' => $formValues['notes'] ?: null,
                ]);
                flash_set('success', 'Supplier added successfully.');
                header('Location: /hr/inventory/suppliers');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to save the supplier right now.';
            }
        }
    }
}

$suppliers = [];
if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT id, name, contact, address_text, notes
             FROM suppliers
             WHERE shop_id = :shop_id
             ORDER BY name ASC'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $suppliers = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load suppliers right now.';
    }
}

require __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Suppliers</h1>
        <p class="text-muted mb-0">Maintain supplier contacts and delivery information.</p>
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
                    <h2 class="h6 mb-3">Add supplier</h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_supplier">
                        <div class="mb-3">
                            <label class="form-label" for="supplier_name">Supplier name</label>
                            <input class="form-control" id="supplier_name" name="name" value="<?= htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="supplier_contact">Contact</label>
                            <input class="form-control" id="supplier_contact" name="contact" value="<?= htmlspecialchars($formValues['contact'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Phone or email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="supplier_address">Address</label>
                            <textarea class="form-control" id="supplier_address" name="address_text" rows="2"><?= htmlspecialchars($formValues['address_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="supplier_notes">Notes</label>
                            <textarea class="form-control" id="supplier_notes" name="notes" rows="2"><?= htmlspecialchars($formValues['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <button class="btn btn-primary" type="submit">Save supplier</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Supplier list</h2>
                    <?php if (!$suppliers): ?>
                        <p class="text-muted mb-0">No suppliers recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Address</th>
                                    <th>Notes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($supplier['contact'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($supplier['address_text'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($supplier['notes'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
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
