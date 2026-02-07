<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Availability Settings';
$errors = [];
$successMessage = flash_get('success');
$shop = null;
$availability = [];

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

$availabilityColumns = get_table_columns(db(), 'shop_availability');
if (!$availabilityColumns) {
    $errors[] = 'Availability settings are not available right now.';
}

if ($shop && $availabilityColumns) {
    try {
        $stmt = db()->prepare('SELECT * FROM shop_availability WHERE shop_id = :shop_id LIMIT 1');
        $stmt->execute(['shop_id' => $shop['id']]);
        $availability = $stmt->fetch() ?: [];
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load availability right now.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_availability' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $acceptingOrders = isset($_POST['accepting_orders']) ? 1 : 0;
        $acceptingQuotes = isset($_POST['accepting_quotes']) ? 1 : 0;
        $acceptingCustom = isset($_POST['accepting_custom']) ? 1 : 0;
        $acceptingRush = isset($_POST['accepting_rush']) ? 1 : 0;

        try {
            $stmt = db()->prepare(
                'INSERT INTO shop_availability (shop_id, accepting_orders, accepting_quotes, accepting_custom, accepting_rush, updated_at)
                 VALUES (:shop_id, :accepting_orders, :accepting_quotes, :accepting_custom, :accepting_rush, :updated_at)
                 ON DUPLICATE KEY UPDATE accepting_orders = VALUES(accepting_orders),
                     accepting_quotes = VALUES(accepting_quotes),
                     accepting_custom = VALUES(accepting_custom),
                     accepting_rush = VALUES(accepting_rush),
                     updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                'shop_id' => $shop['id'],
                'accepting_orders' => $acceptingOrders,
                'accepting_quotes' => $acceptingQuotes,
                'accepting_custom' => $acceptingCustom,
                'accepting_rush' => $acceptingRush,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            flash_set('success', 'Availability settings updated.');
            header('Location: /owner/shop/availability');
            exit;
        } catch (PDOException $exception) {
            $errors[] = 'Unable to save availability right now.';
        }
    }
}

$availability = $availability ?: [
    'accepting_orders' => 1,
    'accepting_quotes' => 1,
    'accepting_custom' => 1,
    'accepting_rush' => 0,
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Availability</h1>
        <p class="text-muted mb-0">Control which requests your shop accepts.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/profile">Shop profile</a>
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/hours">Weekly hours</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop && $availabilityColumns): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="mb-3">
                <strong><?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="text-muted small">These toggles affect what clients can submit.</div>
            </div>
            <form method="post" class="d-grid gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_availability">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="accepting_orders" name="accepting_orders" value="1"
                        <?= !empty($availability['accepting_orders']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="accepting_orders">Accepting orders</label>
                    <div class="form-text">If disabled, the shop will not accept new orders.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="accepting_quotes" name="accepting_quotes" value="1"
                        <?= !empty($availability['accepting_quotes']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="accepting_quotes">Accepting quote requests</label>
                    <div class="form-text">If disabled, clients cannot submit quote requests or posts.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="accepting_custom" name="accepting_custom" value="1"
                        <?= !empty($availability['accepting_custom']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="accepting_custom">Accepting custom work</label>
                    <div class="form-text">Toggle availability for bespoke projects.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="accepting_rush" name="accepting_rush" value="1"
                        <?= !empty($availability['accepting_rush']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="accepting_rush">Accepting rush orders</label>
                    <div class="form-text">Turn off when rush capacity is full.</div>
                </div>
                <div class="d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Save availability</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>