<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';
require_once __DIR__ . '/../../includes/shop_availability.php';
require_once __DIR__ . '/../../handlers/order_handler.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'My Quotations';
$errors = [];
$quotations = [];
$quoteRequests = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $quoteId = (int) ($_POST['quote_id'] ?? 0);

        if (!in_array($action, ['accept_quote', 'reject_quote'], true) || $quoteId <= 0) {
            $errors[] = 'Invalid quote action.';
        } else {
            try {
                db()->beginTransaction();
                $stmt = db()->prepare(
                    'SELECT q.*, qr.id AS request_id, qr.status AS request_status, qr.shop_id, qr.client_user_id,
                            qr.source_type, qr.source_id
                     FROM quotes q
                     JOIN quote_requests qr ON qr.id = q.quote_request_id
                     WHERE q.id = :id
                     AND qr.client_user_id = :client_user_id
                     FOR UPDATE'
                );
                $stmt->execute([
                    'id' => $quoteId,
                    'client_user_id' => $user['id'],
                ]);
                $quote = $stmt->fetch();
                if (!$quote) {
                    throw new RuntimeException('Quote not found.');
                }

                if (!in_array($quote['status'], ['sent', 'revised'], true)) {
                    throw new RuntimeException('Quote is no longer available.');
                }

                if ($action === 'accept_quote') {
                    if (!shop_accepts((int) $quote['shop_id'], 'accepting_orders')) {
                        throw new RuntimeException('This shop is not accepting new orders right now.');
                    }
                    $update = db()->prepare('UPDATE quotes SET status = :status WHERE id = :id');
                    $update->execute([
                        'status' => 'accepted',
                        'id' => $quoteId,
                    ]);

                    $updateRequest = db()->prepare('UPDATE quote_requests SET status = :status WHERE id = :id');
                    $updateRequest->execute([
                        'status' => 'accepted',
                        'id' => $quote['request_id'],
                    ]);

                    $postId = $quote['source_type'] === 'client_post' ? (int) $quote['source_id'] : null;
                    $orderStmt = db()->prepare(
                        'INSERT INTO orders (client_user_id, shop_id, post_id, quote_id, status, created_at)
                         VALUES (:client_user_id, :shop_id, :post_id, :quote_id, :status, :created_at)'
                    );
                    $orderStmt->execute([
                        'client_user_id' => $user['id'],
                        'shop_id' => $quote['shop_id'],
                        'post_id' => $postId,
                        'quote_id' => $quoteId,
                        'status' => 'pending',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $orderId = (int) db()->lastInsertId();
                    ensure_order_number($orderId);
                    log_order_status($orderId, 'pending', 'Order created from accepted quote.', $user['id']);

                    $logStmt = db()->prepare(
                        'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
                         VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
                    );
                    $logStmt->execute([
                        'quote_id' => $quoteId,
                        'status' => 'accepted',
                        'changed_by_user_id' => $user['id'],
                        'note' => null,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);

                    db()->commit();
                    $successMessage = 'Quote accepted. An order has been created.';
                } else {
                    $update = db()->prepare('UPDATE quotes SET status = :status WHERE id = :id');
                    $update->execute([
                        'status' => 'rejected',
                        'id' => $quoteId,
                    ]);

                    $updateRequest = db()->prepare('UPDATE quote_requests SET status = :status WHERE id = :id');
                    $updateRequest->execute([
                        'status' => 'rejected',
                        'id' => $quote['request_id'],
                    ]);

                    $logStmt = db()->prepare(
                        'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
                         VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
                    );
                    $logStmt->execute([
                        'quote_id' => $quoteId,
                        'status' => 'rejected',
                        'changed_by_user_id' => $user['id'],
                        'note' => null,
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);

                    db()->commit();
                    $successMessage = 'Quote rejected.';
                }
            } catch (Throwable $exception) {
                db()->rollBack();
                $errors[] = 'Unable to update the quote right now.';
            }
        }
    }
}

try {
    $stmt = db()->prepare(
        'SELECT po.id, po.price, po.turnaround_days, po.status, po.created_at,
                cp.title AS post_title, s.name AS shop_name
         FROM post_offers po
         JOIN client_posts cp ON cp.id = po.post_id
         JOIN shops s ON s.id = po.shop_id
         WHERE cp.client_user_id = :client_user_id
         ORDER BY po.created_at DESC, po.id DESC'
    );
    $stmt->execute(['client_user_id' => $user['id']]);
    $quotations = $stmt->fetchAll();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load your quotations right now.';
}

if (table_exists('quote_requests')) {
    try {
        $stmt = db()->prepare(
            'SELECT q.id, q.price, q.turnaround_days, q.status, q.notes, q.created_at, q.valid_until,
                    qr.status AS request_status, qr.source_type, qr.source_id,
                    s.name AS shop_name, d.name AS design_name, cp.title AS post_title
             FROM quotes q
             JOIN quote_requests qr ON qr.id = q.quote_request_id
             JOIN shops s ON s.id = qr.shop_id
             LEFT JOIN custom_designs d ON d.id = qr.design_id
             LEFT JOIN client_posts cp ON cp.id = qr.source_id AND qr.source_type = \'client_post\'
             WHERE qr.client_user_id = :client_user_id
             ORDER BY q.created_at DESC, q.id DESC'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $quoteRequests = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load your quote requests right now.';
    }
}

require __DIR__ . '/../../includes/app_header.php';
?>
<h1 class="h4 mb-3">My Quotations</h1>
<p class="text-muted">Review pricing offers submitted by shops.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($quoteRequests): ?>
    <h2 class="h6 mt-4">Quote Requests</h2>
    <div class="list-group mb-4">
        <?php foreach ($quoteRequests as $quote): ?>
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">Quote #<?= (int) $quote['id'] ?> · <?= htmlspecialchars($quote['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small">Request status: <?= htmlspecialchars($quote['request_status'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($quote['design_name'])): ?>
                            <div class="text-muted small">Design: <?= htmlspecialchars($quote['design_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($quote['post_title'])): ?>
                            <div class="text-muted small">Post: <?= htmlspecialchars($quote['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($quote['turnaround_days'])): ?>
                            <div class="text-muted small">Turnaround: <?= (int) $quote['turnaround_days'] ?> days</div>
                        <?php endif; ?>
                        <?php if (!empty($quote['valid_until'])): ?>
                            <div class="text-muted small">Valid until <?= htmlspecialchars($quote['valid_until'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($quote['notes'])): ?>
                            <div class="small mt-2"><?= nl2br(htmlspecialchars($quote['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold">₱<?= number_format((float) $quote['price'], 2) ?></div>
                        <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="text-muted small mt-1">Sent <?= htmlspecialchars($quote['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (in_array($quote['status'], ['sent', 'revised'], true)): ?>
                            <form method="post" class="d-flex flex-column gap-2 mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="quote_id" value="<?= (int) $quote['id'] ?>">
                                <button class="btn btn-sm btn-success" type="submit" name="action" value="accept_quote" data-confirm="Accept this quote and move forward with the order?" data-confirm-title="Accept quote">Accept quote</button>
                                <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="reject_quote" data-confirm="Reject this quote? This action cannot be undone." data-confirm-title="Reject quote" data-confirm-button="Yes, reject">Reject quote</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h6 mt-4">Post Offers</h2>
<div class="list-group">
    <?php if (!$quotations): ?>
        <div class="list-group-item text-muted">No quotations available.</div>
    <?php endif; ?>
    <?php foreach ($quotations as $quote): ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">Quote #<?= (int) $quote['id'] ?> · <?= htmlspecialchars($quote['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small">Post: <?= htmlspecialchars($quote['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($quote['turnaround_days'])): ?>
                        <div class="text-muted small">Turnaround: <?= (int) $quote['turnaround_days'] ?> days</div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">₱<?= number_format((float) $quote['price'], 2) ?></div>
                    <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="text-muted small mt-1">Sent <?= htmlspecialchars($quote['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
require __DIR__ . '/../../includes/app_footer.php';
?>
