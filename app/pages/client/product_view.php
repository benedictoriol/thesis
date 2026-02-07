<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$pageTitle = 'Product Details';

$productId = (int) ($_GET['id'] ?? 0);
$errors = [];
$product = null;

if ($productId <= 0) {
    $errors[] = 'Invalid product selection.';
} else {
    try {
        $sql = "SELECT p.id, p.name, p.description, p.base_price, s.name AS shop_name,
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
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex gap-4 flex-column flex-md-row">
                <?php if (!empty($product['image_path'])): ?>
                    <img src="<?= htmlspecialchars($product['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                         class="rounded" style="width: 180px; height: 180px; object-fit: cover;">
                <?php else: ?>
                    <div class="bg-secondary-subtle d-flex align-items-center justify-content-center rounded" style="width: 180px; height: 180px;">
                        <span class="text-muted small">No image</span>
                    </div>
                <?php endif; ?>
                <div>
                    <h2 class="h5 mb-2"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="text-muted mb-2">
                        <?= htmlspecialchars($product['shop_name'] ?? 'Shop', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="fw-semibold mb-3">₱<?= number_format((float) ($product['base_price'] ?? 0), 2) ?></div>
                    <div class="small text-muted mb-3">
                        ⭐ <?= number_format((float) ($product['avg_rating'] ?? 0), 1) ?>
                        (<?= (int) ($product['review_count'] ?? 0) ?> reviews)
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
    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php else: ?>
    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>