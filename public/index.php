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
];

if (!array_key_exists($path, $routes)) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

require $routes[$path];