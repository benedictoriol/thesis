<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/admin_guard.php';

$currentUser = require_admin();
$pageTitle = 'Audit Logs';
$activeNav = 'audit_logs';

$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');

$where = [];
$params = [];

if ($actionFilter !== '') {
    $where[] = 'audit_logs.action = :action';
    $params['action'] = $actionFilter;
}

if ($entityFilter !== '') {
    $where[] = 'audit_logs.entity = :entity';
    $params['entity'] = $entityFilter;
}

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

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Audit Logs</h1>
        <p class="text-muted mb-0">Track every administrative action and system event.</p>
    </div>
</div>

<form class="row g-3 mb-4" method="get">
    <div class="col-md-4">
        <label class="form-label">Action</label>
        <input class="form-control" name="action" value="<?= htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. approve_verification">
    </div>
    <div class="col-md-4">
        <label class="form-label">Entity</label>
        <input class="form-control" name="entity" value="<?= htmlspecialchars($entityFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. shops">
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

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>