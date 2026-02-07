<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/flash.php';
require_once __DIR__ . '/../../../handlers/catalog_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Inventory Storage';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$storageColumns = get_table_columns(db(), 'storage_locations');
if (!$storageColumns) {
    $errors[] = 'Storage locations are not available right now.';
}

$formValues = [
    'name' => '',
    'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_location' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formValues['name'] = trim((string) ($_POST['name'] ?? ''));
        $formValues['description'] = trim((string) ($_POST['description'] ?? ''));

        if ($formValues['name'] === '') {
            $errors[] = 'Storage name is required.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO storage_locations (shop_id, name, description)
                     VALUES (:shop_id, :name, :description)'
                );
                $stmt->execute([
                    'shop_id' => $shop['id'],
                    'name' => $formValues['name'],
                    'description' => $formValues['description'] ?: null,
                ]);
                flash_set('success', 'Storage location added successfully.');
                header('Location: /hr/inventory/storage');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to save the storage location right now.';
            }
        }
    }
}

$locations = [];
if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT id, name, description
             FROM storage_locations
             WHERE shop_id = :shop_id
             ORDER BY name ASC'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $locations = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load storage locations right now.';
    }
}

require __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Storage Locations</h1>
        <p class="text-muted mb-0">Organize where materials are stored across the shop.</p>
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
                    <h2 class="h6 mb-3">Add location</h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_location">
                        <div class="mb-3">
                            <label class="form-label" for="location_name">Location name</label>
                            <input class="form-control" id="location_name" name="name" value="<?= htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="location_description">Description</label>
                            <textarea class="form-control" id="location_description" name="description" rows="3"><?= htmlspecialchars($formValues['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <button class="btn btn-primary" type="submit">Save location</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Location list</h2>
                    <?php if (!$locations): ?>
                        <p class="text-muted mb-0">No storage locations recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($locations as $location): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($location['description'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
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
