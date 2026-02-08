<?php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/url.php';

function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/auth/login');
    }

    if ($user['role'] !== 'sys_admin') {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }

    return $user;
}
