<?php
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$pageTitle = 'Forgot Password';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Forgot Password</h1>
<p>Please contact support to reset your password.</p>
<div class="d-flex justify-content-between mt-3">
    <a href="/auth/login">Back to login</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
