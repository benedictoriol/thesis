<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Admin Console';
}
$activeNav = $activeNav ?? '';
$currentUserName = $currentUser['fullname'] ?? 'SYS_ADMIN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">Governance Console</span>
        <div class="d-flex align-items-center text-white">
            <span class="me-3 small">Logged in as <?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn btn-outline-light btn-sm" href="/auth/logout">Logout</a>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 border-end bg-white min-vh-100 p-3">
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="/admin/dashboard">Dashboard</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'applications' ? 'active' : '' ?>" href="/admin/shops/applications">Shop Applications</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'users' ? 'active' : '' ?>" href="/admin/users">Users</a>
                <div class="list-group-item text-uppercase small text-muted mt-3">Moderation</div>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_products' ? 'active' : '' ?>" href="/admin/moderation/products">Products</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_portfolio' ? 'active' : '' ?>" href="/admin/moderation/portfolio">Portfolio</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'moderation_reviews' ? 'active' : '' ?>" href="/admin/moderation/reviews">Reviews</a>
                <div class="list-group-item text-uppercase small text-muted mt-3">Config</div>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'dss_config' ? 'active' : '' ?>" href="/admin/dss/config">DSS Config</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'reports' ? 'active' : '' ?>" href="/admin/reports">Reports</a>
                <a class="list-group-item list-group-item-action <?= $activeNav === 'audit_logs' ? 'active' : '' ?>" href="/admin/audit-logs">Audit Logs</a>
            </div>
        </aside>
        <main class="col-lg-10 p-4">
