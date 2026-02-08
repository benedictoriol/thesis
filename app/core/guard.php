<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/url.php';

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        redirect('/auth/login');
    }
}