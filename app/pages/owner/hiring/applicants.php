<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/auth.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/flash.php';
require_once __DIR__ . '/../../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Hiring Applicants';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_staff_user($currentUser);
$temporaryPassword = null;

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$applications = [];
$applicationsTableExists = table_exists('applications');
$applicationColumns = $applicationsTableExists ? table_columns('applications') : [];
$hasApplicantStatus = in_array('status', $applicationColumns, true);
$hasApplicantPosition = in_array('position', $applicationColumns, true);
$hasApplicantPhone = in_array('phone', $applicationColumns, true);
$hasApplicantAppliedAt = in_array('applied_at', $applicationColumns, true);
$hasApplicantApprovedAt = in_array('approved_at', $applicationColumns, true);
$hasApplicantUserId = in_array('user_id', $applicationColumns, true);
$hasApplicantResume = in_array('resume_path', $applicationColumns, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_to_employee' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$applicationsTableExists) {
        $errors[] = 'Applications table is not available in the current database.';
    } else {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        if ($applicationId <= 0) {
            $errors[] = 'Invalid application selected.';
        } else {
            try {
                $selectColumns = ['id', 'shop_id', 'fullname', 'email'];
                if ($hasApplicantPhone) {
                    $selectColumns[] = 'phone';
                }
                if ($hasApplicantPosition) {
                    $selectColumns[] = 'position';
                }
                if ($hasApplicantStatus) {
                    $selectColumns[] = 'status';
                }
                if ($hasApplicantUserId) {
                    $selectColumns[] = 'user_id';
                }
                $stmt = db()->prepare(
                    sprintf(
                        'SELECT %s FROM applications WHERE id = :id AND shop_id = :shop_id LIMIT 1',
                        implode(', ', $selectColumns)
                    )
                );
                $stmt->execute([
                    'id' => $applicationId,
                    'shop_id' => $shop['id'],
                ]);
                $application = $stmt->fetch();

                if (!$application) {
                    $errors[] = 'Application not found.';
                } elseif ($hasApplicantStatus && $application['status'] !== 'approved') {
                    $errors[] = 'Only approved applicants can be converted.';
                } else {
                    $userId = $hasApplicantUserId ? (int) ($application['user_id'] ?? 0) : 0;
                    if ($userId === 0) {
                        $temporaryPassword = bin2hex(random_bytes(4));
                        $registration = register_user([
                            'fullname' => $application['fullname'] ?? 'Applicant',
                            'email' => $application['email'] ?? '',
                            'password' => $temporaryPassword,
                            'phone' => $application['phone'] ?? '',
                        ], 'employee');

                        if (!$registration['ok']) {
                            $errors = array_merge($errors, $registration['errors'] ?? ['Unable to create employee account.']);
                        } else {
                            $userId = (int) $registration['user_id'];
                        }
                    }

                    if (!$errors && $userId > 0) {
                        $staffColumns = table_columns('shop_staff');
                        $insertColumns = ['shop_id', 'user_id', 'role', 'created_at'];
                        $insertValues = [
                            'shop_id' => $shop['id'],
                            'user_id' => $userId,
                            'role' => 'employee',
                            'created_at' => gmdate('Y-m-d H:i:s'),
                        ];

                        if (in_array('position', $staffColumns, true)) {
                            $insertColumns[] = 'position';
                            $insertValues['position'] = $application['position'] ?? null;
                        }
                        if (in_array('status', $staffColumns, true)) {
                            $insertColumns[] = 'status';
                            $insertValues['status'] = 'active';
                        }
                        if (in_array('hired_at', $staffColumns, true)) {
                            $insertColumns[] = 'hired_at';
                            $insertValues['hired_at'] = gmdate('Y-m-d H:i:s');
                        }

                        $placeholders = array_map(static fn(string $column) => ':' . $column, $insertColumns);
                        $sql = sprintf(
                            'INSERT INTO shop_staff (%s) VALUES (%s)',
                            implode(', ', $insertColumns),
                            implode(', ', $placeholders)
                        );
                        $stmt = db()->prepare($sql);
                        $stmt->execute($insertValues);

                        if ($hasApplicantStatus) {
                            $stmt = db()->prepare('UPDATE applications SET status = :status WHERE id = :id');
                            $stmt->execute([
                                'status' => 'converted',
                                'id' => $applicationId,
                            ]);
                        }

                        $message = 'Applicant converted to employee successfully.';
                        if ($temporaryPassword) {
                            $message .= ' Temporary password: ' . $temporaryPassword;
                        }
                        flash_set('success', $message);
                        header('Location: /owner/hiring/applicants');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                $errors[] = 'Unable to convert applicant right now.';
            }
        }
    }
}

if ($shop && !$errors && $applicationsTableExists) {
    $selectParts = ['id', 'fullname', 'email'];
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
    if ($hasApplicantApprovedAt) {
        $selectParts[] = 'approved_at';
    }
    if ($hasApplicantResume) {
        $selectParts[] = 'resume_path';
    }

    $filters = ['shop_id = :shop_id'];
    if ($hasApplicantStatus) {
        $filters[] = "status = 'approved'";
    }

    $sql = sprintf(
        'SELECT %s FROM applications WHERE %s ORDER BY id DESC',
        implode(', ', $selectParts),
        implode(' AND ', $filters)
    );

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(['shop_id' => $shop['id']]);
        $applications = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load applicants right now.';
    }
}

$isOwner = $currentUser['role'] === 'owner';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$staffNavItems = [
    ['label' => 'Staff overview', 'path' => '/owner/staff'],
    ['label' => 'Create HR account', 'path' => '/owner/staff/create-hr', 'owner_only' => true],
    ['label' => 'Permissions', 'path' => '/owner/staff/permissions', 'owner_only' => true],
    ['label' => 'Hiring applicants', 'path' => '/owner/hiring/applicants'],
    ['label' => 'Approved employees', 'path' => '/owner/staff/approved-employees'],
];

require __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Hiring Applicants</h1>
        <p class="text-muted mb-0">Review approved applicants and convert them into staff accounts.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/owner/staff/approved-employees">Approved employees</a>
        <?php if ($isOwner): ?>
            <a class="btn btn-outline-primary btn-sm" href="/owner/staff/create-hr">Create HR account</a>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../../includes/owner_staff_nav.php'; ?>

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

<?php if (!$errors && $applicationsTableExists): ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Applicant</th>
                        <?php if ($hasApplicantPosition): ?><th>Position</th><?php endif; ?>
                        <?php if ($hasApplicantPhone): ?><th>Phone</th><?php endif; ?>
                        <?php if ($hasApplicantApprovedAt): ?><th>Approved</th><?php elseif ($hasApplicantAppliedAt): ?><th>Applied</th><?php endif; ?>
                        <?php if ($hasApplicantResume): ?><th>Resume</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($applications as $application): ?>
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
                        <?php if ($hasApplicantApprovedAt): ?>
                            <td><?= htmlspecialchars($application['approved_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php elseif ($hasApplicantAppliedAt): ?>
                            <td><?= htmlspecialchars($application['applied_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasApplicantResume): ?>
                            <td>
                                <?php if (!empty($application['resume_path'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($application['resume_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                        View resume
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="convert_to_employee">
                                <input type="hidden" name="application_id" value="<?= htmlspecialchars((string) $application['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Convert to staff</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$applications): ?>
                    <tr>
                        <td colspan="<?= 2 + ($hasApplicantPosition ? 1 : 0) + ($hasApplicantPhone ? 1 : 0) + ($hasApplicantApprovedAt || $hasApplicantAppliedAt ? 1 : 0) + ($hasApplicantResume ? 1 : 0) ?>" class="text-muted">
                            No approved applicants yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
