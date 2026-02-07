<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$pageTitle = 'Search';

$query = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? 'products');
$categoryId = (int) ($_GET['category'] ?? 0);
$priceMin = $_GET['price_min'] ?? '';
$priceMax = $_GET['price_max'] ?? '';
$ratingMin = $_GET['rating_min'] ?? '';
$turnaroundMax = $_GET['turnaround_max'] ?? '';
$town = trim($_GET['town'] ?? '');
$acceptingOrders = isset($_GET['accepting_orders']) ? 1 : 0;
$acceptingQuotes = isset($_GET['accepting_quotes']) ? 1 : 0;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$errors = [];
$categories = [];
$results = [];
$totalResults = 0;

$typeOptions = [
    'products' => 'Products',
    'shops' => 'Shops',
    'posts' => 'Posts',
];
if (!array_key_exists($type, $typeOptions)) {
    $type = 'products';
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

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function build_query(array $params): string
{
    return http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''));
}

try {
    $categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load categories right now.';
}

$shopColumns = get_table_columns(db(), 'shops');
$productColumns = get_table_columns(db(), 'products');
$postColumns = get_table_columns(db(), 'client_posts');
$availabilityColumns = get_table_columns(db(), 'shop_availability');
$metricsColumns = get_table_columns(db(), 'shop_metrics');

$shopStatusColumn = find_column($shopColumns, ['status']);
$productStatusColumn = find_column($productColumns, ['status']);
$productNameColumn = find_column($productColumns, ['name', 'title']);
$productDescriptionColumn = find_column($productColumns, ['description', 'details']);
$productPriceColumn = find_column($productColumns, ['base_price', 'price', 'unit_price']);
$productCategoryColumn = find_column($productColumns, ['category_id']);

$shopNameColumn = find_column($shopColumns, ['name']);
$shopDescriptionColumn = find_column($shopColumns, ['description']);
$shopTownColumn = find_column($shopColumns, ['address_text', 'town', 'city', 'location']);

$postTitleColumn = find_column($postColumns, ['title', 'subject', 'name']);
$postDescriptionColumn = find_column($postColumns, ['description', 'details', 'content']);
$postCategoryColumn = find_column($postColumns, ['category_id']);
$postTownColumn = find_column($postColumns, ['town', 'city', 'location', 'address_text']);

$metricsRatingColumn = find_column($metricsColumns, ['avg_rating', 'rating_avg', 'rating']);
$metricsReviewColumn = find_column($metricsColumns, ['review_count', 'reviews_count']);
$availabilityTurnaroundColumn = find_column($availabilityColumns, ['turnaround_days', 'turnaround_time', 'turnaround_hours']);
$availabilityOrdersColumn = find_column($availabilityColumns, ['accepting_orders']);
$availabilityQuotesColumn = find_column($availabilityColumns, ['accepting_quotes']);

if ($type === 'products') {
    try {
        $conditions = [];
        $params = [];

        if ($query !== '' && ($productNameColumn || $productDescriptionColumn)) {
            $likeParts = [];
            if ($productNameColumn) {
                $likeParts[] = sprintf('p.%s LIKE :query', $productNameColumn);
            }
            if ($productDescriptionColumn) {
                $likeParts[] = sprintf('p.%s LIKE :query', $productDescriptionColumn);
            }
            $conditions[] = '(' . implode(' OR ', $likeParts) . ')';
            $params['query'] = '%' . $query . '%';
        }

        if ($categoryId > 0 && $productCategoryColumn) {
            $conditions[] = sprintf('p.%s = :category_id', $productCategoryColumn);
            $params['category_id'] = $categoryId;
        }

        if ($productStatusColumn) {
            $conditions[] = "p.$productStatusColumn = 'active'";
        }

        if ($shopStatusColumn) {
            $conditions[] = "s.$shopStatusColumn = 'active'";
        }

        if ($productPriceColumn && $priceMin !== '') {
            $conditions[] = sprintf('p.%s >= :price_min', $productPriceColumn);
            $params['price_min'] = (float) $priceMin;
        }

        if ($productPriceColumn && $priceMax !== '') {
            $conditions[] = sprintf('p.%s <= :price_max', $productPriceColumn);
            $params['price_max'] = (float) $priceMax;
        }

        if ($metricsRatingColumn && $ratingMin !== '') {
            $conditions[] = sprintf('COALESCE(sm.%s, 0) >= :rating_min', $metricsRatingColumn);
            $params['rating_min'] = (float) $ratingMin;
        }

        if ($availabilityTurnaroundColumn && $turnaroundMax !== '') {
            $conditions[] = sprintf('sa.%s <= :turnaround_max', $availabilityTurnaroundColumn);
            $params['turnaround_max'] = (int) $turnaroundMax;
        }

        if ($shopTownColumn && $town !== '') {
            $conditions[] = sprintf('s.%s LIKE :town', $shopTownColumn);
            $params['town'] = '%' . $town . '%';
        }

        if ($availabilityOrdersColumn && $acceptingOrders) {
            $conditions[] = sprintf('sa.%s = 1', $availabilityOrdersColumn);
        }

        if ($availabilityQuotesColumn && $acceptingQuotes) {
            $conditions[] = sprintf('sa.%s = 1', $availabilityQuotesColumn);
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $joins = [
            'LEFT JOIN shops s ON s.id = p.shop_id',
        ];

        if ($metricsColumns) {
            $joins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = p.shop_id';
        }

        if ($availabilityColumns) {
            $joins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = p.shop_id';
        }

        $joinSql = implode('\n', $joins);

        $countSql = "SELECT COUNT(*) FROM products p\n$joinSql\n$whereSql";
        $countStmt = db()->prepare($countSql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStmt->bindValue(':' . $key, $value, $paramType);
        }
        $countStmt->execute();
        $totalResults = (int) $countStmt->fetchColumn();

        $selectColumns = [
            'p.id',
            $productNameColumn ? "p.$productNameColumn AS name" : 'NULL AS name',
            $productPriceColumn ? "p.$productPriceColumn AS price" : 'NULL AS price',
            $shopNameColumn ? "s.$shopNameColumn AS shop_name" : 'NULL AS shop_name',
            $shopTownColumn ? "s.$shopTownColumn AS town" : 'NULL AS town',
            $metricsRatingColumn ? "sm.$metricsRatingColumn AS avg_rating" : 'NULL AS avg_rating',
            $metricsReviewColumn ? "sm.$metricsReviewColumn AS review_count" : 'NULL AS review_count',
            $availabilityTurnaroundColumn ? "sa.$availabilityTurnaroundColumn AS turnaround" : 'NULL AS turnaround',
        ];

        $sql = "SELECT " . implode(', ', $selectColumns) . "\nFROM products p\n$joinSql\n$whereSql\nORDER BY p.id DESC\nLIMIT :limit OFFSET :offset";
        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load product results right now.';
    }
}

if ($type === 'shops') {
    try {
        $conditions = [];
        $params = [];

        if ($query !== '' && ($shopNameColumn || $shopDescriptionColumn)) {
            $likeParts = [];
            if ($shopNameColumn) {
                $likeParts[] = sprintf('s.%s LIKE :query', $shopNameColumn);
            }
            if ($shopDescriptionColumn) {
                $likeParts[] = sprintf('s.%s LIKE :query', $shopDescriptionColumn);
            }
            $conditions[] = '(' . implode(' OR ', $likeParts) . ')';
            $params['query'] = '%' . $query . '%';
        }

        if ($shopStatusColumn) {
            $conditions[] = "s.$shopStatusColumn = 'active'";
        }

        if ($metricsRatingColumn && $ratingMin !== '') {
            $conditions[] = sprintf('COALESCE(sm.%s, 0) >= :rating_min', $metricsRatingColumn);
            $params['rating_min'] = (float) $ratingMin;
        }

        if ($availabilityTurnaroundColumn && $turnaroundMax !== '') {
            $conditions[] = sprintf('sa.%s <= :turnaround_max', $availabilityTurnaroundColumn);
            $params['turnaround_max'] = (int) $turnaroundMax;
        }

        if ($shopTownColumn && $town !== '') {
            $conditions[] = sprintf('s.%s LIKE :town', $shopTownColumn);
            $params['town'] = '%' . $town . '%';
        }

        if ($availabilityOrdersColumn && $acceptingOrders) {
            $conditions[] = sprintf('sa.%s = 1', $availabilityOrdersColumn);
        }

        if ($availabilityQuotesColumn && $acceptingQuotes) {
            $conditions[] = sprintf('sa.%s = 1', $availabilityQuotesColumn);
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $joins = [];
        if ($metricsColumns) {
            $joins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = s.id';
        }
        if ($availabilityColumns) {
            $joins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = s.id';
        }
        $joinSql = $joins ? "\n" . implode("\n", $joins) : '';

        $countSql = "SELECT COUNT(*) FROM shops s$joinSql\n$whereSql";
        $countStmt = db()->prepare($countSql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStmt->bindValue(':' . $key, $value, $paramType);
        }
        $countStmt->execute();
        $totalResults = (int) $countStmt->fetchColumn();

        $selectColumns = [
            's.id',
            $shopNameColumn ? "s.$shopNameColumn AS name" : 'NULL AS name',
            $shopDescriptionColumn ? "s.$shopDescriptionColumn AS description" : 'NULL AS description',
            $shopTownColumn ? "s.$shopTownColumn AS town" : 'NULL AS town',
            $metricsRatingColumn ? "sm.$metricsRatingColumn AS avg_rating" : 'NULL AS avg_rating',
            $metricsReviewColumn ? "sm.$metricsReviewColumn AS review_count" : 'NULL AS review_count',
            $availabilityTurnaroundColumn ? "sa.$availabilityTurnaroundColumn AS turnaround" : 'NULL AS turnaround',
        ];

        $sql = "SELECT " . implode(', ', $selectColumns) . "\nFROM shops s$joinSql\n$whereSql\nORDER BY s.id DESC\nLIMIT :limit OFFSET :offset";
        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load shop results right now.';
    }
}

if ($type === 'posts') {
    try {
        $conditions = [];
        $params = [];

        if ($query !== '' && ($postTitleColumn || $postDescriptionColumn)) {
            $likeParts = [];
            if ($postTitleColumn) {
                $likeParts[] = sprintf('cp.%s LIKE :query', $postTitleColumn);
            }
            if ($postDescriptionColumn) {
                $likeParts[] = sprintf('cp.%s LIKE :query', $postDescriptionColumn);
            }
            $conditions[] = '(' . implode(' OR ', $likeParts) . ')';
            $params['query'] = '%' . $query . '%';
        }

        if ($categoryId > 0 && $postCategoryColumn) {
            $conditions[] = sprintf('cp.%s = :category_id', $postCategoryColumn);
            $params['category_id'] = $categoryId;
        }

        if ($postTownColumn && $town !== '') {
            $conditions[] = sprintf('cp.%s LIKE :town', $postTownColumn);
            $params['town'] = '%' . $town . '%';
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM client_posts cp\n$whereSql";
        $countStmt = db()->prepare($countSql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $countStmt->bindValue(':' . $key, $value, $paramType);
        }
        $countStmt->execute();
        $totalResults = (int) $countStmt->fetchColumn();

        $selectColumns = [
            'cp.id',
            $postTitleColumn ? "cp.$postTitleColumn AS title" : 'NULL AS title',
            $postDescriptionColumn ? "cp.$postDescriptionColumn AS description" : 'NULL AS description',
            $postTownColumn ? "cp.$postTownColumn AS town" : 'NULL AS town',
        ];

        $sql = "SELECT " . implode(', ', $selectColumns) . "\nFROM client_posts cp\n$whereSql\nORDER BY cp.id DESC\nLIMIT :limit OFFSET :offset";
        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load post results right now.';
    }
}

$totalPages = max(1, (int) ceil($totalResults / $perPage));
$hasPrev = $page > 1;
$hasNext = $page < $totalPages;

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-2">Search</h1>
<p class="text-muted">Find products, shops, or posts with filters tailored to your needs.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<form class="row g-3 align-items-end mb-4" method="get">
    <div class="col-lg-4">
        <label for="search-query" class="form-label">Search</label>
        <input type="text" class="form-control" id="search-query" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by keyword">
    </div>
    <div class="col-lg-3">
        <label for="type" class="form-label">Type</label>
        <select class="form-select" id="type" name="type">
            <?php foreach ($typeOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $type ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-3">
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
    <div class="col-lg-2 d-grid">
        <button class="btn btn-primary" type="submit">Search</button>
    </div>

    <div class="col-md-3">
        <label for="price-min" class="form-label">Price min</label>
        <input type="number" class="form-control" id="price-min" name="price_min" value="<?= htmlspecialchars($priceMin, ENT_QUOTES, 'UTF-8') ?>" min="0" step="0.01">
    </div>
    <div class="col-md-3">
        <label for="price-max" class="form-label">Price max</label>
        <input type="number" class="form-control" id="price-max" name="price_max" value="<?= htmlspecialchars($priceMax, ENT_QUOTES, 'UTF-8') ?>" min="0" step="0.01">
    </div>
    <div class="col-md-3">
        <label for="rating-min" class="form-label">Min rating</label>
        <input type="number" class="form-control" id="rating-min" name="rating_min" value="<?= htmlspecialchars($ratingMin, ENT_QUOTES, 'UTF-8') ?>" min="0" max="5" step="0.1">
    </div>
    <div class="col-md-3">
        <label for="turnaround-max" class="form-label">Max turnaround</label>
        <input type="number" class="form-control" id="turnaround-max" name="turnaround_max" value="<?= htmlspecialchars($turnaroundMax, ENT_QUOTES, 'UTF-8') ?>" min="0">
    </div>

    <div class="col-md-4">
        <label for="town" class="form-label">Town</label>
        <input type="text" class="form-control" id="town" name="town" value="<?= htmlspecialchars($town, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter town or city">
    </div>
    <div class="col-md-4 d-flex align-items-center gap-3">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="accepting-orders" name="accepting_orders" value="1" <?= $acceptingOrders ? 'checked' : '' ?>>
            <label class="form-check-label" for="accepting-orders">Accepting orders</label>
        </div>
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="accepting-quotes" name="accepting_quotes" value="1" <?= $acceptingQuotes ? 'checked' : '' ?>>
            <label class="form-check-label" for="accepting-quotes">Accepting quotes</label>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 mb-0"><?= htmlspecialchars($typeOptions[$type], ENT_QUOTES, 'UTF-8') ?> results</h2>
    <span class="small text-muted"><?= $totalResults ?> found</span>
</div>

<?php if (!$results): ?>
    <div class="alert alert-info">No results found for this search.</div>
<?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($results as $result): ?>
            <?php if ($type === 'products'): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="h6 mb-1"><?= htmlspecialchars($result['name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="text-muted small"><?= htmlspecialchars($result['shop_name'] ?? 'Shop', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($result['town'])): ?>
                                <div class="text-muted small"><?= htmlspecialchars($result['town'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">₱<?= number_format((float) ($result['price'] ?? 0), 2) ?></div>
                            <?php if (!empty($result['avg_rating'])): ?>
                                <div class="small text-muted">⭐ <?= number_format((float) $result['avg_rating'], 1) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($type === 'shops'): ?>
                <div class="list-group-item">
                    <h3 class="h6 mb-1"><?= htmlspecialchars($result['name'] ?? 'Shop', ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php if (!empty($result['description'])): ?>
                        <p class="small text-muted mb-1"><?= htmlspecialchars($result['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                        <?php if (!empty($result['town'])): ?>
                            <span><?= htmlspecialchars($result['town'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($result['avg_rating'])): ?>
                            <span>⭐ <?= number_format((float) $result['avg_rating'], 1) ?> (<?= (int) ($result['review_count'] ?? 0) ?> reviews)</span>
                        <?php endif; ?>
                        <?php if (!empty($result['turnaround'])): ?>
                            <span>Turnaround: <?= htmlspecialchars($result['turnaround'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="list-group-item">
                    <h3 class="h6 mb-1"><?= htmlspecialchars($result['title'] ?? 'Post', ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php if (!empty($result['description'])): ?>
                        <p class="small text-muted mb-1"><?= htmlspecialchars($result['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if (!empty($result['town'])): ?>
                        <div class="small text-muted"><?= htmlspecialchars($result['town'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<nav class="d-flex justify-content-between align-items-center">
    <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
    <div class="btn-group">
        <a class="btn btn-outline-secondary <?= $hasPrev ? '' : 'disabled' ?>"
           href="/client/search?<?= build_query([
               'q' => $query,
               'type' => $type,
               'category' => $categoryId ?: null,
               'price_min' => $priceMin,
               'price_max' => $priceMax,
               'rating_min' => $ratingMin,
               'turnaround_max' => $turnaroundMax,
               'town' => $town,
               'accepting_orders' => $acceptingOrders ?: null,
               'accepting_quotes' => $acceptingQuotes ?: null,
               'page' => $page - 1,
           ]) ?>">
            Previous
        </a>
        <a class="btn btn-outline-secondary <?= $hasNext ? '' : 'disabled' ?>"
           href="/client/search?<?= build_query([
               'q' => $query,
               'type' => $type,
               'category' => $categoryId ?: null,
               'price_min' => $priceMin,
               'price_max' => $priceMax,
               'rating_min' => $ratingMin,
               'turnaround_max' => $turnaroundMax,
               'town' => $town,
               'accepting_orders' => $acceptingOrders ?: null,
               'accepting_quotes' => $acceptingQuotes ?: null,
               'page' => $page + 1,
           ]) ?>">
            Next
        </a>
    </div>
</nav>

<?php require __DIR__ . '/../../includes/footer.php'; ?>