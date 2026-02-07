<?php

require_once __DIR__ . '/../core/db.php';

function load_shop_for_staff_user(array $user): ?array
{
    try {
        if ($user['role'] === 'owner') {
            $stmt = db()->prepare(
                'SELECT id, name
                 FROM shops
                 WHERE owner_user_id = :owner_id
                 LIMIT 1'
            );
            $stmt->execute(['owner_id' => $user['id']]);
            return $stmt->fetch() ?: null;
        }

        if ($user['role'] === 'hr') {
            $stmt = db()->prepare(
                'SELECT s.id, s.name
                 FROM shops s
                 JOIN shop_staff ss ON ss.shop_id = s.id
                 WHERE ss.user_id = :user_id
                 AND ss.role = :role
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'role' => 'hr',
            ]);
            return $stmt->fetch() ?: null;
        }
    } catch (PDOException $exception) {
        return null;
    }

    return null;
}

function table_columns(string $table): array
{
    try {
        $stmt = db()->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
    } catch (PDOException $exception) {
        return [];
    }
}

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