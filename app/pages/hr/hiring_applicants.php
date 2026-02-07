<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Applicants';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_staff_user($currentUser);
$postId = (int) ($_GET['post_id'] ?? 0);

$hiringPostsTableExists = table_exists('hiring_posts');
$applicationsTableExists = table_exists('applications');
$postColumns = $hiringPostsTableExists ? table_columns('hiring_posts') : [];
$applicationColumns = $applicationsTableExists ? table_columns('applications') : [];

$hasPostTitle = in_array('title', $postColumns, true);
$hasPostStatus = in_array('status', $postColumns, true);
$hasPostDescription = in_array('description', $postColumns, true);

$hasApplicantStatus = in_array('status', $applicationColumns, true);
$hasApplicantPhone = in_array('phone', $applicationColumns, true);
$hasApplicantPosition = in_array('position', $applicationColumns, true);
$hasApplicantAppliedAt = in_array('applied_at', $applicationColumns, true);
$hasApplicantInterviewNotes = in_array('interview_notes', $applicationColumns, true);
$hasApplicantInterviewedAt = in_array('interviewed_at', $applicationColumns, true);
$hasApplicantApprovedAt = in_array('approved_at', $applicationColumns, true);
$hasApplicantPostId = in_array('post_id', $applicationColumns, true);

$post = null;
$applications = [];

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}
if ($postId <= 0) {
    $errors[] = 'Invalid hiring post selected.';
}

if ($shop && $postId > 0 && $hiringPostsTableExists) {
    try {
        $selectParts = ['id'];
        if ($hasPostTitle) {
            $selectParts[] = 'title';
        }
        if ($hasPostStatus) {
            $selectParts[] = 'status';
        }
        if ($hasPostDescription) {
            $selectParts[] = 'description';
        }
        $stmt = db()->prepare(
            sprintf(
                'SELECT %s FROM hiring_posts WHERE id = :id AND shop_id = :shop_id LIMIT 1',
                implode(', ', $selectParts)
            )
        );
        $stmt->execute([
            'id' => $postId,
            'shop_id' => $shop['id'],
        ]);
        $post = $stmt->fetch() ?: null;
        if (!$post) {
            $errors[] = 'Hiring post not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load the hiring post details.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_application' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$applicationsTableExists) {
        $errors[] = 'Applications table is not available in the current database.';
    } elseif (!$hasApplicantStatus) {
        $errors[] = 'Applicant status tracking is not available.';
    } else {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $newStatus = (string) ($_POST['status'] ?? 'pending');
        $notes = trim((string) ($_POST['interview_notes'] ?? ''));

        $allowedStatuses = ['pending', 'interviewed', 'approved', 'rejected'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = 'Invalid status selected.';
        } elseif ($applicationId <= 0) {
            $errors[] = 'Invalid applicant selected.';
        } else {
            try {
                $filters = ['id = :id', 'shop_id = :shop_id'];
                $params = [
                    'id' => $applicationId,
                    'shop_id' => $shop['id'],
                ];
                if ($hasApplicantPostId) {
                    $filters[] = 'post_id = :post_id';
                    $params['post_id'] = $postId;
                }
                $stmt = db()->prepare(
                    sprintf(
                        'SELECT id FROM applications WHERE %s LIMIT 1',
                        implode(' AND ', $filters)
                    )
                );
                $stmt->execute($params);
                $applicationExists = (bool) $stmt->fetchColumn();

                if (!$applicationExists) {
                    $errors[] = 'Applicant not found.';
                } else {
                    $updateColumns = ['status = :status'];
                    $updateParams = [
                        'status' => $newStatus,
                        'id' => $applicationId,
                    ];

                    if ($hasApplicantInterviewNotes) {
                        $updateColumns[] = 'interview_notes = :interview_notes';
                        $updateParams['interview_notes'] = $notes ?: null;
                    }
                    if ($hasApplicantInterviewedAt && $newStatus === 'interviewed') {
                        $updateColumns[] = 'interviewed_at = :interviewed_at';
                        $updateParams['interviewed_at'] = gmdate('Y-m-d H:i:s');
                    }
                    if ($hasApplicantApprovedAt && $newStatus === 'approved') {
                        $updateColumns[] = 'approved_at = :approved_at';
                        $updateParams['approved_at'] = gmdate('Y-m-d H:i:s');
                    }

                    $sql = sprintf(
                        'UPDATE applications SET %s WHERE id = :id',
                        implode(', ', $updateColumns)
                    );
                    $stmt = db()->prepare($sql);
                    $stmt->execute($updateParams);
                    flash_set('success', 'Applicant updated successfully.');
                    header('Location: /hr/hiring/' . $postId . '/applicants');
                    exit;
                }
            } catch (PDOException $exception) {
                $errors[] = 'Unable to update applicant right now.';
            }
        }
    }
}

if ($shop && $post && $applicationsTableExists) {
    $selectParts = [
        'id',
        'fullname',
        'email',
    ];
    if ($hasApplicantPhone) {
        $selectParts[] = 'phone';
    }
    if ($hasApplicantPosition) {
        $selectParts[] = 'position';
    }
    if ($hasApplicantStatus) {
        $selectParts[] = 'status';
    }
    if ($hasApplicantAppliedAt) {
        $selectParts[] = 'applied_at';
    }
    if ($hasApplicantInterviewNotes) {
        $selectParts[] = 'interview_notes';
    }
    if ($hasApplicantInterviewedAt) {
        $selectParts[] = 'interviewed_at';
    }
    if ($hasApplicantApprovedAt) {
        $selectParts[] = 'approved_at';
    }

    $filters = ['shop_id = :shop_id'];
    $params = ['shop_id' => $shop['id']];
    if ($hasApplicantPostId) {
        $filters[] = 'post_id = :post_id';
        $params['post_id'] = $postId;
    }

    $sql = sprintf(
        'SELECT %s FROM applications WHERE %s ORDER BY id DESC',
        implode(', ', $selectParts),
        implode(' AND ', $filters)
    );

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $applications = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load applicants right now.';
    }
}

$statusLabels = [
    'pending' => 'Pending',
    'interviewed' => 'Interviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

$statusBadgeClasses = [
    'pending' => 'bg-secondary',
    'interviewed' => 'bg-info',
    'approved' => 'bg-success',
    'rejected' => 'bg-danger',
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Applicants</h1>
        <?php if ($post): ?>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($post['title'] ?? 'Hiring post', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($hasPostStatus): ?>
                    · <span class="badge <?= ($post['status'] ?? '') === 'open' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($post['status'] ?? 'open', ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="mt-3 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/hr/hiring">Back to hiring</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$applicationsTableExists && !$errors): ?>
    <div class="alert alert-warning">
        Applications table is not available. Please run the latest schema migrations.
    </div>
<?php endif; ?>
<?php if (!$hiringPostsTableExists && !$errors): ?>
    <div class="alert alert-warning">
        Hiring posts table is not available. Please run the latest schema migrations.
    </div>
<?php endif; ?>
<?php if ($applicationsTableExists && !$hasApplicantInterviewNotes && !$errors): ?>
    <div class="alert alert-warning">
        Interview notes are unavailable because the database schema is missing the interview_notes column.
    </div>
<?php endif; ?>

<?php if ($post && $hasPostDescription && ($post['description'] ?? '') !== ''): ?>
    <div class="alert alert-light border">
        <?= nl2br(htmlspecialchars((string) $post['description'], ENT_QUOTES, 'UTF-8')) ?>
    </div>
<?php endif; ?>

<?php if (!$errors && $applicationsTableExists && $post): ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Applicant</th>
                        <?php if ($hasApplicantPosition): ?><th>Position</th><?php endif; ?>
                        <?php if ($hasApplicantPhone): ?><th>Phone</th><?php endif; ?>
                        <?php if ($hasApplicantAppliedAt): ?><th>Applied</th><?php endif; ?>
                        <?php if ($hasApplicantStatus): ?><th>Status</th><?php endif; ?>
                        <?php if ($hasApplicantInterviewNotes): ?><th>Interview notes</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($applications as $application): ?>
                    <?php $currentStatus = $hasApplicantStatus ? ($application['status'] ?? 'pending') : 'pending'; ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($application['fullname'] ?? 'Applicant', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($application['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <?php if ($hasApplicantPosition): ?>
                            <td><?= htmlspecialchars($application['position'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasApplicantPhone): ?>
                            <td><?= htmlspecialchars($application['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasApplicantAppliedAt): ?>
                            <td><?= htmlspecialchars($application['applied_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasApplicantStatus): ?>
                            <td>
                                <span class="badge <?= $statusBadgeClasses[$currentStatus] ?? 'bg-secondary' ?>">
                                    <?= htmlspecialchars($statusLabels[$currentStatus] ?? ucfirst((string) $currentStatus), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasApplicantInterviewNotes): ?>
                            <td class="text-muted small">
                                <?= htmlspecialchars($application['interview_notes'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <form method="post" class="d-flex flex-column gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_application">
                                <input type="hidden" name="application_id" value="<?= htmlspecialchars((string) $application['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($hasApplicantStatus): ?>
                                    <select class="form-select form-select-sm" name="status">
                                        <?php foreach ($statusLabels as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $currentStatus === $value ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <?php if ($hasApplicantInterviewNotes): ?>
                                    <textarea class="form-control form-control-sm" name="interview_notes" rows="2" placeholder="Add interview notes..."><?= htmlspecialchars((string) ($application['interview_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$applications): ?>
                    <tr>
                        <td colspan="<?= 2 + ($hasApplicantPosition ? 1 : 0) + ($hasApplicantPhone ? 1 : 0) + ($hasApplicantAppliedAt ? 1 : 0) + ($hasApplicantStatus ? 1 : 0) + ($hasApplicantInterviewNotes ? 1 : 0) ?>" class="text-muted">
                            No applicants yet for this role.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
