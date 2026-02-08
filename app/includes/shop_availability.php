<?php

require_once __DIR__ . '/../core/db.php';

function shop_availability_table_exists(): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => 'shop_availability']);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

function default_shop_availability(): array
{
    return [
        'accepting_orders' => 1,
        'accepting_quotes' => 1,
        'accepting_custom' => 1,
        'accepting_rush' => 0,
    ];
}

function load_shop_availability(int $shopId): array
{
    if ($shopId <= 0 || !shop_availability_table_exists()) {
        return default_shop_availability();
    }

    try {
        $stmt = db()->prepare(
            'SELECT accepting_orders, accepting_quotes, accepting_custom, accepting_rush
             FROM shop_availability
             WHERE shop_id = :shop_id
             LIMIT 1'
        );
        $stmt->execute(['shop_id' => $shopId]);
        $availability = $stmt->fetch();
        if (!$availability) {
            return default_shop_availability();
        }

        return array_merge(default_shop_availability(), $availability);
    } catch (PDOException $exception) {
        return default_shop_availability();
    }
}

function shop_accepts(int $shopId, string $flag): bool
{
    $availability = load_shop_availability($shopId);
    return !empty($availability[$flag]);
}
