<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Quote Requests';
$errors = [];
$requests = [];

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$canQuote = false;
$staffColumns = table_columns('shop_staff');
if ($shop && $currentUser['role'] === 'hr' && in_array('can_quote', $staffColumns, true)) {
    try {
        $stmt = db()->prepare(
            'SELECT can_quote
             FROM shop_staff
             WHERE shop_id = :shop_id
             AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'shop_id' => $shop['id'],
            'user_id' => $currentUser['id'],
        ]);
        $canQuote = (int) $stmt->fetchColumn() === 1;
    } catch (PDOException $exception) {
        $errors[] = 'Unable to verify quote permissions right now.';
    }
}

if (!table_exists('quote_requests')) {
    $errors[] = 'Quote requests are not available right now.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT qr.*, u.fullname AS client_name, u.email AS client_email,
                    d.name AS design_name, cp.title AS post_title,
                    q.id AS quote_id, q.price AS quote_price, q.status AS quote_status, q.created_at AS quote_created_at
             FROM quote_requests qr
             JOIN users u ON u.id = qr.client_user_id
             LEFT JOIN custom_designs d ON d.id = qr.design_id
             LEFT JOIN client_posts cp ON cp.id = qr.source_id AND qr.source_type = \'client_post\'
             LEFT JOIN quotes q ON q.id = (
                SELECT q2.id FROM quotes q2
                WHERE q2.quote_request_id = qr.id
                ORDER BY q2.id DESC
                LIMIT 1
             )
             WHERE qr.shop_id = :shop_id
             ORDER BY qr.created_at DESC, qr.id DESC'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        $requests = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load quote requests right now.';
    }
}

require __DIR__ . '/../../../includes/app_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Quote Requests</h1>
        <p class="text-muted mb-0">Review incoming quote requests and prepare pricing.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="list-group">
        <?php if (!$requests): ?>
            <div class="list-group-item text-muted">No quote requests yet.</div>
        <?php endif; ?>
        <?php foreach ($requests as $request): ?>
            <?php
            $statusLabel = strtolower((string) $request['status']);
            $sourceLabel = $request['source_type'] === 'client_post' ? 'Client post' : 'Customization';
            $quoteStatus = $request['quote_status'] ?? null;
            ?>
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">Request #<?= (int) $request['id'] ?></div>
                        <div class="text-muted small">Client: <?= htmlspecialchars($request['client_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($request['client_email'])): ?>
                            <div class="text-muted small">Email: <?= htmlspecialchars($request['client_email'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="text-muted small">Source: <?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?> #<?= (int) $request['source_id'] ?></div>
                        <?php if (!empty($request['design_name'])): ?>
                            <div class="text-muted small">Design: <?= htmlspecialchars($request['design_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($request['post_title'])): ?>
                            <div class="text-muted small">Post: <?= htmlspecialchars($request['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($request['created_at'])): ?>
                            <div class="text-muted small">Submitted <?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($quoteStatus): ?>
                            <div class="small text-muted mt-1">Quote status: <?= htmlspecialchars($quoteStatus, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($request['quote_price'])): ?>
                            <div class="fw-semibold mt-1">₱<?= number_format((float) $request['quote_price'], 2) ?></div>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="/hr/quotes/requests/<?= (int) $request['id'] ?>">
                            <?= $canQuote ? 'Open request' : 'View request' ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/../../../includes/app_footer.php';
?>
