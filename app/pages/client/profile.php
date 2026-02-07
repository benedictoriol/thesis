<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$user = current_user();
$pageTitle = 'My Profile';
$errors = [];

$stats = [
    'orders' => 0,
    'posts' => 0,
    'quotations' => 0,
    'messages' => 0,
    'notifications' => 0,
    'unread_notifications' => 0,
];
$addresses = [];
$paymentMethods = [];

function table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

try {
    if (table_exists('orders')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE client_user_id = :client_user_id');
        $stmt->execute(['client_user_id' => $user['id']]);
        $stats['orders'] = (int) $stmt->fetchColumn();
    }

    if (table_exists('client_posts')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM client_posts WHERE client_user_id = :client_user_id');
        $stmt->execute(['client_user_id' => $user['id']]);
        $stats['posts'] = (int) $stmt->fetchColumn();
    }

    if (table_exists('post_offers')) {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM post_offers po
             JOIN client_posts cp ON cp.id = po.post_id
             WHERE cp.client_user_id = :client_user_id'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $stats['quotations'] = (int) $stmt->fetchColumn();
    }

    if (table_exists('conversations')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM conversations WHERE client_user_id = :client_user_id');
        $stmt->execute(['client_user_id' => $user['id']]);
        $stats['messages'] = (int) $stmt->fetchColumn();
    }

    if (table_exists('notifications')) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['id']]);
        $stats['notifications'] = (int) $stmt->fetchColumn();

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute(['user_id' => $user['id']]);
        $stats['unread_notifications'] = (int) $stmt->fetchColumn();
    }

    if (table_exists('client_addresses')) {
        $stmt = db()->prepare(
            'SELECT id, label, full_address_text, town_text, phone, is_default
             FROM client_addresses
             WHERE client_user_id = :client_user_id
             ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $addresses = $stmt->fetchAll();
    }

    if (table_exists('client_payment_methods')) {
        $stmt = db()->prepare(
            'SELECT id, method, details_text
             FROM client_payment_methods
             WHERE client_user_id = :client_user_id
             ORDER BY id DESC'
        );
        $stmt->execute(['client_user_id' => $user['id']]);
        $paymentMethods = $stmt->fetchAll();
    }
} catch (PDOException $exception) {
    $errors[] = 'Unable to load profile details right now.';
}

$quickLinks = [
    [
        'label' => 'My Orders',
        'href' => '/client/orders',
        'description' => 'Track ongoing and completed orders.',
        'count' => $stats['orders'],
    ],
    [
        'label' => 'My Posts',
        'href' => '/client/posts',
        'description' => 'Manage requests and offers from shops.',
        'count' => $stats['posts'],
    ],
    [
        'label' => 'My Quotations',
        'href' => '/client/quotations',
        'description' => 'Review price offers from shops.',
        'count' => $stats['quotations'],
    ],
    [
        'label' => 'Messages',
        'href' => '/messages',
        'description' => 'Continue chats with shops.',
        'count' => $stats['messages'],
    ],
    [
        'label' => 'Notifications',
        'href' => '/notifications',
        'description' => 'See updates and alerts.',
        'count' => $stats['notifications'],
        'badge' => $stats['unread_notifications'] > 0 ? $stats['unread_notifications'] . ' new' : null,
    ],
];

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Shopee Me</h1>
<p class="text-muted">Manage your activity, addresses, and payment methods.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1"><?= htmlspecialchars($user['fullname'] ?? 'Client', ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="text-muted small"><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">Phone: <?= htmlspecialchars($user['phone'] ?? 'Not provided', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="text-md-end">
                <span class="badge text-bg-success">Active client</span>
                <div class="text-muted small mt-2">Last login: <?= htmlspecialchars($user['last_login'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($quickLinks as $link): ?>
        <div class="col-md-6">
            <a class="text-decoration-none" href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h3 class="h6 mb-1 text-dark"><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($link['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="text-end">
                                <div class="h5 mb-0 text-primary"><?= (int) $link['count'] ?></div>
                                <?php if (!empty($link['badge'])): ?>
                                    <span class="badge text-bg-warning mt-1"><?= htmlspecialchars($link['badge'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Addresses</h2>
                <?php if (!$addresses): ?>
                    <div class="text-muted small">No saved addresses yet.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($addresses as $address): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <strong><?= htmlspecialchars($address['label'] ?: 'Address', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ((int) $address['is_default'] === 1): ?>
                                        <span class="badge text-bg-primary">Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($address['full_address_text'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($address['town_text'])): ?>
                                        , <?= htmlspecialchars($address['town_text'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($address['phone'])): ?>
                                    <div class="text-muted small">Phone: <?= htmlspecialchars($address['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Payment Methods</h2>
                <?php if (!$paymentMethods): ?>
                    <div class="text-muted small">No saved payment methods yet.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($paymentMethods as $method): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <strong><?= htmlspecialchars($method['method'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <?php if (!empty($method['details_text'])): ?>
                                    <div class="text-muted small"><?= htmlspecialchars($method['details_text'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
        <h2 class="h6 mb-2">Settings</h2>
        <p class="text-muted small mb-0">To update your profile details, contact support or visit the account settings page when available.</p>
    </div>
</div>
<?php
require __DIR__ . '/../../includes/footer.php';
?>