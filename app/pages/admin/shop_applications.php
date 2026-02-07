<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/admin_guard.php';

$currentUser = require_admin();
$pageTitle = 'Shop Applications';
$activeNav = 'applications';

$statusFilter = $_GET['status'] ?? '';
$verificationFilter = $_GET['verification'] ?? '';

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'shops.status = :status';
    $params['status'] = $statusFilter;
}
if ($verificationFilter !== '') {
    $where[] = 'shop_verification.status = :verification';
    $params['verification'] = $verificationFilter;
}

$sql = 'SELECT shops.id, shops.name, shops.status, shops.created_at,
               shop_verification.status AS verification_status,
               shop_verification.submitted_at
        FROM shops
        LEFT JOIN shop_verification ON shop_verification.shop_id = shops.id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY shops.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Shop Applications</h1>
        <p class="text-muted mb-0">Review submissions and manage onboarding.</p>
    </div>
</div>

<form class="row g-3 mb-4" method="get">
    <div class="col-md-4">
        <label class="form-label">Shop status</label>
        <select class="form-select" name="status">
            <option value="">All</option>
            <?php foreach (['pending', 'active', 'suspended', 'hidden'] as $statusOption): ?>
                <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Verification status</label>
        <select class="form-select" name="verification">
            <option value="">All</option>
            <?php foreach (['draft', 'submitted', 'approved', 'rejected'] as $verificationOption): ?>
                <option value="<?= htmlspecialchars($verificationOption, ENT_QUOTES, 'UTF-8') ?>" <?= $verificationFilter === $verificationOption ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($verificationOption), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">Filter</button>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Shop</th>
                <th>Shop status</th>
                <th>Verification</th>
                <th>Submitted</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($applications as $shop): ?>
                <tr>
                    <td><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars($shop['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars($shop['verification_status'] ?? 'draft', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($shop['submitted_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($shop['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="/admin/shops/<?= htmlspecialchars($shop['id'], ENT_QUOTES, 'UTF-8') ?>/review">Review</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$applications): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No applications match this filter.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>