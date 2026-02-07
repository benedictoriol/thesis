<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner']);

$currentUser = current_user();
$pageTitle = 'Shop Verification';
$errors = [];
$shop = null;
$documents = [];

try {
    $stmt = db()->prepare('SELECT id, name, status FROM shops WHERE owner_user_id = :owner_id LIMIT 1');
    $stmt->execute(['owner_id' => $currentUser['id']]);
    $shop = $stmt->fetch();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load shop details right now.';
}

if (!$shop) {
    $errors[] = 'No shop is linked to this owner account yet.';
}

$verification = [
    'status' => 'draft',
    'submitted_at' => null,
    'reviewed_at' => null,
    'reviewed_by_user_id' => null,
    'admin_note' => null,
];

if ($shop) {
    $stmt = db()->prepare('SELECT * FROM shop_verification WHERE shop_id = :shop_id LIMIT 1');
    $stmt->execute(['shop_id' => $shop['id']]);
    $record = $stmt->fetch();
    if ($record) {
        $verification = $record;
    }

    $stmt = db()->prepare('SELECT * FROM shop_documents WHERE shop_id = :shop_id ORDER BY uploaded_at DESC');
    $stmt->execute(['shop_id' => $shop['id']]);
    $documents = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_for_review') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$shop) {
        $errors[] = 'Shop data is unavailable.';
    } elseif ($verification['status'] === 'approved') {
        $errors[] = 'Your shop has already been approved.';
    } elseif (!$documents) {
        $errors[] = 'Upload at least one document before submitting.';
    } else {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = db()->prepare(
            'INSERT INTO shop_verification (shop_id, status, submitted_at, reviewed_at, reviewed_by_user_id, admin_note)
             VALUES (:shop_id, :status, :submitted_at, :reviewed_at, :reviewed_by_user_id, :admin_note)
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                 submitted_at = VALUES(submitted_at),
                 reviewed_at = VALUES(reviewed_at),
                 reviewed_by_user_id = VALUES(reviewed_by_user_id),
                 admin_note = VALUES(admin_note)'
        );
        $stmt->execute([
            'shop_id' => $shop['id'],
            'status' => 'submitted',
            'submitted_at' => $now,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'admin_note' => null,
        ]);

        flash_set('success', 'Your verification package has been submitted for review.');
        header('Location: /owner/verification/status');
        exit;
    }
}

$statusStyles = [
    'draft' => 'secondary',
    'submitted' => 'info',
    'approved' => 'success',
    'rejected' => 'danger',
];
$statusClass = $statusStyles[$verification['status']] ?? 'secondary';
$successMessage = flash_get('success');

require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-2">Shop Verification</h1>
<p class="text-muted">Upload your permits and submit your shop for approval.</p>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-1"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="text-muted small">Current status</div>
                    <span class="badge text-bg-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?> text-capitalize">
                        <?= htmlspecialchars($verification['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="text-md-end">
                    <div class="text-muted small">Documents uploaded</div>
                    <div class="h5 mb-0"><?= count($documents) ?></div>
                    <a class="btn btn-link px-0" href="/owner/verification/status">View review status</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Step 1: Upload documents</h3>
                    <p class="text-muted small">Add business permits, IDs, and other proofs required for approval.</p>
                    <a class="btn btn-outline-primary" href="/owner/verification/upload">Upload documents</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Step 2: Submit for review</h3>
                    <p class="text-muted small">Send your application once all documents are ready.</p>
                    <form method="post" class="d-flex flex-column gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit_for_review">
                        <button class="btn btn-primary" type="submit" <?= ($verification['status'] === 'submitted' || $verification['status'] === 'approved') ? 'disabled' : '' ?>>
                            <?= $verification['status'] === 'submitted' ? 'Submitted' : 'Submit for review' ?>
                        </button>
                        <?php if ($verification['status'] === 'rejected' && !empty($verification['admin_note'])): ?>
                            <small class="text-muted">Admin note: <?= htmlspecialchars($verification['admin_note'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h3 class="h6 mb-3">Uploaded documents</h3>
            <?php if (!$documents): ?>
                <p class="text-muted mb-0">No documents have been uploaded yet.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($documents as $document): ?>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold text-capitalize">
                                    <?= htmlspecialchars(str_replace('_', ' ', $document['doc_type']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-muted small">Uploaded <?= htmlspecialchars($document['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($document['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>