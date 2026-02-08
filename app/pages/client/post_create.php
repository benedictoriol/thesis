<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/dss_helpers.php';
require_once __DIR__ . '/../../handlers/notification_handler.php';
require_once __DIR__ . '/../../handlers/post_handler.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'Create Post';
$errors = [];
$successMessage = flash_get('success');
$designIds = [];
$selectedInviteIds = array_values(array_unique(array_filter(array_map('intval', $_POST['invite_shop_ids'] ?? []))));

$itemTypes = [
    'tshirt' => 'T-Shirt',
    'cap' => 'Cap',
    'bag' => 'Bag',
    'logo' => 'Logo',
    'other' => 'Other',
];

$designs = [];
try {
    $stmt = db()->prepare('SELECT id, name, item_type, created_at FROM custom_designs WHERE owner_user_id = :user_id ORDER BY created_at DESC');
    $stmt->execute(['user_id' => $user['id']]);
    $designs = $stmt->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load saved designs right now.';
}

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
    } catch (PDOException $exception) {
        return [];
    }
}

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$shopColumns = get_table_columns(db(), 'shops');
$metricsColumns = get_table_columns(db(), 'shop_metrics');
$availabilityColumns = get_table_columns(db(), 'shop_availability');
$shopTownColumn = find_column($shopColumns, ['address_text', 'town', 'city', 'location']);
$acceptingQuotesColumn = find_column($availabilityColumns, ['accepting_quotes']);

$townMatch = trim($_POST['town_text'] ?? '');
if ($townMatch === '') {
    try {
        $stmt = db()->prepare(
            'SELECT town_text
             FROM client_addresses
             WHERE client_user_id = :client_user_id
             AND town_text IS NOT NULL
             ORDER BY is_default DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $townMatch = (string) ($stmt->fetchColumn() ?: '');
    } catch (PDOException $exception) {
        $townMatch = '';
    }
}

$weights = dss_load_weights();
$bounds = dss_load_metric_bounds($metricsColumns);
$scoreInfo = dss_build_score_sql(
    $weights,
    $bounds,
    $metricsColumns,
    $availabilityColumns,
    $shopTownColumn,
    $townMatch === '' ? null : $townMatch
);
$scoreSql = $scoreInfo['sql'];
$scoreParams = $scoreInfo['params'];
$recommendedShops = [];

if ($scoreSql !== '0') {
    try {
        $shopJoins = [];
        if ($metricsColumns) {
            $shopJoins[] = 'LEFT JOIN shop_metrics sm ON sm.shop_id = s.id';
        }
        if ($availabilityColumns) {
            $shopJoins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = s.id';
        }
        $shopJoinSql = $shopJoins ? "\n" . implode("\n", $shopJoins) : '';

        $shopSql = "SELECT s.id, s.name, s.address_text,
                COALESCE(sm.avg_rating, 0) AS avg_rating,
                COALESCE(sm.review_count, 0) AS review_count,
                $scoreSql AS recommended_score
            FROM shops s
            $shopJoinSql
            WHERE s.status = 'active'";
        if ($acceptingQuotesColumn) {
            $shopSql .= " AND COALESCE(sa.$acceptingQuotesColumn, 1) = 1";
        }
        $shopSql .= "
            ORDER BY recommended_score DESC, s.id DESC
            LIMIT 6";
        $stmt = db()->prepare($shopSql);
        foreach ($scoreParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $recommendedShops = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $recommendedShops = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $itemType = trim($_POST['item_type'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $budgetMinInput = trim($_POST['budget_min'] ?? '');
    $budgetMaxInput = trim($_POST['budget_max'] ?? '');
    $deadlineDate = trim($_POST['deadline_date'] ?? '');
    $townText = trim($_POST['town_text'] ?? '');

    $budgetMin = $budgetMinInput !== '' ? (float) $budgetMinInput : null;
    $budgetMax = $budgetMaxInput !== '' ? (float) $budgetMaxInput : null;

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }
    if (!array_key_exists($itemType, $itemTypes)) {
        $errors[] = 'Please select a valid item type.';
    }
    if ($quantity <= 0) {
        $errors[] = 'Quantity must be at least 1.';
    }
    if ($budgetMin !== null && $budgetMin < 0) {
        $errors[] = 'Minimum budget must be zero or more.';
    }
    if ($budgetMax !== null && $budgetMax < 0) {
        $errors[] = 'Maximum budget must be zero or more.';
    }
    if ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax) {
        $errors[] = 'Minimum budget cannot exceed maximum budget.';
    }
    if ($deadlineDate !== '') {
        $dateCheck = DateTime::createFromFormat('Y-m-d', $deadlineDate);
        if (!$dateCheck || $dateCheck->format('Y-m-d') !== $deadlineDate) {
            $errors[] = 'Deadline date must be in YYYY-MM-DD format.';
        }
    }

    $designIds = array_map('intval', $_POST['design_ids'] ?? []);
    $designIds = array_values(array_unique(array_filter($designIds)));

    if (!$errors) {
        $attachments = handle_post_attachments($_FILES['post_files'] ?? []);
        if ($attachments['errors']) {
            $errors = array_merge($errors, $attachments['errors']);
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'INSERT INTO client_posts (client_user_id, title, description, item_type, quantity, budget_min, budget_max, deadline_date, town_text, status, created_at)
                 VALUES (:client_user_id, :title, :description, :item_type, :quantity, :budget_min, :budget_max, :deadline_date, :town_text, :status, :created_at)'
            );
            $stmt->execute([
                'client_user_id' => $user['id'],
                'title' => $title,
                'description' => $description,
                'item_type' => $itemType,
                'quantity' => $quantity,
                'budget_min' => $budgetMin,
                'budget_max' => $budgetMax,
                'deadline_date' => $deadlineDate !== '' ? $deadlineDate : null,
                'town_text' => $townText !== '' ? $townText : null,
                'status' => 'open',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $postId = (int) db()->lastInsertId();

            if ($designIds) {
                $validDesigns = array_map('intval', array_column($designs, 'id'));
                $insertDesign = db()->prepare(
                    'INSERT INTO post_design_refs (post_id, design_id, created_at)
                     VALUES (:post_id, :design_id, :created_at)'
                );
                foreach ($designIds as $designId) {
                    if (!in_array($designId, $validDesigns, true)) {
                        continue;
                    }
                    $insertDesign->execute([
                        'post_id' => $postId,
                        'design_id' => $designId,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
            }

            if (!empty($attachments['paths'])) {
                $insertFile = db()->prepare(
                    'INSERT INTO post_files (post_id, file_path, created_at)
                     VALUES (:post_id, :file_path, :created_at)'
                );
                foreach ($attachments['paths'] as $path) {
                    $insertFile->execute([
                        'post_id' => $postId,
                        'file_path' => $path,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
            }

            if ($selectedInviteIds && dss_table_exists('post_invites')) {
                $recommendedIds = array_map('intval', array_column($recommendedShops, 'id'));
                $inviteIds = array_values(array_intersect($selectedInviteIds, $recommendedIds));
                if ($inviteIds) {
                    $insertInvite = db()->prepare(
                        'INSERT INTO post_invites (post_id, shop_id, created_at)
                         VALUES (:post_id, :shop_id, :created_at)'
                    );
                    foreach ($inviteIds as $shopId) {
                        $insertInvite->execute([
                            'post_id' => $postId,
                            'shop_id' => $shopId,
                            'created_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }

            db()->commit();
            try {
                notify_shops_about_post($postId);
            } catch (Throwable $exception) {
                // Notifications are best-effort.
            }
            flash_set('success', 'Post created successfully.');
            header('Location: /client/posts');
            exit;
        } catch (PDOException $exception) {
            db()->rollBack();
            $errors[] = 'Unable to save your post right now.';
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Create a client post</h1>
<p class="text-muted">Share your order needs so HR staff can send offers.</p>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field(); ?>
    <div class="mb-3">
        <label class="form-label" for="title">Post title</label>
        <input class="form-control" id="title" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-control" id="description" name="description" rows="4" required><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="item_type">Item type</label>
            <select class="form-select" id="item_type" name="item_type" required>
                <option value="">Select type</option>
                <?php foreach ($itemTypes as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= ($value === ($_POST['item_type'] ?? '')) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="quantity">Quantity</label>
            <input class="form-control" id="quantity" type="number" name="quantity" min="1" value="<?= htmlspecialchars($_POST['quantity'] ?? '1', ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <label class="form-label" for="budget_min">Budget min</label>
            <input class="form-control" id="budget_min" type="number" step="0.01" min="0" name="budget_min" value="<?= htmlspecialchars($_POST['budget_min'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="budget_max">Budget max</label>
            <input class="form-control" id="budget_max" type="number" step="0.01" min="0" name="budget_max" value="<?= htmlspecialchars($_POST['budget_max'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <label class="form-label" for="deadline_date">Deadline date</label>
            <input class="form-control" id="deadline_date" type="date" name="deadline_date" value="<?= htmlspecialchars($_POST['deadline_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="town_text">Town/City</label>
            <input class="form-control" id="town_text" name="town_text" value="<?= htmlspecialchars($_POST['town_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>
    <div class="mb-3 mt-3">
        <label class="form-label" for="post_files">Attachments (optional)</label>
        <input class="form-control" id="post_files" type="file" name="post_files[]" multiple accept=".jpg,.jpeg,.png,.pdf">
        <div class="form-text">Upload mood boards, size guides, or reference files (max 5MB each).</div>
    </div>

    <?php if ($recommendedShops): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>Recommended shops to invite (optional)</strong>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($recommendedShops as $shop): ?>
                    <label class="list-group-item d-flex align-items-start gap-3">
                        <input class="form-check-input mt-1" type="checkbox" name="invite_shop_ids[]"
                               value="<?= (int) $shop['id'] ?>" <?= in_array((int) $shop['id'], $selectedInviteIds, true) ? 'checked' : '' ?>>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($shop['address_text'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars($shop['address_text'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="small text-muted">
                                ⭐ <?= number_format((float) ($shop['avg_rating'] ?? 0), 1) ?>
                                (<?= (int) ($shop['review_count'] ?? 0) ?> reviews)
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-white">
                <div class="small text-muted">We will notify these shops once your post is published.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($designs): ?>
        <div class="mb-3">
            <label class="form-label">Design references</label>
            <div class="list-group">
                <?php foreach ($designs as $design): ?>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox" name="design_ids[]" value="<?= (int) $design['id'] ?>"
                            <?= in_array((int) $design['id'], $designIds ?? [], true) ? 'checked' : '' ?>>
                        <span>
                            <span class="fw-semibold"><?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-muted small">(<?= htmlspecialchars(strtoupper($design['item_type']), ENT_QUOTES, 'UTF-8') ?>)</span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between">
        <a class="btn btn-outline-secondary" href="/client/posts">Back to posts</a>
        <button class="btn btn-primary" type="submit">Publish post</button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>