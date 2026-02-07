<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Create Hiring Post';
$errors = [];
$shop = load_shop_for_staff_user($currentUser);
$hiringPostsTableExists = table_exists('hiring_posts');
$postColumns = $hiringPostsTableExists ? table_columns('hiring_posts') : [];

$hasDescription = in_array('description', $postColumns, true);
$hasLocation = in_array('location_text', $postColumns, true);
$hasEmploymentType = in_array('employment_type', $postColumns, true);
$hasStatus = in_array('status', $postColumns, true);

$employmentTypeOptions = [
    'full_time' => 'Full time',
    'part_time' => 'Part time',
    'contract' => 'Contract',
    'internship' => 'Internship',
    'temporary' => 'Temporary',
];

$formData = [
    'title' => '',
    'description' => '',
    'location_text' => '',
    'employment_type' => 'full_time',
];

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_post' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!$hiringPostsTableExists) {
        $errors[] = 'Hiring posts table is not available in the current database.';
    } else {
        $formData['title'] = trim((string) ($_POST['title'] ?? ''));
        $formData['description'] = trim((string) ($_POST['description'] ?? ''));
        $formData['location_text'] = trim((string) ($_POST['location_text'] ?? ''));
        $formData['employment_type'] = (string) ($_POST['employment_type'] ?? 'full_time');

        if ($formData['title'] === '') {
            $errors[] = 'Title is required.';
        }

        if (!array_key_exists($formData['employment_type'], $employmentTypeOptions)) {
            $formData['employment_type'] = 'full_time';
        }

        if (!$errors) {
            $insertColumns = ['shop_id', 'title', 'created_at'];
            $insertValues = [
                'shop_id' => $shop['id'],
                'title' => $formData['title'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];

            if ($hasDescription) {
                $insertColumns[] = 'description';
                $insertValues['description'] = $formData['description'] ?: null;
            }
            if ($hasLocation) {
                $insertColumns[] = 'location_text';
                $insertValues['location_text'] = $formData['location_text'] ?: null;
            }
            if ($hasEmploymentType) {
                $insertColumns[] = 'employment_type';
                $insertValues['employment_type'] = $formData['employment_type'];
            }
            if ($hasStatus) {
                $insertColumns[] = 'status';
                $insertValues['status'] = 'open';
            }

            $placeholders = array_map(static fn(string $column) => ':' . $column, $insertColumns);
            $sql = sprintf(
                'INSERT INTO hiring_posts (%s) VALUES (%s)',
                implode(', ', $insertColumns),
                implode(', ', $placeholders)
            );

            try {
                $stmt = db()->prepare($sql);
                $stmt->execute($insertValues);
                flash_set('success', 'Hiring post created successfully.');
                header('Location: /hr/hiring');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to create hiring post right now.';
            }
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Create hiring post</h1>
        <p class="text-muted mb-0">Share a new role to attract applicants.</p>
    </div>
    <div class="mt-3 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/hr/hiring">Back to hiring</a>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$hiringPostsTableExists && !$errors): ?>
    <div class="alert alert-warning">
        Hiring posts table is not available. Please run the latest schema migrations.
    </div>
<?php endif; ?>

<?php if ($hiringPostsTableExists && !$errors): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_post">

                <div class="mb-3">
                    <label class="form-label">Role title</label>
                    <input class="form-control" type="text" name="title" value="<?= htmlspecialchars($formData['title'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <?php if ($hasEmploymentType): ?>
                    <div class="mb-3">
                        <label class="form-label">Employment type</label>
                        <select class="form-select" name="employment_type">
                            <?php foreach ($employmentTypeOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $formData['employment_type'] === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($hasLocation): ?>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input class="form-control" type="text" name="location_text" value="<?= htmlspecialchars($formData['location_text'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endif; ?>

                <?php if ($hasDescription): ?>
                    <div class="mb-3">
                        <label class="form-label">Role description</label>
                        <textarea class="form-control" name="description" rows="4"><?= htmlspecialchars($formData['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Publish post</button>
                    <a class="btn btn-outline-secondary" href="/hr/hiring">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
