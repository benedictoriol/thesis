<?php

require_once __DIR__ . '/../core/auth.php';

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /auth/login');
        exit;
    }

    return $user;
}
