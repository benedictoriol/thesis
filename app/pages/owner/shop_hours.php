<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Weekly Hours';
$errors = [];
$successMessage = flash_get('success');
$shop = null;
$hours = [];

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
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

$shop = load_shop_for_user($currentUser);

if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$hoursColumns = get_table_columns(db(), 'shop_hours');
if (!$hoursColumns) {
    $errors[] = 'Shop hours are not available right now.';
}

$days = [
    'monday' => 'Monday',
    'tuesday' => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday' => 'Thursday',
    'friday' => 'Friday',
    'saturday' => 'Saturday',
    'sunday' => 'Sunday',
];

if ($shop && $hoursColumns) {
    try {
        $stmt = db()->prepare(
            'SELECT day_of_week, open_time, close_time, is_closed
             FROM shop_hours
             WHERE shop_id = :shop_id'
        );
        $stmt->execute(['shop_id' => $shop['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $hours[$row['day_of_week']] = $row;
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load hours right now.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_hours' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO shop_hours (shop_id, day_of_week, open_time, close_time, is_closed)
                 VALUES (:shop_id, :day_of_week, :open_time, :close_time, :is_closed)
                 ON DUPLICATE KEY UPDATE open_time = VALUES(open_time),
                     close_time = VALUES(close_time),
                     is_closed = VALUES(is_closed)'
            );

            foreach ($days as $dayKey => $dayLabel) {
                $isClosed = isset($_POST['is_closed'][$dayKey]) ? 1 : 0;
                $openTime = trim((string) ($_POST['open_time'][$dayKey] ?? ''));
                $closeTime = trim((string) ($_POST['close_time'][$dayKey] ?? ''));

                $stmt->execute([
                    'shop_id' => $shop['id'],
                    'day_of_week' => $dayKey,
                    'open_time' => $isClosed || $openTime === '' ? null : $openTime,
                    'close_time' => $isClosed || $closeTime === '' ? null : $closeTime,
                    'is_closed' => $isClosed,
                ]);
            }

            flash_set('success', 'Weekly hours updated.');
            header('Location: /owner/shop/hours');
            exit;
        } catch (PDOException $exception) {
            $errors[] = 'Unable to save hours right now.';
        }
    }

    $shop = load_shop_for_user($currentUser);
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Weekly Hours</h1>
        <p class="text-muted mb-0">Set your regular opening schedule.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/profile">Shop profile</a>
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/availability">Availability</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop && $hoursColumns): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="mb-3">
                <strong><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="text-muted small">All times are in your local timezone.</div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_hours">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Day</th>
                            <th>Open</th>
                            <th>Close</th>
                            <th class="text-center">Closed</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($days as $dayKey => $dayLabel): ?>
                            <?php
                            $dayHours = $hours[$dayKey] ?? [];
                            $openValue = $dayHours['open_time'] ?? '';
                            $closeValue = $dayHours['close_time'] ?? '';
                            $isClosed = !empty($dayHours['is_closed']);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($dayLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="max-width: 180px;">
                                    <input class="form-control" type="time" name="open_time[<?= htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8') ?>]"
                                           value="<?= htmlspecialchars($openValue, ENT_QUOTES, 'UTF-8') ?>" <?= $isClosed ? 'disabled' : '' ?>>
                                </td>
                                <td style="max-width: 180px;">
                                    <input class="form-control" type="time" name="close_time[<?= htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8') ?>]"
                                           value="<?= htmlspecialchars($closeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $isClosed ? 'disabled' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="is_closed[<?= htmlspecialchars($dayKey, ENT_QUOTES, 'UTF-8') ?>]" value="1"
                                           <?= $isClosed ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Save hours</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>