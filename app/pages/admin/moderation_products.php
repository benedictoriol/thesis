<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../includes/admin_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$currentUser = require_admin();
$pageTitle = 'Product Moderation';
$activeNav = 'moderation_products';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $actionType = $_POST['action_type'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if ($entityId <= 0) {
            $errors[] = 'Product ID is required.';
        }

        if (!in_array($actionType, ['hide', 'unhide'], true)) {
            $errors[] = 'Select a valid action.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO moderation_actions (admin_user_id, entity, entity_id, action, reason, created_at)
                 VALUES (:admin_user_id, :entity, :entity_id, :action, :reason, :created_at)'
            );
            $stmt->execute([
                'admin_user_id' => $currentUser['id'],
                'entity' => 'product',
                'entity_id' => $entityId,
                'action' => $actionType,
                'reason' => $reason !== '' ? $reason : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            audit_log((int) $currentUser['id'], $actionType . '_product', 'product', $entityId, ['reason' => $reason]);
            flash_set('success', 'Moderation action recorded.');
            header('Location: /admin/moderation/products');
            exit;
        }
    }
}

$successMessage = flash_get('success');

$stmt = db()->prepare(
    'SELECT moderation_actions.*, users.fullname
     FROM moderation_actions
     JOIN users ON users.id = moderation_actions.admin_user_id
     WHERE moderation_actions.entity = :entity
     ORDER BY moderation_actions.created_at DESC
     LIMIT 10'
);
$stmt->execute(['entity' => 'product']);
$recentActions = $stmt->fetchAll();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Product Moderation</h1>
        <p class="text-muted mb-0">Hide or restore product listings.</p>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Record action</strong>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Product ID</label>
                        <input class="form-control" type="number" name="entity_id" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Action</label>
                        <select class="form-select" name="action_type" required>
                            <option value="hide">Hide product</option>
                            <option value="unhide">Unhide product</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason (optional)</label>
                        <textarea class="form-control" name="reason" rows="3"></textarea>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Save action</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Recent moderation activity</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Action</th>
                        <th>Admin</th>
                        <th>Timestamp</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentActions as $action): ?>
                        <tr>
                            <td>#<?= htmlspecialchars((string) $action['entity_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-capitalize"><?= htmlspecialchars($action['action'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($action['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($action['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentActions): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No product actions recorded.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>
