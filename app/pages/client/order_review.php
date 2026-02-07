<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['client']);

$pageTitle = 'Submit Review';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

$currentUser = current_user();
$orderId = (int) ($_GET['id'] ?? 0);
$errors = [];
$successMessage = flash_get('success');
$ordersColumns = get_table_columns(db(), 'orders');
$reviewsColumns = get_table_columns(db(), 'reviews');

$order = null;
$shop = null;
$product = null;
$alreadyReviewed = false;

$clientColumn = find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
$statusColumn = find_column($ordersColumns, ['status', 'order_status']);
$productIdColumn = find_column($ordersColumns, ['product_id', 'item_id']);
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);

if ($orderId <= 0) {
    $errors[] = 'Invalid order selection.';
} elseif (!$ordersColumns) {
    $errors[] = 'Orders are not available right now.';
} else {
    if (!$clientColumn) {
        $errors[] = 'Order ownership details are missing.';
    }
    if (!$statusColumn) {
        $errors[] = 'Order status is unavailable.';
    }

    if (!$errors) {
        try {
            $selectParts = ['o.id'];
            $selectParts[] = "o.$clientColumn AS client_user_id";
            $selectParts[] = "o.$statusColumn AS order_status";
            if ($productIdColumn) {
                $selectParts[] = "o.$productIdColumn AS product_id";
            }
            if ($shopIdColumn) {
                $selectParts[] = "o.$shopIdColumn AS shop_id";
            }

            $stmt = db()->prepare(
                'SELECT ' . implode(', ', $selectParts) . '
                 FROM orders o
                 WHERE o.id = :order_id
                 LIMIT 1'
            );
            $stmt->execute(['order_id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $errors[] = 'Order not found.';
            }
        } catch (PDOException $exception) {
            $errors[] = 'Unable to load order details right now.';
        }
    }
}

if ($order && $currentUser) {
    if ((int) $order['client_user_id'] !== (int) $currentUser['id']) {
        http_response_code(403);
        $errors[] = 'You do not have access to this order.';
        $order = null;
    }
}

if ($order) {
    $statusValue = strtolower((string) ($order['order_status'] ?? ''));
    if ($statusValue !== 'completed') {
        $errors[] = 'Only completed orders can be reviewed.';
    }
}

if ($order && $reviewsColumns) {
    try {
        $reviewStmt = db()->prepare('SELECT id FROM reviews WHERE order_id = :order_id LIMIT 1');
        $reviewStmt->execute(['order_id' => $orderId]);
        $alreadyReviewed = (bool) $reviewStmt->fetch();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to verify existing reviews.';
    }
} elseif ($order && !$reviewsColumns) {
    $errors[] = 'Reviews are not available right now.';
}

if ($order) {
    $productId = $order['product_id'] ?? null;
    $shopId = $order['shop_id'] ?? null;

    if ($shopId === null && $productId) {
        try {
            $shopStmt = db()->prepare('SELECT shop_id FROM products WHERE id = :product_id');
            $shopStmt->execute(['product_id' => $productId]);
            $shopId = $shopStmt->fetchColumn();
        } catch (PDOException $exception) {
            $shopId = null;
        }
    }

    if ($productId) {
        try {
            $productStmt = db()->prepare('SELECT id, name FROM products WHERE id = :product_id');
            $productStmt->execute(['product_id' => $productId]);
            $product = $productStmt->fetch();
        } catch (PDOException $exception) {
            $product = null;
        }
    }

    if ($shopId) {
        try {
            $shopStmt = db()->prepare('SELECT id, name FROM shops WHERE id = :shop_id');
            $shopStmt->execute(['shop_id' => $shopId]);
            $shop = $shopStmt->fetch();
        } catch (PDOException $exception) {
            $shop = null;
        }
    }

    $order['shop_id'] = $shopId;
    $order['product_id'] = $productId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    }

    if ($alreadyReviewed) {
        $errors[] = 'This order already has a review.';
    }

    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Rating must be between 1 and 5.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO reviews (order_id, product_id, shop_id, client_user_id, rating, comment, created_at)
                 VALUES (:order_id, :product_id, :shop_id, :client_user_id, :rating, :comment, :created_at)'
            );
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => $order['product_id'] ?: null,
                'shop_id' => $order['shop_id'],
                'client_user_id' => $currentUser['id'],
                'rating' => $rating,
                'comment' => $comment !== '' ? $comment : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            flash_set('success', 'Review submitted successfully.');
            header('Location: /order/' . $orderId . '/review');
            exit;
        } catch (PDOException $exception) {
            $errors[] = 'Unable to submit review right now.';
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Submit Review</h1>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($order): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-column gap-1">
                <div class="fw-semibold">Order #<?= htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($product): ?>
                    <div class="text-muted">Product: <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($shop): ?>
                    <div class="text-muted">Shop: <?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="text-muted">Status: <?= htmlspecialchars((string) ($order['order_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>

    <?php if (!$alreadyReviewed && !$errors): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="post">
                    <?= csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label" for="rating">Rating</label>
                        <select class="form-select" id="rating" name="rating" required>
                            <option value="">Select rating</option>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>" <?= (int) ($_POST['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> stars</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="comment">Comment</label>
                        <textarea class="form-control" id="comment" name="comment" rows="4" placeholder="Share your experience..."><?= htmlspecialchars($_POST['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Submit review</button>
                </form>
            </div>
        </div>
    <?php elseif ($alreadyReviewed): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">This order already has a review on file.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php else: ?>
    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/client/home">Back to marketplace</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
