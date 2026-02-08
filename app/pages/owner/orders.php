<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Orders';
$errors = [];
$orders = [];

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

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
$clientColumn = find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
$statusColumn = find_column($ordersColumns, ['status', 'order_status']);
$createdAtColumn = find_column($ordersColumns, ['created_at', 'order_date', 'placed_at']);
$totalColumn = find_column($ordersColumns, ['total_price', 'total', 'amount_total']);
$sourceTypeColumn = find_column($ordersColumns, ['source_type', 'source']);
$sourceIdColumn = find_column($ordersColumns, ['source_id', 'source_ref', 'source_reference']);
$postIdColumn = find_column($ordersColumns, ['post_id']);
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);

if (!$ordersColumns || !$clientColumn || !$shopIdColumn) {
    $errors[] = 'Orders are not available right now.';
}

if (!$errors) {
    try {
        $selectParts = ['o.id', 'u.fullname AS client_name', 'u.email AS client_email'];
        if ($statusColumn) {
            $selectParts[] = "o.$statusColumn AS order_status";
        }
        if ($createdAtColumn) {
            $selectParts[] = "o.$createdAtColumn AS created_at";
        }
        if ($totalColumn) {
            $selectParts[] = "o.$totalColumn AS total_price";
        }
        if ($sourceTypeColumn) {
            $selectParts[] = "o.$sourceTypeColumn AS source_type";
        }
        if ($sourceIdColumn) {
            $selectParts[] = "o.$sourceIdColumn AS source_id";
        }
        $postJoinSql = '';
        if ($postIdColumn) {
            $selectParts[] = "o.$postIdColumn AS post_id";
            $selectParts[] = 'cp.title AS post_title';
            $postJoinSql = ' LEFT JOIN client_posts cp ON cp.id = o.' . $postIdColumn;
        }
        if (table_exists('order_assignments')) {
            $selectParts[] = '(SELECT COUNT(*) FROM order_assignments oa WHERE oa.order_id = o.id) AS assignment_count';
        }
        if (table_exists('order_job_tickets')) {
            $selectParts[] = '(SELECT COUNT(*) FROM order_job_tickets oj WHERE oj.order_id = o.id) AS ticket_count';
        }
        if (table_exists('order_proofs')) {
            $selectParts[] = '(SELECT COUNT(*) FROM order_proofs op WHERE op.order_id = o.id) AS proof_count';
        }

        $orderBy = $createdAtColumn ? "o.$createdAtColumn DESC" : 'o.id DESC';
        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM orders o
             JOIN users u ON u.id = o.' . $clientColumn . $postJoinSql . '
             WHERE o.' . $shopIdColumn . ' = :shop_id
             ORDER BY ' . $orderBy . ', o.id DESC'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $orders = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load orders right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Orders</h1>
        <p class="text-muted mb-0">Review incoming orders and manage active production.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="list-group">
        <?php if (!$orders): ?>
            <div class="list-group-item text-muted">No orders yet.</div>
        <?php endif; ?>
        <?php foreach ($orders as $order): ?>
            <?php
            $statusValue = strtolower((string) ($order['order_status'] ?? ''));
            $statusLabel = $statusValue !== '' ? $statusValue : 'unknown';
            $sourceType = strtolower((string) ($order['source_type'] ?? ''));
            $sourceLabel = '';
            if ($sourceType !== '') {
                $sourceLabel = match ($sourceType) {
                    'catalog' => 'Catalog purchase',
                    'quote' => 'Quote request',
                    'post_offer' => 'Post offer',
                    default => ucfirst($sourceType),
                };
            } elseif (!empty($order['post_title'])) {
                $sourceLabel = 'Client post';
            }
            ?>
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">Order #<?= (int) $order['id'] ?></div>
                        <div class="text-muted small">Client: <?= htmlspecialchars($order['client_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($order['client_email'])): ?>
                            <div class="text-muted small">Email: <?= htmlspecialchars($order['client_email'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($sourceLabel !== ''): ?>
                            <div class="text-muted small">Source: <?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($order['source_id'])): ?>
                                    #<?= (int) $order['source_id'] ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($order['post_title'])): ?>
                            <div class="text-muted small">Post: <?= htmlspecialchars($order['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (isset($order['total_price'])): ?>
                            <div class="text-muted small">Total: <?= htmlspecialchars(number_format((float) $order['total_price'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($order['created_at'])): ?>
                            <div class="text-muted small mt-1">Placed <?= htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2 mt-2 justify-content-end">
                            <?php if (isset($order['assignment_count'])): ?>
                                <span class="badge bg-light text-dark">Assignments: <?= (int) $order['assignment_count'] ?></span>
                            <?php endif; ?>
                            <?php if (isset($order['ticket_count'])): ?>
                                <span class="badge bg-light text-dark">Tickets: <?= (int) $order['ticket_count'] ?></span>
                            <?php endif; ?>
                            <?php if (isset($order['proof_count'])): ?>
                                <span class="badge bg-light text-dark">Proofs: <?= (int) $order['proof_count'] ?></span>
                            <?php endif; ?>
                        </div>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="/owner/orders/<?= (int) $order['id'] ?>">View order</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
require __DIR__ . '/../../includes/app_footer.php';
?>