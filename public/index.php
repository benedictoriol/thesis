<?php

$appConfig = require __DIR__ . '/../app/config/app.php';
session_name($appConfig['session_name']);
session_set_cookie_params([
    'lifetime' => $appConfig['session_lifetime'],
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/flash.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($uri, '/');
if ($path === '') {
    $path = '/';
}

$routes = [
    '/' => __DIR__ . '/../app/pages/auth/login.php',
    '/auth/login' => __DIR__ . '/../app/pages/auth/login.php',
    '/auth/register_client' => __DIR__ . '/../app/pages/auth/register_client.php',
    '/auth/register_owner' => __DIR__ . '/../app/pages/auth/register_owner.php',
    '/auth/logout' => __DIR__ . '/../app/pages/auth/logout.php',
    '/auth/forgot_password' => __DIR__ . '/../app/pages/auth/forgot_password.php',
    '/admin/dashboard' => __DIR__ . '/../app/pages/admin/dashboard.php',
    '/admin/shops/applications' => __DIR__ . '/../app/pages/admin/shop_applications.php',
    '/admin/users' => __DIR__ . '/../app/pages/admin/users.php',
    '/admin/moderation/products' => __DIR__ . '/../app/pages/admin/moderation_products.php',
    '/admin/moderation/portfolio' => __DIR__ . '/../app/pages/admin/moderation_portfolio.php',
    '/admin/moderation/reviews' => __DIR__ . '/../app/pages/admin/moderation_reviews.php',
    '/admin/dss/config' => __DIR__ . '/../app/pages/admin/dss_config.php',
    '/admin/reports' => __DIR__ . '/../app/pages/admin/reports.php',
    '/admin/audit-logs' => __DIR__ . '/../app/pages/admin/audit_logs.php',
    '/notifications' => __DIR__ . '/../app/pages/notifications/index.php',
    '/messages' => __DIR__ . '/../app/pages/messages/index.php',
    '/client/home' => __DIR__ . '/../app/pages/client/home.php',
    '/client/product_view' => __DIR__ . '/../app/pages/client/product_view.php',
    '/client/search' => __DIR__ . '/../app/pages/client/search.php',
    '/client/customize' => __DIR__ . '/../app/pages/client/customize.php',
    '/client/designs' => __DIR__ . '/../app/pages/client/designs.php',
    '/client/posts' => __DIR__ . '/../app/pages/client/posts.php',
    '/client/posts/create' => __DIR__ . '/../app/pages/client/post_create.php',
    '/client/profile' => __DIR__ . '/../app/pages/client/profile.php',
    '/client/orders' => __DIR__ . '/../app/pages/client/orders.php',
    '/client/quotations' => __DIR__ . '/../app/pages/client/quotations.php',
    '/hr/inventory' => __DIR__ . '/../app/pages/hr/inventory.php',
    '/hr/inventory/materials' => __DIR__ . '/../app/pages/hr/inventory/materials.php',
    '/hr/inventory/suppliers' => __DIR__ . '/../app/pages/hr/inventory/suppliers.php',
    '/hr/inventory/stock-in' => __DIR__ . '/../app/pages/hr/inventory/stock_in.php',
    '/hr/inventory/stock-out' => __DIR__ . '/../app/pages/hr/inventory/stock_out.php',
    '/hr/inventory/storage' => __DIR__ . '/../app/pages/hr/inventory/storage.php',
    '/hr/inventory/alerts' => __DIR__ . '/../app/pages/hr/inventory/alerts.php',
    '/hr/hiring' => __DIR__ . '/../app/pages/hr/hiring.php',
    '/hr/hiring/create' => __DIR__ . '/../app/pages/hr/hiring_create.php',
    '/hr/timesheets' => __DIR__ . '/../app/pages/hr/timesheets.php',
    '/hr/productivity' => __DIR__ . '/../app/pages/hr/productivity.php',
    '/hr/quotes/requests' => __DIR__ . '/../app/pages/hr/quotes/requests.php',
    '/hr/payroll/periods' => __DIR__ . '/../app/pages/hr/payroll/periods.php',
    '/hr/payments' => __DIR__ . '/../app/pages/hr/payments.php',
    '/owner/verification' => __DIR__ . '/../app/pages/owner/verification.php',
    '/owner/verification/upload' => __DIR__ . '/../app/pages/owner/verification_upload.php',
    '/owner/verification/status' => __DIR__ . '/../app/pages/owner/verification_status.php',
    '/owner/shop/profile' => __DIR__ . '/../app/pages/owner/shop_profile.php',
    '/owner/shop/hours' => __DIR__ . '/../app/pages/owner/shop_hours.php',
    '/owner/shop/availability' => __DIR__ . '/../app/pages/owner/shop_availability.php',
    '/owner/catalog' => __DIR__ . '/../app/pages/owner/catalog.php',
    '/owner/catalog/create' => __DIR__ . '/../app/pages/owner/catalog_create.php',
    '/owner/portfolio' => __DIR__ . '/../app/pages/owner/portfolio.php',
    '/owner/portfolio/create' => __DIR__ . '/../app/pages/owner/portfolio_create.php',
    '/owner/staff' => __DIR__ . '/../app/pages/owner/staff.php',
    '/owner/staff/create-hr' => __DIR__ . '/../app/pages/owner/create_hr.php',
    '/owner/staff/permissions' => __DIR__ . '/../app/pages/owner/staff_permissions.php',
    '/owner/earnings' => __DIR__ . '/../app/pages/owner/earnings.php',
    '/owner/hiring/applicants' => __DIR__ . '/../app/pages/owner/hiring/applicants.php',
    '/owner/staff/approved-employees' => __DIR__ . '/../app/pages/owner/approved_employees.php',
    '/owner/orders' => __DIR__ . '/../app/pages/owner/orders.php',
    '/employee/tickets' => __DIR__ . '/../app/pages/employee/tickets.php',
    '/employee/payslips' => __DIR__ . '/../app/pages/employee/payslips.php',
];

if (!array_key_exists($path, $routes)) {
    if (preg_match('#^/admin/shops/(\d+)/review$#', $path, $matches)) {
        $_GET['shop_id'] = $matches[1];
        require __DIR__ . '/../app/pages/admin/shop_review.php';
        exit;
    }
    if (preg_match('#^/product/(\\d+)/reviews$#', $path, $matches)) {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/product_reviews.php';
        exit;
    }
    if (preg_match('#^/shop/(\\d+)$#', $path, $matches)) {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/shop_view.php';
        exit;
    }
    if (preg_match('#^/shop/(\\d+)/reviews$#', $path, $matches)) {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/../app/pages/owner/shop_reviews.php';
        exit;
    }
    if (preg_match('#^/order/(\\d+)/review$#', $path, $matches)) {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/order_review.php';
        exit;
    }
    if (preg_match('#^/owner/catalog/(\\d+)/edit$#', $path, $matches)) {
        $_GET['product_id'] = $matches[1];
        require __DIR__ . '/../app/pages/owner/catalog_edit.php';
        exit;
    }
    if (preg_match('#^/hr/hiring/(\\d+)/applicants$#', $path, $matches)) {
        $_GET['post_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/hiring_applicants.php';
        exit;
    }
    if (preg_match('#^/hr/productivity/(\\d+)$#', $path, $matches)) {
        $_GET['employee_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/productivity_view.php';
        exit;
    }
    if (preg_match('#^/hr/quotes/requests/(\\d+)$#', $path, $matches)) {
        $_GET['request_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/quotes/request_view.php';
        exit;
    }
    if (preg_match('#^/hr/payments/(\\d+)$#', $path, $matches)) {
        $_GET['payment_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/payment_view.php';
        exit;
    }
    if (preg_match('#^/hr/quotes/(\\d+)/revise$#', $path, $matches)) {
        $_GET['quote_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/quotes/revise.php';
        exit;
    }
    if (preg_match('#^/hr/payroll/periods/(\\d+)$#', $path, $matches)) {
        $_GET['period_id'] = $matches[1];
        require __DIR__ . '/../app/pages/hr/payroll/period_view.php';
        exit;
    }
    if (preg_match('#^/notifications/(\d+)$#', $path, $matches)) {
        $_GET['notification_id'] = $matches[1];
        require __DIR__ . '/../app/pages/notifications/view.php';
        exit;
    }
    if (preg_match('#^/messages/(\d+)$#', $path, $matches)) {
        $_GET['conversation_id'] = $matches[1];
        require __DIR__ . '/../app/pages/messages/view.php';
        exit;
    }
    if (preg_match('#^/client/orders/(\d+)$#', $path, $matches)) {
        $_GET['order_id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/order_view.php';
        exit;
    }
    if (preg_match('#^/owner/orders/(\d+)$#', $path, $matches)) {
        $_GET['order_id'] = $matches[1];
        require __DIR__ . '/../app/pages/owner/order_view.php';
        exit;
    }
    if (preg_match('#^/owner/orders/(\d+)/tickets$#', $path, $matches)) {
        $_GET['order_id'] = $matches[1];
        require __DIR__ . '/../app/pages/owner/order_tickets.php';
        exit;
    }
    if (preg_match('#^/employee/tickets/(\d+)$#', $path, $matches)) {
        $_GET['ticket_id'] = $matches[1];
        require __DIR__ . '/../app/pages/employee/ticket_view.php';
        exit;
    }
    if (preg_match('#^/client/orders/(\d+)/payment$#', $path, $matches)) {
        $_GET['order_id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/payment.php';
        exit;
    }
    if (preg_match('#^/client/designs/(\\d+)$#', $path, $matches)) {
        $_GET['design_id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/design_view.php';
        exit;
    }
    if (preg_match('#^/client/posts/(\\d+)$#', $path, $matches)) {
        $_GET['post_id'] = $matches[1];
        require __DIR__ . '/../app/pages/client/post_view.php';
        exit;
    }
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require $routes[$path];