<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$pageTitle = 'Marketplace';

$categoryId = (int) ($_GET['category'] ?? 0);
$sort = trim($_GET['sort'] ?? 'newest');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$errors = [];
$categories = [];
$products = [];
$totalProducts = 0;

$sortOptions = [
    'newest' => 'Newest',
    'popular' => 'Popular',
    'recommended' => 'Recommended',
];
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'newest';
}

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
    } catch (PDOException $exception) {
        return [];
    }
}

try {
    $categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load categories right now.';
}

try {
    $columns = get_table_columns(db(), 'products');
    $hasStatus = in_array('status', $columns, true);
    $hasCreatedAt = in_array('created_at', $columns, true);

    $conditions = [];
    $params = [];
    if ($categoryId > 0) {
        $conditions[] = 'p.category_id = :category_id';
        $params['category_id'] = $categoryId;
    }
    if ($hasStatus) {
        $conditions[] = "p.status = 'active'";
    }

    $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countSql = "SELECT COUNT(*) FROM products p $whereSql";
    $countStmt = db()->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $countStmt->execute();
    $totalProducts = (int) $countStmt->fetchColumn();

    $defaultSort = $hasCreatedAt ? 'p.created_at DESC' : 'p.id DESC';
    $sortSql = $defaultSort;

    if ($sort === 'popular') {
        $popularSorts = [];
        if (in_array('view_count', $columns, true)) {
            $popularSorts[] = 'p.view_count DESC';
        }
        if (in_array('views', $columns, true)) {
            $popularSorts[] = 'p.views DESC';
        }
        if (in_array('order_count', $columns, true)) {
            $popularSorts[] = 'p.order_count DESC';
        }
        if (in_array('orders_count', $columns, true)) {
            $popularSorts[] = 'p.orders_count DESC';
        }
        if ($popularSorts) {
            $popularSorts[] = $defaultSort;
            $sortSql = implode(', ', $popularSorts);
        }
    }

    if ($sort === 'recommended') {
        if (in_array('dss_score', $columns, true)) {
            $sortSql = 'p.dss_score DESC';
        } elseif (in_array('recommended_score', $columns, true)) {
            $sortSql = 'p.recommended_score DESC';
        } elseif (in_array('score', $columns, true)) {
            $sortSql = 'p.score DESC';
        }
    }

    $sql = "SELECT p.id, p.name, p.base_price, p.created_at, s.name AS shop_name,
            COALESCE(sm.avg_rating, 0) AS avg_rating,
            COALESCE(sm.review_count, 0) AS review_count,
            pi.image_path
        FROM products p
        LEFT JOIN shops s ON s.id = p.shop_id
        LEFT JOIN shop_metrics sm ON sm.shop_id = p.shop_id
        LEFT JOIN (
            SELECT pi1.product_id, pi1.image_path
            FROM product_images pi1
            INNER JOIN (
                SELECT product_id, MIN(sort_order) AS min_sort
                FROM product_images
                GROUP BY product_id
            ) pi2 ON pi1.product_id = pi2.product_id AND pi1.sort_order = pi2.min_sort
        ) pi ON pi.product_id = p.id
        $whereSql
        ORDER BY $sortSql
        LIMIT :limit OFFSET :offset";

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load products right now.';
}

$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$hasPrev = $page > 1;
$hasNext = $page < $totalPages;

function build_query(array $params): string
{
    return http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''));
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Marketplace</h1>
<p class="text-muted">Browse curated products from active shops.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<form class="row g-3 align-items-end mb-4" method="get">
    <div class="col-md-5">
        <label for="category" class="form-label">Category</label>
        <select class="form-select" id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === $categoryId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-5">
        <label for="sort" class="form-label">Sort</label>
        <select class="form-select" id="sort" name="sort">
            <?php foreach ($sortOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $sort ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-grid">
        <button class="btn btn-primary" type="submit">Filter</button>
    </div>
</form>

<?php if (!$products): ?>
    <div class="alert alert-info">No products found for this selection.</div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($products as $product): ?>
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex gap-3">
                            <?php if (!empty($product['image_path'])): ?>
                                <img src="<?= htmlspecialchars($product['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                     class="rounded" style="width: 96px; height: 96px; object-fit: cover;">
                            <?php else: ?>
                                <div class="bg-secondary-subtle d-flex align-items-center justify-content-center rounded" style="width: 96px; height: 96px;">
                                    <span class="text-muted small">No image</span>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <h2 class="h6 mb-1"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <div class="text-muted small mb-2">
                                    <?= htmlspecialchars($product['shop_name'] ?? 'Shop', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="fw-semibold mb-2">₱<?= number_format((float) ($product['base_price'] ?? 0), 2) ?></div>
                                <div class="small text-muted">
                                    ⭐ <?= number_format((float) ($product['avg_rating'] ?? 0), 1) ?>
                                    (<?= (int) ($product['review_count'] ?? 0) ?> reviews)
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 pt-0">
                        <a class="btn btn-outline-primary w-100" href="/client/product_view?id=<?= (int) $product['id'] ?>">View</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<nav class="d-flex justify-content-between align-items-center mt-4">
    <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
    <div class="btn-group">
        <a class="btn btn-outline-secondary <?= $hasPrev ? '' : 'disabled' ?>"
           href="/client/home?<?= build_query(['category' => $categoryId ?: null, 'sort' => $sort, 'page' => $page - 1]) ?>">
            Previous
        </a>
        <a class="btn btn-outline-secondary <?= $hasNext ? '' : 'disabled' ?>"
           href="/client/home?<?= build_query(['category' => $categoryId ?: null, 'sort' => $sort, 'page' => $page + 1]) ?>">
            Next
        </a>
    </div>
</nav>

<?php require __DIR__ . '/../../includes/footer.php'; ?>