<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client', 'owner', 'hr', 'employee']);

$user = current_user();
$designId = (int) ($_GET['design_id'] ?? 0);
$design = null;
$layers = [];
$errors = [];

if ($designId <= 0) {
    $errors[] = 'Invalid design selected.';
} else {
    $stmt = db()->prepare(
        'SELECT d.*, u.fullname AS owner_name
         FROM custom_designs d
         JOIN users u ON u.id = d.owner_user_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $designId]);
    $design = $stmt->fetch();

    if (!$design) {
        $errors[] = 'Design not found.';
    } elseif ($user['role'] === 'client' && (int) $design['owner_user_id'] !== (int) $user['id']) {
        $errors[] = 'You do not have access to this design.';
        $design = null;
    } else {
        $layerStmt = db()->prepare(
            'SELECT * FROM custom_design_layers WHERE design_id = :design_id ORDER BY id ASC'
        );
        $layerStmt->execute(['design_id' => $designId]);
        $layers = $layerStmt->fetchAll();
    }
}

$pageTitle = 'Design Details';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Design Details</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($design): ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($design['preview_path'])): ?>
                    <img src="<?= htmlspecialchars($design['preview_path'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?>"
                         class="rounded border" style="width: 120px; height: 120px; object-fit: cover;">
                <?php else: ?>
                    <div class="bg-secondary-subtle rounded" style="width: 120px; height: 120px;"></div>
                <?php endif; ?>
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="text-muted small">Item type: <?= strtoupper($design['item_type']) ?></div>
                    <div class="text-muted small">Owner: <?= htmlspecialchars($design['owner_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">Created: <?= htmlspecialchars($design['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h6">Layers</h2>
    <?php if (!$layers): ?>
        <div class="alert alert-info">No layers saved for this design.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Content</th>
                        <th>Position</th>
                        <th>Scale</th>
                        <th>Rotation</th>
                        <th>Thickness</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($layers as $layer): ?>
                        <tr>
                            <td><?= htmlspecialchars($layer['layer_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-truncate" style="max-width: 200px;">
                                <?= htmlspecialchars($layer['content'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= htmlspecialchars($layer['x'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($layer['y'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($layer['scale'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($layer['rotation'], ENT_QUOTES, 'UTF-8') ?>°</td>
                            <td>
                                <?= $layer['layer_type'] === 'text'
                                    ? htmlspecialchars((string) ($layer['font_weight'] ?? '600'), ENT_QUOTES, 'UTF-8')
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a class="btn btn-outline-secondary" href="/client/designs">Back to designs</a>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/footer.php';
?>
