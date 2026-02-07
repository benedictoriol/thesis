<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/admin_guard.php';

$currentUser = require_admin();
$pageTitle = 'Reports';
$activeNav = 'reports';

$shopTotals = db()->query('SELECT status, COUNT(*) AS total FROM shops GROUP BY status')->fetchAll();
$shopCount = (int) db()->query('SELECT COUNT(*) FROM shops')->fetchColumn();
$approvedShopCount = (int) db()->query('SELECT COUNT(*) FROM shop_verification WHERE status = "approved"')->fetchColumn();
$verificationTotals = db()->query('SELECT status, COUNT(*) AS total FROM shop_verification GROUP BY status')->fetchAll();
$moderationTotals = db()->query('SELECT entity, COUNT(*) AS total FROM moderation_actions GROUP BY entity')->fetchAll();
$usersByRole = db()->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll();
$platformUsage = [
    'Orders placed' => (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'Quote requests' => (int) db()->query('SELECT COUNT(*) FROM quote_requests')->fetchColumn(),
    'Payments logged' => (int) db()->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
    'Messages sent' => (int) db()->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
];
$recentAudits = db()->query(
    'SELECT audit_logs.*, users.fullname
     FROM audit_logs
     LEFT JOIN users ON users.id = audit_logs.actor_user_id
     ORDER BY audit_logs.created_at DESC
     LIMIT 8'
)->fetchAll();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Governance Reports</h1>
        <p class="text-muted mb-0">Snapshot of operational risk and admin activity.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Shops registered</h6>
                <div class="display-6 fw-semibold"><?= number_format($shopCount) ?></div>
                <p class="text-muted mb-0"><?= number_format($approvedShopCount) ?> approved shops</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Shop status breakdown</h6>
                <?php foreach ($shopTotals as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Verification outcomes</h6>
                <?php foreach ($verificationTotals as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Moderation activity</h6>
                <?php foreach ($moderationTotals as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['entity'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Users by role</h6>
                <?php foreach ($usersByRole as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-capitalize"><?= htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars((string) $row['total'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="text-uppercase text-muted">Platform usage counts</h6>
                <?php foreach ($platformUsage as $label => $value): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= number_format($value) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <strong>Recent audit activity</strong>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Action</th>
                <th>Entity</th>
                <th>Actor</th>
                <th>Timestamp</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentAudits as $audit): ?>
                <tr>
                    <td><?= htmlspecialchars($audit['action'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($audit['entity'], ENT_QUOTES, 'UTF-8') ?> #<?= htmlspecialchars((string) ($audit['entity_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($audit['fullname'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($audit['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentAudits): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">No audit activity logged.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>