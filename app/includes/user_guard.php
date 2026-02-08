<?php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/url.php';

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/auth/login');
    }

    return $user;
}
