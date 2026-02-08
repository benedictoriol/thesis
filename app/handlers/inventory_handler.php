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

function consume_materials(int $orderId): int
{
    if ($orderId <= 0) {
        return 0;
    }

    $transactionColumns = get_table_columns(db(), 'material_transactions');
    if (!$transactionColumns) {
        return 0;
    }

    $orderColumns = get_table_columns(db(), 'orders');
    $shopIdColumn = find_column($orderColumns, ['shop_id', 'vendor_id']);
    if (!$orderColumns || !$shopIdColumn) {
        return 0;
    }

    $orderStmt = db()->prepare(
        'SELECT id, ' . $shopIdColumn . ' AS shop_id
         FROM orders
         WHERE id = :order_id
         LIMIT 1'
    );
    $orderStmt->execute(['order_id' => $orderId]);
    $order = $orderStmt->fetch();
    if (!$order) {
        return 0;
    }

    $existingConditions = ['ref_type = :ref_type', 'ref_id = :ref_id', 'type = "OUT"'];
    $existingParams = [
        'ref_type' => 'order',
        'ref_id' => $orderId,
    ];
    if (in_array('reason', $transactionColumns, true)) {
        $existingConditions[] = 'reason LIKE :reason_prefix';
        $existingParams['reason_prefix'] = 'Auto-deducted%';
    }
    $existingStmt = db()->prepare(
        'SELECT COUNT(*)
         FROM material_transactions
         WHERE ' . implode(' AND ', $existingConditions)
    );
    $existingStmt->execute($existingParams);
    if ((int) $existingStmt->fetchColumn() > 0) {
        return 0;
    }

    $orderItemsColumns = get_table_columns(db(), 'order_items');
    $orderIdColumn = find_column($orderItemsColumns, ['order_id']);
    $productIdColumn = find_column($orderItemsColumns, ['product_id']);
    $qtyColumn = find_column($orderItemsColumns, ['qty', 'quantity']);
    if (!$orderIdColumn || !$qtyColumn) {
        return 0;
    }

    $selectParts = ["oi.$qtyColumn AS qty"];
    if ($productIdColumn) {
        $selectParts[] = "oi.$productIdColumn AS product_id";
    }

    $itemsStmt = db()->prepare(
        'SELECT ' . implode(', ', $selectParts) . '
         FROM order_items oi
         WHERE oi.' . $orderIdColumn . ' = :order_id'
    );
    $itemsStmt->execute(['order_id' => $orderId]);
    $items = $itemsStmt->fetchAll();
    if (!$items) {
        return 0;
    }

    $templateTable = null;
    $templateColumns = [];
    foreach (['material_usage_templates', 'service_materials', 'service_material_templates'] as $candidate) {
        $columns = get_table_columns(db(), $candidate);
        if ($columns) {
            $templateTable = $candidate;
            $templateColumns = $columns;
            break;
        }
    }

    if (!$templateTable) {
        return 0;
    }

    $templateMaterialColumn = find_column($templateColumns, ['material_id', 'material']);
    $templateQtyColumn = find_column($templateColumns, ['qty_per_unit', 'usage_qty', 'qty', 'quantity']);
    if (!$templateMaterialColumn || !$templateQtyColumn) {
        return 0;
    }

    $templateProductColumn = find_column($templateColumns, ['product_id', 'service_id']);
    $templateItemTypeColumn = find_column($templateColumns, ['item_type', 'service_type', 'type', 'category']);
    $templateShopColumn = find_column($templateColumns, ['shop_id', 'vendor_id']);

    $productTypes = [];
    $productItemTypeColumn = null;
    if ($templateItemTypeColumn && $productIdColumn) {
        $productColumns = get_table_columns(db(), 'products');
        $productItemTypeColumn = find_column($productColumns, ['item_type', 'service_type', 'type', 'category']);
        if ($productItemTypeColumn) {
            $productIds = [];
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                if ($productId > 0) {
                    $productIds[] = $productId;
                }
            }
            $productIds = array_values(array_unique($productIds));
            if ($productIds) {
                $placeholders = [];
                $params = [];
                foreach ($productIds as $index => $productId) {
                    $key = 'product_id_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $productId;
                }
                $productStmt = db()->prepare(
                    'SELECT id, ' . $productItemTypeColumn . ' AS item_type
                     FROM products
                     WHERE id IN (' . implode(', ', $placeholders) . ')'
                );
                $productStmt->execute($params);
                foreach ($productStmt->fetchAll() as $row) {
                    $productTypes[(int) $row['id']] = (string) ($row['item_type'] ?? '');
                }
            }
        }
    }

    $conditions = [];
    $params = [];
    if ($templateShopColumn) {
        $conditions[] = $templateShopColumn . ' = :shop_id';
        $params['shop_id'] = $order['shop_id'];
    }

    $productIds = [];
    if ($templateProductColumn && $productIdColumn) {
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }
        $productIds = array_values(array_unique($productIds));
        if ($productIds) {
            $placeholders = [];
            foreach ($productIds as $index => $productId) {
                $key = 'template_product_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $productId;
            }
            $conditions[] = $templateProductColumn . ' IN (' . implode(', ', $placeholders) . ')';
        }
    }

    $itemTypes = [];
    if ($templateItemTypeColumn && $productItemTypeColumn && $productTypes) {
        foreach ($productTypes as $itemType) {
            $itemType = trim((string) $itemType);
            if ($itemType !== '') {
                $itemTypes[] = $itemType;
            }
        }
        $itemTypes = array_values(array_unique($itemTypes));
        if ($itemTypes) {
            $placeholders = [];
            foreach ($itemTypes as $index => $itemType) {
                $key = 'template_type_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $itemType;
            }
            $conditions[] = $templateItemTypeColumn . ' IN (' . implode(', ', $placeholders) . ')';
        }
    }

    $selectTemplateParts = [
        $templateMaterialColumn . ' AS material_id',
        $templateQtyColumn . ' AS qty_per_unit',
    ];
    if ($templateProductColumn) {
        $selectTemplateParts[] = $templateProductColumn . ' AS product_id';
    }
    if ($templateItemTypeColumn) {
        $selectTemplateParts[] = $templateItemTypeColumn . ' AS item_type';
    }

    $templateSql = 'SELECT ' . implode(', ', $selectTemplateParts) . '
        FROM ' . $templateTable;
    if ($conditions) {
        $templateSql .= "\nWHERE " . implode(' AND ', $conditions);
    }

    $templateStmt = db()->prepare($templateSql);
    $templateStmt->execute($params);
    $templates = $templateStmt->fetchAll();
    if (!$templates) {
        return 0;
    }

    $materialTotals = [];
    foreach ($items as $item) {
        $qty = (float) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }

        $productId = $productIdColumn ? (int) ($item['product_id'] ?? 0) : 0;
        $itemType = null;
        if ($productId > 0 && $productItemTypeColumn) {
            $itemType = $productTypes[$productId] ?? null;
        }

        $matched = [];
        foreach ($templates as $template) {
            if ($templateProductColumn && $productId > 0) {
                if ((int) ($template['product_id'] ?? 0) === $productId) {
                    $matched[] = $template;
                }
                continue;
            }

            if ($templateItemTypeColumn && $itemType !== null) {
                if ((string) ($template['item_type'] ?? '') === (string) $itemType) {
                    $matched[] = $template;
                }
                continue;
            }

            if (!$templateProductColumn && !$templateItemTypeColumn) {
                $matched[] = $template;
            }
        }

        foreach ($matched as $template) {
            $materialId = (int) ($template['material_id'] ?? 0);
            $perUnit = (float) ($template['qty_per_unit'] ?? 0);
            if ($materialId <= 0 || $perUnit <= 0) {
                continue;
            }
            $materialTotals[$materialId] = ($materialTotals[$materialId] ?? 0) + ($perUnit * $qty);
        }
    }

    if (!$materialTotals) {
        return 0;
    }

    $createdCount = 0;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($materialTotals as $materialId => $totalQty) {
            if ($totalQty <= 0) {
                continue;
            }
            $saved = insert_material_transaction([
                'shop_id' => $order['shop_id'],
                'material_id' => $materialId,
                'type' => 'OUT',
                'qty' => $totalQty,
                'unit_cost' => null,
                'supplier_id' => null,
                'ref_type' => 'order',
                'ref_id' => $orderId,
                'reason' => 'Auto-deducted for order #' . $orderId,
            ]);
            if (!$saved) {
                throw new RuntimeException('Failed to insert material transaction.');
            }
            $createdCount++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 0;
    }

    return $createdCount;
}