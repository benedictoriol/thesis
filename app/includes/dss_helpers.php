<?php

require_once __DIR__ . '/../core/db.php';

function dss_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}

function dss_default_weights(): array
{
    return [
        'avg_rating' => 0.3,
        'completion_rate' => 0.2,
        'avg_turnaround' => 0.15,
        'price_index' => 0.1,
        'cancellation_rate' => 0.1,
        'availability' => 0.1,
        'town_match' => 0.05,
    ];
}

function dss_load_weights(): array
{
    $weights = dss_default_weights();

    if (!dss_table_exists('dss_global_weights')) {
        return $weights;
    }

    try {
        $rows = db()->query('SELECT criterion, weight FROM dss_global_weights')->fetchAll();
        foreach ($rows as $row) {
            $criterion = trim((string) $row['criterion']);
            if ($criterion === '') {
                continue;
            }
            $weights[$criterion] = (float) $row['weight'];
        }
    } catch (PDOException $exception) {
        return $weights;
    }

    return $weights;
}

function dss_load_metric_bounds(array $metricsColumns): array
{
    if (!$metricsColumns || !dss_table_exists('shop_metrics')) {
        return [];
    }

    $columnMap = [
        'avg_rating' => 'avg_rating',
        'completion_rate' => 'completion_rate',
        'avg_turnaround' => 'avg_turnaround_days',
        'price_index' => 'price_index',
        'cancellation_rate' => 'cancellation_rate',
    ];

    $selectParts = [];
    foreach ($columnMap as $key => $column) {
        if (in_array($column, $metricsColumns, true)) {
            $selectParts[] = sprintf('MIN(%s) AS min_%s', $column, $key);
            $selectParts[] = sprintf('MAX(%s) AS max_%s', $column, $key);
        }
    }

    if (!$selectParts) {
        return [];
    }

    try {
        $stmt = db()->query('SELECT ' . implode(', ', $selectParts) . ' FROM shop_metrics');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        $bounds = [];
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = 0;
            }
        }
        foreach ($columnMap as $key => $column) {
            $minKey = 'min_' . $key;
            $maxKey = 'max_' . $key;
            if (array_key_exists($minKey, $row) && array_key_exists($maxKey, $row)) {
                $bounds[$key] = [
                    'min' => (float) $row[$minKey],
                    'max' => (float) $row[$maxKey],
                ];
            }
        }

        return $bounds;
    } catch (PDOException $exception) {
        return [];
    }
}

function dss_build_score_sql(
    array $weights,
    array $bounds,
    array $metricsColumns,
    array $availabilityColumns,
    ?string $townColumn,
    ?string $townValue,
    string $shopAlias = 's',
    string $metricsAlias = 'sm',
    string $availabilityAlias = 'sa'
): array {
    $parts = [];
    $params = [];

    $normalized = static function (string $column, string $minKey, string $maxKey, bool $higherBetter): string {
        if ($higherBetter) {
            return sprintf(
                'COALESCE((COALESCE(%s, 0) - :%s) / NULLIF(:%s - :%s, 0), 0)',
                $column,
                $minKey,
                $maxKey,
                $minKey
            );
        }

        return sprintf(
            'COALESCE((:%s - COALESCE(%s, 0)) / NULLIF(:%s - :%s, 0), 0)',
            $maxKey,
            $column,
            $maxKey,
            $minKey
        );
    };

    $addWeighted = static function (string $criterion, string $expr) use (&$parts, &$params, $weights): void {
        $weight = (float) ($weights[$criterion] ?? 0);
        if ($weight == 0.0) {
            return;
        }
        $paramKey = 'dss_weight_' . $criterion;
        $parts[] = sprintf('(:%s * %s)', $paramKey, $expr);
        $params[$paramKey] = $weight;
    };

    $metricMap = [
        'avg_rating' => ['column' => 'avg_rating', 'higher' => true],
        'completion_rate' => ['column' => 'completion_rate', 'higher' => true],
        'avg_turnaround' => ['column' => 'avg_turnaround_days', 'higher' => false],
        'price_index' => ['column' => 'price_index', 'higher' => false],
        'cancellation_rate' => ['column' => 'cancellation_rate', 'higher' => false],
    ];

    foreach ($metricMap as $key => $details) {
        if (!in_array($details['column'], $metricsColumns, true)) {
            continue;
        }
        if (!isset($bounds[$key])) {
            continue;
        }
        $minKey = 'dss_min_' . $key;
        $maxKey = 'dss_max_' . $key;
        $params[$minKey] = $bounds[$key]['min'];
        $params[$maxKey] = $bounds[$key]['max'];
        $expr = $normalized(sprintf('%s.%s', $metricsAlias, $details['column']), $minKey, $maxKey, $details['higher']);
        $addWeighted($key, $expr);
    }

    $availabilityFields = array_values(array_intersect(
        $availabilityColumns,
        ['accepting_orders', 'accepting_quotes', 'accepting_custom', 'accepting_rush']
    ));
    if ($availabilityFields) {
        $availabilityExpr = implode(
            ' + ',
            array_map(
                static fn(string $column): string => sprintf('COALESCE(%s.%s, 0)', $availabilityAlias, $column),
                $availabilityFields
            )
        );
        $availabilityExpr = sprintf('(%s) / %d', $availabilityExpr, count($availabilityFields));
        $addWeighted('availability', $availabilityExpr);
    }

    if ($townColumn && $townValue !== null && $townValue !== '') {
        $params['dss_town_match'] = '%' . $townValue . '%';
        $townExpr = sprintf('CASE WHEN %s.%s LIKE :dss_town_match THEN 1 ELSE 0 END', $shopAlias, $townColumn);
        $addWeighted('town_match', $townExpr);
    }

    return [
        'sql' => $parts ? '(' . implode(' + ', $parts) . ')' : '0',
        'params' => $params,
    ];
}

function dss_log(int $clientUserId, array $queryData, array $resultsData): void
{
    if (!dss_table_exists('dss_logs')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO dss_logs (client_user_id, query_json, results_json, created_at)
             VALUES (:client_user_id, :query_json, :results_json, :created_at)'
        );
        $stmt->execute([
            'client_user_id' => $clientUserId,
            'query_json' => json_encode($queryData, JSON_THROW_ON_ERROR),
            'results_json' => json_encode($resultsData, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException | JsonException $exception) {
        return;
    }
}
