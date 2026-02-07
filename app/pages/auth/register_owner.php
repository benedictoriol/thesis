<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $result = register_user($_POST, 'owner');
        if ($result['ok']) {
            $successMessage = 'Owner account created and pending verification.';
        } else {
            $errors = $result['errors'];
        }
    }
}

$pageTitle = 'Register Owner';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Owner Registration</h1>
<?php if ($successMessage): ?>
    <div class="alert alert-info"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>
<form method="POST">
    <?= csrf_field(); ?>
    <div class="mb-3">
        <label for="fullname" class="form-label">Full name</label>
        <input class="form-control" type="text" id="fullname" name="fullname" value="<?= htmlspecialchars($_POST['fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="mb-3">
        <label for="phone" class="form-label">Phone (optional)</label>
        <input class="form-control" type="text" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input class="form-control" type="password" id="password" name="password" required>
    </div>

    <button class="btn btn-primary w-100" type="submit">Create account</button>
</form>
<div class="d-flex justify-content-between mt-3">
    <a href="/auth/login">Login</a>
    <a href="/auth/register_client">Register as client</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>