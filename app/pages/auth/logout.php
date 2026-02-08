<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_verify()) {
        logout_user();
        flash_set('success', 'Logged out successfully.');
        header('Location: /auth/login');
        exit;
    }
}

$pageTitle = 'Logout';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Logout</h1>
<p>Are you sure you want to log out?</p>
<form method="POST" data-confirm="Log out of your account now?" data-confirm-title="Confirm logout">
    <?= csrf_field(); ?>
    <button class="btn btn-danger w-100" type="submit">Logout</button>
</form>
<div class="d-flex justify-content-between mt-3">
    <a href="/auth/login">Back to login</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>