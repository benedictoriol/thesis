<?php

require_once __DIR__ . '/../../core/guard.php';

require_role(['owner', 'hr']);

$pageTitle = 'Inventory Hub';

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Inventory + Suppliers + Storage</h1>
        <p class="text-muted mb-0">Track materials, stock movements, suppliers, and low stock alerts.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Materials</h2>
                <p class="text-muted small mb-3">Maintain material list, reorder levels, and status.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/materials">Open materials</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Suppliers</h2>
                <p class="text-muted small mb-3">Manage supplier contacts and notes.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/suppliers">Open suppliers</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Stock In</h2>
                <p class="text-muted small mb-3">Record incoming materials and costs.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/stock-in">Record stock in</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Stock Out</h2>
                <p class="text-muted small mb-3">Track usage, waste, and manual adjustments.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/stock-out">Record stock out</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Storage</h2>
                <p class="text-muted small mb-3">Document storage locations and descriptions.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/storage">Open storage</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Alerts</h2>
                <p class="text-muted small mb-3">Review items below reorder levels.</p>
                <a class="btn btn-outline-primary btn-sm" href="/hr/inventory/alerts">View alerts</a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>