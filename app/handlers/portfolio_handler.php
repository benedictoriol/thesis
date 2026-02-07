<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/catalog_handler.php';

function load_portfolio_categories(): array
{
    if (!get_table_columns(db(), 'categories')) {
        return [];
    }

    try {
        $stmt = db()->query('SELECT id, name FROM categories ORDER BY name ASC');
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}