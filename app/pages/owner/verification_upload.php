<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner']);

$currentUser = current_user();
$pageTitle = 'Upload Verification Documents';
$errors = [];
$shop = null;
$documents = [];

$documentTypes = [
    'business_permit' => 'Business permit',
    'dti_sec' => 'DTI/SEC registration',
    'valid_id' => 'Valid government ID',
    'location_proof' => 'Proof of location',
    'other' => 'Other supporting file',
];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$shop) {
        $errors[] = 'Shop data is unavailable.';
    } elseif ($verification['status'] === 'approved') {
        $errors[] = 'Approved shops cannot upload new documents.';
    } elseif ($verification['status'] === 'submitted') {
        $errors[] = 'Your verification is already submitted. Wait for review or contact support.';
    } else {
        $docType = $_POST['doc_type'] ?? '';
        if (!array_key_exists($docType, $documentTypes)) {
            $errors[] = 'Select a valid document type.';
        }

        $file = $_FILES['document'] ?? null;
        if (!$file || !isset($file['error']) || is_array($file['error'])) {
            $errors[] = 'Invalid document upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Document upload failed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Document exceeds the 5MB limit.';
        }

        if (!$errors) {
            $allowed = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ];
            $mimeType = mime_content_type($file['tmp_name']);
            if (!isset($allowed[$mimeType])) {
                $errors[] = 'Only PDF, JPG, or PNG files are allowed.';
            } else {
                $uploadDir = __DIR__ . '/../../../public/uploads/verification/' . $shop['id'];
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mimeType];
                $destination = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $errors[] = 'Unable to save the document.';
                } else {
                    $relativePath = '/uploads/verification/' . $shop['id'] . '/' . $filename;
                    $stmt = db()->prepare(
                        'INSERT INTO shop_documents (shop_id, doc_type, file_path, uploaded_at)
                         VALUES (:shop_id, :doc_type, :file_path, :uploaded_at)'
                    );
                    $stmt->execute([
                        'shop_id' => $shop['id'],
                        'doc_type' => $docType,
                        'file_path' => $relativePath,
                        'uploaded_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $docId = (int) db()->lastInsertId();
                    audit_log(
                        (int) $currentUser['id'],
                        'upload_document',
                        'shop_documents',
                        $docId,
                        [
                            'shop_id' => $shop['id'],
                            'doc_type' => $docType,
                            'file_path' => $relativePath,
                        ]
                    );

                    flash_set('success', 'Document uploaded successfully.');
                    header('Location: /owner/verification/upload');
                    exit;
                }
            }
        }
    }
}

$successMessage = flash_get('success');

require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-2">Upload Verification Documents</h1>
<p class="text-muted">Provide the required permits and IDs to proceed with shop verification.</p>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_document">
                <div>
                    <label class="form-label">Document type</label>
                    <select class="form-select" name="doc_type" required>
                        <option value="">Select type</option>
                        <?php foreach ($documentTypes as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Upload file (PDF, JPG, PNG)</label>
                    <input class="form-control" type="file" name="document" required>
                </div>
                <button class="btn btn-primary" type="submit" <?= $verification['status'] === 'approved' || $verification['status'] === 'submitted' ? 'disabled' : '' ?>>Upload document</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">Uploaded documents</h2>
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
        <a class="btn btn-outline-primary" href="/owner/verification/status">View status</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
