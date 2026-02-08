<?php

/**
 * Base-path + URL helpers.
 *
 * This project is commonly hosted inside a subfolder (e.g. http://localhost/thesis-platform).
 * Using url() / redirect() keeps links, assets, and redirects working regardless of base path.
 */

function base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Example:
    // - SCRIPT_NAME: /thesis-platform/public/index.php
    // - dirname(SCRIPT_NAME): /thesis-platform/public
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptDir === '.' || $scriptDir === '') {
        $scriptDir = '';
    }

    // If front controller lives in /public, strip it.
    if (str_ends_with($scriptDir, '/public')) {
        $scriptDir = substr($scriptDir, 0, -7);
    }

    $base = rtrim($scriptDir, '/');
    if ($base === '/' || $base === '') {
        $cached = '';
        return $cached;
    }

    $cached = $base;
    return $cached;
}

/**
 * Build an app URL.
 *
 * - url('/auth/login') => '/thesis-platform/auth/login' (if hosted in /thesis-platform)
 * - url('auth/login')  => '/thesis-platform/auth/login'
 */
function url(string $path = ''): string
{
    $base = base_path();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

/**
 * Build an asset URL.
 */
function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

/**
 * Redirect to an internal path.
 */
function redirect(string $path, int $statusCode = 302): void
{
    header('Location: ' . url($path), true, $statusCode);
    exit;
}

/**
 * Redirect using a full location string/expression.
 *
 * Useful when the code builds the path dynamically (e.g. '/client/orders/' . $id).
 */
function redirect_to(string $location, int $statusCode = 302): void
{
    $base = base_path();

    // Normalize empty location
    if ($location === '') {
        $location = '/';
    }

    // If it's an internal absolute path like "/auth/login", prefix base path if missing.
    if ($base !== '' && str_starts_with($location, '/') && !str_starts_with($location, $base . '/')) {
        $location = $base . $location;
    }

    header('Location: ' . $location, true, $statusCode);
    exit;
}
