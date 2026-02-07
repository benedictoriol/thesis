<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../handlers/catalog_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Service Catalog';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);
$products = [];

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$productColumns = get_table_columns(db(), 'products');
if (!$productColumns) {
    $errors[] = 'Catalog listings are not available right now.';
}

$statusColumn = find_column($productColumns, ['status']);
$isActiveColumn = find_column($productColumns, ['is_active', 'active']);
$turnaroundColumn = find_column($productColumns, [
    'turnaround_time',
    'turnaround_days',
    'turnaround_text',
    'lead_time',
    'lead_time_days',
    'turnaround',
]);
$hasImagesTable = (bool) get_table_columns(db(), 'product_images');
$hasVariantsTable = (bool) get_table_columns(db(), 'product_variants');
$hasAddonsTable = (bool) get_table_columns(db(), 'product_addons');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_listing' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $enable = ($_POST['listing_enabled'] ?? '0') === '1';

        if ($productId <= 0) {
            $errors[] = 'Invalid product selection.';
        }

        if (!$statusColumn && !$isActiveColumn) {
            $errors[] = 'Listing status cannot be updated right now.';
        }

        if (!$errors) {
            $updates = [];
            $params = [
                'product_id' => $productId,
                'shop_id' => $shop['id'],
            ];

            if ($statusColumn) {
                $updates[] = sprintf('%s = :status', $statusColumn);
                $params['status'] = $enable ? 'active' : 'hidden';
            }

            if ($isActiveColumn) {
                $updates[] = sprintf('%s = :is_active', $isActiveColumn);
                $params['is_active'] = $enable ? 1 : 0;
            }

            try {
                $stmt = db()->prepare(
                    'UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = :product_id AND shop_id = :shop_id'
                );
                $stmt->execute($params);
                flash_set('success', 'Listing status updated.');
                header('Location: /owner/catalog');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to update listing status right now.';
            }
        }
    }
}

if (!$errors) {
    $select = [
        'p.id',
        'p.name',
        'p.description',
        'p.base_price',
    ];

    if ($statusColumn) {
        $select[] = sprintf('p.%s AS status_value', $statusColumn);
    }
    if ($isActiveColumn) {
        $select[] = sprintf('p.%s AS active_value', $isActiveColumn);
    }
    if ($turnaroundColumn) {
        $select[] = sprintf('p.%s AS turnaround_value', $turnaroundColumn);
    }
    if ($hasImagesTable) {
        $select[] = '(SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS image_count';
    }
    if ($hasVariantsTable) {
        $select[] = '(SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS variant_count';
    }
    if ($hasAddonsTable) {
        $select[] = '(SELECT COUNT(*) FROM product_addons pa WHERE pa.product_id = p.id AND pa.is_active = 1) AS addon_count';
    }

    $orderBy = in_array('created_at', $productColumns, true) ? 'p.created_at DESC' : 'p.id DESC';

    try {
        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM products p WHERE p.shop_id = :shop_id ORDER BY ' . $orderBy
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $products = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load catalog listings right now.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Service Catalog</h1>
        <p class="text-muted mb-0">Manage listings, pricing, and add-ons for your shop.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" href="/owner/catalog/create">Create listing</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="alert alert-info">
        Listings need at least one image and a base price of ₱0 or higher.
    </div>

    <?php if (!$products): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted mb-3">No listings yet. Start by creating your first service.</p>
                <a class="btn btn-primary" href="/owner/catalog/create">Create listing</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Listing</th>
                        <th>Base price</th>
                        <th>Turnaround</th>
                        <th>Images</th>
                        <th>Variants</th>
                        <th>Add-ons</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $isActive = true;
                        if ($isActiveColumn) {
                            $isActive = (int) ($product['active_value'] ?? 0) === 1;
                        } elseif ($statusColumn) {
                            $isActive = ($product['status_value'] ?? '') === 'active';
                        }
                        $priceValue = (float) ($product['base_price'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($product['description'] ?? 'No description', ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td>
                                <?= $priceValue > 0 ? '₱' . number_format($priceValue, 2) : 'Quote required' ?>
                            </td>
                            <td><?= htmlspecialchars($product['turnaround_value'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $hasImagesTable ? (int) ($product['image_count'] ?? 0) : '—' ?></td>
                            <td><?= $hasVariantsTable ? (int) ($product['variant_count'] ?? 0) : '—' ?></td>
                            <td><?= $hasAddonsTable ? (int) ($product['addon_count'] ?? 0) : '—' ?></td>
                            <td>
                                <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $isActive ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                                    <a class="btn btn-outline-primary btn-sm" href="/owner/catalog/<?= (int) $product['id'] ?>/edit">Edit</a>
                                    <?php if ($statusColumn || $isActiveColumn): ?>
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_listing">
                                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                            <input type="hidden" name="listing_enabled" value="<?= $isActive ? '0' : '1' ?>">
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">
                                                <?= $isActive ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>