<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../handlers/message_handler.php';

require_role(['client', 'owner', 'hr', 'employee']);
$user = current_user();

$errors = [];

if ($user && $user['role'] === 'client' && ($_GET['action'] ?? '') === 'start') {
    $shopId = (int) ($_GET['shop_id'] ?? 0);
    $contextType = trim($_GET['context_type'] ?? '');
    $contextIdRaw = trim($_GET['context_id'] ?? '');
    $contextId = $contextIdRaw === '' ? null : (int) $contextIdRaw;

    if ($shopId > 0 && $contextType !== '') {
        try {
            $conversation = get_or_create_conversation($shopId, (int) $user['id'], $contextType, $contextId);
            header('Location: /messages/' . $conversation['id']);
            exit;
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    } else {
        $errors[] = 'Missing conversation context.';
    }
}

$conversations = $user ? list_conversations_for_user($user) : [];

$pageTitle = 'Messages';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Messages</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$conversations): ?>
    <div class="alert alert-info">No conversations yet.</div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($conversations as $conversation): ?>
            <?php
            $conversationId = (int) $conversation['id'];
            $lastMessage = get_last_message_for_conversation($conversationId);
            $unreadCount = count_unread_messages($conversationId, (int) $user['id']);
            $contextLabel = ucwords(str_replace('_', ' ', $conversation['context_type']));
            $contextMeta = $conversation['context_id'] ? $contextLabel . ' #' . $conversation['context_id'] : $contextLabel;
            $client = $user['role'] === 'client' ? null : get_conversation_client((int) $conversation['client_user_id']);
            $counterparty = $user['role'] === 'client'
                ? ($conversation['shop_name'] ?? 'Shop')
                : ($client['fullname'] ?? 'Client');
            ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start"
               href="/messages/<?= $conversationId ?>">
                <div class="me-3">
                    <div class="fw-semibold"><?= htmlspecialchars($counterparty, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($contextMeta, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($lastMessage): ?>
                        <div class="small">
                            <span class="text-muted"><?= htmlspecialchars($lastMessage['sender_name'], ENT_QUOTES, 'UTF-8') ?>:</span>
                            <?= htmlspecialchars(mb_strimwidth($lastMessage['message_text'], 0, 80, '...'), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="small text-muted">
                        <?= htmlspecialchars($conversation['last_message_at'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
