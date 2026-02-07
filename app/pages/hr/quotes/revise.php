<?php

require_once __DIR__ . '/../../../core/guard.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Revise Quote';
$errors = [];
$quote = null;
$request = null;
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
        if (!$canQuote) {
            $errors[] = 'You do not have permission to revise quotes.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to verify quote permissions right now.';
    }
}

$quoteId = (int) ($_GET['quote_id'] ?? 0);
if ($quoteId <= 0) {
    $errors[] = 'Invalid quote selected.';
}

if (!$errors) {
    try {
        $stmt = db()->prepare(
            'SELECT q.*, qr.id AS request_id, qr.status AS request_status, qr.shop_id,
                    u.fullname AS quoted_by_name
             FROM quotes q
             JOIN quote_requests qr ON qr.id = q.quote_request_id
             JOIN users u ON u.id = q.quoted_by_user_id
             WHERE q.id = :id
             AND qr.shop_id = :shop_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $quoteId,
            'shop_id' => $shop['id'],
        ]);
        $quote = $stmt->fetch() ?: null;
        if (!$quote) {
            $errors[] = 'Quote not found.';
        } else {
            $request = [
                'id' => $quote['request_id'],
                'status' => $quote['request_status'],
            ];
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load the quote right now.';
    }
}

$isLocked = $quote && ($quote['status'] === 'accepted' || $quote['request_status'] === 'accepted');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $quote) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (!$canQuote) {
        $errors[] = 'You do not have permission to revise quotes.';
    } elseif ($isLocked) {
        $errors[] = 'This quote is locked because it has already been accepted.';
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
                    'UPDATE quotes
                     SET price = :price,
                         turnaround_days = :turnaround_days,
                         notes = :notes,
                         valid_until = :valid_until,
                         status = :status
                     WHERE id = :id'
                );
                $stmt->execute([
                    'price' => $price,
                    'turnaround_days' => $turnaroundDays,
                    'notes' => $notes !== '' ? $notes : null,
                    'valid_until' => $validUntil,
                    'status' => 'revised',
                    'id' => $quoteId,
                ]);

                $stmt = db()->prepare(
                    'UPDATE quote_requests SET status = :status WHERE id = :id'
                );
                $stmt->execute([
                    'status' => 'quoted',
                    'id' => $quote['request_id'],
                ]);

                $logStmt = db()->prepare(
                    'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
                     VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
                );
                $logStmt->execute([
                    'quote_id' => $quoteId,
                    'status' => 'revised',
                    'changed_by_user_id' => $currentUser['id'],
                    'note' => $notes !== '' ? $notes : null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);

                audit_log(
                    (int) $currentUser['id'],
                    'revise_quote',
                    'quotes',
                    $quoteId,
                    [
                        'shop_id' => $shop['id'],
                        'request_id' => $quote['request_id'],
                        'price' => $price,
                        'turnaround_days' => $turnaroundDays,
                        'valid_until' => $validUntil,
                    ]
                );
                
                db()->commit();
                $successMessage = 'Quote revised successfully.';
                header('Location: /hr/quotes/requests/' . (int) $quote['request_id']);
                exit;
            } catch (Throwable $exception) {
                db()->rollBack();
                $errors[] = 'Unable to revise the quote right now.';
            }
        }
    }
}

$validityDaysValue = '';
if ($quote && !empty($quote['valid_until'])) {
    $seconds = strtotime($quote['valid_until']) - time();
    if ($seconds > 0) {
        $validityDaysValue = (string) max(1, (int) ceil($seconds / 86400));
    }
}

require __DIR__ . '/../../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Revise Quote #<?= $quote ? (int) $quote['id'] : 0 ?></h1>
        <p class="text-muted mb-0">Update pricing details before resending.</p>
    </div>
    <div class="text-end">
        <?php if ($quote): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/hr/quotes/requests/<?= (int) $quote['request_id'] ?>">Back to request</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary btn-sm" href="/hr/quotes/requests">Back to requests</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($quote && !$errors): ?>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="price">Price</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="price" name="price"
                               value="<?= htmlspecialchars((string) $quote['price'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="turnaround_days">Turnaround days</label>
                        <input type="number" min="1" class="form-control" id="turnaround_days" name="turnaround_days"
                               value="<?= htmlspecialchars((string) $quote['turnaround_days'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="validity_days">Validity days (optional)</label>
                        <input type="number" min="1" class="form-control" id="validity_days" name="validity_days"
                               value="<?= htmlspecialchars($validityDaysValue, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars((string) ($quote['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save revision</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/../../../includes/footer.php';
?>
