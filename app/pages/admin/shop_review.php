<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../includes/admin_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$currentUser = require_admin();
$pageTitle = 'Shop Review';
$activeNav = 'applications';

$shopId = (int) ($_GET['shop_id'] ?? 0);
if ($shopId <= 0) {
    http_response_code(404);
    echo 'Shop not found.';
    exit;
}

$stmt = db()->prepare(
    'SELECT shops.*, users.fullname AS owner_name, users.email AS owner_email
     FROM shops
     JOIN users ON users.id = shops.owner_user_id
     WHERE shops.id = :shop_id
     LIMIT 1'
);
$stmt->execute(['shop_id' => $shopId]);
$shop = $stmt->fetch();

if (!$shop) {
    http_response_code(404);
    echo 'Shop not found.';
    exit;
}

$stmt = db()->prepare('SELECT * FROM shop_verification WHERE shop_id = :shop_id LIMIT 1');
$stmt->execute(['shop_id' => $shopId]);
$verification = $stmt->fetch();
$verification = $verification ?: [
    'status' => 'draft',
    'submitted_at' => null,
    'reviewed_at' => null,
    'reviewed_by_user_id' => null,
    'admin_note' => null,
];

$stmt = db()->prepare('SELECT * FROM shop_documents WHERE shop_id = :shop_id ORDER BY uploaded_at DESC');
$stmt->execute(['shop_id' => $shopId]);
$documents = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $note = trim($_POST['admin_note'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        if ($action === 'approve_verification') {
            $stmt = db()->prepare(
                'INSERT INTO shop_verification (shop_id, status, submitted_at, reviewed_at, reviewed_by_user_id, admin_note)
                 VALUES (:shop_id, :status, :submitted_at, :reviewed_at, :reviewed_by_user_id, :admin_note)
                 ON DUPLICATE KEY UPDATE status = VALUES(status),
                     reviewed_at = VALUES(reviewed_at),
                     reviewed_by_user_id = VALUES(reviewed_by_user_id),
                     admin_note = VALUES(admin_note)'
            );
            $stmt->execute([
                'shop_id' => $shopId,
                'status' => 'approved',
                'submitted_at' => $verification['submitted_at'] ?? $now,
                'reviewed_at' => $now,
                'reviewed_by_user_id' => $currentUser['id'],
                'admin_note' => $note !== '' ? $note : null,
            ]);

            $stmt = db()->prepare('UPDATE shops SET status = :status WHERE id = :id');
            $stmt->execute(['status' => 'active', 'id' => $shopId]);

            audit_log((int) $currentUser['id'], 'approve_verification', 'shop_verification', $shopId, ['note' => $note]);
            flash_set('success', 'Shop verification approved and activated.');
            header('Location: /admin/shops/' . $shopId . '/review');
            exit;
        }

        if ($action === 'reject_verification') {
            $stmt = db()->prepare(
                'INSERT INTO shop_verification (shop_id, status, submitted_at, reviewed_at, reviewed_by_user_id, admin_note)
                 VALUES (:shop_id, :status, :submitted_at, :reviewed_at, :reviewed_by_user_id, :admin_note)
                 ON DUPLICATE KEY UPDATE status = VALUES(status),
                     reviewed_at = VALUES(reviewed_at),
                     reviewed_by_user_id = VALUES(reviewed_by_user_id),
                     admin_note = VALUES(admin_note)'
            );
            $stmt->execute([
                'shop_id' => $shopId,
                'status' => 'rejected',
                'submitted_at' => $verification['submitted_at'] ?? $now,
                'reviewed_at' => $now,
                'reviewed_by_user_id' => $currentUser['id'],
                'admin_note' => $note !== '' ? $note : null,
            ]);

            $stmt = db()->prepare('UPDATE shops SET status = :status WHERE id = :id');
            $stmt->execute(['status' => 'pending', 'id' => $shopId]);

            audit_log((int) $currentUser['id'], 'reject_verification', 'shop_verification', $shopId, ['note' => $note]);
            flash_set('success', 'Shop verification rejected.');
            header('Location: /admin/shops/' . $shopId . '/review');
            exit;
        }

        if ($action === 'activate_shop') {
            if ($verification['status'] !== 'approved') {
                $errors[] = 'Verification must be approved before activating the shop.';
            } else {
                $stmt = db()->prepare('UPDATE shops SET status = :status WHERE id = :id');
                $stmt->execute(['status' => 'active', 'id' => $shopId]);

                audit_log((int) $currentUser['id'], 'activate_shop', 'shops', $shopId, []);
                flash_set('success', 'Shop activated.');
                header('Location: /admin/shops/' . $shopId . '/review');
                exit;
            }
        }

        if ($action === 'suspend_shop') {
            $stmt = db()->prepare('UPDATE shops SET status = :status WHERE id = :id');
            $stmt->execute(['status' => 'suspended', 'id' => $shopId]);

            audit_log((int) $currentUser['id'], 'suspend_shop', 'shops', $shopId, []);
            flash_set('success', 'Shop suspended.');
            header('Location: /admin/shops/' . $shopId . '/review');
            exit;
        }
    }
}

$successMessage = flash_get('success');

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Review Shop Application</h1>
        <p class="text-muted mb-0">Validate documents and approve verification.</p>
    </div>
    <a class="btn btn-outline-secondary" href="/admin/shops/applications">Back to applications</a>
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
            <div class="card-body">
                <h5 class="card-title"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></h5>
                <p class="text-muted mb-2">Owner: <?= htmlspecialchars($shop['owner_name'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars($shop['owner_email'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><?= htmlspecialchars($shop['description'] ?? 'No description provided.', ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($shop['address_text'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mb-1"><strong>Status:</strong> <?= htmlspecialchars($shop['status'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mb-0"><strong>Verification:</strong> <?= htmlspecialchars($verification['status'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Submitted Documents</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>File path</th>
                        <th>Uploaded</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $doc['doc_type']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($doc['file_path'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($doc['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$documents): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No documents uploaded yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>Verification Decision</strong>
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="approve_verification">
                    <div class="mb-3">
                        <label class="form-label">Admin note (optional)</label>
                        <textarea class="form-control" name="admin_note" rows="3"></textarea>
                    </div>
                    <button class="btn btn-success w-100" type="submit">Approve Verification</button>
                </form>

                <form method="post">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="reject_verification">
                    <div class="mb-3">
                        <label class="form-label">Rejection note</label>
                        <textarea class="form-control" name="admin_note" rows="3" required></textarea>
                    </div>
                    <button class="btn btn-outline-danger w-100" type="submit">Reject Verification</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Shop Status</strong>
            </div>
            <div class="card-body">
                <form method="post" class="mb-2">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="activate_shop">
                    <button class="btn btn-primary w-100" type="submit">Activate Shop</button>
                </form>
                <form method="post">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="suspend_shop">
                    <button class="btn btn-outline-secondary w-100" type="submit">Suspend Shop</button>
                </form>
                <p class="small text-muted mt-3 mb-0">Shops remain hidden until verification is approved.</p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>