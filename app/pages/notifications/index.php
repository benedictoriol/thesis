<?php
require_once __DIR__ . '/../../includes/user_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../core/notifications.php';

$currentUser = require_user();
$pageTitle = 'Notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Invalid request token.');
        header('Location: /notifications');
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        mark_all_notifications_read((int) $currentUser['id']);
        flash_set('success', 'All notifications marked as read.');
        header('Location: /notifications');
        exit;
    }

    if ($action === 'mark_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            mark_notification_read((int) $currentUser['id'], $notificationId);
            flash_set('success', 'Notification marked as read.');
        }
        header('Location: /notifications');
        exit;
    }
}

$notifications = list_notifications((int) $currentUser['id']);
$unreadCount = count_unread_notifications((int) $currentUser['id']);
$successMessage = flash_get('success');
$errorMessage = flash_get('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
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
            <h1 class="h4 mb-1">Notifications</h1>
            <p class="text-muted mb-0">Stay up to date with messages, quotes, orders, and payments.</p>
        </div>
        <form method="post" class="mt-3 mt-md-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-outline-primary" type="submit" <?= $notifications ? '' : 'disabled' ?>>Mark all read</button>
        </form>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $isRead = (int) $notification['is_read'] === 1;
                $targetLink = notification_link($notification);
                ?>
                <div class="list-group-item d-flex flex-column flex-md-row justify-content-between gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <strong><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!$isRead): ?>
                                <span class="badge bg-primary">Unread</span>
                            <?php endif; ?>
                        </div>
                        <p class="mb-1 text-muted"><?= htmlspecialchars($notification['body'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="small text-muted">
                            <?= htmlspecialchars($notification['type'], ENT_QUOTES, 'UTF-8') ?> ·
                            <?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="/notifications/<?= htmlspecialchars((string) $notification['id'], ENT_QUOTES, 'UTF-8') ?>">View</a>
                        <?php if (!$isRead): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= htmlspecialchars((string) $notification['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$notifications): ?>
                <div class="list-group-item text-muted">You have no notifications yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
