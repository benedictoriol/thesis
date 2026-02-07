<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'My Orders';
$errors = [];
$orders = [];

try {
    $stmt = db()->prepare(
        'SELECT o.id, o.status, o.created_at, s.name AS shop_name, cp.title AS post_title
         FROM orders o
         JOIN shops s ON s.id = o.shop_id
         LEFT JOIN client_posts cp ON cp.id = o.post_id
         WHERE o.client_user_id = :client_user_id
         ORDER BY o.created_at DESC, o.id DESC'
    );
    $stmt->execute(['client_user_id' => $user['id']]);
    $orders = $stmt->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load your orders right now.';
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">My Orders</h1>
<p class="text-muted">Monitor the status of your orders and past requests.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="list-group">
    <?php if (!$orders): ?>
        <div class="list-group-item text-muted">No orders found.</div>
    <?php endif; ?>
    <?php foreach ($orders as $order): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">Order #<?= (int) $order['id'] ?></div>
                    <div class="text-muted small">Shop: <?= htmlspecialchars($order['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($order['post_title'])): ?>
                        <div class="text-muted small">Post: <?= htmlspecialchars($order['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="text-muted small mt-1">Placed <?= htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
require __DIR__ . '/../../includes/footer.php';
?>