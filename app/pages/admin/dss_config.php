<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../includes/admin_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$currentUser = require_admin();
$pageTitle = 'DSS Configuration';
$activeNav = 'dss_config';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_weight') {
            $weightId = (int) ($_POST['weight_id'] ?? 0);
            $weightValue = (float) ($_POST['weight'] ?? 0);

            if ($weightId <= 0) {
                $errors[] = 'Invalid weight selection.';
            } else {
                $stmt = db()->prepare('UPDATE dss_global_weights SET weight = :weight WHERE id = :id');
                $stmt->execute(['weight' => $weightValue, 'id' => $weightId]);
                audit_log((int) $currentUser['id'], 'update_dss_weight', 'dss_global_weights', $weightId, ['weight' => $weightValue]);
                flash_set('success', 'DSS weight updated.');
                header('Location: /admin/dss/config');
                exit;
            }
        }

        if ($action === 'add_weight') {
            $criterion = trim($_POST['criterion'] ?? '');
            $weightValue = (float) ($_POST['weight'] ?? 0);

            if ($criterion === '') {
                $errors[] = 'Criterion is required.';
            } else {
                $stmt = db()->prepare('INSERT INTO dss_global_weights (criterion, weight) VALUES (:criterion, :weight)');
                $stmt->execute(['criterion' => $criterion, 'weight' => $weightValue]);
                audit_log((int) $currentUser['id'], 'add_dss_weight', 'dss_global_weights', (int) db()->lastInsertId(), ['criterion' => $criterion]);
                flash_set('success', 'DSS weight added.');
                header('Location: /admin/dss/config');
                exit;
            }
        }

        if ($action === 'update_setting') {
            $key = trim($_POST['setting_key'] ?? '');
            $value = trim($_POST['setting_value'] ?? '');

            if ($key === '') {
                $errors[] = 'Setting key is required.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO system_settings (`key`, value) VALUES (:key, :value)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
                $stmt->execute(['key' => $key, 'value' => $value]);
                audit_log((int) $currentUser['id'], 'update_system_setting', 'system_settings', null, ['key' => $key]);
                flash_set('success', 'System setting saved.');
                header('Location: /admin/dss/config');
                exit;
            }
        }
        
        if ($action === 'update_thresholds') {
            $minScore = trim($_POST['threshold_min_score'] ?? '');
            $maxScore = trim($_POST['threshold_max_score'] ?? '');

            if ($minScore === '' || $maxScore === '') {
                $errors[] = 'Both threshold values are required.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO system_settings (`key`, value) VALUES (:key, :value)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
                $stmt->execute(['key' => 'dss_threshold_min_score', 'value' => $minScore]);
                $stmt->execute(['key' => 'dss_threshold_max_score', 'value' => $maxScore]);
                audit_log((int) $currentUser['id'], 'update_dss_thresholds', 'system_settings', null, [
                    'dss_threshold_min_score' => $minScore,
                    'dss_threshold_max_score' => $maxScore,
                ]);
                flash_set('success', 'DSS thresholds updated.');
                header('Location: /admin/dss/config');
                exit;
            }
        }

        if ($action === 'run_recalculation') {
            $stmt = db()->prepare(
                'INSERT INTO dss_recalculation_jobs (triggered_by, status, created_at)
                 VALUES (:triggered_by, :status, :created_at)'
            );
            $stmt->execute([
                'triggered_by' => (int) $currentUser['id'],
                'status' => 'queued',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $jobId = (int) db()->lastInsertId();
            audit_log((int) $currentUser['id'], 'queue_dss_recalculation', 'dss_recalculation_jobs', $jobId, []);
            flash_set('success', 'DSS recalculation queued.');
            header('Location: /admin/dss/config');
            exit;
        }
    }
}

$successMessage = flash_get('success');

$weights = db()->query('SELECT * FROM dss_global_weights ORDER BY criterion')->fetchAll();
$settings = db()->query('SELECT * FROM system_settings ORDER BY `key`')->fetchAll();
$thresholds = [
    'dss_threshold_min_score' => null,
    'dss_threshold_max_score' => null,
];
foreach ($settings as $setting) {
    if (array_key_exists($setting['key'], $thresholds)) {
        $thresholds[$setting['key']] = $setting['value'];
    }
}
$jobs = db()->query('SELECT * FROM dss_recalculation_jobs ORDER BY created_at DESC LIMIT 5')->fetchAll();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">DSS + Global Configuration</h1>
        <p class="text-muted mb-0">Adjust weights and system settings that affect ranking.</p>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>Global DSS weights</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Criterion</th>
                        <th>Weight</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($weights as $weight): ?>
                        <tr>
                            <td><?= htmlspecialchars($weight['criterion'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($weight['weight'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form class="d-flex gap-2" method="post">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_weight">
                                    <input type="hidden" name="weight_id" value="<?= htmlspecialchars((string) $weight['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input class="form-control form-control-sm" type="number" step="0.01" name="weight" value="<?= htmlspecialchars($weight['weight'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$weights): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No weights configured yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Add new criterion</strong>
            </div>
            <div class="card-body">
                <form class="row g-3" method="post">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="add_weight">
                    <div class="col-md-6">
                        <label class="form-label">Criterion name</label>
                        <input class="form-control" name="criterion" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Weight</label>
                        <input class="form-control" type="number" step="0.01" name="weight" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>System settings</strong>
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="update_setting">
                    <div class="mb-3">
                        <label class="form-label">Setting key</label>
                        <input class="form-control" name="setting_key" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="setting_value" rows="3"></textarea>
                    </div>
                    <button class="btn btn-outline-primary w-100" type="submit">Save setting</button>
                </form>
                <div class="small text-muted">Use this panel for global toggles (e.g., feature flags, service thresholds).</div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Current settings</strong>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($settings as $setting): ?>
                    <div class="list-group-item">
                        <strong><?= htmlspecialchars($setting['key'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="small text-muted"><?= htmlspecialchars($setting['value'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$settings): ?>
                    <div class="list-group-item text-muted">No system settings recorded.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card shadow-sm mt-3">
            <div class="card-header bg-white">
                <strong>DSS thresholds</strong>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="update_thresholds">
                    <div class="col-6">
                        <label class="form-label">Minimum score</label>
                        <input class="form-control" type="number" step="0.01" name="threshold_min_score" value="<?= htmlspecialchars((string) ($thresholds['dss_threshold_min_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Maximum score</label>
                        <input class="form-control" type="number" step="0.01" name="threshold_max_score" value="<?= htmlspecialchars((string) ($thresholds['dss_threshold_max_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary w-100" type="submit">Save thresholds</button>
                    </div>
                </form>
                <div class="small text-muted mt-2">Thresholds are used to bound DSS scoring and alerts.</div>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-header bg-white">
                <strong>DSS recalculation</strong>
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="run_recalculation">
                    <button class="btn btn-warning w-100" type="submit">Rerun DSS recalculation</button>
                </form>
                <div class="small text-muted mb-3">Queues a backend job to recompute DSS scores.</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($jobs as $job): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <strong>#<?= htmlspecialchars((string) $job['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="badge bg-secondary"><?= htmlspecialchars($job['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="small text-muted">Queued at <?= htmlspecialchars($job['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$jobs): ?>
                        <div class="list-group-item text-muted">No recalculation jobs queued yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>