<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../handlers/catalog_handler.php';
require_once __DIR__ . '/../../handlers/portfolio_handler.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Portfolio Samples';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_user($currentUser);
$portfolioItems = [];

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$portfolioColumns = get_table_columns(db(), 'shop_portfolio');
if (!$portfolioColumns) {
    $errors[] = 'Portfolio listings are not available right now.';
}

$statusColumn = find_column($portfolioColumns, ['status']);
$isActiveColumn = find_column($portfolioColumns, ['is_active', 'active']);
$titleColumn = find_column($portfolioColumns, ['title', 'name']);
$descriptionColumn = find_column($portfolioColumns, ['description', 'details']);
$categoryColumn = find_column($portfolioColumns, ['category_id', 'category']);
$createdAtColumn = find_column($portfolioColumns, ['created_at', 'created']);
$imageColumn = find_column($portfolioColumns, ['image_path', 'image_url', 'thumbnail_path']);
$hasImagesTable = (bool) get_table_columns(db(), 'shop_portfolio_images');

if (!$titleColumn) {
    $errors[] = 'Portfolio listings are missing required fields.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_visibility' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
        $enable = ($_POST['listing_enabled'] ?? '0') === '1';

        if ($portfolioId <= 0) {
            $errors[] = 'Invalid portfolio selection.';
        }

        if (!$statusColumn && !$isActiveColumn) {
            $errors[] = 'Portfolio visibility cannot be updated right now.';
        }

        if (!$errors) {
            $updates = [];
            $params = [
                'portfolio_id' => $portfolioId,
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
                    'UPDATE shop_portfolio SET ' . implode(', ', $updates) . ' WHERE id = :portfolio_id AND shop_id = :shop_id'
                );
                $stmt->execute($params);
                flash_set('success', 'Portfolio visibility updated.');
                redirect('/owner/portfolio');
            } catch (PDOException $exception) {
                $errors[] = 'Unable to update portfolio visibility right now.';
            }
        }
    }
}

$categories = load_portfolio_categories();
$categoryLookup = [];
foreach ($categories as $category) {
    $categoryLookup[(int) $category['id']] = $category['name'];
}

if (!$errors) {
    $select = [
        'sp.id',
    ];

    if ($titleColumn) {
        $select[] = sprintf('sp.%s AS title', $titleColumn);
    }
    if ($descriptionColumn) {
        $select[] = sprintf('sp.%s AS description', $descriptionColumn);
    }
    if ($categoryColumn) {
        $select[] = sprintf('sp.%s AS category_id', $categoryColumn);
    }
    if ($statusColumn) {
        $select[] = sprintf('sp.%s AS status_value', $statusColumn);
    }
    if ($isActiveColumn) {
        $select[] = sprintf('sp.%s AS active_value', $isActiveColumn);
    }
    if ($createdAtColumn) {
        $select[] = sprintf('sp.%s AS created_at', $createdAtColumn);
    }
    if ($hasImagesTable) {
        $select[] = '(SELECT COUNT(*) FROM shop_portfolio_images spi WHERE spi.portfolio_id = sp.id) AS image_count';
    }
    if (!$hasImagesTable && $imageColumn) {
        $select[] = sprintf('sp.%s AS image_path', $imageColumn);
    }

    $orderBy = $createdAtColumn ? 'sp.' . $createdAtColumn . ' DESC' : 'sp.id DESC';

    try {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM shop_portfolio sp';
        $sql .= ' WHERE sp.shop_id = :shop_id';
        $sql .= ' ORDER BY ' . $orderBy;
        $stmt = db()->prepare($sql);
        $stmt->execute(['shop_id' => $shop['id']]);
        $portfolioItems = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load portfolio listings right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Portfolio Samples</h1>
        <p class="text-muted mb-0">Showcase finished work and control what clients can see.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" href="/owner/portfolio/create">Add portfolio item</a>
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
        Portfolio items should include at least one image and a clear description.
    </div>

    <?php if (!$portfolioItems): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted mb-3">No portfolio samples yet. Add your first project to get started.</p>
                <a class="btn btn-primary" href="/owner/portfolio/create">Add portfolio item</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Images</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($portfolioItems as $item): ?>
                        <?php
                        $isActive = true;
                        if ($isActiveColumn) {
                            $isActive = (int) ($item['active_value'] ?? 0) === 1;
                        } elseif ($statusColumn) {
                            $isActive = ($item['status_value'] ?? '') === 'active';
                        }
                        $categoryLabel = 'Uncategorized';
                        if ($categoryColumn && isset($item['category_id']) && (int) $item['category_id'] > 0) {
                            $categoryLabel = $categoryLookup[(int) $item['category_id']] ?? 'Uncategorized';
                        }
                        $imageCount = '—';
                        if ($hasImagesTable) {
                            $imageCount = (int) ($item['image_count'] ?? 0);
                        } elseif (!empty($item['image_path'])) {
                            $imageCount = 1;
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($item['description'] ?? 'No description', ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $imageCount, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $isActive ? 'Visible' : 'Hidden' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                                    <?php if ($statusColumn || $isActiveColumn): ?>
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_visibility">
                                            <input type="hidden" name="portfolio_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="listing_enabled" value="<?= $isActive ? '0' : '1' ?>">
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">
                                                <?= $isActive ? 'Hide' : 'Unhide' ?>
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

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>