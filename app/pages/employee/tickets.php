<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['employee']);

$pageTitle = 'My Tickets';
$currentUser = current_user();
$errors = [];
$tickets = [];

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

if (!table_exists('job_tickets')) {
    $errors[] = 'Production tickets are not available yet.';
}

if (!$errors) {
    try {
        $ordersColumns = table_columns('orders');
        $shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);
        $statusColumn = find_column($ordersColumns, ['status', 'order_status']);
        $selectParts = [
            'jt.id',
            'jt.order_id',
            'jt.step',
            'jt.status',
            'jt.started_at',
            'jt.finished_at',
        ];
        if ($statusColumn) {
            $selectParts[] = 'o.' . $statusColumn . ' AS order_status';
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
             WHERE jt.assigned_to_user_id = :user_id
             ORDER BY jt.status, jt.id DESC'
        );
        $stmt->execute(['user_id' => $currentUser['id']]);
        $tickets = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load your tickets right now.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">My production tickets</h1>
        <p class="text-muted mb-0">Update assigned production steps and track progress.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="list-group">
        <?php if (!$tickets): ?>
            <div class="list-group-item text-muted">No tickets assigned yet.</div>
        <?php endif; ?>
        <?php foreach ($tickets as $ticket): ?>
            <a class="list-group-item list-group-item-action" href="/employee/tickets/<?= (int) $ticket['id'] ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold text-capitalize"><?= htmlspecialchars($ticket['step'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small">Order #<?= (int) $ticket['order_id'] ?></div>
                        <?php if (!empty($ticket['shop_name'])): ?>
                            <div class="text-muted small"><?= htmlspecialchars($ticket['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (isset($ticket['order_status'])): ?>
                            <div class="text-muted small">Order status: <?= htmlspecialchars((string) $ticket['order_status'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-light text-dark text-uppercase">
                            <?= htmlspecialchars($ticket['status'] ?? 'queued', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <div class="text-muted small mt-1">
                            <?= htmlspecialchars($ticket['started_at'] ?? 'Not started', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>