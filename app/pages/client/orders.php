<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'My Orders';
$errors = [];
$orders = [];

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

$ordersColumns = get_table_columns(db(), 'orders');
$clientColumn = find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
$statusColumn = find_column($ordersColumns, ['status', 'order_status']);
$createdAtColumn = find_column($ordersColumns, ['created_at', 'order_date', 'placed_at']);
$totalColumn = find_column($ordersColumns, ['total_price', 'total', 'amount_total']);
$paymentStatusColumn = find_column($ordersColumns, ['payment_status', 'payment_state']);
$sourceTypeColumn = find_column($ordersColumns, ['source_type', 'source']);
$sourceIdColumn = find_column($ordersColumns, ['source_id', 'source_ref', 'source_reference']);
$postIdColumn = find_column($ordersColumns, ['post_id']);
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);

if (!$ordersColumns || !$clientColumn || !$shopIdColumn) {
    $errors[] = 'Orders are not available right now.';
}

try {
    if (!$errors) {
        $selectParts = ['o.id', 's.name AS shop_name'];
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
        $postJoinSql = '';
        if ($postIdColumn) {
            $selectParts[] = "o.$postIdColumn AS post_id";
            $selectParts[] = 'cp.title AS post_title';
            $postJoinSql = ' LEFT JOIN client_posts cp ON cp.id = o.' . $postIdColumn;
        }

        $orderBy = $createdAtColumn ? "o.$createdAtColumn DESC" : 'o.id DESC';
        $stmt = db()->prepare(
            'SELECT ' . implode(', ', $selectParts) . '
             FROM orders o
             JOIN shops s ON s.id = o.' . $shopIdColumn . $postJoinSql . '
             WHERE o.' . $clientColumn . ' = :client_user_id
             ORDER BY ' . $orderBy . ', o.id DESC'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $orders = $stmt->fetchAll();
    }
} catch (PDOException $exception) {
    $errors[] = 'Unable to load your orders right now.';
}

require __DIR__ . '/../../includes/app_header.php';
?>
<h1 class="h4 mb-3">My Orders</h1>
<p class="text-muted">Monitor the status of your orders and past requests.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="list-group">
    <?php if (!$orders): ?>
        <div class="list-group-item text-muted">No orders found.</div>
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
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">Order #<?= (int) $order['id'] ?></div>
                    <div class="text-muted small">Shop: <?= htmlspecialchars($order['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
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
                    <?php if (!empty($order['payment_status'])): ?>
                        <div class="text-muted small">Payment: <?= htmlspecialchars($order['payment_status'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($order['created_at'])): ?>
                        <div class="text-muted small mt-1">Placed <?= htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary mt-2" href="/client/orders/<?= (int) $order['id'] ?>">View</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
require __DIR__ . '/../../includes/app_footer.php';
?>