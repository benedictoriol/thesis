<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../handlers/order_handler.php';

require_role(['client']);

$pageTitle = 'Order Details';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

$currentUser = current_user();
$orderId = (int) ($_GET['order_id'] ?? 0);
$errors = [];
$successMessage = flash_get('success');

$ordersColumns = get_table_columns(db(), 'orders');
$orderItemsColumns = get_table_columns(db(), 'order_items');
$orderCustomizationsColumns = get_table_columns(db(), 'order_customization');
$orderStatusLogsColumns = get_table_columns(db(), 'order_status_logs');
$productsColumns = get_table_columns(db(), 'products');
$variantsColumns = get_table_columns(db(), 'product_variants');

$order = null;
$items = [];
$customizations = [];
$statusLogs = [];
$alreadyReviewed = false;

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
        $selectParts = ['o.id', 's.name AS shop_name', 's.id AS shop_id'];
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
             WHERE o.id = :order_id
             LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $errors[] = 'Order not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load this order right now.';
    }
}

if ($order && (int) $order['client_user_id'] !== (int) $currentUser['id']) {
    http_response_code(403);
    $errors[] = 'You do not have access to this order.';
    $order = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $statusValue = strtolower((string) ($order['order_status'] ?? ''));
        $nextStatus = null;
        $note = null;

        if ($action === 'cancel') {
            if ($statusValue !== 'pending') {
                $errors[] = 'Only pending orders can be cancelled.';
            } else {
                $nextStatus = 'cancelled';
                $note = 'Order cancelled by client.';
            }
        } elseif ($action === 'confirm') {
            if ($statusValue !== 'ready') {
                $errors[] = 'Only ready orders can be marked as completed.';
            } else {
                $nextStatus = 'completed';
                $note = 'Client confirmed completion.';
            }
        }

        if ($nextStatus && !$errors) {
            $updated = update_order_status($orderId, $statusValue, $nextStatus, (int) $currentUser['id'], $note);
            if (!$updated) {
                $errors[] = 'Unable to update order status right now.';
            } else {
                flash_set('success', 'Order updated successfully.');
                header('Location: /client/orders/' . $orderId);
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

if ($order) {
    try {
        $reviewStmt = db()->prepare('SELECT id FROM reviews WHERE order_id = :order_id LIMIT 1');
        $reviewStmt->execute(['order_id' => $orderId]);
        $alreadyReviewed = (bool) $reviewStmt->fetch();
    } catch (PDOException $exception) {
        $alreadyReviewed = false;
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Order #<?= htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0">Review order details and track progress.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="/client/orders/<?= htmlspecialchars((string) $orderId, ENT_QUOTES, 'UTF-8') ?>/payment">Payment</a>
        <a class="btn btn-outline-secondary" href="/client/orders">Back to orders</a>
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
                    <div class="text-muted small">Shop</div>
                    <div class="fw-semibold"><?= htmlspecialchars($order['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
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
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-outline-danger">Cancel order</button>
                    </form>
                <?php endif; ?>
                <?php if ($statusValue === 'ready'): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm">
                        <button type="submit" class="btn btn-outline-success">Confirm completion</button>
                    </form>
                <?php endif; ?>
                <?php if ($statusValue === 'completed'): ?>
                    <?php if ($alreadyReviewed): ?>
                        <span class="badge bg-success">Review submitted</span>
                    <?php else: ?>
                        <a class="btn btn-primary" href="/order/<?= (int) $orderId ?>/review">Leave review</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
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