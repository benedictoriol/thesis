<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/notifications.php';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM %s', $table));
        return array_map(static fn(array $row) => $row['Field'], $stmt->fetchAll());
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

function notify_shops_about_post(int $postId): void
{
    $stmt = db()->prepare(
        'SELECT id, title, item_type, town_text
         FROM client_posts
         WHERE id = :id'
    );
    $stmt->execute(['id' => $postId]);
    $post = $stmt->fetch();
    if (!$post) {
        return;
    }

    $itemType = (string) ($post['item_type'] ?? '');
    $townText = trim((string) ($post['town_text'] ?? ''));

    $shopColumns = get_table_columns(db(), 'shops');
    $availabilityColumns = get_table_columns(db(), 'shop_availability');
    $productColumns = get_table_columns(db(), 'products');
    $categoryColumns = get_table_columns(db(), 'categories');

    $townColumn = find_column($shopColumns, ['address_text', 'town', 'city', 'location']);
    $serviceColumn = find_column($productColumns, ['item_type', 'service_type', 'type', 'category']);
    $productNameColumn = find_column($productColumns, ['name', 'title']);
    $productDescriptionColumn = find_column($productColumns, ['description', 'details', 'summary']);
    $productCategoryColumn = find_column($productColumns, ['category_id', 'category']);
    $categoryNameColumn = find_column($categoryColumns, ['name', 'title']);

    $conditions = ["s.status = 'active'"];
    $params = [];
    $joins = [];

    if ($availabilityColumns) {
        $joins[] = 'LEFT JOIN shop_availability sa ON sa.shop_id = s.id';
        if (in_array('accepting_quotes', $availabilityColumns, true)) {
            $conditions[] = 'COALESCE(sa.accepting_quotes, 1) = 1';
        }
    }

    $serviceConditions = [];
    if ($productColumns) {
        $joins[] = 'JOIN products p ON p.shop_id = s.id';
        if ($serviceColumn) {
            $serviceConditions[] = sprintf('p.%s = :item_type', $serviceColumn);
            $params['item_type'] = $itemType;
        } elseif ($productCategoryColumn && $categoryNameColumn) {
            $joins[] = 'LEFT JOIN categories c ON c.id = p.' . $productCategoryColumn;
            $serviceConditions[] = sprintf('c.%s LIKE :item_type_like', $categoryNameColumn);
            $params['item_type_like'] = '%' . $itemType . '%';
        } else {
            $textConditions = [];
            if ($productNameColumn) {
                $textConditions[] = sprintf('p.%s LIKE :item_type_like', $productNameColumn);
            }
            if ($productDescriptionColumn) {
                $textConditions[] = sprintf('p.%s LIKE :item_type_like', $productDescriptionColumn);
            }
            if ($textConditions) {
                $serviceConditions[] = '(' . implode(' OR ', $textConditions) . ')';
                $params['item_type_like'] = '%' . $itemType . '%';
            }
        }
    } elseif (in_array('description', $shopColumns, true)) {
        $serviceConditions[] = 's.description LIKE :item_type_like';
        $params['item_type_like'] = '%' . $itemType . '%';
    }

    if ($serviceConditions) {
        $conditions[] = '(' . implode(' OR ', $serviceConditions) . ')';
    }

    if ($townText !== '' && $townColumn) {
        $conditions[] = sprintf('s.%s LIKE :town_text', $townColumn);
        $params['town_text'] = '%' . $townText . '%';
    }

    $sql = 'SELECT DISTINCT s.id, s.name, s.owner_user_id
        FROM shops s';
    if ($joins) {
        $sql .= "\n" . implode("\n", array_unique($joins));
    }
    if ($conditions) {
        $sql .= "\nWHERE " . implode(' AND ', $conditions);
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $shops = $stmt->fetchAll();
    } catch (PDOException $exception) {
        return;
    }

    if (!$shops) {
        return;
    }

    $itemLabel = $itemType !== '' ? ucfirst(str_replace('_', ' ', $itemType)) : 'Service';
    $title = 'New post: ' . $itemLabel;
    $body = $townText !== ''
        ? sprintf('A client posted a %s request in %s.', strtolower($itemLabel), $townText)
        : sprintf('A client posted a %s request.', strtolower($itemLabel));

    foreach ($shops as $shop) {
        $shopId = (int) $shop['id'];
        $recipientIds = [];

        $ownerId = (int) $shop['owner_user_id'];
        if ($ownerId > 0) {
            $recipientIds[] = $ownerId;
        }

        $staffStmt = db()->prepare('SELECT user_id FROM shop_staff WHERE shop_id = :shop_id AND status = :status');
        $staffStmt->execute([
            'shop_id' => $shopId,
            'status' => 'active',
        ]);
        foreach ($staffStmt->fetchAll() as $row) {
            $recipientIds[] = (int) $row['user_id'];
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds)));
        foreach ($recipientIds as $recipientId) {
            create_notification(
                $recipientId,
                'post_match',
                $title,
                $body,
                'post',
                $postId
            );
        }
    }
}