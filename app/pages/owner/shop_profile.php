<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Shop Profile';
$errors = [];
$successMessage = flash_get('success');
$shop = null;

function load_shop_for_user(array $user): ?array
{
    try {
        if ($user['role'] === 'owner') {
            $stmt = db()->prepare(
                'SELECT id, name, description, address_text, logo_path, cover_path
                 FROM shops
                 WHERE owner_user_id = :owner_id
                 LIMIT 1'
            );
            $stmt->execute(['owner_id' => $user['id']]);
            return $stmt->fetch() ?: null;
        }

        if ($user['role'] === 'hr') {
            $stmt = db()->prepare(
                'SELECT s.id, s.name, s.description, s.address_text, s.logo_path, s.cover_path
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile' && !$errors) {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $address = trim((string) ($_POST['address_text'] ?? ''));
        $logoPath = trim((string) ($_POST['logo_path'] ?? ''));
        $coverPath = trim((string) ($_POST['cover_path'] ?? ''));

        if ($name === '') {
            $errors[] = 'Shop name is required.';
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare(
                    'UPDATE shops
                     SET name = :name,
                         description = :description,
                         address_text = :address_text,
                         logo_path = :logo_path,
                         cover_path = :cover_path
                     WHERE id = :shop_id'
                );
                $stmt->execute([
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'address_text' => $address !== '' ? $address : null,
                    'logo_path' => $logoPath !== '' ? $logoPath : null,
                    'cover_path' => $coverPath !== '' ? $coverPath : null,
                    'shop_id' => $shop['id'],
                ]);

                flash_set('success', 'Shop profile updated.');
                header('Location: /owner/shop/profile');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Unable to save the shop profile right now.';
            }
        }
    }

    $shop = load_shop_for_user($currentUser);
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Shop Profile</h1>
        <p class="text-muted mb-0">Manage public details that clients see.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/hours">Weekly hours</a>
        <a class="btn btn-outline-secondary btn-sm" href="/owner/shop/availability">Availability</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="col-md-6">
                    <label class="form-label" for="name">Shop name</label>
                    <input class="form-control" id="name" name="name" value="<?= htmlspecialchars($shop['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="address_text">Address</label>
                    <input class="form-control" id="address_text" name="address_text" value="<?= htmlspecialchars($shop['address_text'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($shop['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="logo_path">Logo URL or path</label>
                    <input class="form-control" id="logo_path" name="logo_path" value="<?= htmlspecialchars($shop['logo_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="cover_path">Cover URL or path</label>
                    <input class="form-control" id="cover_path" name="cover_path" value="<?= htmlspecialchars($shop['cover_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row g-3 mt-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Projects & pricing</h2>
                    <p class="text-muted mb-3">Post services with descriptions and prices for clients browsing your profile.</p>
                    <a class="btn btn-outline-primary btn-sm" href="/owner/catalog">Manage service catalog</a>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Finished project feed</h2>
                    <p class="text-muted mb-3">Share completed projects to showcase them on your public shop profile.</p>
                    <a class="btn btn-outline-primary btn-sm" href="/owner/portfolio">Manage portfolio feed</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>