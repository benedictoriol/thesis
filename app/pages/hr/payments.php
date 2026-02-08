<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner', 'hr']);

$pageTitle = 'Payments Verification';

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

$errors = [];
$successMessage = flash_get('success');

$paymentsColumns = get_table_columns(db(), 'payments');
$proofsColumns = get_table_columns(db(), 'payment_proofs');
$reviewsColumns = get_table_columns(db(), 'payment_reviews');
$ordersColumns = get_table_columns(db(), 'orders');
$usersColumns = get_table_columns(db(), 'users');
$shopsColumns = get_table_columns(db(), 'shops');

$clientColumn = find_column($ordersColumns, ['client_user_id', 'client_id', 'customer_id', 'user_id']);
$shopIdColumn = find_column($ordersColumns, ['shop_id', 'vendor_id']);
$clientNameColumn = find_column($usersColumns, ['fullname', 'full_name', 'name']);
$shopNameColumn = find_column($shopsColumns, ['name']);

$payments = [];
$latestProofs = [];
$latestReviews = [];

if (!$paymentsColumns || !$ordersColumns) {
    $errors[] = 'Payments are not available right now.';
} elseif (!$reviewsColumns) {
    $errors[] = 'Payment reviews are not available right now.';
}

if (!$errors) {
    try {
        $selectParts = [
            'p.id',
            'p.order_id',
            'p.method',
            'p.amount',
            'p.status',
            'p.created_at',
        ];
        if ($clientColumn) {
            $selectParts[] = 'o.' . $clientColumn . ' AS client_user_id';
        }
        if ($shopIdColumn && $shopNameColumn) {
            $selectParts[] = 's.' . $shopNameColumn . ' AS shop_name';
        }
        if ($clientNameColumn) {
            $selectParts[] = 'u.' . $clientNameColumn . ' AS client_name';
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . '
            FROM payments p
            JOIN orders o ON o.id = p.order_id';
        if ($clientColumn) {
            $sql .= ' LEFT JOIN users u ON u.id = o.' . $clientColumn;
        }
        if ($shopIdColumn) {
            $sql .= ' LEFT JOIN shops s ON s.id = o.' . $shopIdColumn;
        }
        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute();
        $payments = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payments right now.';
    }
}

if ($payments && $proofsColumns) {
    try {
        $stmt = db()->query(
            'SELECT payment_id, proof_path, uploaded_at
             FROM payment_proofs
             ORDER BY uploaded_at DESC'
        );
        foreach ($stmt->fetchAll() as $proof) {
            if (!isset($latestProofs[$proof['payment_id']])) {
                $latestProofs[$proof['payment_id']] = $proof;
            }
        }
    } catch (PDOException $exception) {
        $latestProofs = [];
    }
}

if ($payments && $reviewsColumns) {
    try {
        $stmt = db()->query(
            'SELECT payment_id, decision, reason, reviewed_at
             FROM payment_reviews
             ORDER BY reviewed_at DESC'
        );
        foreach ($stmt->fetchAll() as $review) {
            if (!isset($latestReviews[$review['payment_id']])) {
                $latestReviews[$review['payment_id']] = $review;
            }
        }
    } catch (PDOException $exception) {
        $latestReviews = [];
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Payments Verification</h1>
        <p class="text-muted mb-0">Review payment submissions and COD receipts.</p>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if ($payments): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Order</th>
                        <th>Client</th>
                        <th>Shop</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Proof</th>
                        <th>Review</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $paymentId = (int) $payment['id'];
                        $status = strtolower((string) $payment['status']);
                        $proof = $latestProofs[$paymentId] ?? null;
                        $review = $latestReviews[$paymentId] ?? null;
                        ?>
                        <tr>
                            <td>#<?= htmlspecialchars((string) $payment['order_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($payment['client_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($payment['shop_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($payment['method'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-uppercase fw-semibold"><?= htmlspecialchars($status !== '' ? $status : 'unpaid', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($proof): ?>
                                    <a href="<?= htmlspecialchars($proof['proof_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">View</a>
                                    <div class="small text-muted"><?= htmlspecialchars($proof['uploaded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($review): ?>
                                    <div class="small text-muted">Last: <?= htmlspecialchars($review['decision'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($review['reason'])): ?>
                                        <div class="small">Reason: <?= htmlspecialchars($review['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">No review</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="/hr/payments/<?= htmlspecialchars((string) $paymentId, ENT_QUOTES, 'UTF-8') ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted">No payments available yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php
require __DIR__ . '/../../includes/app_footer.php';
?>