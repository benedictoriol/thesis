<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$errors = [];
$successMessage = flash_get('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $result = login_user(
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        );
        if ($result['ok']) {
            flash_set('success', 'Logged in successfully.');
            header('Location: /auth/login');
            exit;
        }
        $errors[] = $result['error'];
    }
}

$pageTitle = 'Login';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Login</h1>
<?php if ($successMessage): ?>
    <div class="alert alert-info"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>
<form method="POST">
    <?= csrf_field(); ?>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input class="form-control" type="password" id="password" name="password" required>
    </div>

    <button class="btn btn-primary w-100" type="submit">Login</button>
</form>
<div class="d-flex justify-content-between mt-3">
    <a href="/auth/register_client">Register client</a>
    <a href="/auth/register_owner">Register owner</a>
</div>
<div class="d-flex justify-content-between mt-2">
    <a href="/auth/forgot_password">Forgot password?</a>
    <a href="/auth/logout">Logout</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>