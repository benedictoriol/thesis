<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Audit Logs';
$errors = [];

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');

$where = ['JSON_EXTRACT(audit_logs.meta_json, "$.shop_id") = :shop_id'];
$params = ['shop_id' => $shop['id'] ?? 0];

if ($actionFilter !== '') {
    $where[] = 'audit_logs.action = :action';
    $params['action'] = $actionFilter;
}

if ($entityFilter !== '') {
    $where[] = 'audit_logs.entity = :entity';
    $params['entity'] = $entityFilter;
}

$logs = [];
if (!$errors) {
    $sql = 'SELECT audit_logs.*, users.fullname
            FROM audit_logs
            LEFT JOIN users ON users.id = audit_logs.actor_user_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY audit_logs.created_at DESC LIMIT 50';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Audit Logs</h1>
        <p class="text-muted mb-0">Review activity for <?= htmlspecialchars($shop['name'] ?? 'your shop', ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <form class="row g-3 mb-4" method="get">
        <div class="col-md-4">
            <label class="form-label">Action</label>
            <input class="form-control" name="action" value="<?= htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. payment_verified">
        </div>
        <div class="col-md-4">
            <label class="form-label">Entity</label>
            <input class="form-control" name="entity" value="<?= htmlspecialchars($entityFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. orders">
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Filter logs</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Actor</th>
                    <th>Metadata</th>
                    <th>Timestamp</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($log['entity'], ENT_QUOTES, 'UTF-8') ?> #<?= htmlspecialchars((string) ($log['entity_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($log['fullname'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($log['meta_json'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No audit logs found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
