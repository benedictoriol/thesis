<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Hiring';
$errors = [];
$successMessage = flash_get('success');
$shop = load_shop_for_staff_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$posts = [];
$hiringPostsTableExists = table_exists('hiring_posts');
$applicationsTableExists = table_exists('applications');
$postColumns = $hiringPostsTableExists ? table_columns('hiring_posts') : [];
$applicationColumns = $applicationsTableExists ? table_columns('applications') : [];

$hasLocation = in_array('location_text', $postColumns, true);
$hasEmploymentType = in_array('employment_type', $postColumns, true);
$hasStatus = in_array('status', $postColumns, true);
$hasCreatedAt = in_array('created_at', $postColumns, true);
$hasApplicationPostId = in_array('post_id', $applicationColumns, true);

if ($shop && $hiringPostsTableExists) {
    $selectParts = [
        'hp.id',
        'hp.title',
    ];
    if ($hasLocation) {
        $selectParts[] = 'hp.location_text';
    }
    if ($hasEmploymentType) {
        $selectParts[] = 'hp.employment_type';
    }
    if ($hasStatus) {
        $selectParts[] = 'hp.status';
    }
    if ($hasCreatedAt) {
        $selectParts[] = 'hp.created_at';
    }
    if ($applicationsTableExists && $hasApplicationPostId) {
        $selectParts[] = 'COUNT(a.id) AS applicant_count';
    }

    $sql = sprintf(
        'SELECT %s FROM hiring_posts hp',
        implode(', ', $selectParts)
    );

    if ($applicationsTableExists && $hasApplicationPostId) {
        $sql .= ' LEFT JOIN applications a ON a.post_id = hp.id';
    }

    $sql .= ' WHERE hp.shop_id = :shop_id';

    if ($applicationsTableExists && $hasApplicationPostId) {
        $sql .= ' GROUP BY hp.id';
    }

    if ($hasCreatedAt) {
        $sql .= ' ORDER BY hp.created_at DESC';
    } else {
        $sql .= ' ORDER BY hp.id DESC';
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(['shop_id' => $shop['id']]);
        $posts = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load hiring posts right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Hiring</h1>
        <p class="text-muted mb-0">Publish and track hiring listings for <?= htmlspecialchars($shop['name'] ?? 'your shop', ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
        <a class="btn btn-primary btn-sm" href="/hr/hiring/create">Create hiring post</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$hiringPostsTableExists && !$errors): ?>
    <div class="alert alert-warning">
        Hiring posts table is not available. Please run the latest schema migrations.
    </div>
<?php endif; ?>

<?php if (!$errors && $hiringPostsTableExists): ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <?php if ($hasLocation): ?><th>Location</th><?php endif; ?>
                        <?php if ($hasEmploymentType): ?><th>Type</th><?php endif; ?>
                        <?php if ($applicationsTableExists && $hasApplicationPostId): ?><th>Applicants</th><?php endif; ?>
                        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
                        <?php if ($hasCreatedAt): ?><th>Created</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($post['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <?php if ($hasLocation): ?>
                            <td><?= htmlspecialchars($post['location_text'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasEmploymentType): ?>
                            <td><?= htmlspecialchars(str_replace('_', ' ', $post['employment_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($applicationsTableExists && $hasApplicationPostId): ?>
                            <td><?= htmlspecialchars((string) ($post['applicant_count'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <?php if ($hasStatus): ?>
                            <td>
                                <span class="badge <?= ($post['status'] ?? '') === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= htmlspecialchars($post['status'] ?? 'open', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasCreatedAt): ?>
                            <td><?= htmlspecialchars($post['created_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/hr/hiring/<?= (int) ($post['id'] ?? 0) ?>/applicants">View applicants</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$posts): ?>
                    <tr>
                        <td colspan="<?= 2 + ($hasLocation ? 1 : 0) + ($hasEmploymentType ? 1 : 0) + ($applicationsTableExists && $hasApplicationPostId ? 1 : 0) + ($hasStatus ? 1 : 0) + ($hasCreatedAt ? 1 : 0) ?>" class="text-muted">
                            No hiring posts yet. Create your first listing to start collecting applicants.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>