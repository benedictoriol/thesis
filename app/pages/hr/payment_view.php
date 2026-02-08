<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../handlers/payment_handler.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner', 'hr']);

$pageTitle = 'Payment Details';

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

function load_payment_audit_context(int $paymentId, ?string $shopIdColumn): ?array
{
    if (!$shopIdColumn) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT o.id AS order_id, o.' . $shopIdColumn . ' AS shop_id
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             WHERE p.id = :payment_id
             LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'order_id' => (int) $row['order_id'],
            'shop_id' => $row['shop_id'] !== null ? (int) $row['shop_id'] : null,
        ];
    } catch (PDOException $exception) {
        return null;
    }
}

$currentUser = current_user();
$errors = [];
$successMessage = flash_get('success');

$paymentId = (int) ($_GET['payment_id'] ?? 0);

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

$payment = null;
$proof = null;
$review = null;

if ($paymentId <= 0) {
    $errors[] = 'Invalid payment selection.';
} elseif (!$paymentsColumns || !$ordersColumns) {
    $errors[] = 'Payments are not available right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'review') {
            $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if (!in_array($decision, ['verified', 'rejected'], true)) {
                $errors[] = 'Please choose a valid decision.';
            } elseif ($decision === 'rejected' && $reason === '') {
                $errors[] = 'A rejection reason is required.';
            } else {
                try {
                    $stmt = db()->prepare('SELECT id, status FROM payments WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $paymentId]);
                    $paymentRow = $stmt->fetch();

                    if (!$paymentRow) {
                        $errors[] = 'Payment not found.';
                    } elseif (strtolower((string) $paymentRow['status']) !== 'pending_proof') {
                        $errors[] = 'Only payments pending proof can be reviewed.';
                    } else {
                        $finalReason = $reason !== '' ? $reason : 'Verified payment proof.';
                        $finalized = finalize_payment_review($paymentId, (int) $currentUser['id'], $decision, $finalReason);
                        if (!$finalized) {
                            throw new RuntimeException('Unable to finalize payment.');
                        }

                        $auditContext = load_payment_audit_context($paymentId, $shopIdColumn);
                        if ($auditContext && $auditContext['shop_id']) {
                            audit_log(
                                (int) $currentUser['id'],
                                $decision === 'verified' ? 'payment_verified' : 'payment_rejected',
                                'payments',
                                $paymentId,
                                [
                                    'shop_id' => $auditContext['shop_id'],
                                    'order_id' => $auditContext['order_id'],
                                    'decision' => $decision,
                                    'reason' => $finalReason,
                                ]
                            );
                        }

                        flash_set('success', 'Payment review saved.');
                        header('Location: /hr/payments/' . $paymentId);
                        exit;
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'Unable to save the review right now.';
                }
            }
        }

        if ($action === 'cod_received') {
            try {
                $stmt = db()->prepare('SELECT id, status, method FROM payments WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $paymentId]);
                $paymentRow = $stmt->fetch();

                if (!$paymentRow) {
                    $errors[] = 'Payment not found.';
                } elseif (strtolower((string) $paymentRow['method']) !== 'cod') {
                    $errors[] = 'COD confirmation is only available for COD payments.';
                } elseif (in_array(strtolower((string) $paymentRow['status']), ['verified', 'rejected'], true)) {
                    $errors[] = 'This payment has already been finalized.';
                } else {
                    $finalized = mark_cod_received($paymentId, (int) $currentUser['id'], 'COD received.');
                    if (!$finalized) {
                        throw new RuntimeException('Unable to finalize COD payment.');
                    }

                    $auditContext = load_payment_audit_context($paymentId, $shopIdColumn);
                    if ($auditContext && $auditContext['shop_id']) {
                        audit_log(
                            (int) $currentUser['id'],
                            'payment_verified',
                            'payments',
                            $paymentId,
                            [
                                'shop_id' => $auditContext['shop_id'],
                                'order_id' => $auditContext['order_id'],
                                'decision' => 'verified',
                                'reason' => 'COD received.',
                            ]
                        );
                    }

                    flash_set('success', 'COD marked as received.');
                    header('Location: /hr/payments/' . $paymentId);
                    exit;
                }
            } catch (Throwable $exception) {
                $errors[] = 'Unable to update COD status right now.';
            }
        }
    }
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
        $sql .= ' WHERE p.id = :payment_id LIMIT 1';

        $stmt = db()->prepare($sql);
        $stmt->execute(['payment_id' => $paymentId]);
        $payment = $stmt->fetch();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load payment details right now.';
    }
}

if ($payment && $proofsColumns) {
    try {
        $stmt = db()->prepare(
            'SELECT proof_path, uploaded_at
             FROM payment_proofs
             WHERE payment_id = :payment_id
             ORDER BY uploaded_at DESC
             LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $proof = $stmt->fetch();
    } catch (PDOException $exception) {
        $proof = null;
    }
}

if ($payment && $reviewsColumns) {
    try {
        $stmt = db()->prepare(
            'SELECT decision, reason, reviewed_at
             FROM payment_reviews
             WHERE payment_id = :payment_id
             ORDER BY reviewed_at DESC
             LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $review = $stmt->fetch();
    } catch (PDOException $exception) {
        $review = null;
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Payment Details</h1>
        <p class="text-muted mb-0">Verify proofs, reject with reasons, or confirm COD receipt.</p>
    </div>
    <div>
        <a class="btn btn-outline-secondary btn-sm" href="/hr/payments">Back to payments</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($payment): ?>
    <?php
    $status = strtolower((string) $payment['status']);
    $method = strtolower((string) $payment['method']);
    ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Payment Overview</h2>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <div class="text-muted small">Order</div>
                            <div class="fw-semibold">#<?= htmlspecialchars((string) $payment['order_id'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Client</div>
                            <div class="fw-semibold"><?= htmlspecialchars($payment['client_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Shop</div>
                            <div class="fw-semibold"><?= htmlspecialchars($payment['shop_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Method</div>
                            <div class="fw-semibold text-uppercase"><?= htmlspecialchars($method !== '' ? $method : 'n/a', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Amount</div>
                            <div class="fw-semibold">₱<?= htmlspecialchars(number_format((float) $payment['amount'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Status</div>
                            <div class="fw-semibold text-uppercase"><?= htmlspecialchars($status !== '' ? $status : 'unpaid', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-sm-12">
                            <div class="text-muted small">Created</div>
                            <div class="fw-semibold"><?= htmlspecialchars($payment['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Payment Proof</h2>
                    <?php if ($proof): ?>
                        <div class="mb-2">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($proof['proof_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">View full image</a>
                            <div class="small text-muted mt-1">Uploaded <?= htmlspecialchars($proof['uploaded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="border rounded p-2 bg-light">
                            <img src="<?= htmlspecialchars($proof['proof_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Payment proof" class="img-fluid rounded">
                        </div>
                    <?php else: ?>
                        <div class="text-muted">No payment proof uploaded yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Review Decision</h2>
                    <?php if ($review): ?>
                        <div class="text-muted small">Last decision</div>
                        <div class="fw-semibold text-uppercase mb-2"><?= htmlspecialchars($review['decision'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small">Reason</div>
                        <div><?= htmlspecialchars($review['reason'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small mt-2">Reviewed at</div>
                        <div><?= htmlspecialchars($review['reviewed_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php else: ?>
                        <div class="text-muted">No review has been submitted yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Actions</h2>
                    <?php if ($status === 'pending_proof'): ?>
                        <form method="post" class="d-flex flex-column gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="review">
                            <label class="form-label small" for="decision">Decision</label>
                            <select id="decision" name="decision" class="form-select form-select-sm" required>
                                <option value="">Choose</option>
                                <option value="verified">Verify</option>
                                <option value="rejected">Reject</option>
                            </select>
                            <label class="form-label small" for="reason">Reason (required for rejection)</label>
                            <textarea id="reason" name="reason" class="form-control form-control-sm" rows="3" placeholder="Provide the reason"></textarea>
                            <button class="btn btn-sm btn-primary" type="submit">Submit review</button>
                        </form>
                    <?php elseif ($method === 'cod' && $status !== 'verified' && $status !== 'rejected'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cod_received">
                            <p class="text-muted small">Confirm that the cash on delivery payment has been received.</p>
                            <button class="btn btn-sm btn-success" type="submit">Mark COD received</button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted">No actions available for this payment.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/footer.php';
?>
