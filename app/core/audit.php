<?php

require_once __DIR__ . '/db.php';

function audit_log(?int $actorUserId, string $action, string $entity, ?int $entityId, array $meta = []): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity, entity_id, meta_json, created_at)
         VALUES (:actor_user_id, :action, :entity, :entity_id, :meta_json, :created_at)'
    );
    $stmt->execute([
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}