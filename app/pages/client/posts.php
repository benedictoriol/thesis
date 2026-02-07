<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['client', 'owner', 'hr', 'employee']);

$user = current_user();
$pageTitle = 'Client Posts';
$successMessage = flash_get('success');
$errors = [];
$posts = [];

try {
    $stmt = db()->prepare(
        "UPDATE client_posts
         SET status = 'expired'
         WHERE status = 'open'
         AND deadline_date IS NOT NULL
         AND deadline_date < :today"
    );
    $stmt->execute(['today' => gmdate('Y-m-d')]);
} catch (PDOException $exception) {
    $errors[] = 'Unable to update expired posts right now.';
}

try {
    if ($user['role'] === 'client') {
        $stmt = db()->prepare(
            'SELECT cp.*, (
                SELECT COUNT(*) FROM post_offers po WHERE po.post_id = cp.id
            ) AS offers_count
            FROM client_posts cp
            WHERE cp.client_user_id = :client_user_id
            ORDER BY cp.created_at DESC, cp.id DESC'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $posts = $stmt->fetchAll();
    } else {
        $stmt = db()->query(
            "SELECT cp.*, u.fullname AS client_name, (
                SELECT COUNT(*) FROM post_offers po WHERE po.post_id = cp.id
            ) AS offers_count
            FROM client_posts cp
            JOIN users u ON u.id = cp.client_user_id
            WHERE cp.status = 'open'
            ORDER BY cp.created_at DESC, cp.id DESC"
        );
        $posts = $stmt->fetchAll();
    }
} catch (PDOException $exception) {
    $errors[] = 'Unable to load posts right now.';
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Client posts</h1>
<p class="text-muted">Browse open requests or manage your active posts.</p>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($user['role'] === 'client'): ?>
    <div class="mb-3 text-end">
        <a class="btn btn-primary" href="/client/posts/create">Create post</a>
    </div>
<?php endif; ?>

<div class="list-group">
    <?php if (!$posts): ?>
        <div class="list-group-item text-muted">No posts available.</div>
    <?php endif; ?>
    <?php foreach ($posts as $post): ?>
        <?php
        $status = $post['status'] ?? 'open';
        $badgeClass = match ($status) {
            'open' => 'bg-success',
            'closed' => 'bg-secondary',
            'expired' => 'bg-warning text-dark',
            'converted' => 'bg-primary',
            default => 'bg-light text-dark',
        };
        ?>
        <a class="list-group-item list-group-item-action" href="/client/posts/<?= (int) $post['id'] ?>">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">
                        <?= htmlspecialchars(strtoupper($post['item_type']), ENT_QUOTES, 'UTF-8') ?> · Qty <?= (int) $post['quantity'] ?>
                        <?php if ($user['role'] !== 'client'): ?>
                            · Client: <?= htmlspecialchars($post['client_name'] ?? 'Client', ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge <?= $badgeClass ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="small text-muted mt-1">Offers: <?= (int) $post['offers_count'] ?></div>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>