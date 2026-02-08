<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['owner', 'hr']);

$pageTitle = 'Shop Reviews';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

$currentUser = current_user();
$shopId = (int) ($_GET['id'] ?? 0);
$errors = [];
$shop = null;
$reviews = [];
$reviewSummary = [
    'avg_rating' => 0,
    'review_count' => 0,
];
$reviewImages = [];

if ($shopId <= 0) {
    $errors[] = 'Invalid shop selection.';
} else {
    try {
        $stmt = db()->prepare('SELECT id, name, owner_user_id FROM shops WHERE id = :shop_id LIMIT 1');
        $stmt->execute(['shop_id' => $shopId]);
        $shop = $stmt->fetch();

        if (!$shop) {
            $errors[] = 'Shop not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load shop details right now.';
    }
}

if ($shop && $currentUser) {
    $role = $currentUser['role'] ?? '';
    if ($role === 'owner') {
        if ((int) $shop['owner_user_id'] !== (int) $currentUser['id']) {
            http_response_code(403);
            $errors[] = 'You do not have access to this shop.';
            $shop = null;
        }
    } elseif ($role === 'hr') {
        if (!get_table_columns(db(), 'shop_staff')) {
            http_response_code(403);
            $errors[] = 'Staff access is not available.';
            $shop = null;
        } else {
            $staffStmt = db()->prepare(
                'SELECT id FROM shop_staff WHERE shop_id = :shop_id AND user_id = :user_id AND role = :role LIMIT 1'
            );
            $staffStmt->execute([
                'shop_id' => $shopId,
                'user_id' => $currentUser['id'],
                'role' => 'hr',
            ]);
            if (!$staffStmt->fetch()) {
                http_response_code(403);
                $errors[] = 'You do not have access to this shop.';
                $shop = null;
            }
        }
    }
}

if ($shop && get_table_columns(db(), 'reviews')) {
    try {
        $summaryStmt = db()->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
             FROM reviews
             WHERE shop_id = :shop_id'
        );
        $summaryStmt->execute(['shop_id' => $shopId]);
        $summaryRow = $summaryStmt->fetch();
        if ($summaryRow) {
            $reviewSummary = [
                'avg_rating' => (float) ($summaryRow['avg_rating'] ?? 0),
                'review_count' => (int) ($summaryRow['review_count'] ?? 0),
            ];
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load review summary.';
    }

    try {
        $reviewStmt = db()->prepare(
            'SELECT r.id, r.rating, r.comment, r.created_at, u.fullname AS client_name, p.name AS product_name
             FROM reviews r
             JOIN users u ON u.id = r.client_user_id
             LEFT JOIN products p ON p.id = r.product_id
             WHERE r.shop_id = :shop_id
             ORDER BY r.created_at DESC'
        );
        $reviewStmt->execute(['shop_id' => $shopId]);
        $reviews = $reviewStmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load reviews right now.';
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

require __DIR__ . '/../../includes/app_header.php';
?>
<h1 class="h4 mb-3">Shop Reviews</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="text-muted small">Review performance for your shop.</div>
                </div>
                <div class="text-end">
                    <div class="small text-muted">⭐ <?= number_format((float) ($reviewSummary['avg_rating'] ?? 0), 1) ?> (<?= (int) ($reviewSummary['review_count'] ?? 0) ?> reviews)</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($reviews): ?>
        <div class="vstack gap-3">
            <?php foreach ($reviews as $review): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong><?= htmlspecialchars($review['client_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="text-muted small"><?= htmlspecialchars($review['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="small text-muted mb-2">⭐ <?= (int) $review['rating'] ?>/5</div>
                        <?php if (!empty($review['product_name'])): ?>
                            <div class="small text-muted mb-2">Product: <?= htmlspecialchars($review['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
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
                                         class="rounded border" style="width: 88px; height: 88px; object-fit: cover;">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">No reviews have been submitted for this shop yet.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/owner/dashboard">Back to dashboard</a>
    </div>
<?php else: ?>
    <div class="mt-3">
        <a class="btn btn-outline-secondary" href="/owner/dashboard">Back to dashboard</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
