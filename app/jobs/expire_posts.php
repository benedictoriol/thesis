<?php

require_once __DIR__ . '/../core/db.php';

$today = gmdate('Y-m-d');
$stmt = db()->prepare(
    "UPDATE client_posts
     SET status = 'expired'
     WHERE status = 'open'
     AND deadline_date IS NOT NULL
     AND deadline_date < :today"
);
$stmt->execute(['today' => $today]);

printf("Expired %d posts.\n", $stmt->rowCount());