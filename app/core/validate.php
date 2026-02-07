<?php

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_password(string $password, int $minLength = 8): bool
{
    return mb_strlen($password) >= $minLength;
}