<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../handlers/catalog_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Edit Listing';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);

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
$imageColumn = find_column($productColumns, ['image_path', 'image_url', 'thumbnail_path']);
$hasImagesTable = (bool) get_table_columns(db(), 'product_images');
$hasVariantsTable = (bool) get_table_columns(db(), 'product_variants');
$hasAddonsTable = (bool) get_table_columns(db(), 'product_addons');

$productId = (int) ($_GET['product_id'] ?? $_GET['id'] ?? 0);
$product = null;
$images = [];
$variantRows = [];
$addonRows = [];

if ($productId <= 0) {
    $errors[] = 'Invalid listing selection.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare('SELECT * FROM products WHERE id = :product_id AND shop_id = :shop_id LIMIT 1');
        $stmt->execute([
            'product_id' => $productId,
            'shop_id' => $shop['id'],
        ]);
        $product = $stmt->fetch();
        if (!$product) {
            $errors[] = 'Listing not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load listing details right now.';
    }
}

if ($product && $hasImagesTable) {
    try {
        $stmt = db()->prepare(
            'SELECT image_path
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $images = array_filter(array_column($stmt->fetchAll(), 'image_path'));
    } catch (PDOException $exception) {
        $images = [];
    }
} elseif ($product && $imageColumn) {
    $images = array_filter([(string) ($product[$imageColumn] ?? '')]);
}

if ($product && $hasVariantsTable) {
    try {
        $stmt = db()->prepare(
            'SELECT name, price_add
             FROM product_variants
             WHERE product_id = :product_id
             ORDER BY id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $variantRows = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $variantRows = [];
    }
}

if ($product && $hasAddonsTable) {
    try {
        $stmt = db()->prepare(
            'SELECT name, addon_price
             FROM product_addons
             WHERE product_id = :product_id
             ORDER BY id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $addonRows = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $addonRows = [];
    }
}

$form = [
    'name' => $product['name'] ?? '',
    'description' => $product['description'] ?? '',
    'base_price' => (string) ($product['base_price'] ?? '0'),
    'turnaround' => $turnaroundColumn ? ($product[$turnaroundColumn] ?? '') : '',
    'listing_enabled' => '1',
    'image_list' => implode("\n", $images),
];

if ($product) {
    if ($isActiveColumn) {
        $form['listing_enabled'] = (int) ($product[$isActiveColumn] ?? 0) === 1 ? '1' : '0';
    } elseif ($statusColumn) {
        $form['listing_enabled'] = ($product[$statusColumn] ?? '') === 'active' ? '1' : '0';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_listing' && $product && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $form['name'] = trim((string) ($_POST['name'] ?? ''));
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['base_price'] = trim((string) ($_POST['base_price'] ?? '0'));
        $form['turnaround'] = trim((string) ($_POST['turnaround'] ?? ''));
        $form['listing_enabled'] = ($_POST['listing_enabled'] ?? '1') === '1' ? '1' : '0';
        $form['image_list'] = trim((string) ($_POST['image_list'] ?? ''));

        if ($form['name'] === '') {
            $errors[] = 'Listing name is required.';
        }

        if (!is_numeric($form['base_price'])) {
            $errors[] = 'Base price must be a number.';
        }

        $basePrice = (float) $form['base_price'];
        if ($basePrice < 0) {
            $errors[] = 'Base price must be 0 or higher.';
        }

        $images = parse_image_list($form['image_list']);
        if (!$images) {
            $errors[] = 'At least one image is required.';
        }

        if (!$hasImagesTable && !$imageColumn) {
            $errors[] = 'Image uploads are not available right now.';
        }

        $variantRows = parse_priced_items($_POST['variant_name'] ?? [], $_POST['variant_price'] ?? []);
        $addonRows = parse_priced_items($_POST['addon_name'] ?? [], $_POST['addon_price'] ?? []);

        foreach ($variantRows as $variant) {
            if ($variant['name'] === '') {
                $errors[] = 'Variant name is required when adding a variant.';
            }
            if ($variant['price'] === null) {
                $errors[] = 'Variant price must be a number.';
            } elseif ($variant['price'] < 0) {
                $errors[] = 'Variant price must be 0 or higher.';
            }
        }

        foreach ($addonRows as $addon) {
            if ($addon['name'] === '') {
                $errors[] = 'Add-on name is required when adding an add-on.';
            }
            if ($addon['price'] === null) {
                $errors[] = 'Add-on price must be a number.';
            } elseif ($addon['price'] < 0) {
                $errors[] = 'Add-on price must be 0 or higher.';
            }
        }

        if (!$errors) {
            $enabled = $form['listing_enabled'] === '1';
            $updates = [
                'name = :name',
                'description = :description',
                'base_price = :base_price',
            ];
            $params = [
                'name' => $form['name'],
                'description' => $form['description'] !== '' ? $form['description'] : null,
                'base_price' => $basePrice,
                'product_id' => $productId,
                'shop_id' => $shop['id'],
            ];

            if ($turnaroundColumn) {
                $updates[] = $turnaroundColumn . ' = :turnaround';
                $params['turnaround'] = $form['turnaround'] !== '' ? $form['turnaround'] : null;
            }

            if ($statusColumn) {
                $updates[] = $statusColumn . ' = :status';
                $params['status'] = $enabled ? 'active' : 'hidden';
            }

            if ($isActiveColumn) {
                $updates[] = $isActiveColumn . ' = :is_active';
                $params['is_active'] = $enabled ? 1 : 0;
            }

            if (!$hasImagesTable && $imageColumn && $images) {
                $updates[] = $imageColumn . ' = :image_path';
                $params['image_path'] = $images[0];
            }

            try {
                $stmt = db()->prepare(
                    'UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = :product_id AND shop_id = :shop_id'
                );
                $stmt->execute($params);

                if ($hasImagesTable) {
                    $deleteStmt = db()->prepare('DELETE FROM product_images WHERE product_id = :product_id');
                    $deleteStmt->execute(['product_id' => $productId]);

                    $imageStmt = db()->prepare(
                        'INSERT INTO product_images (product_id, image_path, sort_order)
                         VALUES (:product_id, :image_path, :sort_order)'
                    );
                    foreach ($images as $index => $imagePath) {
                        $imageStmt->execute([
                            'product_id' => $productId,
                            'image_path' => $imagePath,
                            'sort_order' => $index + 1,
                        ]);
                    }
                }

                if ($hasVariantsTable) {
                    $deleteStmt = db()->prepare('DELETE FROM product_variants WHERE product_id = :product_id');
                    $deleteStmt->execute(['product_id' => $productId]);

                    if ($variantRows) {
                        $variantStmt = db()->prepare(
                            'INSERT INTO product_variants (product_id, name, price_add, is_active)
                             VALUES (:product_id, :name, :price_add, :is_active)'
                        );
                        foreach ($variantRows as $variant) {
                            $variantStmt->execute([
                                'product_id' => $productId,
                                'name' => $variant['name'],
                                'price_add' => $variant['price'],
                                'is_active' => 1,
                            ]);
                        }
                    }
                }

                if ($hasAddonsTable) {
                    $deleteStmt = db()->prepare('DELETE FROM product_addons WHERE product_id = :product_id');
                    $deleteStmt->execute(['product_id' => $productId]);

                    if ($addonRows) {
                        $addonStmt = db()->prepare(
                            'INSERT INTO product_addons (product_id, name, addon_price, is_active)
                             VALUES (:product_id, :name, :addon_price, :is_active)'
                        );
                        foreach ($addonRows as $addon) {
                            $addonStmt->execute([
                                'product_id' => $productId,
                                'name' => $addon['name'],
                                'addon_price' => $addon['price'],
                                'is_active' => 1,
                            ]);
                        }
                    }
                }

                flash_set('success', 'Listing updated successfully.');
                header('Location: /owner/catalog/' . $productId . '/edit');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to update listing right now.';
            }
        }
    }
}

if (!$variantRows) {
    $variantRows = array_fill(0, 3, ['name' => '', 'price_add' => '']);
}

if (!$addonRows) {
    $addonRows = array_fill(0, 3, ['name' => '', 'addon_price' => '']);
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Edit listing</h1>
        <p class="text-muted mb-0">Update pricing, images, and add-ons for this listing.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/owner/catalog">Back to catalog</a>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($product): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_listing">

                <div class="mb-3">
                    <label class="form-label">Listing name</label>
                    <input class="form-control" type="text" name="name" value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Base price (₱)</label>
                        <input class="form-control" type="number" name="base_price" min="0" step="0.01" value="<?= htmlspecialchars($form['base_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <?php if ($turnaroundColumn): ?>
                        <div class="col-md-4">
                            <label class="form-label">Turnaround</label>
                            <input class="form-control" type="text" name="turnaround" value="<?= htmlspecialchars($form['turnaround'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., 3-5 business days">
                        </div>
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label">Listing status</label>
                        <select class="form-select" name="listing_enabled">
                            <option value="1" <?= $form['listing_enabled'] === '1' ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= $form['listing_enabled'] === '0' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label">Image URLs (one per line)</label>
                    <textarea class="form-control" name="image_list" rows="4" placeholder="https://..." required><?= htmlspecialchars($form['image_list'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text">At least one image is required.</div>
                </div>

                <?php if ($images): ?>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <?php foreach ($images as $image): ?>
                            <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="Listing image" class="rounded border" style="width: 80px; height: 80px; object-fit: cover;">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4">
                    <h2 class="h6">Variants</h2>
                    <p class="text-muted small">Add optional variants (e.g., sizes, materials) with price adjustments.</p>
                    <?php foreach ($variantRows as $variant): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-8">
                                <input class="form-control" type="text" name="variant_name[]" placeholder="Variant name" value="<?= htmlspecialchars((string) ($variant['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <input class="form-control" type="number" name="variant_price[]" min="0" step="0.01" placeholder="Price add" value="<?= htmlspecialchars((string) ($variant['price_add'] ?? $variant['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4">
                    <h2 class="h6">Add-ons</h2>
                    <p class="text-muted small">Add optional extras (e.g., rush service, packaging) with their own prices.</p>
                    <?php foreach ($addonRows as $addon): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-8">
                                <input class="form-control" type="text" name="addon_name[]" placeholder="Add-on name" value="<?= htmlspecialchars((string) ($addon['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <input class="form-control" type="number" name="addon_price[]" min="0" step="0.01" placeholder="Add-on price" value="<?= htmlspecialchars((string) ($addon['addon_price'] ?? $addon['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4 d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>