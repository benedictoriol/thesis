<?php

require_once __DIR__ . '/../core/db.php';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function load_shop_for_user(array $user): ?array
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

function parse_image_list(?string $raw): array
{
    if ($raw === null) {
        return [];
    }

    $parts = preg_split('/[\r\n,]+/', $raw);
    $images = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value !== '') {
            $images[] = $value;
        }
    }

    return $images;
}

function parse_priced_items(array $names, array $prices): array
{
    $items = [];
    $count = max(count($names), count($prices));
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string) ($names[$i] ?? ''));
        $priceRaw = trim((string) ($prices[$i] ?? ''));
        if ($name === '' && $priceRaw === '') {
            continue;
        }
        $price = is_numeric($priceRaw) ? (float) $priceRaw : null;
        $items[] = [
            'name' => $name,
            'price' => $price,
        ];
    }

    return $items;
}