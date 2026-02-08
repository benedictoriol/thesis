<?php

require_once __DIR__ . '/db.php';

const NOTIFICATION_TYPES = [
    'message_received',
    'post_match',
    'quote_request_received',
    'quote_sent',
    'quote_accepted',
    'quote_rejected',
    'order_placed',
    'order_accepted',
    'order_rejected',
    'order_status_changed',
    'payment_proof_uploaded',
    'payment_reminder',
    'payment_verified',
    'payment_rejected',
    'low_stock',
    'hiring_application',
];

const NOTIFICATION_LINK_TYPES = [
    'conversation',
    'quote_request',
    'quote',
    'order',
    'payment',
    'post',
    'application',
];

function create_notification(
    int $userId,
    string $type,
    string $title,
    string $body,
    ?string $linkType = null,
    ?int $linkId = null
): void {
    if (!in_array($type, NOTIFICATION_TYPES, true)) {
        throw new InvalidArgumentException('Unknown notification type.');
    }

    if ($linkType !== null && !in_array($linkType, NOTIFICATION_LINK_TYPES, true)) {
        throw new InvalidArgumentException('Unknown notification link type.');
    }

    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, type, title, body, link_type, link_id, is_read, created_at)
         VALUES (:user_id, :type, :title, :body, :link_type, :link_id, 0, :created_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'link_type' => $linkType,
        'link_id' => $linkId,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function list_notifications(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function get_notification(int $userId, int $notificationId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute([
        'id' => $notificationId,
        'user_id' => $userId,
    ]);
    $notification = $stmt->fetch();

    return $notification ?: null;
}

function count_unread_notifications(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0'
    );
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function mark_notification_read(int $userId, int $notificationId): void
{
    $stmt = db()->prepare(
        'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        'id' => $notificationId,
        'user_id' => $userId,
    ]);
}

function mark_all_notifications_read(int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE notifications SET is_read = 1 WHERE user_id = :user_id'
    );
    $stmt->execute(['user_id' => $userId]);
}

function notification_link(array $notification): string
{
    $linkType = $notification['link_type'] ?? null;
    $linkId = $notification['link_id'] ?? null;

    if (!$linkType || !$linkId) {
        return '/notifications/' . $notification['id'];
    }

    switch ($linkType) {
        case 'conversation':
            return '/messages/' . $linkId;
        case 'quote_request':
            return '/quotes/requests/' . $linkId;
        case 'quote':
            return '/quotes/' . $linkId;
        case 'order':
            return '/orders/' . $linkId;
        case 'payment':
            return '/payments/' . $linkId;
        case 'post':
            return '/posts/' . $linkId;
        case 'application':
            return '/applications/' . $linkId;
        default:
            return '/notifications/' . $notification['id'];
    }
}
