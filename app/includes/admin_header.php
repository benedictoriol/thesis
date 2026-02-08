<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Admin Console';
}
$activeNav = $activeNav ?? '';
require_once __DIR__ . '/../core/url.php';
$currentUserName = $currentUser['fullname'] ?? 'SYS_ADMIN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= asset('app.css') ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="app-body">
<nav class="navbar navbar-expand-lg app-navbar navbar-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Governance Console</span>
        <div class="d-flex align-items-center text-white">
            <span class="me-3 small">Logged in as <?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn btn-outline-light btn-sm" href="<?= url('/auth/logout') ?>">Logout</a>
        </div>
    </div>
</nav>
<div class="container-fluid app-shell">
    <div class="row">
        <aside class="col-lg-2 min-vh-100 p-3 app-sidebar">
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= url('/admin/dashboard') ?>">Dashboard</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'applications' ? 'active' : '' ?>" href="<?= url('/admin/shops/applications') ?>">Shop Applications</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'users' ? 'active' : '' ?>" href="<?= url('/admin/users') ?>">Users</a>
                <div class="list-group-item text-uppercase small text-muted mt-3">Moderation</div>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_products' ? 'active' : '' ?>" href="<?= url('/admin/moderation/products') ?>">Products</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_portfolio' ? 'active' : '' ?>" href="<?= url('/admin/moderation/portfolio') ?>">Portfolio</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_reviews' ? 'active' : '' ?>" href="<?= url('/admin/moderation/reviews') ?>">Reviews</a>
                <div class="list-group-item text-uppercase small text-muted mt-3">Config</div>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'dss_config' ? 'active' : '' ?>" href="<?= url('/admin/dss/config') ?>">DSS Config</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'reports' ? 'active' : '' ?>" href="<?= url('/admin/reports') ?>">Reports</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'audit_logs' ? 'active' : '' ?>" href="<?= url('/admin/audit-logs') ?>">Audit Logs</a>
            </div>
        </aside>
        <main class="col-lg-10 p-4">
