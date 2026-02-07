<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/catalog_handler.php';

function load_inventory_materials(int $shopId): array
{
    $columns = get_table_columns(db(), 'materials');
    if (!$columns) {
        return [];
    }

    $orderBy = in_array('name', $columns, true) ? 'm.name ASC' : 'm.id DESC';

    $stmt = db()->prepare(
        'SELECT m.id,
                m.name,
                m.unit,
                m.reorder_level,
                m.status,
                COALESCE(SUM(
                    CASE
                        WHEN t.type = "IN" THEN t.qty
                        WHEN t.type = "OUT" THEN -t.qty
                        WHEN t.type = "ADJUST" THEN t.qty
                        ELSE 0
                    END
                ), 0) AS stock_qty
         FROM materials m
         LEFT JOIN material_transactions t
            ON t.material_id = m.id
           AND t.shop_id = m.shop_id
         WHERE m.shop_id = :shop_id
         GROUP BY m.id
         ORDER BY ' . $orderBy
    );
    $stmt->execute(['shop_id' => $shopId]);
    return $stmt->fetchAll();
}

function load_material_stock(int $shopId, int $materialId): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(
            CASE
                WHEN t.type = "IN" THEN t.qty
                WHEN t.type = "OUT" THEN -t.qty
                WHEN t.type = "ADJUST" THEN t.qty
                ELSE 0
            END
        ), 0) AS stock_qty
         FROM material_transactions t
         WHERE t.shop_id = :shop_id
           AND t.material_id = :material_id'
    );
    $stmt->execute([
        'shop_id' => $shopId,
        'material_id' => $materialId,
    ]);
    return (float) ($stmt->fetchColumn() ?? 0);
}

function insert_material_transaction(array $payload): bool
{
    $columns = get_table_columns(db(), 'material_transactions');
    if (!$columns) {
        return false;
    }

    $fields = [
        'shop_id',
        'material_id',
        'type',
        'qty',
        'unit_cost',
        'supplier_id',
        'ref_type',
        'ref_id',
        'reason',
    ];

    $insertColumns = [];
    $placeholders = [];
    $params = [];
    foreach ($fields as $field) {
        if (!in_array($field, $columns, true)) {
            continue;
        }
        $insertColumns[] = $field;
        $placeholders[] = ':' . $field;
        $params[$field] = $payload[$field] ?? null;
    }

    if (in_array('created_at', $columns, true)) {
        $insertColumns[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (!$insertColumns) {
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO material_transactions (' . implode(', ', $insertColumns) . ')
         VALUES (' . implode(', ', $placeholders) . ')'
    );

    return $stmt->execute($params);
}