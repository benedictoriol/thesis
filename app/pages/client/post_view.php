<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/shop_availability.php';
require_once __DIR__ . '/../../handlers/post_handler.php';
require_once __DIR__ . '/../../handlers/order_handler.php';

require_role(['client', 'owner', 'hr', 'employee']);

$user = current_user();
$postId = (int) ($_GET['post_id'] ?? 0);
$pageTitle = 'Post Details';
$errors = [];
$successMessage = flash_get('success');

if ($postId <= 0) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

$itemTypes = [
    'tshirt' => 'T-Shirt',
    'cap' => 'Cap',
    'bag' => 'Bag',
    'logo' => 'Logo',
    'other' => 'Other',
];

$post = null;
try {
    $stmt = db()->prepare(
        'SELECT cp.*, u.fullname AS client_name, u.email AS client_email
         FROM client_posts cp
         JOIN users u ON u.id = cp.client_user_id
         WHERE cp.id = :post_id'
    );
    $stmt->execute(['post_id' => $postId]);
    $post = $stmt->fetch();
} catch (PDOException $exception) {
    $errors[] = 'Unable to load post details.';
}

if (!$post) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

if ($user['role'] === 'client' && (int) $post['client_user_id'] !== (int) $user['id']) {
    http_response_code(403);
    echo 'You do not have access to this post.';
    exit;
}

if ($post['status'] === 'open' && !empty($post['deadline_date'])) {
    $today = gmdate('Y-m-d');
    if ($post['deadline_date'] < $today) {
        try {
            $stmt = db()->prepare('UPDATE client_posts SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => 'expired',
                'id' => $post['id'],
            ]);
            $post['status'] = 'expired';
        } catch (PDOException $exception) {
            $errors[] = 'Unable to update post status.';
        }
    }
}

$staffShops = [];
if (in_array($user['role'], ['owner', 'hr', 'employee'], true)) {
    try {
        $staffShops = list_staff_shops((int) $user['id'], $user['role']);
    } catch (PDOException $exception) {
        $staffShops = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update' && $user['role'] === 'client') {
            if ($post['status'] !== 'open') {
                $errors[] = 'Only open posts can be edited.';
            }

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $itemType = trim($_POST['item_type'] ?? '');
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $budgetMinInput = trim($_POST['budget_min'] ?? '');
            $budgetMaxInput = trim($_POST['budget_max'] ?? '');
            $deadlineDate = trim($_POST['deadline_date'] ?? '');
            $townText = trim($_POST['town_text'] ?? '');

            $budgetMin = $budgetMinInput !== '' ? (float) $budgetMinInput : null;
            $budgetMax = $budgetMaxInput !== '' ? (float) $budgetMaxInput : null;

            if ($title === '') {
                $errors[] = 'Title is required.';
            }
            if ($description === '') {
                $errors[] = 'Description is required.';
            }
            if (!array_key_exists($itemType, $itemTypes)) {
                $errors[] = 'Please select a valid item type.';
            }
            if ($quantity <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }
            if ($budgetMin !== null && $budgetMin < 0) {
                $errors[] = 'Minimum budget must be zero or more.';
            }
            if ($budgetMax !== null && $budgetMax < 0) {
                $errors[] = 'Maximum budget must be zero or more.';
            }
            if ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax) {
                $errors[] = 'Minimum budget cannot exceed maximum budget.';
            }
            if ($deadlineDate !== '') {
                $dateCheck = DateTime::createFromFormat('Y-m-d', $deadlineDate);
                if (!$dateCheck || $dateCheck->format('Y-m-d') !== $deadlineDate) {
                    $errors[] = 'Deadline date must be in YYYY-MM-DD format.';
                }
            }

            if (!$errors) {
                try {
                    $stmt = db()->prepare(
                        'UPDATE client_posts
                         SET title = :title,
                             description = :description,
                             item_type = :item_type,
                             quantity = :quantity,
                             budget_min = :budget_min,
                             budget_max = :budget_max,
                             deadline_date = :deadline_date,
                             town_text = :town_text
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'title' => $title,
                        'description' => $description,
                        'item_type' => $itemType,
                        'quantity' => $quantity,
                        'budget_min' => $budgetMin,
                        'budget_max' => $budgetMax,
                        'deadline_date' => $deadlineDate !== '' ? $deadlineDate : null,
                        'town_text' => $townText !== '' ? $townText : null,
                        'id' => $post['id'],
                    ]);
                    flash_set('success', 'Post updated successfully.');
                    header('Location: /client/posts/' . $post['id']);
                    exit;
                } catch (PDOException $exception) {
                    $errors[] = 'Unable to update the post.';
                }
            }
        } elseif ($action === 'close' && $user['role'] === 'client') {
            if ($post['status'] !== 'open') {
                $errors[] = 'Only open posts can be closed.';
            } else {
                try {
                    $stmt = db()->prepare('UPDATE client_posts SET status = :status WHERE id = :id');
                    $stmt->execute([
                        'status' => 'closed',
                        'id' => $post['id'],
                    ]);
                    flash_set('success', 'Post closed successfully.');
                    header('Location: /client/posts/' . $post['id']);
                    exit;
                } catch (PDOException $exception) {
                    $errors[] = 'Unable to close the post.';
                }
            }
        } elseif ($action === 'send_offer' && in_array($user['role'], ['owner', 'hr', 'employee'], true)) {
            if ($post['status'] !== 'open') {
                $errors[] = 'Offers can only be sent on open posts.';
            }

            $offerPrice = (float) ($_POST['offer_price'] ?? 0);
            $turnaroundDays = (int) ($_POST['turnaround_days'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $shopId = (int) ($_POST['shop_id'] ?? 0);
            $allowedShopIds = array_map('intval', array_column($staffShops, 'id'));

            if ($offerPrice <= 0) {
                $errors[] = 'Offer price must be greater than zero.';
            }
            if ($turnaroundDays <= 0) {
                $errors[] = 'Turnaround days must be at least 1.';
            }
            if (!$shopId || !in_array($shopId, $allowedShopIds, true)) {
                $errors[] = 'Please choose a valid shop.';
            }

            if (!$errors) {
                try {
                    $stmt = db()->prepare(
                        'INSERT INTO post_offers (post_id, shop_id, offered_by_user_id, price, turnaround_days, notes, status, created_at)
                         VALUES (:post_id, :shop_id, :offered_by_user_id, :price, :turnaround_days, :notes, :status, :created_at)'
                    );
                    $stmt->execute([
                        'post_id' => $post['id'],
                        'shop_id' => $shopId,
                        'offered_by_user_id' => $user['id'],
                        'price' => $offerPrice,
                        'turnaround_days' => $turnaroundDays,
                        'notes' => $notes !== '' ? $notes : null,
                        'status' => 'sent',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    flash_set('success', 'Offer sent to the client.');
                    header('Location: /client/posts/' . $post['id']);
                    exit;
                } catch (PDOException $exception) {
                    $errors[] = 'Unable to send offer right now.';
                }
            }
        } elseif ($action === 'accept_offer' && $user['role'] === 'client') {
            if ($post['status'] !== 'open') {
                $errors[] = 'Only open posts can accept offers.';
            }

            $offerId = (int) ($_POST['offer_id'] ?? 0);
            if ($offerId <= 0) {
                $errors[] = 'Invalid offer selection.';
            }

            if (!$errors) {
                try {
                    db()->beginTransaction();
                    $stmt = db()->prepare('SELECT * FROM post_offers WHERE id = :id AND post_id = :post_id FOR UPDATE');
                    $stmt->execute([
                        'id' => $offerId,
                        'post_id' => $post['id'],
                    ]);
                    $offer = $stmt->fetch();

                    if (!$offer || $offer['status'] !== 'sent') {
                        throw new RuntimeException('Offer is no longer available.');
                    }
                    if (!shop_accepts((int) $offer['shop_id'], 'accepting_orders')) {
                        throw new RuntimeException('This shop is not accepting new orders right now.');
                    }

                    $stmt = db()->prepare('UPDATE post_offers SET status = :status WHERE id = :id');
                    $stmt->execute([
                        'status' => 'accepted',
                        'id' => $offerId,
                    ]);

                    $stmt = db()->prepare('UPDATE post_offers SET status = :status WHERE post_id = :post_id AND id != :id');
                    $stmt->execute([
                        'status' => 'rejected',
                        'post_id' => $post['id'],
                        'id' => $offerId,
                    ]);

                    $stmt = db()->prepare('UPDATE client_posts SET status = :status WHERE id = :id');
                    $stmt->execute([
                        'status' => 'converted',
                        'id' => $post['id'],
                    ]);

                    $stmt = db()->prepare(
                        'INSERT INTO orders (client_user_id, shop_id, post_id, offer_id, status, created_at)
                         VALUES (:client_user_id, :shop_id, :post_id, :offer_id, :status, :created_at)'
                    );
                    $stmt->execute([
                        'client_user_id' => $user['id'],
                        'shop_id' => $offer['shop_id'],
                        'post_id' => $post['id'],
                        'offer_id' => $offerId,
                        'status' => 'pending',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $orderId = (int) db()->lastInsertId();
                    ensure_order_number($orderId);
                    log_order_status($orderId, 'pending', 'Order created from accepted offer.', $user['id']);

                    db()->commit();
                    flash_set('success', 'Offer accepted. An order has been created.');
                    header('Location: /client/posts/' . $post['id']);
                    exit;
                } catch (Throwable $exception) {
                    db()->rollBack();
                    $errors[] = 'Unable to accept the offer right now.';
                }
            }
            } elseif ($action === 'reject_offer' && $user['role'] === 'client') {
            if ($post['status'] !== 'open') {
                $errors[] = 'Only open posts can reject offers.';
            }

            $offerId = (int) ($_POST['offer_id'] ?? 0);
            if ($offerId <= 0) {
                $errors[] = 'Invalid offer selection.';
            }

            if (!$errors) {
                try {
                    $stmt = db()->prepare(
                        'UPDATE post_offers
                         SET status = :status
                         WHERE id = :id
                         AND post_id = :post_id
                         AND status = :current_status'
                    );
                    $stmt->execute([
                        'status' => 'rejected',
                        'id' => $offerId,
                        'post_id' => $post['id'],
                        'current_status' => 'sent',
                    ]);

                    if ($stmt->rowCount() === 0) {
                        throw new RuntimeException('Offer is no longer available.');
                    }

                    flash_set('success', 'Offer rejected.');
                    header('Location: /client/posts/' . $post['id']);
                    exit;
                } catch (Throwable $exception) {
                    $errors[] = 'Unable to reject the offer right now.';
                }
            }
        }
    }
}

$files = [];
$designRefs = [];
$offers = [];

try {
    $stmt = db()->prepare('SELECT file_path FROM post_files WHERE post_id = :post_id ORDER BY id DESC');
    $stmt->execute(['post_id' => $post['id']]);
    $files = $stmt->fetchAll();
} catch (PDOException $exception) {
    $files = [];
}

try {
    $stmt = db()->prepare(
        'SELECT d.id, d.name, d.item_type, d.preview_path
         FROM post_design_refs pdr
         JOIN custom_designs d ON d.id = pdr.design_id
         WHERE pdr.post_id = :post_id'
    );
    $stmt->execute(['post_id' => $post['id']]);
    $designRefs = $stmt->fetchAll();
} catch (PDOException $exception) {
    $designRefs = [];
}

try {
    if ($user['role'] === 'client') {
        $stmt = db()->prepare(
            'SELECT po.*, s.name AS shop_name, u.fullname AS staff_name
             FROM post_offers po
             JOIN shops s ON s.id = po.shop_id
             JOIN users u ON u.id = po.offered_by_user_id
             WHERE po.post_id = :post_id
             ORDER BY po.created_at DESC, po.id DESC'
        );
        $stmt->execute(['post_id' => $post['id']]);
        $offers = $stmt->fetchAll();
    } elseif ($staffShops) {
        $shopIds = array_map('intval', array_column($staffShops, 'id'));
        $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
        $params = array_merge([$post['id']], $shopIds);
        $stmt = db()->prepare(
            "SELECT po.*, s.name AS shop_name, u.fullname AS staff_name
             FROM post_offers po
             JOIN shops s ON s.id = po.shop_id
             JOIN users u ON u.id = po.offered_by_user_id
             WHERE po.post_id = ?
             AND po.shop_id IN ($placeholders)
             ORDER BY po.created_at DESC, po.id DESC"
        );
        $stmt->execute($params);
        $offers = $stmt->fetchAll();
    }
} catch (PDOException $exception) {
    $offers = [];
}

$status = $post['status'] ?? 'open';
$badgeClass = match ($status) {
    'open' => 'bg-success',
    'closed' => 'bg-secondary',
    'expired' => 'bg-warning text-dark',
    'converted' => 'bg-primary',
    default => 'bg-light text-dark',
};

require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="text-muted small">Client: <?= htmlspecialchars($post['client_name'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="text-end">
        <span class="badge <?= $badgeClass ?> text-uppercase"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
        <div class="small text-muted mt-1">Posted <?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card mb-4">
    <div class="card-body">
        <div class="mb-2"><strong>Item:</strong> <?= htmlspecialchars(strtoupper($post['item_type']), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="mb-2"><strong>Quantity:</strong> <?= (int) $post['quantity'] ?></div>
        <div class="mb-2"><strong>Budget:</strong>
            <?php if ($post['budget_min'] === null && $post['budget_max'] === null): ?>
                Not specified
            <?php elseif ($post['budget_min'] !== null && $post['budget_max'] !== null): ?>
                <?= htmlspecialchars(number_format((float) $post['budget_min'], 2), ENT_QUOTES, 'UTF-8') ?>
                -
                <?= htmlspecialchars(number_format((float) $post['budget_max'], 2), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($post['budget_min'] !== null): ?>
                From <?= htmlspecialchars(number_format((float) $post['budget_min'], 2), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                Up to <?= htmlspecialchars(number_format((float) $post['budget_max'], 2), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
        <div class="mb-2"><strong>Deadline:</strong> <?= htmlspecialchars($post['deadline_date'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="mb-3"><strong>Location:</strong> <?= htmlspecialchars($post['town_text'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
        <div><?= nl2br(htmlspecialchars($post['description'], ENT_QUOTES, 'UTF-8')) ?></div>
    </div>
</div>

<?php if ($files): ?>
    <div class="mb-4">
        <h2 class="h6">Attachments</h2>
        <ul class="list-group">
            <?php foreach ($files as $file): ?>
                <li class="list-group-item">
                    <a href="<?= htmlspecialchars($file['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View attachment</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($designRefs): ?>
    <div class="mb-4">
        <h2 class="h6">Design references</h2>
        <div class="list-group">
            <?php foreach ($designRefs as $design): ?>
                <div class="list-group-item d-flex align-items-center gap-3">
                    <?php if (!empty($design['preview_path'])): ?>
                        <img src="<?= htmlspecialchars($design['preview_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Preview"
                             class="rounded border" style="width: 56px; height: 56px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-secondary-subtle rounded" style="width: 56px; height: 56px;"></div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($design['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small"><?= htmlspecialchars(strtoupper($design['item_type']), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($user['role'] === 'client' && $post['status'] === 'open'): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Edit post details</h2>
            <form method="post" class="mb-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update">
                <div class="mb-3">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control" id="title" name="title" value="<?= htmlspecialchars($_POST['title'] ?? $post['title'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4" required><?= htmlspecialchars($_POST['description'] ?? $post['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="item_type">Item type</label>
                        <select class="form-select" id="item_type" name="item_type" required>
                            <?php foreach ($itemTypes as $value => $label): ?>
                                <?php $selectedType = $_POST['item_type'] ?? $post['item_type']; ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $selectedType ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quantity">Quantity</label>
                        <input class="form-control" id="quantity" type="number" name="quantity" min="1"
                               value="<?= htmlspecialchars($_POST['quantity'] ?? (string) $post['quantity'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" for="budget_min">Budget min</label>
                        <input class="form-control" id="budget_min" type="number" step="0.01" min="0" name="budget_min"
                               value="<?= htmlspecialchars($_POST['budget_min'] ?? (string) $post['budget_min'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="budget_max">Budget max</label>
                        <input class="form-control" id="budget_max" type="number" step="0.01" min="0" name="budget_max"
                               value="<?= htmlspecialchars($_POST['budget_max'] ?? (string) $post['budget_max'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" for="deadline_date">Deadline</label>
                        <input class="form-control" id="deadline_date" type="date" name="deadline_date"
                               value="<?= htmlspecialchars($_POST['deadline_date'] ?? $post['deadline_date'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="town_text">Town/City</label>
                        <input class="form-control" id="town_text" name="town_text"
                               value="<?= htmlspecialchars($_POST['town_text'] ?? $post['town_text'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-3">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                </div>
            </form>
            <form method="post">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="close">
                <button class="btn btn-outline-danger" type="submit" data-confirm="Close this post and stop receiving offers?" data-confirm-title="Close post" data-confirm-button="Yes, close">Close post</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (in_array($user['role'], ['owner', 'hr', 'employee'], true) && $post['status'] === 'open'): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Send an offer</h2>
            <?php if (!$staffShops): ?>
                <div class="alert alert-warning">You are not linked to a shop yet.</div>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="send_offer">
                    <div class="mb-3">
                        <label class="form-label" for="shop_id">Shop</label>
                        <select class="form-select" id="shop_id" name="shop_id" required>
                            <?php foreach ($staffShops as $shop): ?>
                                <option value="<?= (int) $shop['id'] ?>"><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="offer_price">Offer price</label>
                        <input class="form-control" id="offer_price" type="number" step="0.01" min="0" name="offer_price" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="turnaround_days">Turnaround days</label>
                        <input class="form-control" id="turnaround_days" type="number" min="1" name="turnaround_days" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes (optional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Send offer</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6">Offers</h2>
        <?php if (!$offers): ?>
            <div class="text-muted">No offers yet.</div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($offers as $offer): ?>
                    <?php
                    $offerStatus = $offer['status'] ?? 'sent';
                    $offerBadge = match ($offerStatus) {
                        'accepted' => 'bg-success',
                        'rejected' => 'bg-secondary',
                        'withdrawn' => 'bg-warning text-dark',
                        default => 'bg-info text-dark',
                    };
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($offer['shop_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small">By <?= htmlspecialchars($offer['staff_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="text-end">
                                <span class="badge <?= $offerBadge ?> text-uppercase"><?= htmlspecialchars($offerStatus, ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="fw-semibold mt-1">₱<?= htmlspecialchars(number_format((float) $offer['price'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <?php if (!empty($offer['turnaround_days'])): ?>
                            <div class="mt-2 small text-muted">Turnaround: <?= (int) $offer['turnaround_days'] ?> day(s)</div>
                        <?php endif; ?>
                        <?php if (!empty($offer['notes'])): ?>
                            <div class="mt-2 small text-muted"><?= nl2br(htmlspecialchars($offer['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php endif; ?>
                        <?php if ($user['role'] === 'client'): ?>
                            <?php if ($post['status'] === 'open' && $offerStatus === 'sent'): ?>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <form method="post">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="accept_offer">
                                        <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                        <button class="btn btn-sm btn-success" type="submit" data-confirm="Accept this offer and proceed with the order?" data-confirm-title="Accept offer">Accept offer</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="reject_offer">
                                        <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Reject this offer? This action cannot be undone." data-confirm-title="Reject offer" data-confirm-button="Yes, reject">Reject offer</button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-dark"
                                       href="/messages?action=start&shop_id=<?= (int) $offer['shop_id'] ?>&context_type=post&context_id=<?= (int) $post['id'] ?>">
                                        Message shop
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="mt-3">
                                    <a class="btn btn-sm btn-outline-dark"
                                       href="/messages?action=start&shop_id=<?= (int) $offer['shop_id'] ?>&context_type=post&context_id=<?= (int) $post['id'] ?>">
                                        Message shop
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<a class="btn btn-outline-secondary" href="/client/posts">Back to posts</a>

<?php require __DIR__ . '/../../includes/footer.php'; ?>