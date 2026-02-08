<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$pageTitle = 'Production Tickets';
$currentUser = current_user();
$orderId = (int) ($_GET['order_id'] ?? 0);
$errors = [];
$successMessage = flash_get('success');

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$steps = ['digitizing', 'hooping', 'stitching', 'trimming', 'qc', 'packing'];

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$staffColumns = table_columns('shop_staff');
$hasManageOrders = in_array('can_manage_orders', $staffColumns, true);
if ($shop && $currentUser['role'] === 'hr' && $hasManageOrders) {
    try {
        $stmt = db()->prepare(
            'SELECT can_manage_orders
             FROM shop_staff
             WHERE shop_id = :shop_id
             AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'shop_id' => $shop['id'],
            'user_id' => $currentUser['id'],
        ]);
        $canManageOrders = (int) $stmt->fetchColumn() === 1;
        if (!$canManageOrders) {
            $errors[] = 'You do not have permission to manage orders.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to verify order permissions right now.';
    }
}

$ordersColumns = table_columns('orders');
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);
$statusColumn = find_column($ordersColumns, ['status', 'order_status']);

$order = null;
$tickets = [];
$employees = [];

if ($orderId <= 0) {
    $errors[] = 'Invalid order selection.';
} elseif (!$ordersColumns || !$shopIdColumn) {
    $errors[] = 'Orders are not available right now.';
}

if (!$errors) {
    try {
        $selectParts = ['o.id'];
        if ($statusColumn) {
            $selectParts[] = "o.$statusColumn AS order_status";
        }
        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM orders o
             WHERE o.id = :order_id
             AND o.' . $shopIdColumn . ' = :shop_id
             LIMIT 1'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'shop_id' => $shop['id'],
        ]);
        $order = $stmt->fetch();
        if (!$order) {
            $errors[] = 'Order not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load this order right now.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } elseif (!table_exists('job_tickets')) {
        $errors[] = 'Production tickets are not configured yet.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_ticket') {
            $step = strtolower(trim((string) ($_POST['step'] ?? '')));
            $assignedTo = (int) ($_POST['assigned_to_user_id'] ?? 0);
            $note = trim((string) ($_POST['ticket_note'] ?? ''));

            if (!in_array($step, $steps, true)) {
                $errors[] = 'Select a valid production step.';
            }
            if ($assignedTo <= 0) {
                $errors[] = 'Select an employee to assign.';
            }

            if (!$errors) {
                try {
                    $filters = ['shop_id = :shop_id', 'user_id = :user_id', "role = 'employee'"];
                    $params = [
                        'shop_id' => $shop['id'],
                        'user_id' => $assignedTo,
                    ];
                    if (in_array('status', $staffColumns, true)) {
                        $filters[] = "status = 'active'";
                    }
                    $stmt = db()->prepare(
                        'SELECT user_id
                         FROM shop_staff
                         WHERE ' . implode(' AND ', $filters) . '
                         LIMIT 1'
                    );
                    $stmt->execute($params);
                    $employeeMatch = $stmt->fetchColumn();
                    if (!$employeeMatch) {
                        $errors[] = 'Selected employee is not available.';
                    }
                } catch (PDOException $exception) {
                    $errors[] = 'Unable to verify the employee right now.';
                }
            }

            if (!$errors) {
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'INSERT INTO job_tickets (order_id, step, assigned_to_user_id, status, started_at, finished_at)
                         VALUES (:order_id, :step, :assigned_to_user_id, :status, :started_at, :finished_at)'
                    );
                    $stmt->execute([
                        'order_id' => $orderId,
                        'step' => $step,
                        'assigned_to_user_id' => $assignedTo,
                        'status' => 'queued',
                        'started_at' => null,
                        'finished_at' => null,
                    ]);
                    $ticketId = (int) $pdo->lastInsertId();

                    if ($note !== '' && table_exists('job_updates')) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO job_updates (ticket_id, status, note, photo_path, created_at)
                             VALUES (:ticket_id, :status, :note, :photo_path, :created_at)'
                        );
                        $stmt->execute([
                            'ticket_id' => $ticketId,
                            'status' => 'queued',
                            'note' => $note,
                            'photo_path' => null,
                            'created_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                    }

                    $pdo->commit();
                    flash_set('success', 'Production ticket created.');
                    redirect_to('' . );
                } catch (PDOException $exception) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Unable to create a production ticket right now.';
                }
            }
        }
    }
}

if ($order && table_exists('job_tickets')) {
    try {
        $stmt = db()->prepare(
            'SELECT jt.id, jt.step, jt.status, jt.started_at, jt.finished_at,
                    u.fullname AS assigned_name, u.email AS assigned_email
             FROM job_tickets jt
             LEFT JOIN users u ON u.id = jt.assigned_to_user_id
             WHERE jt.order_id = :order_id
             ORDER BY jt.id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $tickets = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $tickets = [];
    }
}

if ($order) {
    try {
        $filters = ['ss.shop_id = :shop_id', "ss.role = 'employee'"];
        if (in_array('status', $staffColumns, true)) {
            $filters[] = "ss.status = 'active'";
        }
        $stmt = db()->prepare(
            'SELECT ss.user_id, u.fullname, u.email
             FROM shop_staff ss
             JOIN users u ON u.id = ss.user_id
             WHERE ' . implode(' AND ', $filters) . '
             ORDER BY u.fullname'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $employees = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $employees = [];
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Production tickets</h1>
        <p class="text-muted mb-0">Assign production steps and track employee progress.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/owner/orders/<?= (int) $orderId ?>">Back to order</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($order): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Create ticket</h2>
            <?php if (!table_exists('job_tickets')): ?>
                <p class="text-muted mb-0">Production tickets are not configured yet.</p>
            <?php else: ?>
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_ticket">
                    <div class="col-md-4">
                        <label class="form-label" for="step">Step</label>
                        <select class="form-select" id="step" name="step" required>
                            <option value="">Select step</option>
                            <?php foreach ($steps as $step): ?>
                                <option value="<?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($step), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="assigned_to_user_id">Assign to</label>
                        <select class="form-select" id="assigned_to_user_id" name="assigned_to_user_id" required>
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int) $employee['user_id'] ?>">
                                    <?= htmlspecialchars($employee['fullname'] ?? 'Employee', ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($employee['email'])): ?>
                                        (<?= htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ticket_note">Initial note (optional)</label>
                        <input class="form-control" id="ticket_note" name="ticket_note" placeholder="Add context for the team">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="submit">Create ticket</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h6">Tickets for order #<?= (int) $orderId ?></h2>
            <?php if (!table_exists('job_tickets')): ?>
                <p class="text-muted mb-0">Production tickets are not configured yet.</p>
            <?php elseif (!$tickets): ?>
                <p class="text-muted mb-0">No production tickets yet.</p>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($tickets as $ticket): ?>
                        <div class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold text-capitalize"><?= htmlspecialchars($ticket['step'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-muted small">
                                        Assigned to <?= htmlspecialchars($ticket['assigned_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($ticket['assigned_email'])): ?>
                                            (<?= htmlspecialchars($ticket['assigned_email'], ENT_QUOTES, 'UTF-8') ?>)
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">
                                        Started: <?= htmlspecialchars($ticket['started_at'] ?? 'Not started', ENT_QUOTES, 'UTF-8') ?>
                                        · Finished: <?= htmlspecialchars($ticket['finished_at'] ?? 'Not finished', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <span class="badge bg-light text-dark text-uppercase">
                                    <?= htmlspecialchars($ticket['status'] ?? 'queued', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
