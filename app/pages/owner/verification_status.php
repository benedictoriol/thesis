<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['owner']);

$currentUser = current_user();
$pageTitle = 'Verification Status';
$errors = [];
$shop = null;
$documents = [];

try {
    $stmt = db()->prepare('SELECT id, name FROM shops WHERE owner_user_id = :owner_id LIMIT 1');
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

$statusStyles = [
    'draft' => 'secondary',
    'submitted' => 'info',
    'approved' => 'success',
    'rejected' => 'danger',
];
$statusClass = $statusStyles[$verification['status']] ?? 'secondary';

require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-2">Verification Status</h1>
<p class="text-muted">Track the review status and read remarks from the admin team.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-1"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <span class="badge text-bg-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?> text-capitalize">
                        <?= htmlspecialchars($verification['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="text-md-end">
                    <div class="text-muted small">Submitted</div>
                    <div><?= htmlspecialchars($verification['submitted_at'] ?? 'Not submitted yet', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small mt-2">Reviewed</div>
                    <div><?= htmlspecialchars($verification['reviewed_at'] ?? 'Pending review', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6 mb-2">Admin remarks</h3>
            <?php if (!empty($verification['admin_note'])): ?>
                <p class="mb-0"><?= nl2br(htmlspecialchars($verification['admin_note'], ENT_QUOTES, 'UTF-8')) ?></p>
            <?php else: ?>
                <p class="text-muted mb-0">No remarks have been posted yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6 mb-3">Submitted documents</h3>
            <?php if (!$documents): ?>
                <p class="text-muted mb-0">No documents uploaded yet.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($documents as $document): ?>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold text-capitalize">
                                    <?= htmlspecialchars(str_replace('_', ' ', $document['doc_type']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-muted small"><?= htmlspecialchars($document['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($document['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/owner/verification">Back to overview</a>
        <a class="btn btn-outline-primary" href="/owner/verification/upload">Upload more documents</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
