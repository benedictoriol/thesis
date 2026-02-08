<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';
require_once __DIR__ . '/../../handlers/order_handler.php';

require_role(['owner', 'hr']);

$pageTitle = 'Order Details';

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$currentUser = current_user();
$orderId = (int) ($_GET['order_id'] ?? 0);
$errors = [];
$successMessage = flash_get('success');

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
$orderItemsColumns = table_columns('order_items');
$orderCustomizationsColumns = table_columns('order_customization');
$orderStatusLogsColumns = table_columns('order_status_logs');
$productsColumns = table_columns('products');
$variantsColumns = table_columns('product_variants');

$order = null;
$items = [];
$customizations = [];
$statusLogs = [];
$assignments = [];
$employees = [];
$jobTickets = [];
$proofs = [];

$clientColumn = find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
$statusColumn = find_column($ordersColumns, ['status', 'order_status']);
$createdAtColumn = find_column($ordersColumns, ['created_at', 'order_date', 'placed_at']);
$totalColumn = find_column($ordersColumns, ['total_price', 'total', 'amount_total']);
$paymentStatusColumn = find_column($ordersColumns, ['payment_status', 'payment_state']);
$sourceTypeColumn = find_column($ordersColumns, ['source_type', 'source']);
$sourceIdColumn = find_column($ordersColumns, ['source_id', 'source_ref', 'source_reference']);
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);

if ($orderId <= 0) {
    $errors[] = 'Invalid order selection.';
} elseif (!$ordersColumns || !$clientColumn || !$shopIdColumn) {
    $errors[] = 'Orders are not available right now.';
}

if (!$errors) {
    try {
        $selectParts = ['o.id', 's.name AS shop_name', 's.id AS shop_id', 'u.fullname AS client_name', 'u.email AS client_email'];
        $selectParts[] = "o.$clientColumn AS client_user_id";
        if ($statusColumn) {
            $selectParts[] = "o.$statusColumn AS order_status";
        }
        if ($createdAtColumn) {
            $selectParts[] = "o.$createdAtColumn AS created_at";
        }
        if ($totalColumn) {
            $selectParts[] = "o.$totalColumn AS total_price";
        }
        if ($paymentStatusColumn) {
            $selectParts[] = "o.$paymentStatusColumn AS payment_status";
        }
        if ($sourceTypeColumn) {
            $selectParts[] = "o.$sourceTypeColumn AS source_type";
        }
        if ($sourceIdColumn) {
            $selectParts[] = "o.$sourceIdColumn AS source_id";
        }

        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM orders o
             JOIN shops s ON s.id = o.' . $shopIdColumn . '
             JOIN users u ON u.id = o.' . $clientColumn . '
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
    } else {
        $action = $_POST['action'] ?? '';
        $statusValue = strtolower((string) ($order['order_status'] ?? ''));
        $nextStatus = null;
        $note = null;

        if ($action === 'accept') {
            if ($statusValue !== 'pending') {
                $errors[] = 'Only pending orders can be accepted.';
            } else {
                $nextStatus = 'in_progress';
                $note = 'Order accepted by shop.';
            }
        } elseif ($action === 'reject') {
            if ($statusValue !== 'pending') {
                $errors[] = 'Only pending orders can be rejected.';
            } else {
                $nextStatus = 'rejected';
                $note = 'Order rejected by shop.';
            }
        } elseif ($action === 'mark_ready') {
            if (!in_array($statusValue, ['in_progress', 'accepted'], true)) {
                $errors[] = 'Only in-progress orders can be marked as ready.';
            } else {
                $nextStatus = 'ready';
                $note = 'Order marked as ready.';
            }
        } elseif ($action === 'mark_completed') {
            if ($statusValue !== 'ready') {
                $errors[] = 'Only ready orders can be marked as completed.';
            } else {
                $nextStatus = 'completed';
                $note = 'Order marked as completed.';
            }
        } elseif ($action === 'assign_employee') {
            if (!table_exists('order_assignments')) {
                $errors[] = 'Order assignments are not available right now.';
            } else {
                $staffUserId = (int) ($_POST['staff_user_id'] ?? 0);
                if ($staffUserId <= 0) {
                    $errors[] = 'Select a valid employee to assign.';
                } else {
                    try {
                        $stmt = db()->prepare(
                            'SELECT ss.user_id
                             FROM shop_staff ss
                             WHERE ss.shop_id = :shop_id
                             AND ss.user_id = :user_id
                             AND ss.role = :role
                             LIMIT 1'
                        );
                        $stmt->execute([
                            'shop_id' => $shop['id'],
                            'user_id' => $staffUserId,
                            'role' => 'employee',
                        ]);
                        $staffExists = (bool) $stmt->fetchColumn();
                        if (!$staffExists) {
                            $errors[] = 'Selected employee is not available for this shop.';
                        } else {
                            $insert = db()->prepare(
                                'INSERT INTO order_assignments (order_id, staff_user_id, assigned_by_user_id, assigned_at)
                                 VALUES (:order_id, :staff_user_id, :assigned_by_user_id, :assigned_at)'
                            );
                            $insert->execute([
                                'order_id' => $orderId,
                                'staff_user_id' => $staffUserId,
                                'assigned_by_user_id' => $currentUser['id'],
                                'assigned_at' => gmdate('Y-m-d H:i:s'),
                            ]);
                            flash_set('success', 'Employee assigned successfully.');
                            header('Location: /owner/orders/' . $orderId);
                            exit;
                        }
                    } catch (PDOException $exception) {
                        $errors[] = 'Unable to assign employee right now.';
                    }
                }
            }
        } elseif ($action === 'remove_assignment') {
            if (!table_exists('order_assignments')) {
                $errors[] = 'Order assignments are not available right now.';
            } else {
                $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
                if ($assignmentId <= 0) {
                    $errors[] = 'Invalid assignment selection.';
                } else {
                    try {
                        $stmt = db()->prepare(
                            'DELETE oa FROM order_assignments oa
                             JOIN shop_staff ss ON ss.user_id = oa.staff_user_id
                             WHERE oa.id = :assignment_id
                             AND oa.order_id = :order_id
                             AND ss.shop_id = :shop_id'
                        );
                        $stmt->execute([
                            'assignment_id' => $assignmentId,
                            'order_id' => $orderId,
                            'shop_id' => $shop['id'],
                        ]);
                        flash_set('success', 'Assignment removed.');
                        header('Location: /owner/orders/' . $orderId);
                        exit;
                    } catch (PDOException $exception) {
                        $errors[] = 'Unable to remove assignment right now.';
                    }
                }
            }
        } elseif ($action === 'generate_ticket') {
            if (!table_exists('order_job_tickets')) {
                $errors[] = 'Job tickets are not available right now.';
            } else {
                $title = trim((string) ($_POST['ticket_title'] ?? ''));
                $notes = trim((string) ($_POST['ticket_notes'] ?? ''));
                try {
                    $ticketCode = 'JT-' . strtoupper(bin2hex(random_bytes(4)));
                    $stmt = db()->prepare(
                        'INSERT INTO order_job_tickets (order_id, ticket_code, title, notes, status, created_by_user_id, created_at)
                         VALUES (:order_id, :ticket_code, :title, :notes, :status, :created_by_user_id, :created_at)'
                    );
                    $stmt->execute([
                        'order_id' => $orderId,
                        'ticket_code' => $ticketCode,
                        'title' => $title !== '' ? $title : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'status' => 'open',
                        'created_by_user_id' => $currentUser['id'],
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    flash_set('success', 'Job ticket generated.');
                    header('Location: /owner/orders/' . $orderId);
                    exit;
                } catch (Throwable $exception) {
                    $errors[] = 'Unable to generate a job ticket right now.';
                }
            }
        } elseif ($action === 'upload_proof') {
            if (!table_exists('order_proofs')) {
                $errors[] = 'Proof uploads are not available right now.';
            } else {
                $file = $_FILES['proof_image'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Proof upload failed.';
                } else {
                    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (!in_array($extension, $allowedExtensions, true)) {
                        $errors[] = 'Upload a valid image file (JPG, PNG, GIF, WEBP).';
                    } else {
                        $uploadDir = __DIR__ . '/../../../public/uploads/orders/' . $orderId . '/proofs';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }
                        $filename = sprintf('proof-%s.%s', bin2hex(random_bytes(8)), $extension);
                        $destination = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $errors[] = 'Unable to save the proof image.';
                        } else {
                            $relativePath = '/uploads/orders/' . $orderId . '/proofs/' . $filename;
                            try {
                                $stmt = db()->prepare(
                                    'INSERT INTO order_proofs (order_id, proof_path, uploaded_by_user_id, uploaded_at)
                                     VALUES (:order_id, :proof_path, :uploaded_by_user_id, :uploaded_at)'
                                );
                                $stmt->execute([
                                    'order_id' => $orderId,
                                    'proof_path' => $relativePath,
                                    'uploaded_by_user_id' => $currentUser['id'],
                                    'uploaded_at' => gmdate('Y-m-d H:i:s'),
                                ]);
                                flash_set('success', 'Proof uploaded successfully.');
                                header('Location: /owner/orders/' . $orderId);
                                exit;
                            } catch (PDOException $exception) {
                                $errors[] = 'Unable to record the proof image.';
                            }
                        }
                    }
                }
            }
        }

        if ($nextStatus && !$errors) {
            $updated = update_order_status($orderId, $statusValue, $nextStatus, (int) $currentUser['id'], $note);
            if (!$updated) {
                $errors[] = 'Unable to update order status right now.';
            } else {
                if ($shop) {
                    $auditMeta = [
                        'shop_id' => $shop['id'],
                        'from_status' => $statusValue,
                        'to_status' => $nextStatus,
                    ];
                    if ($note) {
                        $auditMeta['note'] = $note;
                    }

                        if ($action === 'accept') {
                        audit_log((int) $currentUser['id'], 'approve_order', 'orders', $orderId, $auditMeta);
                    } elseif ($action === 'reject') {
                        audit_log((int) $currentUser['id'], 'reject_order', 'orders', $orderId, $auditMeta);
                    }

                    audit_log((int) $currentUser['id'], 'order_status_change', 'orders', $orderId, $auditMeta);
                }
                
                flash_set('success', 'Order updated successfully.');
                header('Location: /owner/orders/' . $orderId);
                exit;
            }
        }
    }
}

if ($order && $orderItemsColumns) {
    try {
        $orderIdColumn = find_column($orderItemsColumns, ['order_id']);
        if ($orderIdColumn) {
            $selectParts = ['oi.id'];
            $selectParts[] = "oi.$orderIdColumn AS order_id";
            $productIdColumn = find_column($orderItemsColumns, ['product_id']);
            $variantIdColumn = find_column($orderItemsColumns, ['variant_id']);
            $qtyColumn = find_column($orderItemsColumns, ['qty', 'quantity']);
            $unitPriceColumn = find_column($orderItemsColumns, ['unit_price', 'price']);
            $notesColumn = find_column($orderItemsColumns, ['notes', 'note']);

            if ($productIdColumn) {
                $selectParts[] = "oi.$productIdColumn AS product_id";
            }
            if ($variantIdColumn) {
                $selectParts[] = "oi.$variantIdColumn AS variant_id";
            }
            if ($qtyColumn) {
                $selectParts[] = "oi.$qtyColumn AS qty";
            }
            if ($unitPriceColumn) {
                $selectParts[] = "oi.$unitPriceColumn AS unit_price";
            }
            if ($notesColumn) {
                $selectParts[] = "oi.$notesColumn AS notes";
            }

            $productNameColumn = find_column($productsColumns, ['name', 'title']);
            $variantNameColumn = find_column($variantsColumns, ['name', 'title']);

            $joins = '';
            if ($productIdColumn && $productNameColumn) {
                $selectParts[] = "p.$productNameColumn AS product_name";
                $joins .= ' LEFT JOIN products p ON p.id = oi.' . $productIdColumn;
            }
            if ($variantIdColumn && $variantNameColumn) {
                $selectParts[] = "pv.$variantNameColumn AS variant_name";
                $joins .= ' LEFT JOIN product_variants pv ON pv.id = oi.' . $variantIdColumn;
            }

            $stmt = db()->prepare(
                'SELECT ' . implode(', ', $selectParts) . '
                 FROM order_items oi' . $joins . '
                 WHERE oi.' . $orderIdColumn . ' = :order_id
                 ORDER BY oi.id ASC'
            );
            $stmt->execute(['order_id' => $orderId]);
            $items = $stmt->fetchAll();
        }
    } catch (PDOException $exception) {
        $items = [];
    }
}

if ($order && $orderCustomizationsColumns) {
    try {
        $customOrderIdColumn = find_column($orderCustomizationsColumns, ['order_id']);
        if ($customOrderIdColumn) {
            $selectParts = ['oc.id'];
            $designIdColumn = find_column($orderCustomizationsColumns, ['design_id']);
            $customizationColumn = find_column($orderCustomizationsColumns, ['customization_json', 'customization']);
            $previewColumn = find_column($orderCustomizationsColumns, ['preview_path', 'preview_url']);
            if ($designIdColumn) {
                $selectParts[] = "oc.$designIdColumn AS design_id";
            }
            if ($customizationColumn) {
                $selectParts[] = "oc.$customizationColumn AS customization_json";
            }
            if ($previewColumn) {
                $selectParts[] = "oc.$previewColumn AS preview_path";
            }

            $stmt = db()->prepare(
                'SELECT ' . implode(', ', $selectParts) . '
                 FROM order_customization oc
                 WHERE oc.' . $customOrderIdColumn . ' = :order_id
                 ORDER BY oc.id ASC'
            );
            $stmt->execute(['order_id' => $orderId]);
            $customizations = $stmt->fetchAll();
        }
    } catch (PDOException $exception) {
        $customizations = [];
    }
}

if ($order && $orderStatusLogsColumns) {
    try {
        $logOrderIdColumn = find_column($orderStatusLogsColumns, ['order_id']);
        if ($logOrderIdColumn) {
            $selectParts = ['osl.id'];
            $statusLogColumn = find_column($orderStatusLogsColumns, ['status', 'order_status']);
            $noteLogColumn = find_column($orderStatusLogsColumns, ['note', 'remarks']);
            $userLogColumn = find_column($orderStatusLogsColumns, ['changed_by_user_id', 'user_id']);
            $createdLogColumn = find_column($orderStatusLogsColumns, ['created_at', 'created_on']);

            if ($statusLogColumn) {
                $selectParts[] = "osl.$statusLogColumn AS status";
            }
            if ($noteLogColumn) {
                $selectParts[] = "osl.$noteLogColumn AS note";
            }
            if ($userLogColumn) {
                $selectParts[] = "osl.$userLogColumn AS changed_by_user_id";
            }
            if ($createdLogColumn) {
                $selectParts[] = "osl.$createdLogColumn AS created_at";
            }

            $stmt = db()->prepare(
                'SELECT ' . implode(', ', $selectParts) . '
                 FROM order_status_logs osl
                 WHERE osl.' . $logOrderIdColumn . ' = :order_id
                 ORDER BY osl.id DESC'
            );
            $stmt->execute(['order_id' => $orderId]);
            $statusLogs = $stmt->fetchAll();
        }
    } catch (PDOException $exception) {
        $statusLogs = [];
    }
}

if ($order && table_exists('order_assignments')) {
    try {
        $stmt = db()->prepare(
            'SELECT oa.id, oa.assigned_at, u.fullname, u.email
             FROM order_assignments oa
             JOIN users u ON u.id = oa.staff_user_id
             WHERE oa.order_id = :order_id
             ORDER BY oa.assigned_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $assignments = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $assignments = [];
    }
}

if ($order) {
    try {
        $selectParts = ['ss.user_id', 'u.fullname', 'u.email'];
        $filters = ['ss.shop_id = :shop_id', "ss.role = 'employee'"];
        $hasStaffStatus = in_array('status', $staffColumns, true);
        if ($hasStaffStatus) {
            $filters[] = "ss.status = 'active'";
        }

        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
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

if ($order && table_exists('order_job_tickets')) {
    try {
        $stmt = db()->prepare(
            'SELECT id, ticket_code, title, notes, status, created_at
             FROM order_job_tickets
             WHERE order_id = :order_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $jobTickets = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $jobTickets = [];
    }
}

if ($order && table_exists('order_proofs')) {
    try {
        $stmt = db()->prepare(
            'SELECT op.id, op.proof_path, op.uploaded_at, u.fullname AS uploaded_by
             FROM order_proofs op
             LEFT JOIN users u ON u.id = op.uploaded_by_user_id
             WHERE op.order_id = :order_id
             ORDER BY op.uploaded_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        $proofs = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $proofs = [];
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Order #<?= htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0">Manage production, assignments, and customer updates.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/owner/orders">Back to orders</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($order): ?>
    <?php
    $statusValue = strtolower((string) ($order['order_status'] ?? ''));
    $paymentValue = strtolower((string) ($order['payment_status'] ?? ''));
    $statusLabel = $statusValue !== '' ? $statusValue : 'unknown';
    $sourceType = strtolower((string) ($order['source_type'] ?? ''));
    $sourceLabel = $sourceType !== ''
        ? match ($sourceType) {
            'catalog' => 'Catalog purchase',
            'quote' => 'Quote request',
            'post_offer' => 'Post offer',
            default => ucfirst($sourceType),
        }
        : 'Manual order';
    ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Status</div>
                    <div class="fw-semibold text-uppercase"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Payment</div>
                    <div class="fw-semibold text-uppercase"><?= htmlspecialchars($paymentValue !== '' ? $paymentValue : 'unavailable', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Total</div>
                    <div class="fw-semibold">
                        <?php if (isset($order['total_price'])): ?>
                            <?= htmlspecialchars(number_format((float) $order['total_price'], 2), ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">Not available</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Client</div>
                    <div class="fw-semibold"><?= htmlspecialchars($order['client_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($order['client_email'])): ?>
                        <div class="text-muted small"><?= htmlspecialchars($order['client_email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Source</div>
                    <div class="fw-semibold">
                        <?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($order['source_id'])): ?>
                            #<?= (int) $order['source_id'] ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Placed</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($order['created_at'] ?? 'Not available'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Actions</h2>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($statusValue === 'pending'): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="accept">
                        <button type="submit" class="btn btn-outline-success" data-confirm="Accept this order and begin processing?" data-confirm-title="Accept order">Accept order</button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-outline-danger" data-confirm="Reject this order? This action cannot be undone." data-confirm-title="Reject order" data-confirm-button="Yes, reject">Reject order</button>
                    </form>
                <?php endif; ?>
                <?php if (in_array($statusValue, ['in_progress', 'accepted'], true)): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_ready">
                        <button type="submit" class="btn btn-outline-primary" data-confirm="Mark this order as ready for pickup or delivery?" data-confirm-title="Mark ready">Mark ready</button>
                    </form>
                <?php endif; ?>
                <?php if ($statusValue === 'ready'): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_completed">
                        <button type="submit" class="btn btn-outline-primary" data-confirm="Mark this order as completed? This will close the order." data-confirm-title="Mark completed">Mark completed</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Assignments</h2>
                    <?php if (!table_exists('order_assignments')): ?>
                        <p class="text-muted mb-0">Assignments are not configured yet.</p>
                    <?php else: ?>
                        <?php if ($assignments): ?>
                            <ul class="list-group list-group-flush mb-3">
                                <?php foreach ($assignments as $assignment): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($assignment['fullname'] ?? 'Employee', ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($assignment['email'])): ?>
                                                <div class="text-muted small"><?= htmlspecialchars($assignment['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                            <div class="text-muted small">Assigned <?= htmlspecialchars($assignment['assigned_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Remove this employee from the order assignment?" data-confirm-title="Remove assignment" data-confirm-button="Yes, remove">Remove</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">No employees assigned yet.</p>
                        <?php endif; ?>

                        <form method="post" class="row g-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="assign_employee">
                            <div class="col-12">
                                <label class="form-label" for="staff_user_id">Assign employee</label>
                                <select class="form-select" id="staff_user_id" name="staff_user_id">
                                    <option value="">Select an employee</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['user_id'] ?>">
                                            <?= htmlspecialchars($employee['fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($employee['email'])): ?>
                                                (<?= htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8') ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary" type="submit">Assign</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Job tickets</h2>
                    <a class="small d-inline-block mb-3" href="/owner/orders/<?= (int) $orderId ?>/tickets">View production tickets</a>
                    <?php if (!table_exists('order_job_tickets')): ?>
                        <p class="text-muted mb-0">Job tickets are not configured yet.</p>
                    <?php else: ?>
                        <?php if ($jobTickets): ?>
                            <ul class="list-group list-group-flush mb-3">
                                <?php foreach ($jobTickets as $ticket): ?>
                                    <li class="list-group-item px-0">
                                        <div class="fw-semibold"><?= htmlspecialchars($ticket['ticket_code'] ?? 'Ticket', ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($ticket['title'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($ticket['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($ticket['notes'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($ticket['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between text-muted small">
                                            <span>Status: <?= htmlspecialchars($ticket['status'] ?? 'open', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span><?= htmlspecialchars($ticket['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">No tickets generated yet.</p>
                        <?php endif; ?>
                        <form method="post" class="row g-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="generate_ticket">
                            <div class="col-12">
                                <label class="form-label" for="ticket_title">Ticket title</label>
                                <input class="form-control" id="ticket_title" name="ticket_title" placeholder="Optional ticket label">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="ticket_notes">Notes</label>
                                <textarea class="form-control" id="ticket_notes" name="ticket_notes" rows="3" placeholder="Instructions for production"></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary" type="submit">Generate ticket</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm my-4">
        <div class="card-body">
            <h2 class="h6">Proof images</h2>
            <?php if (!table_exists('order_proofs')): ?>
                <p class="text-muted mb-0">Proof uploads are not configured yet.</p>
            <?php else: ?>
                <?php if ($proofs): ?>
                    <div class="row g-3 mb-3">
                        <?php foreach ($proofs as $proof): ?>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <a href="<?= htmlspecialchars($proof['proof_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">View proof</a>
                                    <div class="text-muted small">Uploaded <?= htmlspecialchars($proof['uploaded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($proof['uploaded_by'])): ?>
                                        <div class="text-muted small">By <?= htmlspecialchars($proof['uploaded_by'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No proofs uploaded yet.</p>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_proof">
                    <div class="col-12">
                        <label class="form-label" for="proof_image">Upload proof image</label>
                        <input class="form-control" type="file" id="proof_image" name="proof_image" accept="image/*" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="submit">Upload proof</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Items</h2>
            <?php if (!$items): ?>
                <p class="text-muted mb-0">No items recorded for this order.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Unit price</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <?= htmlspecialchars($item['product_name'] ?? ('Product #' . (int) ($item['product_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <?php if (!empty($item['variant_id'])): ?>
                                            <div class="text-muted small">
                                                Variant: <?= htmlspecialchars($item['variant_name'] ?? ('#' . (int) $item['variant_id']), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($item['qty'] ?? '1'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= isset($item['unit_price'])
                                            ? htmlspecialchars(number_format((float) $item['unit_price'], 2), ENT_QUOTES, 'UTF-8')
                                            : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Customization</h2>
            <?php if (!$customizations): ?>
                <p class="text-muted mb-0">No customizations linked to this order.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($customizations as $customization): ?>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="fw-semibold mb-2">Design #<?= htmlspecialchars((string) ($customization['design_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if (!empty($customization['preview_path'])): ?>
                                    <img src="<?= htmlspecialchars($customization['preview_path'], ENT_QUOTES, 'UTF-8') ?>"
                                         alt="Customization preview"
                                         class="img-fluid rounded border mb-2">
                                <?php endif; ?>
                                <?php if (!empty($customization['customization_json'])): ?>
                                    <pre class="bg-light p-2 rounded small mb-0" style="white-space: pre-wrap;">
<?= htmlspecialchars((string) $customization['customization_json'], ENT_QUOTES, 'UTF-8') ?>
                                    </pre>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6">Status updates</h2>
            <?php if (!$statusLogs): ?>
                <p class="text-muted mb-0">No status updates yet.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($statusLogs as $log): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold text-uppercase"><?= htmlspecialchars((string) ($log['status'] ?? 'update'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($log['note'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars((string) $log['note'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($log['changed_by_user_id'])): ?>
                                        <div class="text-muted small">Updated by user #<?= (int) $log['changed_by_user_id'] ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars((string) ($log['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/footer.php';
?>