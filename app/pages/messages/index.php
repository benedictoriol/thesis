<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/shop_availability.php';
require_once __DIR__ . '/../../handlers/message_handler.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['client', 'owner', 'hr', 'employee']);
$user = current_user();

$errors = [];

function infer_quote_request_source(int $clientUserId, ?int $contextId): array
{
    $sourceType = 'customization';
    $sourceId = $contextId ?? 0;
    $designId = null;

    if ($contextId) {
        try {
            $stmt = db()->prepare(
                'SELECT id FROM client_posts WHERE id = :id AND client_user_id = :client_user_id LIMIT 1'
            );
            $stmt->execute([
                'id' => $contextId,
                'client_user_id' => $clientUserId,
            ]);
            if ($stmt->fetch()) {
                return ['client_post', $contextId, null];
            }
        } catch (PDOException $exception) {
            // Ignore lookup errors and fall back to customization.
        }

        try {
            $stmt = db()->prepare(
                'SELECT id FROM custom_designs WHERE id = :id AND owner_user_id = :client_user_id LIMIT 1'
            );
            $stmt->execute([
                'id' => $contextId,
                'client_user_id' => $clientUserId,
            ]);
            if ($stmt->fetch()) {
                $designId = $contextId;
            }
        } catch (PDOException $exception) {
            // Ignore lookup errors and fall back to generic customization.
        }
    }

    return [$sourceType, $sourceId, $designId];
}

function ensure_quote_request(int $shopId, int $clientUserId, ?int $contextId): void
{
    if (!$contextId || !table_exists('quote_requests')) {
        return;
    }

    [$sourceType, $sourceId, $designId] = infer_quote_request_source($clientUserId, $contextId);

    try {
        $stmt = db()->prepare(
            'SELECT id FROM quote_requests
             WHERE shop_id = :shop_id
             AND client_user_id = :client_user_id
             AND source_type = :source_type
             AND source_id = :source_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'shop_id' => $shopId,
            'client_user_id' => $clientUserId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        if ($stmt->fetch()) {
            return;
        }

        $insert = db()->prepare(
            'INSERT INTO quote_requests
                (client_user_id, shop_id, source_type, source_id, design_id, notes, status, created_at)
             VALUES
                (:client_user_id, :shop_id, :source_type, :source_id, :design_id, :notes, :status, :created_at)'
        );
        $insert->execute([
            'client_user_id' => $clientUserId,
            'shop_id' => $shopId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'design_id' => $designId,
            'notes' => null,
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $exception) {
        // Ignore failures to avoid blocking messages.
    }
}

if ($user && $user['role'] === 'client' && ($_GET['action'] ?? '') === 'start') {
    $shopId = (int) ($_GET['shop_id'] ?? 0);
    $contextType = trim($_GET['context_type'] ?? '');
    $contextIdRaw = trim($_GET['context_id'] ?? '');
    $contextId = $contextIdRaw === '' ? null : (int) $contextIdRaw;

    if ($shopId > 0 && $contextType !== '') {
        try {
            if (in_array($contextType, ['quote_request', 'post'], true) && !shop_accepts($shopId, 'accepting_quotes')) {
                throw new InvalidArgumentException('This shop is not accepting quote requests right now.');
            }
            if ($contextType === 'order' && !shop_accepts($shopId, 'accepting_orders')) {
                throw new InvalidArgumentException('This shop is not accepting new orders right now.');
            }
            $conversation = get_or_create_conversation($shopId, (int) $user['id'], $contextType, $contextId);
            if ($contextType === 'quote_request') {
                ensure_quote_request($shopId, (int) $user['id'], $contextId);
            }
            redirect_to('' . );
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    } else {
        $errors[] = 'Missing conversation context.';
    }
}

$conversations = $user ? list_conversations_for_user($user) : [];

$pageTitle = 'Messages';
require __DIR__ . '/../../includes/app_header.php';
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

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
