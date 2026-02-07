<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'My Quotations';
$errors = [];
$quotations = [];

try {
    $stmt = db()->prepare(
        'SELECT po.id, po.price, po.turnaround_days, po.status, po.created_at,
                cp.title AS post_title, s.name AS shop_name
         FROM post_offers po
         JOIN client_posts cp ON cp.id = po.post_id
         JOIN shops s ON s.id = po.shop_id
         WHERE cp.client_user_id = :client_user_id
         ORDER BY po.created_at DESC, po.id DESC'
    );
    $stmt->execute(['client_user_id' => $user['id']]);
    $quotations = $stmt->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load your quotations right now.';
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">My Quotations</h1>
<p class="text-muted">Review pricing offers submitted by shops.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="list-group">
    <?php if (!$quotations): ?>
        <div class="list-group-item text-muted">No quotations available.</div>
    <?php endif; ?>
    <?php foreach ($quotations as $quote): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">Quote #<?= (int) $quote['id'] ?> · <?= htmlspecialchars($quote['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">Post: <?= htmlspecialchars($quote['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($quote['turnaround_days'])): ?>
                        <div class="text-muted small">Turnaround: <?= (int) $quote['turnaround_days'] ?> days</div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">₱<?= number_format((float) $quote['price'], 2) ?></div>
                    <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="text-muted small mt-1">Sent <?= htmlspecialchars($quote['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
require __DIR__ . '/../../includes/footer.php';
?>
