<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/audit.php';
require_once __DIR__ . '/../includes/staff_helpers.php';

function resolve_quote_item_type(array $request, ?array $design): ?string
{
    if (!empty($design['item_type'])) {
        return $design['item_type'];
    }

    if (($request['source_type'] ?? '') === 'client_post' && !empty($request['source_id'])) {
        try {
            $stmt = db()->prepare('SELECT item_type FROM client_posts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $request['source_id']]);
            $itemType = $stmt->fetchColumn();
            return $itemType !== false ? (string) $itemType : null;
        } catch (PDOException $exception) {
            return null;
        }
    }

    return null;
}

function resolve_base_product(int $shopId, ?string $itemType): ?array
{
    if (!table_exists('products')) {
        return null;
    }

    $columns = table_columns('products');
    if (!in_array('base_price', $columns, true)) {
        return null;
    }

    $query = 'SELECT id, base_price FROM products WHERE shop_id = :shop_id';
    $params = ['shop_id' => $shopId];

    if ($itemType && in_array('item_type', $columns, true)) {
        $query .= ' AND item_type = :item_type';
        $params['item_type'] = $itemType;
    }

    $query .= ' ORDER BY base_price ASC, id ASC LIMIT 1';

    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        $product = $stmt->fetch();
        return $product ?: null;
    } catch (PDOException $exception) {
        return null;
    }
}

function resolve_complexity_multiplier(int $layerCount): float
{
    if ($layerCount >= 8) {
        return 1.3;
    }

    if ($layerCount >= 5) {
        return 1.2;
    }

    if ($layerCount >= 3) {
        return 1.1;
    }

    return 1.0;
}

function sum_selected_prices(string $table, string $priceColumn, int $productId, array $selectedIds): float
{
    if (!$selectedIds || !table_exists($table)) {
        return 0.0;
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $query = sprintf(
        'SELECT SUM(%s) FROM %s WHERE product_id = ? AND id IN (%s)',
        $priceColumn,
        $table,
        $placeholders
    );
    $params = array_merge([$productId], array_map('intval', $selectedIds));

    try {
        $stmt = db()->prepare($query);
        $stmt->execute($params);
        $sum = $stmt->fetchColumn();
        return $sum !== false ? (float) $sum : 0.0;
    } catch (PDOException $exception) {
        return 0.0;
    }
}

function suggest_price(array $request, ?array $design = null, array $designLayers = [], array $options = []): array
{
    $shopId = (int) ($request['shop_id'] ?? 0);
    $itemType = resolve_quote_item_type($request, $design);
    $product = resolve_base_product($shopId, $itemType);

    $basePrice = 0.0;
    if (isset($options['base_price']) && is_numeric($options['base_price'])) {
        $basePrice = (float) $options['base_price'];
    } elseif ($product && isset($product['base_price'])) {
        $basePrice = (float) $product['base_price'];
    }

    $productId = $product ? (int) $product['id'] : 0;
    $variantIds = array_values(array_filter($options['variant_ids'] ?? [], 'is_numeric'));
    $addonIds = array_values(array_filter($options['addon_ids'] ?? [], 'is_numeric'));

    $variantTotal = $productId > 0
        ? sum_selected_prices('product_variants', 'price_add', $productId, $variantIds)
        : 0.0;
    $addonTotal = $productId > 0
        ? sum_selected_prices('product_addons', 'addon_price', $productId, $addonIds)
        : 0.0;

    $layerCount = count($designLayers);
    $multiplier = 1.0;
    if (isset($options['complexity_multiplier']) && is_numeric($options['complexity_multiplier'])) {
        $multiplier = max(1.0, (float) $options['complexity_multiplier']);
    } else {
        $multiplier = resolve_complexity_multiplier($layerCount);
    }

    $suggested = ($basePrice + $variantTotal + $addonTotal) * $multiplier;
    $suggested = round($suggested, 2);

    return [
        'item_type' => $itemType,
        'product_id' => $productId,
        'base_price' => $basePrice,
        'variants_total' => $variantTotal,
        'addons_total' => $addonTotal,
        'complexity_multiplier' => $multiplier,
        'layer_count' => $layerCount,
        'suggested_price' => $suggested,
    ];
}

function create_quote(
    array $request,
    array $currentUser,
    float $price,
    int $turnaroundDays,
    ?string $notes,
    ?int $validityDays
): int {
    $validUntil = null;
    if ($validityDays && $validityDays > 0) {
        $validUntil = gmdate('Y-m-d H:i:s', strtotime('+' . $validityDays . ' days'));
    }

    db()->beginTransaction();

    try {
        $stmt = db()->prepare(
            'INSERT INTO quotes
                (quote_request_id, quoted_by_user_id, price, turnaround_days, notes, valid_until, status, created_at)
             VALUES
                (:quote_request_id, :quoted_by_user_id, :price, :turnaround_days, :notes, :valid_until, :status, :created_at)'
        );
        $stmt->execute([
            'quote_request_id' => (int) $request['id'],
            'quoted_by_user_id' => (int) $currentUser['id'],
            'price' => $price,
            'turnaround_days' => $turnaroundDays,
            'notes' => $notes !== '' ? $notes : null,
            'valid_until' => $validUntil,
            'status' => 'sent',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $quoteId = (int) db()->lastInsertId();

        $stmt = db()->prepare(
            'UPDATE quote_requests SET status = :status WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'quoted',
            'id' => (int) $request['id'],
        ]);

        $logStmt = db()->prepare(
            'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
             VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
        );
        $logStmt->execute([
            'quote_id' => $quoteId,
            'status' => 'sent',
            'changed_by_user_id' => (int) $currentUser['id'],
            'note' => $notes !== '' ? $notes : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        audit_log(
            (int) $currentUser['id'],
            'create_quote',
            'quotes',
            $quoteId,
            [
                'shop_id' => $request['shop_id'] ?? null,
                'request_id' => $request['id'] ?? null,
                'price' => $price,
                'turnaround_days' => $turnaroundDays,
                'valid_until' => $validUntil,
            ]
        );

        db()->commit();

        return $quoteId;
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}