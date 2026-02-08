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
    'shop_name' => '',
    'pickup_address' => '',
    'shop_email' => '',
    'shop_phone' => '',
    'individual_name' => '',
    'business_name' => '',
    'business_address' => '',
    'primary_document_type' => '',
    'government_id_type' => '',
    'business_email' => '',
    'business_phone' => '',
    'tax_identification_number' => '',
    'vat_registration' => '',
    'bir_certification' => '',
    'sworn_declaration' => 0,
    'terms_accepted' => 0,
    'updated_at' => null,
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
        $verification = array_merge($verification, $record);
    }

    $stmt = db()->prepare('SELECT * FROM shop_documents WHERE shop_id = :shop_id ORDER BY uploaded_at DESC');
    $stmt->execute(['shop_id' => $shop['id']]);
    $documents = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_application') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$shop) {
        $errors[] = 'Shop data is unavailable.';
    } elseif ($verification['status'] === 'approved') {
        $errors[] = 'Approved shops cannot edit the application.';
    } elseif ($verification['status'] === 'submitted') {
        $errors[] = 'Your application is already submitted for review.';
    } else {
        $primaryDocumentTypes = [
            'business_permit' => 'Business permit',
            'dti_sec' => 'DTI/SEC registration',
            'barangay_clearance' => 'Barangay clearance',
            'other' => 'Other supporting document',
        ];
        $governmentIdTypes = [
            'philsys' => 'PhilSys National ID',
            'drivers_license' => "Driver's license",
            'passport' => 'Passport',
            'sss' => 'SSS ID',
            'philhealth' => 'PhilHealth ID',
            'postal' => 'Postal ID',
            'voters' => "Voter's ID",
            'other' => 'Other government ID',
        ];
        $vatOptions = ['vat_registered', 'non_vat_registered'];

        $application = [
            'shop_name' => trim((string) ($_POST['shop_name'] ?? '')),
            'pickup_address' => trim((string) ($_POST['pickup_address'] ?? '')),
            'shop_email' => trim((string) ($_POST['shop_email'] ?? '')),
            'shop_phone' => trim((string) ($_POST['shop_phone'] ?? '')),
            'individual_name' => trim((string) ($_POST['individual_name'] ?? '')),
            'business_name' => trim((string) ($_POST['business_name'] ?? '')),
            'business_address' => trim((string) ($_POST['business_address'] ?? '')),
            'primary_document_type' => trim((string) ($_POST['primary_document_type'] ?? '')),
            'government_id_type' => trim((string) ($_POST['government_id_type'] ?? '')),
            'business_email' => trim((string) ($_POST['business_email'] ?? '')),
            'business_phone' => trim((string) ($_POST['business_phone'] ?? '')),
            'tax_identification_number' => trim((string) ($_POST['tax_identification_number'] ?? '')),
            'vat_registration' => trim((string) ($_POST['vat_registration'] ?? '')),
            'bir_certification' => trim((string) ($_POST['bir_certification'] ?? '')),
            'sworn_declaration' => ($_POST['sworn_declaration'] ?? '') === 'yes' ? 1 : 0,
            'terms_accepted' => isset($_POST['terms_accepted']) ? 1 : 0,
        ];

        if ($application['primary_document_type'] !== '' && !isset($primaryDocumentTypes[$application['primary_document_type']])) {
            $errors[] = 'Select a valid primary document type.';
        }
        if ($application['government_id_type'] !== '' && !isset($governmentIdTypes[$application['government_id_type']])) {
            $errors[] = 'Select a valid government ID type.';
        }
        if ($application['vat_registration'] !== '' && !in_array($application['vat_registration'], $vatOptions, true)) {
            $errors[] = 'Select a valid VAT registration option.';
        }

        if (!$errors) {
            $now = gmdate('Y-m-d H:i:s');
            $stmt = db()->prepare(
                'INSERT INTO shop_verification (
                        shop_id, status, shop_name, pickup_address, shop_email, shop_phone, individual_name,
                        business_name, business_address, primary_document_type, government_id_type, business_email,
                        business_phone, tax_identification_number, vat_registration, bir_certification,
                        sworn_declaration, terms_accepted, updated_at
                 )
                 VALUES (
                        :shop_id, :status, :shop_name, :pickup_address, :shop_email, :shop_phone, :individual_name,
                        :business_name, :business_address, :primary_document_type, :government_id_type, :business_email,
                        :business_phone, :tax_identification_number, :vat_registration, :bir_certification,
                        :sworn_declaration, :terms_accepted, :updated_at
                 )
                 ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        shop_name = VALUES(shop_name),
                        pickup_address = VALUES(pickup_address),
                        shop_email = VALUES(shop_email),
                        shop_phone = VALUES(shop_phone),
                        individual_name = VALUES(individual_name),
                        business_name = VALUES(business_name),
                        business_address = VALUES(business_address),
                        primary_document_type = VALUES(primary_document_type),
                        government_id_type = VALUES(government_id_type),
                        business_email = VALUES(business_email),
                        business_phone = VALUES(business_phone),
                        tax_identification_number = VALUES(tax_identification_number),
                        vat_registration = VALUES(vat_registration),
                        bir_certification = VALUES(bir_certification),
                        sworn_declaration = VALUES(sworn_declaration),
                        terms_accepted = VALUES(terms_accepted),
                        updated_at = VALUES(updated_at)'
            );
            $stmt->execute(array_merge(
                [
                    'shop_id' => $shop['id'],
                    'status' => $verification['status'] ?: 'draft',
                    'updated_at' => $now,
                ],
                $application
            ));

            flash_set('success', 'Application details saved.');
            header('Location: /owner/verification');
            exit;
        }

        $verification = array_merge($verification, $application);
    }
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
    } elseif ($verification['status'] === 'submitted') {
        $errors[] = 'Your verification is already submitted.';
    } else {
        $requiredFields = [
            'shop_name' => 'Shop name',
            'pickup_address' => 'Pickup address',
            'shop_email' => 'Email',
            'shop_phone' => 'Phone number',
            'individual_name' => 'Individual registered name',
            'business_name' => 'Business name/trade name',
            'business_address' => 'Business address',
            'primary_document_type' => 'Primary business document type',
            'government_id_type' => 'Government ID type',
            'business_email' => 'Business email',
            'business_phone' => 'Business phone number',
            'tax_identification_number' => 'Tax identification number',
            'vat_registration' => 'VAT registration',
            'bir_certification' => 'BIR certification of registration',
        ];
        foreach ($requiredFields as $field => $label) {
            if (empty($verification[$field])) {
                $errors[] = $label . ' is required before submitting.';
            }
        }
        if (!$verification['sworn_declaration']) {
            $errors[] = 'Confirm the sworn declaration before submitting.';
        }
        if (!$verification['terms_accepted']) {
            $errors[] = 'Accept the terms and data privacy policy before submitting.';
        }

        if ($errors) {
            $errors[] = 'Complete the application details before submitting for review.';
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
}

$statusStyles = [
    'draft' => 'secondary',
    'submitted' => 'info',
    'approved' => 'success',
    'rejected' => 'danger',
];
$statusClass = $statusStyles[$verification['status']] ?? 'secondary';
$successMessage = flash_get('success');
$primaryDocumentTypes = [
    'business_permit' => 'Business permit',
    'dti_sec' => 'DTI/SEC registration',
    'barangay_clearance' => 'Barangay clearance',
    'other' => 'Other supporting document',
];
$governmentIdTypes = [
    'philsys' => 'PhilSys National ID',
    'drivers_license' => "Driver's license",
    'passport' => 'Passport',
    'sss' => 'SSS ID',
    'philhealth' => 'PhilHealth ID',
    'postal' => 'Postal ID',
    'voters' => "Voter's ID",
    'other' => 'Other government ID',
];

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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">Shop application details</h2>
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_application">
                <div class="col-md-6">
                    <label class="form-label" for="shop_name">Shop name</label>
                    <input class="form-control" id="shop_name" name="shop_name" value="<?= htmlspecialchars($verification['shop_name'] ?: $shop['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="pickup_address">Pick up address</label>
                    <input class="form-control" id="pickup_address" name="pickup_address" value="<?= htmlspecialchars($verification['pickup_address'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="shop_email">Email</label>
                    <input class="form-control" id="shop_email" name="shop_email" type="email" value="<?= htmlspecialchars($verification['shop_email'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="shop_phone">Phone number</label>
                    <input class="form-control" id="shop_phone" name="shop_phone" value="<?= htmlspecialchars($verification['shop_phone'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6">Business information</h3>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="individual_name">Individual registered name</label>
                    <input class="form-control" id="individual_name" name="individual_name" value="<?= htmlspecialchars($verification['individual_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="business_name">Business name / trade name</label>
                    <input class="form-control" id="business_name" name="business_name" value="<?= htmlspecialchars($verification['business_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="business_address">Business address</label>
                    <input class="form-control" id="business_address" name="business_address" value="<?= htmlspecialchars($verification['business_address'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="primary_document_type">Primary business document type</label>
                    <select class="form-select" id="primary_document_type" name="primary_document_type" required>
                        <option value="">Select document type</option>
                        <?php foreach ($primaryDocumentTypes as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $verification['primary_document_type'] === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="government_id_type">Government ID</label>
                    <select class="form-select" id="government_id_type" name="government_id_type" required>
                        <option value="">Select government ID</option>
                        <?php foreach ($governmentIdTypes as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $verification['government_id_type'] === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="business_email">Business email</label>
                    <input class="form-control" id="business_email" name="business_email" type="email" value="<?= htmlspecialchars($verification['business_email'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="business_phone">Business phone number</label>
                    <input class="form-control" id="business_phone" name="business_phone" value="<?= htmlspecialchars($verification['business_phone'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tax_identification_number">Tax identification number</label>
                    <input class="form-control" id="tax_identification_number" name="tax_identification_number" value="<?= htmlspecialchars($verification['tax_identification_number'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">Value added tax registration</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="vat_registration" id="vat_registered" value="vat_registered" <?= $verification['vat_registration'] === 'vat_registered' ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="vat_registered">VAT registered</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="vat_registration" id="non_vat_registered" value="non_vat_registered" <?= $verification['vat_registration'] === 'non_vat_registered' ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="non_vat_registered">Non-VAT registered</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="bir_certification">BIR certification of registration</label>
                    <input class="form-control" id="bir_certification" name="bir_certification" value="<?= htmlspecialchars($verification['bir_certification'], ENT_QUOTES, 'UTF-8') ?>" required>
                    <small class="text-muted">Provide the certificate number or reference.</small>
                </div>
                <div class="col-12">
                    <label class="form-label d-block">Submit sworn declaration</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="sworn_declaration" id="sworn_yes" value="yes" <?= $verification['sworn_declaration'] ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="sworn_yes">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="sworn_declaration" id="sworn_no" value="no" <?= !$verification['sworn_declaration'] && $verification['sworn_declaration'] !== null ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="sworn_no">No</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="terms_accepted" name="terms_accepted" value="1" <?= $verification['terms_accepted'] ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="terms_accepted">I agree to the terms and conditions and data privacy policy.</label>
                    </div>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-outline-primary" type="submit" <?= ($verification['status'] === 'submitted' || $verification['status'] === 'approved') ? 'disabled' : '' ?>>
                        Save application details
                    </button>
                    <?php if ($verification['updated_at']): ?>
                        <span class="text-muted small">Last saved <?= htmlspecialchars($verification['updated_at'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
            </form>
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