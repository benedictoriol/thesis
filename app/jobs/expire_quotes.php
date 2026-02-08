<?php

require_once __DIR__ . '/../core/db.php';

$now = gmdate('Y-m-d H:i:s');

try {
    $stmt = db()->prepare(
        "SELECT id FROM quotes
         WHERE status IN ('sent', 'revised')
         AND valid_until IS NOT NULL
         AND valid_until < :now"
    );
    $stmt->execute(['now' => $now]);
    $quoteIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$quoteIds) {
        printf("Expired 0 quotes.\n");
        exit(0);
    }

    $placeholders = implode(',', array_fill(0, count($quoteIds), '?'));

    db()->beginTransaction();

    $updateQuotes = db()->prepare(
        "UPDATE quotes
         SET status = 'rejected'
         WHERE id IN ($placeholders)"
    );
    $updateQuotes->execute($quoteIds);

    $updateRequests = db()->prepare(
        "UPDATE quote_requests qr
         JOIN quotes q ON q.quote_request_id = qr.id
         SET qr.status = 'expired'
         WHERE q.id IN ($placeholders)
         AND qr.status IN ('pending', 'quoted')"
    );
    $updateRequests->execute($quoteIds);

    $logStmt = db()->prepare(
        'INSERT INTO quote_status_logs (quote_id, status, changed_by_user_id, note, created_at)
         VALUES (:quote_id, :status, :changed_by_user_id, :note, :created_at)'
    );

    foreach ($quoteIds as $quoteId) {
        $logStmt->execute([
            'quote_id' => $quoteId,
            'status' => 'expired',
            'changed_by_user_id' => null,
            'note' => 'Quote expired after validity period.',
            'created_at' => $now,
        ]);
    }

    db()->commit();

    printf("Expired %d quotes.\n", count($quoteIds));
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    fwrite(STDERR, "Failed to expire quotes.\n");
    exit(1);
}
