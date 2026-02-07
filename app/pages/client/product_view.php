<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$pageTitle = 'Product Details';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

$productId = (int) ($_GET['id'] ?? 0);
$errors = [];
$product = null;
$images = [];
$variants = [];
$addons = [];
$reviews = [];
$reviewSummary = [
    'avg_rating' => 0,
    'review_count' => 0,
];
$reviewImages = [];
$hasReviewsTable = false;

if ($productId <= 0) {
    $errors[] = 'Invalid product selection.';
} else {
    try {
        $sql = "SELECT p.id, p.name, p.description, p.base_price, p.shop_id,
                s.name AS shop_name, s.description AS shop_description, s.address_text AS shop_address, s.logo_path AS shop_logo,
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
            WHERE p.id = :product_id
            LIMIT 1";

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $product = $stmt->fetch();

        if (!$product) {
            $errors[] = 'Product not found.';
            } else {
            try {
                $imageStmt = db()->prepare(
                    'SELECT image_path
                     FROM product_images
                     WHERE product_id = :product_id
                     ORDER BY sort_order ASC, id ASC'
                );
                $imageStmt->execute(['product_id' => $productId]);
                $images = array_filter(array_column($imageStmt->fetchAll(), 'image_path'));
            } catch (PDOException $exception) {
                $images = [];
            }

            if (!$images && !empty($product['image_path'])) {
                $images = [$product['image_path']];
            }

            if (get_table_columns(db(), 'product_variants')) {
                try {
                    $variantStmt = db()->prepare(
                        'SELECT name, price_add
                         FROM product_variants
                         WHERE product_id = :product_id AND is_active = 1
                         ORDER BY id ASC'
                    );
                    $variantStmt->execute(['product_id' => $productId]);
                    $variants = $variantStmt->fetchAll();
                } catch (PDOException $exception) {
                    $variants = [];
                }
            }

            if (get_table_columns(db(), 'product_addons')) {
                try {
                    $addonStmt = db()->prepare(
                        'SELECT name, addon_price
                         FROM product_addons
                         WHERE product_id = :product_id AND is_active = 1
                         ORDER BY id ASC'
                    );
                    $addonStmt->execute(['product_id' => $productId]);
                    $addons = $addonStmt->fetchAll();
                } catch (PDOException $exception) {
                    $addons = [];
                }
            }
            
            if (get_table_columns(db(), 'reviews')) {
                $hasReviewsTable = true;
                try {
                    $summaryStmt = db()->prepare(
                        'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
                         FROM reviews
                         WHERE product_id = :product_id'
                    );
                    $summaryStmt->execute(['product_id' => $productId]);
                    $summaryRow = $summaryStmt->fetch();
                    if ($summaryRow) {
                        $reviewSummary = [
                            'avg_rating' => (float) ($summaryRow['avg_rating'] ?? 0),
                            'review_count' => (int) ($summaryRow['review_count'] ?? 0),
                        ];
                    }
                } catch (PDOException $exception) {
                    $reviewSummary = [
                        'avg_rating' => 0,
                        'review_count' => 0,
                    ];
                }

                try {
                    $reviewStmt = db()->prepare(
                        'SELECT r.id, r.rating, r.comment, r.created_at, u.fullname AS client_name
                         FROM reviews r
                         JOIN users u ON u.id = r.client_user_id
                         WHERE r.product_id = :product_id
                         ORDER BY r.created_at DESC
                         LIMIT 3'
                    );
                    $reviewStmt->execute(['product_id' => $productId]);
                    $reviews = $reviewStmt->fetchAll();
                } catch (PDOException $exception) {
                    $reviews = [];
                }

                if ($reviews && get_table_columns(db(), 'review_images')) {
                    try {
                        $reviewIds = array_map(static fn(array $review) => (int) $review['id'], $reviews);
                        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
                        $imageStmt = db()->prepare(
                            "SELECT review_id, image_path FROM review_images WHERE review_id IN ($placeholders)"
                        );
                        $imageStmt->execute($reviewIds);
                        foreach ($imageStmt->fetchAll() as $row) {
                            $reviewId = (int) $row['review_id'];
                            $reviewImages[$reviewId][] = $row['image_path'];
                        }
                    } catch (PDOException $exception) {
                        $reviewImages = [];
                    }
                }
            }
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load product details right now.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Product Details</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($product): ?>
    <?php
    $shopName = $product['shop_name'] ?? 'Shop';
    $shopSearchLink = '/client/search?type=shops&query=' . rawurlencode($shopName);
    $shopId = (int) ($product['shop_id'] ?? 0);
    $messageBase = '/messages?action=start&shop_id=' . $shopId . '&context_id=' . (int) $product['id'];
    $needsQuote = (float) ($product['base_price'] ?? 0) <= 0;
    $isCustomizable = !empty($variants) || !empty($addons);
    $mainImage = $images[0] ?? '';
    $reviewCount = $hasReviewsTable ? ($reviewSummary['review_count'] ?? 0) : (int) ($product['review_count'] ?? 0);
    $avgRating = $hasReviewsTable ? ($reviewSummary['avg_rating'] ?? 0) : (float) ($product['avg_rating'] ?? 0);
    ?>
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-12 col-md-5">
                            <?php if (!empty($mainImage)): ?>
                                <img src="<?= htmlspecialchars($mainImage, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                     class="rounded w-100 mb-3" style="height: 240px; object-fit: cover;">
                                <?php if (count($images) > 1): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach (array_slice($images, 1) as $image): ?>
                                            <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                                                 alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                 class="rounded border" style="width: 70px; height: 70px; object-fit: cover;">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="bg-secondary-subtle d-flex align-items-center justify-content-center rounded" style="height: 240px;">
                                    <span class="text-muted small">No image</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-7">
                            <h2 class="h5 mb-2"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="text-muted mb-2">
                                <?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="fw-semibold mb-3">₱<?= number_format((float) ($product['base_price'] ?? 0), 2) ?></div>
                            <div class="small text-muted mb-3">
                                ⭐ <?= number_format((float) $avgRating, 1) ?>
                                (<?= (int) $reviewCount ?> reviews)
                            </div>
                            <?php if (!empty($product['description'])): ?>
                                <p class="mb-0"><?= nl2br(htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php else: ?>
                                <p class="text-muted mb-0">No description provided.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h6 mb-3">Actions</h3>
                    <div class="d-grid gap-2">
                        <a class="btn btn-primary" href="<?= $messageBase ?>&context_type=order">Order now</a>
                        <?php if ($isCustomizable): ?>
                            <a class="btn btn-outline-primary" href="<?= $messageBase ?>&context_type=inquiry">Customize</a>
                        <?php endif; ?>
                        <?php if ($needsQuote): ?>
                            <a class="btn btn-outline-secondary" href="<?= $messageBase ?>&context_type=quote_request">Request quotation</a>
                        <?php endif; ?>
                        <a class="btn btn-outline-dark" href="<?= $messageBase ?>&context_type=inquiry">Message shop</a>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        Prefer a custom design? Use the messaging options to coordinate with the shop.
                    </p>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if (!empty($product['shop_logo'])): ?>
                            <img src="<?= htmlspecialchars($product['shop_logo'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?>"
                                 class="rounded-circle border" style="width: 56px; height: 56px; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-secondary-subtle rounded-circle d-flex align-items-center justify-content-center"
                                 style="width: 56px; height: 56px;">
                                <span class="text-muted small">Logo</span>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($product['shop_address'])): ?>
                                <div class="text-muted small"><?= htmlspecialchars($product['shop_address'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($product['shop_description'])): ?>
                        <p class="small text-muted mb-3"><?= nl2br(htmlspecialchars($product['shop_description'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php else: ?>
                        <p class="small text-muted mb-3">Shop description is not available yet.</p>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary w-100" href="<?= $shopSearchLink ?>">Visit shop page</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 mb-3">Variants</h3>
                    <?php if ($variants): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($variants as $variant): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($variant['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-muted">+₱<?= number_format((float) ($variant['price_add'] ?? 0), 2) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0">No active variants listed.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 mb-3">Add-ons</h3>
                    <?php if ($addons): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($addons as $addon): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($addon['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-muted">+₱<?= number_format((float) ($addon['addon_price'] ?? 0), 2) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0">No add-ons available yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card shadow-sm mt-4" id="reviews">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h3 class="h6 mb-1">Reviews</h3>
                    <div class="small text-muted">
                        ⭐ <?= number_format((float) $avgRating, 1) ?>
                        (<?= (int) $reviewCount ?> reviews)
                    </div>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="/product/<?= (int) $product['id'] ?>/reviews">See all reviews</a>
            </div>
            <?php if ($reviews): ?>
                <div class="vstack gap-3">
                    <?php foreach ($reviews as $review): ?>
                        <div class="border rounded p-3 bg-white">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong><?= htmlspecialchars($review['client_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="text-muted small"><?= htmlspecialchars($review['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="small text-muted mb-2">⭐ <?= (int) $review['rating'] ?>/5</div>
                            <?php if (!empty($review['comment'])): ?>
                                <p class="mb-2"><?= nl2br(htmlspecialchars($review['comment'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php else: ?>
                                <p class="text-muted mb-2">No written feedback provided.</p>
                            <?php endif; ?>
                            <?php if (!empty($reviewImages[$review['id']])): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($reviewImages[$review['id']] as $imagePath): ?>
                                        <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>"
                                             alt="Review photo"
                                             class="rounded border" style="width: 72px; height: 72px; object-fit: cover;">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Reviews will appear here once customers submit feedback.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php else: ?>
    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>