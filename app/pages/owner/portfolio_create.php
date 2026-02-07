<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../handlers/catalog_handler.php';
require_once __DIR__ . '/../../handlers/portfolio_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Create Portfolio Sample';
$errors = [];
$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$portfolioColumns = get_table_columns(db(), 'shop_portfolio');
if (!$portfolioColumns) {
    $errors[] = 'Portfolio listings are not available right now.';
}

$titleColumn = find_column($portfolioColumns, ['title', 'name']);
$descriptionColumn = find_column($portfolioColumns, ['description', 'details']);
$categoryColumn = find_column($portfolioColumns, ['category_id', 'category']);
$statusColumn = find_column($portfolioColumns, ['status']);
$isActiveColumn = find_column($portfolioColumns, ['is_active', 'active']);
$createdAtColumn = find_column($portfolioColumns, ['created_at', 'created']);
$imageColumn = find_column($portfolioColumns, ['image_path', 'image_url', 'thumbnail_path']);
$hasImagesTable = (bool) get_table_columns(db(), 'shop_portfolio_images');

if (!$titleColumn) {
    $errors[] = 'Portfolio listings are missing required fields.';
}

$categories = load_portfolio_categories();

$form = [
    'title' => '',
    'description' => '',
    'category_id' => '',
    'listing_enabled' => '1',
    'image_list' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_portfolio' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $form['title'] = trim((string) ($_POST['title'] ?? ''));
        $form['description'] = trim((string) ($_POST['description'] ?? ''));
        $form['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
        $form['listing_enabled'] = ($_POST['listing_enabled'] ?? '1') === '1' ? '1' : '0';
        $form['image_list'] = trim((string) ($_POST['image_list'] ?? ''));

        if ($form['title'] === '') {
            $errors[] = 'Title is required.';
        }

        $images = parse_image_list($form['image_list']);
        if (!$images) {
            $errors[] = 'At least one image is required.';
        }

        if (!$hasImagesTable && !$imageColumn) {
            $errors[] = 'Image uploads are not available right now.';
        }

        $categoryId = 0;
        if ($categoryColumn && $form['category_id'] !== '') {
            $categoryId = (int) $form['category_id'];
            $validCategoryIds = array_map(static fn(array $category): int => (int) $category['id'], $categories);
            if ($categoryId > 0 && !in_array($categoryId, $validCategoryIds, true)) {
                $errors[] = 'Select a valid category.';
            }
        }

        if (!$errors) {
            $enabled = $form['listing_enabled'] === '1';
            $fields = ['shop_id'];
            $placeholders = [':shop_id'];
            $params = [
                'shop_id' => $shop['id'],
            ];

            if ($titleColumn) {
                $fields[] = $titleColumn;
                $placeholders[] = ':title';
                $params['title'] = $form['title'];
            }

            if ($descriptionColumn) {
                $fields[] = $descriptionColumn;
                $placeholders[] = ':description';
                $params['description'] = $form['description'] !== '' ? $form['description'] : null;
            }

            if ($categoryColumn) {
                $fields[] = $categoryColumn;
                $placeholders[] = ':category_id';
                $params['category_id'] = $categoryId > 0 ? $categoryId : null;
            }

            if ($statusColumn) {
                $fields[] = $statusColumn;
                $placeholders[] = ':status';
                $params['status'] = $enabled ? 'active' : 'hidden';
            }

            if ($isActiveColumn) {
                $fields[] = $isActiveColumn;
                $placeholders[] = ':is_active';
                $params['is_active'] = $enabled ? 1 : 0;
            }

            if ($createdAtColumn) {
                $fields[] = $createdAtColumn;
                $placeholders[] = ':created_at';
                $params['created_at'] = gmdate('Y-m-d H:i:s');
            }

            if (!$hasImagesTable && $imageColumn && $images) {
                $fields[] = $imageColumn;
                $placeholders[] = ':image_path';
                $params['image_path'] = $images[0];
            }

            try {
                $stmt = db()->prepare(
                    'INSERT INTO shop_portfolio (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
                );
                $stmt->execute($params);
                $portfolioId = (int) db()->lastInsertId();

                if ($hasImagesTable && $images) {
                    $imageStmt = db()->prepare(
                        'INSERT INTO shop_portfolio_images (portfolio_id, image_path, sort_order)
                         VALUES (:portfolio_id, :image_path, :sort_order)'
                    );
                    foreach ($images as $index => $imagePath) {
                        $imageStmt->execute([
                            'portfolio_id' => $portfolioId,
                            'image_path' => $imagePath,
                            'sort_order' => $index + 1,
                        ]);
                    }
                }

                flash_set('success', 'Portfolio item created successfully.');
                header('Location: /owner/portfolio');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to create portfolio item right now.';
            }
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Create portfolio sample</h1>
        <p class="text-muted mb-0">Add photos and details that highlight your best work.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="/owner/portfolio">Back to portfolio</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_portfolio">

                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input class="form-control" type="text" name="title" value="<?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <?php if ($categoryColumn): ?>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id">
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (string) $category['id'] === $form['category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Visibility</label>
                    <select class="form-select" name="listing_enabled">
                        <option value="1" <?= $form['listing_enabled'] === '1' ? 'selected' : '' ?>>Visible to clients</option>
                        <option value="0" <?= $form['listing_enabled'] === '0' ? 'selected' : '' ?>>Hidden</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Image URLs (one per line)</label>
                    <textarea class="form-control" name="image_list" rows="4" placeholder="https://..." required><?= htmlspecialchars($form['image_list'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text">Add multiple images to show different angles or details.</div>
                </div>

                <div class="mt-4 d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Create portfolio item</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>