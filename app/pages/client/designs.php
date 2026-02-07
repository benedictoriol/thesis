<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client', 'owner', 'hr', 'employee']);

$user = current_user();
$pageTitle = 'Saved Designs';

$designs = [];
$query = 'SELECT d.*, u.fullname AS owner_name
          FROM custom_designs d
          JOIN users u ON u.id = d.owner_user_id';
$params = [];

if ($user['role'] === 'client') {
    $query .= ' WHERE d.owner_user_id = :owner_id';
    $params['owner_id'] = $user['id'];
}

$query .= ' ORDER BY d.created_at DESC';
$stmt = db()->prepare($query);
$stmt->execute($params);
$designs = $stmt->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Saved Designs</h1>
<p class="text-muted small">Browse saved customization drafts and previews.</p>

<?php if (!$designs): ?>
    <div class="alert alert-info">No saved designs yet.</div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($designs as $design): ?>
            <a class="list-group-item list-group-item-action" href="/client/designs/<?= (int) $design['id'] ?>">
                <div class="d-flex gap-3 align-items-center">
                    <?php if (!empty($design['preview_path'])): ?>
                        <img src="<?= htmlspecialchars($design['preview_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?>"
                             class="rounded border" style="width: 64px; height: 64px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-secondary-subtle rounded" style="width: 64px; height: 64px;"></div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small">
                            <?= strtoupper($design['item_type']) ?> · Created <?= htmlspecialchars($design['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php if ($user['role'] !== 'client'): ?>
                            <div class="text-muted small">Owner: <?= htmlspecialchars($design['owner_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/footer.php';
?>
