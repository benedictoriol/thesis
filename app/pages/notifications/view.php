<?php
require_once __DIR__ . '/../../includes/user_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../core/notifications.php';

$currentUser = require_user();
$pageTitle = 'Notification Details';
$notificationId = (int) ($_GET['notification_id'] ?? 0);

if ($notificationId <= 0) {
    http_response_code(404);
    echo 'Notification not found.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Invalid request token.');
        redirect_to('' . );
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        mark_notification_read((int) $currentUser['id'], $notificationId);
        flash_set('success', 'Notification marked as read.');
        redirect_to('' . );
    }
}

$notification = get_notification((int) $currentUser['id'], $notificationId);
if (!$notification) {
    http_response_code(404);
    echo 'Notification not found.';
    exit;
}

$unreadCount = count_unread_notifications((int) $currentUser['id']);
$successMessage = flash_get('success');
$errorMessage = flash_get('error');
$targetLink = notification_link($notification);
$isRead = (int) $notification['is_read'] === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/app.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="app-body">
<nav class="navbar navbar-expand-lg app-navbar navbar-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Thesis Portal</span>
        <div class="d-flex align-items-center gap-3 text-white">
            <div class="position-relative">
                <span class="fs-5">🔔</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= htmlspecialchars((string) $unreadCount, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="small"><?= htmlspecialchars($currentUser['fullname'], ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn btn-outline-light btn-sm" href="/auth/logout">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1">Notification Details</h1>
            <p class="text-muted mb-0">Review the full update before you navigate away.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/notifications">Back to notifications</a>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h2 class="h5 mb-0"><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <?php if (!$isRead): ?>
                    <span class="badge bg-primary">Unread</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Read</span>
                <?php endif; ?>
            </div>
            <p class="text-muted mb-3"><?= htmlspecialchars($notification['body'], ENT_QUOTES, 'UTF-8') ?></p>
            <dl class="row mb-4">
                <dt class="col-sm-3">Type</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($notification['type'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-3">Created</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-3">Linked record</dt>
                <dd class="col-sm-9">
                    <?php if ($notification['link_type']): ?>
                        <?= htmlspecialchars($notification['link_type'], ENT_QUOTES, 'UTF-8') ?> #<?= htmlspecialchars((string) $notification['link_id'], ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        None
                    <?php endif; ?>
                </dd>
            </dl>
            <div class="d-flex flex-wrap gap-2">
                <?php if (!$isRead): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_read">
                        <button class="btn btn-primary" type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($targetLink, ENT_QUOTES, 'UTF-8') ?>">Go to linked item</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
