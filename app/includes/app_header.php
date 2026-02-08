<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Thesis Portal';
}

require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../core/auth.php';

$currentUser = $currentUser ?? current_user();
$currentRole = $currentUser['role'] ?? 'guest';

function nav_item(string $label, string $path, string $active = ''): string
{
    $href = url($path);
    $isActive = $active === $path;
    $cls = 'nav-link' . ($isActive ? ' active' : '');
    return '<li class="nav-item"><a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
}

$activePath = $activePath ?? '';
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
        <a class="navbar-brand" href="<?= url('/') ?>">Embroidery Platform</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($currentRole === 'client'): ?>
                    <?= nav_item('Home', '/client/home', $activePath) ?>
                    <?= nav_item('Orders', '/client/orders', $activePath) ?>
                    <?= nav_item('Posts', '/client/posts', $activePath) ?>
                    <?= nav_item('Designs', '/client/designs', $activePath) ?>
                <?php elseif ($currentRole === 'owner'): ?>
                    <?= nav_item('Orders', '/owner/orders', $activePath) ?>
                    <?= nav_item('Catalog', '/owner/catalog', $activePath) ?>
                    <?= nav_item('Portfolio', '/owner/portfolio', $activePath) ?>
                    <?= nav_item('Staff', '/owner/staff', $activePath) ?>
                    <?= nav_item('Earnings', '/owner/earnings', $activePath) ?>
                    <?= nav_item('Verification', '/owner/verification', $activePath) ?>
                <?php elseif ($currentRole === 'hr'): ?>
                    <?= nav_item('Inventory', '/hr/inventory', $activePath) ?>
                    <?= nav_item('Hiring', '/hr/hiring', $activePath) ?>
                    <?= nav_item('Payroll', '/hr/payroll/periods', $activePath) ?>
                    <?= nav_item('Payments', '/hr/payments', $activePath) ?>
                    <?= nav_item('Productivity', '/hr/productivity', $activePath) ?>
                <?php elseif ($currentRole === 'employee'): ?>
                    <?= nav_item('Tickets', '/employee/tickets', $activePath) ?>
                    <?= nav_item('Payslips', '/employee/payslips', $activePath) ?>
                <?php endif; ?>

                <?php if ($currentRole !== 'guest'): ?>
                    <?= nav_item('Messages', '/messages', $activePath) ?>
                    <?= nav_item('Notifications', '/notifications', $activePath) ?>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center text-white gap-2">
                <span class="small d-none d-lg-inline"><?= htmlspecialchars($currentUser['fullname'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($currentRole !== 'guest'): ?>
                    <a class="btn btn-outline-light btn-sm" href="<?= url('/auth/logout') ?>">Logout</a>
                <?php else: ?>
                    <a class="btn btn-outline-light btn-sm" href="<?= url('/auth/login') ?>">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<main class="container py-4">