<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';

const MESSAGE_CONTEXT_TYPES = ['inquiry', 'post', 'quote_request', 'order'];

function list_user_shop_ids(int $userId, string $role): array
{
    if ($role === 'owner') {
        $stmt = db()->prepare('SELECT id FROM shops WHERE owner_user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    if ($role === 'hr' || $role === 'employee') {
        $stmt = db()->prepare('SELECT shop_id FROM shop_staff WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'shop_id'));
    }

    return [];
}

function get_or_create_conversation(
    int $shopId,
    int $clientUserId,
    string $contextType,
    ?int $contextId
): array {
    if (!in_array($contextType, MESSAGE_CONTEXT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid context type.');
    }

    $stmt = db()->prepare('SELECT id FROM shops WHERE id = :id');
    $stmt->execute(['id' => $shopId]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('Shop not found.');
    }

    $stmt = db()->prepare(
        'SELECT * FROM conversations
         WHERE shop_id = :shop_id
         AND client_user_id = :client_user_id
         AND context_type = :context_type
         AND ((context_id IS NULL AND :context_id IS NULL) OR context_id = :context_id)
         LIMIT 1'
    );
    $stmt->execute([
        'shop_id' => $shopId,
        'client_user_id' => $clientUserId,
        'context_type' => $contextType,
        'context_id' => $contextId,
    ]);
    $conversation = $stmt->fetch();
    if ($conversation) {
        return $conversation;
    }

    $now = gmdate('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO conversations (shop_id, client_user_id, context_type, context_id, created_at, last_message_at)
         VALUES (:shop_id, :client_user_id, :context_type, :context_id, :created_at, :last_message_at)'
    );
    $stmt->execute([
        'shop_id' => $shopId,
        'client_user_id' => $clientUserId,
        'context_type' => $contextType,
        'context_id' => $contextId,
        'created_at' => $now,
        'last_message_at' => $now,
    ]);

    $conversationId = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = :id');
    $stmt->execute(['id' => $conversationId]);

    return $stmt->fetch();
}

function get_conversation_for_user(int $conversationId, array $user): ?array
{
    $stmt = db()->prepare(
        'SELECT conversations.*, shops.name AS shop_name, shops.owner_user_id
         FROM conversations
         JOIN shops ON shops.id = conversations.shop_id
         WHERE conversations.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $conversationId]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        return null;
    }

    return user_can_access_conversation($conversation, $user) ? $conversation : null;
}

function user_can_access_conversation(array $conversation, array $user): bool
{
    if ($user['role'] === 'client') {
        return (int) $conversation['client_user_id'] === (int) $user['id'];
    }

    if (in_array($user['role'], ['owner', 'hr', 'employee'], true)) {
        $shopIds = list_user_shop_ids((int) $user['id'], $user['role']);
        return in_array((int) $conversation['shop_id'], $shopIds, true);
    }

    return false;
}

function list_conversations_for_user(array $user): array
{
    if ($user['role'] === 'client') {
        $stmt = db()->prepare(
            'SELECT conversations.*, shops.name AS shop_name
             FROM conversations
             JOIN shops ON shops.id = conversations.shop_id
             WHERE conversations.client_user_id = :user_id
             ORDER BY conversations.last_message_at DESC, conversations.id DESC'
        );
        $stmt->execute(['user_id' => $user['id']]);
        return $stmt->fetchAll();
    }

    if (in_array($user['role'], ['owner', 'hr', 'employee'], true)) {
        $shopIds = list_user_shop_ids((int) $user['id'], $user['role']);
        if (!$shopIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
        $stmt = db()->prepare(
            "SELECT conversations.*, shops.name AS shop_name
             FROM conversations
             JOIN shops ON shops.id = conversations.shop_id
             WHERE conversations.shop_id IN ($placeholders)
             ORDER BY conversations.last_message_at DESC, conversations.id DESC"
        );
        $stmt->execute($shopIds);
        return $stmt->fetchAll();
    }

    return [];
}

function list_messages_for_conversation(int $conversationId): array
{
    $stmt = db()->prepare(
        'SELECT messages.*, users.fullname AS sender_name, users.role AS sender_role
         FROM messages
         JOIN users ON users.id = messages.sender_user_id
         WHERE messages.conversation_id = :conversation_id
         ORDER BY messages.created_at ASC, messages.id ASC'
    );
    $stmt->execute(['conversation_id' => $conversationId]);
    return $stmt->fetchAll();
}

function get_last_message_for_conversation(int $conversationId): ?array
{
    $stmt = db()->prepare(
        'SELECT messages.*, users.fullname AS sender_name
         FROM messages
         JOIN users ON users.id = messages.sender_user_id
         WHERE messages.conversation_id = :conversation_id
         ORDER BY messages.created_at DESC, messages.id DESC
         LIMIT 1'
    );
    $stmt->execute(['conversation_id' => $conversationId]);
    $message = $stmt->fetch();
    return $message ?: null;
}

function get_conversation_client(int $clientUserId): ?array
{
    $stmt = db()->prepare('SELECT id, fullname, email FROM users WHERE id = :id');
    $stmt->execute(['id' => $clientUserId]);
    $client = $stmt->fetch();
    return $client ?: null;
}

function count_unread_messages(int $conversationId, int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM messages
         WHERE conversation_id = :conversation_id
         AND sender_user_id != :user_id
         AND is_read = 0'
    );
    $stmt->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId,
    ]);
    return (int) $stmt->fetchColumn();
}

function mark_conversation_read(int $conversationId, int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE messages
         SET is_read = 1
         WHERE conversation_id = :conversation_id
         AND sender_user_id != :user_id'
    );
    $stmt->execute([
        'conversation_id' => $conversationId,
        'user_id' => $userId,
    ]);
}

function send_message(
    int $conversationId,
    int $senderUserId,
    string $messageText,
    ?string $attachmentPath
): void {
    $now = gmdate('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO messages (conversation_id, sender_user_id, message_text, attachment_path, created_at, is_read)
         VALUES (:conversation_id, :sender_user_id, :message_text, :attachment_path, :created_at, 0)'
    );
    $stmt->execute([
        'conversation_id' => $conversationId,
        'sender_user_id' => $senderUserId,
        'message_text' => $messageText,
        'attachment_path' => $attachmentPath,
        'created_at' => $now,
    ]);

    $stmt = db()->prepare('UPDATE conversations SET last_message_at = :last_message_at WHERE id = :id');
    $stmt->execute([
        'last_message_at' => $now,
        'id' => $conversationId,
    ]);

    $stmt = db()->prepare(
        'SELECT conversations.*, shops.name AS shop_name, shops.owner_user_id
         FROM conversations
         JOIN shops ON shops.id = conversations.shop_id
         WHERE conversations.id = :id'
    );
    $stmt->execute(['id' => $conversationId]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        return;
    }

    $senderStmt = db()->prepare('SELECT fullname, role FROM users WHERE id = :id');
    $senderStmt->execute(['id' => $senderUserId]);
    $sender = $senderStmt->fetch() ?: ['fullname' => 'Someone', 'role' => null];

    $recipientIds = conversation_recipient_ids($conversation, $senderUserId);
    foreach ($recipientIds as $recipientId) {
        create_notification(
            $recipientId,
            'message_received',
            'New message from ' . $sender['fullname'],
            'Conversation about ' . str_replace('_', ' ', $conversation['context_type']) . '.',
            'conversation',
            (int) $conversation['id']
        );
    }
}

function conversation_recipient_ids(array $conversation, int $senderUserId): array
{
    $recipients = [];

    if ((int) $conversation['client_user_id'] !== $senderUserId) {
        $recipients[] = (int) $conversation['client_user_id'];
    }

    $ownerId = (int) $conversation['owner_user_id'];
    if ($ownerId !== $senderUserId) {
        $recipients[] = $ownerId;
    }

    $stmt = db()->prepare('SELECT user_id FROM shop_staff WHERE shop_id = :shop_id');
    $stmt->execute(['shop_id' => $conversation['shop_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $staffId = (int) $row['user_id'];
        if ($staffId !== $senderUserId) {
            $recipients[] = $staffId;
        }
    }

    return array_values(array_unique($recipients));
}

function handle_message_attachment(array $file): array
{
    if (empty($file['name'])) {
        return ['path' => null, 'error' => null];
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['path' => null, 'error' => 'Invalid attachment upload.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'Attachment upload failed.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];
    $mimeType = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mimeType])) {
        return ['path' => null, 'error' => 'Attachments must be JPG, PNG, or PDF files.'];
    }

    $uploadDir = __DIR__ . '/../../public/uploads/messages';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mimeType];
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['path' => null, 'error' => 'Unable to save attachment.'];
    }

    return ['path' => '/uploads/messages/' . $filename, 'error' => null];
}