<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['employee']);

$pageTitle = 'Ticket Details';
$currentUser = current_user();
$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$errors = [];
$successMessage = flash_get('success');
$ticket = null;
$updates = [];

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$statusOptions = ['queued', 'working', 'for_review', 'done'];

if ($ticketId <= 0) {
    $errors[] = 'Invalid ticket selection.';
} elseif (!table_exists('job_tickets')) {
    $errors[] = 'Production tickets are not available right now.';
}

$ordersColumns = table_columns('orders');
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);
$orderStatusColumn = find_column($ordersColumns, ['status', 'order_status']);

if (!$errors) {
    try {
        $selectParts = [
            'jt.id',
            'jt.order_id',
            'jt.step',
            'jt.status',
            'jt.started_at',
            'jt.finished_at',
        ];
        if ($orderStatusColumn) {
            $selectParts[] = 'o.' . $orderStatusColumn . ' AS order_status';
        }
        if ($shopIdColumn) {
            $selectParts[] = 's.name AS shop_name';
        }
        $joinShop = $shopIdColumn ? 'LEFT JOIN shops s ON s.id = o.' . $shopIdColumn : '';

        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM job_tickets jt
             JOIN orders o ON o.id = jt.order_id
             ' . $joinShop . '
             WHERE jt.id = :ticket_id
             AND jt.assigned_to_user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'user_id' => $currentUser['id'],
        ]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $errors[] = 'Ticket not found or not assigned to you.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load this ticket right now.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_update' || $action === 'start_work') {
            $status = strtolower(trim((string) ($_POST['status'] ?? '')));
            $note = trim((string) ($_POST['note'] ?? ''));
            $photoPath = null;

            if ($action === 'start_work') {
                $status = 'working';
                if ($note === '') {
                    $note = 'Work started.';
                }
            }

            if (!in_array($status, $statusOptions, true)) {
                $errors[] = 'Select a valid status update.';
            }

            if ($action === 'add_update') {
                $file = $_FILES['photo'] ?? null;
                if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = 'Photo upload failed.';
                    } else {
                        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        if (!in_array($extension, $allowedExtensions, true)) {
                            $errors[] = 'Upload a valid image file (JPG, PNG, GIF, WEBP).';
                        } else {
                            $uploadDir = __DIR__ . '/../../../public/uploads/tickets/' . $ticketId;
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0775, true);
                            }
                            $filename = sprintf('update-%s.%s', bin2hex(random_bytes(8)), $extension);
                            $destination = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                                $errors[] = 'Unable to save the photo.';
                            } else {
                                $photoPath = '/uploads/tickets/' . $ticketId . '/' . $filename;
                            }
                        }
                    }
                }
            }

            if (!$errors) {
                try {
                    $pdo = db();
                    $pdo->beginTransaction();

                    $updateFields = ['status = :status'];
                    $params = [
                        'status' => $status,
                        'ticket_id' => $ticketId,
                    ];
                    if ($status === 'working' && empty($ticket['started_at'])) {
                        $updateFields[] = 'started_at = :started_at';
                        $params['started_at'] = gmdate('Y-m-d H:i:s');
                        $ticket['started_at'] = $params['started_at'];
                    }
                    if ($status === 'done') {
                        $updateFields[] = 'finished_at = :finished_at';
                        $params['finished_at'] = gmdate('Y-m-d H:i:s');
                        $ticket['finished_at'] = $params['finished_at'];
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE job_tickets
                         SET ' . implode(', ', $updateFields) . '
                         WHERE id = :ticket_id'
                    );
                    $stmt->execute($params);

                    if (table_exists('job_updates')) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO job_updates (ticket_id, status, note, photo_path, created_at)
                             VALUES (:ticket_id, :status, :note, :photo_path, :created_at)'
                        );
                        $stmt->execute([
                            'ticket_id' => $ticketId,
                            'status' => $status,
                            'note' => $note !== '' ? $note : null,
                            'photo_path' => $photoPath,
                            'created_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                    }

                    $pdo->commit();
                    $ticket['status'] = $status;

                    flash_set('success', 'Ticket updated successfully.');
                    header('Location: /employee/tickets/' . $ticketId);
                    exit;
                } catch (PDOException $exception) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Unable to save your update right now.';
                }
            }
        }
    }
}

if ($ticket && table_exists('job_updates')) {
    try {
        $stmt = db()->prepare(
            'SELECT status, note, photo_path, created_at
             FROM job_updates
             WHERE ticket_id = :ticket_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        $updates = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $updates = [];
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Ticket #<?= (int) $ticketId ?></h1>
        <p class="text-muted mb-0">Update progress for your assigned step.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/employee/tickets">Back to tickets</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($ticket): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Ticket details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Step</div>
                    <div class="fw-semibold text-capitalize"><?= htmlspecialchars($ticket['step'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Status</div>
                    <div class="fw-semibold text-uppercase"><?= htmlspecialchars($ticket['status'] ?? 'queued', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Order</div>
                    <div class="fw-semibold">#<?= (int) $ticket['order_id'] ?></div>
                    <?php if (!empty($ticket['shop_name'])): ?>
                        <div class="text-muted small"><?= htmlspecialchars($ticket['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Started</div>
                    <div class="fw-semibold"><?= htmlspecialchars($ticket['started_at'] ?? 'Not started', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Finished</div>
                    <div class="fw-semibold"><?= htmlspecialchars($ticket['finished_at'] ?? 'Not finished', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if (isset($ticket['order_status'])): ?>
                    <div class="col-md-4">
                        <div class="text-muted small">Order status</div>
                        <div class="fw-semibold text-uppercase"><?= htmlspecialchars((string) $ticket['order_status'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Add update</h2>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $ticket['status'] === $statusOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusOption)), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="note">Note</label>
                    <input class="form-control" id="note" name="note" placeholder="Share what changed or what you need reviewed">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="photo">Photo (optional)</label>
                    <input class="form-control" id="photo" type="file" name="photo" accept="image/*">
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                        <?php if (($ticket['status'] ?? '') !== 'working'): ?>
                            <button class="btn btn-primary" type="submit" name="action" value="start_work">Start work</button>
                        <?php endif; ?>
                        <button class="btn btn-outline-primary" type="submit" name="action" value="add_update">Save update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h6">Updates</h2>
            <?php if (!table_exists('job_updates')): ?>
                <p class="text-muted mb-0">Updates are not configured yet.</p>
            <?php elseif (!$updates): ?>
                <p class="text-muted mb-0">No updates yet.</p>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($updates as $update): ?>
                        <div class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold text-uppercase"><?= htmlspecialchars($update['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($update['note'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($update['note'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($update['photo_path'])): ?>
                                        <a class="small" href="<?= htmlspecialchars($update['photo_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">View photo</a>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small"><?= htmlspecialchars($update['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>