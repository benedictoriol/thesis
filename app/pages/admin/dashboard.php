<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/admin_guard.php';

$currentUser = require_admin();
$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';

$successMessage = flash_get('success');
$errorMessage = flash_get('error');

$shopCounts = db()->query('SELECT status, COUNT(*) AS total FROM shops GROUP BY status')->fetchAll();
$verificationCounts = db()->query('SELECT status, COUNT(*) AS total FROM shop_verification GROUP BY status')->fetchAll();
$userCounts = db()->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll();

$recentApplications = db()->query(
    'SELECT shops.id, shops.name, shops.status, shops.created_at,
            shop_verification.status AS verification_status
     FROM shops
     LEFT JOIN shop_verification ON shop_verification.shop_id = shops.id
     ORDER BY shops.created_at DESC
     LIMIT 5'
)->fetchAll();

$recentModeration = db()->query(
    'SELECT moderation_actions.*, users.fullname
     FROM moderation_actions
     JOIN users ON users.id = moderation_actions.admin_user_id
     ORDER BY moderation_actions.created_at DESC
     LIMIT 5'
)->fetchAll();

$pendingVerifications = db()->query(
    "SELECT COUNT(*) AS total FROM shop_verification WHERE status = 'submitted'"
)->fetchColumn();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">SYS_ADMIN Governance Dashboard</h1>
        <p class="text-muted mb-0">Moderation, configuration, and compliance overview.</p>
    </div>
    <div class="text-end">
        <span class="badge bg-info text-dark">Pending verifications: <?= htmlspecialchars((string) $pendingVerifications, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Shops by status</h6>
                <?php foreach ($shopCounts as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Verification pipeline</h6>
                <?php foreach ($verificationCounts as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Users by role</h6>
                <?php foreach ($userCounts as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $row['role']), ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Latest Shop Applications</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Shop</th>
                        <th>Status</th>
                        <th>Verification</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentApplications as $shop): ?>
                        <tr>
                            <td><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-capitalize"><?= htmlspecialchars($shop['status'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-capitalize"><?= htmlspecialchars($shop['verification_status'] ?? 'draft', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($shop['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a class="btn btn-sm btn-outline-primary" href="/admin/shops/<?= htmlspecialchars($shop['id'], ENT_QUOTES, 'UTF-8') ?>/review">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Recent Moderation Actions</strong>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentModeration as $action): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong><?= htmlspecialchars($action['entity'], ENT_QUOTES, 'UTF-8') ?> #<?= htmlspecialchars((string) $action['entity_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="badge bg-secondary"><?= htmlspecialchars($action['action'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="small text-muted">
                            <?= htmlspecialchars($action['fullname'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars($action['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php if (!empty($action['reason'])): ?>
                            <div class="small">Reason: <?= htmlspecialchars($action['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentModeration): ?>
                    <div class="list-group-item text-muted">No moderation activity recorded yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-warning mt-4">
    Reminder: shops remain hidden from clients until verification is approved.
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>