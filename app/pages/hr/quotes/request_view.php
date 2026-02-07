<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Quote Request';
$errors = [];
$request = null;
$design = null;
$designLayers = [];
$quotes = [];
$successMessage = '';

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

$requestId = (int) ($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    $errors[] = 'Invalid request selected.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT qr.*, u.fullname AS client_name, u.email AS client_email,
                    d.name AS design_name, d.item_type AS design_item_type, d.preview_path AS design_preview,
                    cp.title AS post_title
             FROM quote_requests qr
             JOIN users u ON u.id = qr.client_user_id
             LEFT JOIN custom_designs d ON d.id = qr.design_id
             LEFT JOIN client_posts cp ON cp.id = qr.source_id AND qr.source_type = \'client_post\'
             WHERE qr.id = :id
             AND qr.shop_id = :shop_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $requestId,
            'shop_id' => $shop['id'],
        ]);
        $request = $stmt->fetch() ?: null;
        if (!$request) {
            $errors[] = 'Quote request not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load this quote request right now.';
    }
}

if ($request && !empty($request['design_id'])) {
    try {
        $stmt = db()->prepare('SELECT * FROM custom_designs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $request['design_id']]);
        $design = $stmt->fetch() ?: null;

        if ($design) {
            $layerStmt = db()->prepare(
                'SELECT * FROM custom_design_layers WHERE design_id = :design_id ORDER BY id ASC'
            );
            $layerStmt->execute(['design_id' => $request['design_id']]);
            $designLayers = $layerStmt->fetchAll();
        }
    } catch (PDOException $exception) {
        $design = null;
        $designLayers = [];
    }
}

if ($request) {
    try {
        $stmt = db()->prepare(
            'SELECT q.*, u.fullname AS quoted_by_name
             FROM quotes q
             JOIN users u ON u.id = q.quoted_by_user_id
             WHERE q.quote_request_id = :request_id
             ORDER BY q.id DESC'
        );
        $stmt->execute(['request_id' => $requestId]);
        $quotes = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $quotes = [];
    }
}

$latestQuote = $quotes[0] ?? null;
$isLocked = $request && ($request['status'] === 'accepted' || ($latestQuote && $latestQuote['status'] === 'accepted'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (!$canQuote) {
        $errors[] = 'You do not have permission to send quotes.';
    } elseif ($isLocked) {
        $errors[] = 'This quote is locked because it has already been accepted.';
    } elseif ($latestQuote) {
        $errors[] = 'This request already has a quote. Use revise instead.';
    } else {
        $price = (float) ($_POST['price'] ?? 0);
        $turnaroundDays = (int) ($_POST['turnaround_days'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $validityDays = (int) ($_POST['validity_days'] ?? 0);

        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($turnaroundDays <= 0) {
            $errors[] = 'Turnaround days must be at least 1.';
        }

        if (!$errors) {
            $validUntil = null;
            if ($validityDays > 0) {
                $validUntil = gmdate('Y-m-d H:i:s', strtotime('+' . $validityDays . ' days'));
            }

            try {
                db()->beginTransaction();
                $stmt = db()->prepare(
                    'INSERT INTO quotes
                        (quote_request_id, quoted_by_user_id, price, turnaround_days, notes, valid_until, status, created_at)
                     VALUES
                        (:quote_request_id, :quoted_by_user_id, :price, :turnaround_days, :notes, :valid_until, :status, :created_at)'
                );
                $stmt->execute([
                    'quote_request_id' => $requestId,
                    'quoted_by_user_id' => $currentUser['id'],
                    'price' => $price,
                    'turnaround_days' => $turnaroundDays,
                    'notes' => $notes !== '' ? $notes : null,
                    'valid_until' => $validUntil,
                    'status' => 'sent',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $quoteId = (int) db()->lastInsertId();

                $stmt = db()->prepare(
                    'UPDATE quote_requests SET status = :status WHERE id = :id'
                );
                $stmt->execute([
                    'status' => 'quoted',
                    'id' => $requestId,
                ]);

                $logStmt = db()->prepare(
                    'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
                     VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
                );
                $logStmt->execute([
                    'quote_id' => $quoteId,
                    'status' => 'sent',
                    'changed_by_user_id' => $currentUser['id'],
                    'note' => $notes !== '' ? $notes : null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);

                audit_log(
                    (int) $currentUser['id'],
                    'create_quote',
                    'quotes',
                    $quoteId,
                    [
                        'shop_id' => $shop['id'],
                        'request_id' => $requestId,
                        'price' => $price,
                        'turnaround_days' => $turnaroundDays,
                        'valid_until' => $validUntil,
                    ]
                );

                db()->commit();
                $successMessage = 'Quote sent successfully.';
                header('Location: /hr/quotes/requests/' . $requestId);
                exit;
            } catch (Throwable $exception) {
                db()->rollBack();
                $errors[] = 'Unable to send the quote right now.';
            }
        }
    }
}

require __DIR__ . '/../../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Quote Request #<?= $request ? (int) $request['id'] : 0 ?></h1>
        <p class="text-muted mb-0">Review the request details and send pricing.</p>
    </div>
    <div class="text-end">
        <a class="btn btn-outline-secondary btn-sm" href="/hr/quotes/requests">Back to requests</a>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($request && !$errors): ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Client: <?= htmlspecialchars($request['client_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($request['client_email'])): ?>
                        <div class="text-muted small">Email: <?= htmlspecialchars($request['client_email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="text-muted small">
                        Source:
                        <?= htmlspecialchars($request['source_type'] === 'client_post' ? 'Client post' : 'Customization', ENT_QUOTES, 'UTF-8') ?>
                        #<?= (int) $request['source_id'] ?>
                    </div>
                    <?php if (!empty($request['post_title'])): ?>
                        <div class="text-muted small">Post: <?= htmlspecialchars($request['post_title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="text-muted small">Status: <?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($request['created_at'])): ?>
                        <div class="text-muted small">Submitted <?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($request['notes'])): ?>
                    <div class="bg-light border rounded p-3">
                        <div class="fw-semibold small">Client notes</div>
                        <div class="small"><?= nl2br(htmlspecialchars($request['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($design): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <?php if (!empty($design['preview_path'])): ?>
                        <img src="<?= htmlspecialchars($design['preview_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?>"
                             class="rounded border" style="width: 120px; height: 120px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-secondary-subtle rounded" style="width: 120px; height: 120px;"></div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small">Item type: <?= htmlspecialchars(strtoupper($design['item_type']), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($design['created_at'])): ?>
                            <div class="text-muted small">Created <?= htmlspecialchars($design['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary mt-2" href="/client/designs/<?= (int) $design['id'] ?>">View design</a>
                    </div>
                </div>
                <?php if ($designLayers): ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Content</th>
                                    <th>Position</th>
                                    <th>Scale</th>
                                    <th>Rotation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($designLayers as $layer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($layer['layer_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-truncate" style="max-width: 200px;"><?= htmlspecialchars($layer['content'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($layer['x'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($layer['y'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($layer['scale'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($layer['rotation'], ENT_QUOTES, 'UTF-8') ?>°</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Quotes</h2>
            <?php if (!$quotes): ?>
                <p class="text-muted mb-0">No quotes sent yet.</p>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($quotes as $quote): ?>
                        <div class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold">Quote #<?= (int) $quote['id'] ?></div>
                                    <div class="text-muted small">Prepared by <?= htmlspecialchars($quote['quoted_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($quote['notes'])): ?>
                                        <div class="small"><?= nl2br(htmlspecialchars($quote['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($quote['valid_until'])): ?>
                                        <div class="text-muted small">Valid until <?= htmlspecialchars($quote['valid_until'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="fw-semibold">₱<?= number_format((float) $quote['price'], 2) ?></div>
                                    <div class="text-muted small">Turnaround: <?= (int) $quote['turnaround_days'] ?> days</div>
                                    <span class="badge text-bg-secondary text-uppercase"><?= htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($quote['created_at'])): ?>
                                        <div class="text-muted small mt-1">Sent <?= htmlspecialchars($quote['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($canQuote && !$isLocked): ?>
                                        <a class="btn btn-sm btn-outline-primary mt-2" href="/hr/quotes/<?= (int) $quote['id'] ?>/revise">Revise</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canQuote && !$isLocked && !$latestQuote): ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-3">Send Quote</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="price">Price</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="price" name="price" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="turnaround_days">Turnaround days</label>
                            <input type="number" min="1" class="form-control" id="turnaround_days" name="turnaround_days" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="validity_days">Validity days (optional)</label>
                            <input type="number" min="1" class="form-control" id="validity_days" name="validity_days">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Send quote</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
require __DIR__ . '/../../../includes/footer.php';
?>
