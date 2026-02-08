<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['client']);

$currentUser = current_user();
$pageTitle = 'Apply for a role';
$errors = [];
$successMessage = flash_get('success');

$shopId = (int) ($_GET['shop_id'] ?? 0);
$postId = (int) ($_GET['post_id'] ?? 0);
$shop = null;
$post = null;

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

$hiringColumns = get_table_columns(db(), 'hiring_posts');
$applicationColumns = get_table_columns(db(), 'applications');

$hasPostLocation = in_array('location_text', $hiringColumns, true);
$hasPostEmploymentType = in_array('employment_type', $hiringColumns, true);
$hasPostStatus = in_array('status', $hiringColumns, true);
$hasPostDescription = in_array('description', $hiringColumns, true);

$hasApplicantPhone = in_array('phone', $applicationColumns, true);
$hasApplicantPosition = in_array('position', $applicationColumns, true);
$hasApplicantStatus = in_array('status', $applicationColumns, true);
$hasApplicantResume = in_array('resume_path', $applicationColumns, true);
$hasApplicantUserId = in_array('user_id', $applicationColumns, true);

$formData = [
    'fullname' => $currentUser['fullname'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'phone' => $currentUser['phone'] ?? '',
    'position' => '',
];

if ($shopId <= 0 || $postId <= 0) {
    $errors[] = 'Invalid hiring post selection.';
}

if ($shopId > 0) {
    try {
        $shopStmt = db()->prepare('SELECT id, name FROM shops WHERE id = :shop_id LIMIT 1');
        $shopStmt->execute(['shop_id' => $shopId]);
        $shop = $shopStmt->fetch();
        if (!$shop) {
            $errors[] = 'Shop not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load shop details right now.';
    }
}

if ($shop && $postId > 0 && $hiringColumns) {
    try {
        $select = ['id', 'shop_id', 'title'];
        if ($hasPostDescription) {
            $select[] = 'description';
        }
        if ($hasPostLocation) {
            $select[] = 'location_text';
        }
        if ($hasPostEmploymentType) {
            $select[] = 'employment_type';
        }
        if ($hasPostStatus) {
            $select[] = 'status';
        }
        $stmt = db()->prepare(
            sprintf(
                'SELECT %s FROM hiring_posts WHERE id = :id AND shop_id = :shop_id LIMIT 1',
                implode(', ', $select)
            )
        );
        $stmt->execute([
            'id' => $postId,
            'shop_id' => $shopId,
        ]);
        $post = $stmt->fetch() ?: null;
        if (!$post) {
            $errors[] = 'Hiring post not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load the hiring post details.';
    }
}

if ($post && $hasApplicantPosition) {
    $formData['position'] = $post['title'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_application' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$hiringColumns) {
        $errors[] = 'Hiring posts are not available in the current database.';
    } elseif (!$applicationColumns) {
        $errors[] = 'Applications are not available in the current database.';
    } elseif ($hasPostStatus && ($post['status'] ?? '') !== 'open') {
        $errors[] = 'This hiring post is no longer accepting applications.';
    } elseif (!$hasApplicantResume) {
        $errors[] = 'Resume uploads are not available yet.';
    } else {
        $formData['fullname'] = trim((string) ($_POST['fullname'] ?? ''));
        $formData['email'] = trim((string) ($_POST['email'] ?? ''));
        $formData['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $formData['position'] = trim((string) ($_POST['position'] ?? $formData['position']));

        if ($formData['fullname'] === '') {
            $errors[] = 'Full name is required.';
        }
        if ($formData['email'] === '') {
            $errors[] = 'Email is required.';
        }

        $resumePath = null;
        if (!isset($_FILES['resume']) || ($_FILES['resume']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please upload your resume.';
        } elseif (($_FILES['resume']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Resume upload failed.';
        } else {
            $file = $_FILES['resume'];
            $filename = $file['name'] ?? '';
            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = 'Resume must be a PDF or Word document.';
            } elseif (($file['size'] ?? 0) > 5 * 1024 * 1024) {
                $errors[] = 'Resume must be 5MB or smaller.';
            } else {
                $uploadDir = __DIR__ . '/../../../public/uploads/applications/' . $postId;
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Unable to prepare the resume upload directory.';
                } else {
                    $safeName = bin2hex(random_bytes(8)) . '.' . $extension;
                    $destination = $uploadDir . '/' . $safeName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $errors[] = 'Resume upload failed.';
                    } else {
                        $resumePath = '/uploads/applications/' . $postId . '/' . $safeName;
                    }
                }
            }
        }

        if (!$errors) {
            $insertColumns = ['shop_id', 'post_id', 'fullname', 'email', 'applied_at'];
            $insertValues = [
                'shop_id' => $shopId,
                'post_id' => $postId,
                'fullname' => $formData['fullname'],
                'email' => $formData['email'],
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ];

            if ($hasApplicantUserId) {
                $insertColumns[] = 'user_id';
                $insertValues['user_id'] = $currentUser['id'];
            }
            if ($hasApplicantPhone) {
                $insertColumns[] = 'phone';
                $insertValues['phone'] = $formData['phone'] ?: null;
            }
            if ($hasApplicantPosition) {
                $insertColumns[] = 'position';
                $insertValues['position'] = $formData['position'] ?: null;
            }
            if ($hasApplicantStatus) {
                $insertColumns[] = 'status';
                $insertValues['status'] = 'pending';
            }
            if ($hasApplicantResume) {
                $insertColumns[] = 'resume_path';
                $insertValues['resume_path'] = $resumePath;
            }

            $placeholders = array_map(static fn(string $column) => ':' . $column, $insertColumns);
            $sql = sprintf(
                'INSERT INTO applications (%s) VALUES (%s)',
                implode(', ', $insertColumns),
                implode(', ', $placeholders)
            );

            try {
                $stmt = db()->prepare($sql);
                $stmt->execute($insertValues);
                flash_set('success', 'Application submitted successfully.');
                redirect_to('' . );
            } catch (PDOException $exception) {
                $errors[] = 'Unable to submit your application right now.';
            }
        }
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Apply for this role</h1>
        <p class="text-muted mb-0">Complete your details and upload a resume for review.</p>
    </div>
    <div class="mt-3 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/shop/<?= (int) $shopId ?>">Back to shop</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop && $post): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-1"><?= htmlspecialchars($post['title'] ?? 'Role', ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="text-muted small"><?= htmlspecialchars($shop['name'] ?? 'Shop', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if (!empty($post['location_text'])): ?>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($post['location_text'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if (!empty($post['employment_type'])): ?>
                        <span class="badge bg-light text-dark border">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $post['employment_type'])), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($post['status'])): ?>
                        <span class="badge <?= ($post['status'] ?? '') === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= htmlspecialchars($post['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($post['description'])): ?>
                <p class="text-muted mt-3 mb-0"><?= nl2br(htmlspecialchars($post['description'], ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$errors && $shop && $post): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_application">

                <div class="mb-3">
                    <label class="form-label">Full name</label>
                    <input class="form-control" type="text" name="fullname" value="<?= htmlspecialchars($formData['fullname'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($formData['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <?php if ($hasApplicantPhone): ?>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input class="form-control" type="text" name="phone" value="<?= htmlspecialchars($formData['phone'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endif; ?>

                <?php if ($hasApplicantPosition): ?>
                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <input class="form-control" type="text" name="position" value="<?= htmlspecialchars($formData['position'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Resume (PDF or Word)</label>
                    <input class="form-control" type="file" name="resume" accept=".pdf,.doc,.docx" required>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Submit application</button>
                    <a class="btn btn-outline-secondary" href="/shop/<?= (int) $shopId ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
