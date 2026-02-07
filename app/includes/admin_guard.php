<?php

require_once __DIR__ . '/../core/auth.php';

function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /auth/login');
        exit;
    }

    if ($user['role'] !== 'sys_admin') {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    return $user;
}
