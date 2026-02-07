<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/audit.php';

function register_user(array $data, string $role): array
{
    $errors = [];
    $fullname = trim($data['fullname'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $phone = trim($data['phone'] ?? '');

    if ($fullname === '') {
        $errors[] = 'Full name is required.';
    }

    if (!validate_email($email)) {
        $errors[] = 'A valid email is required.';
    }

    if (!validate_password($password)) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        $errors[] = 'Email is already registered.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $status = $role === 'owner' ? 'pending' : 'active';
    $stmt = db()->prepare(
        'INSERT INTO users (fullname, email, password_hash, role, status, phone, created_at, last_login)
         VALUES (:fullname, :email, :password_hash, :role, :status, :phone, :created_at, NULL)'
    );
    $stmt->execute([
        'fullname' => $fullname,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $status,
        'phone' => $phone !== '' ? $phone : null,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $userId = (int) db()->lastInsertId();
    audit_log($userId, 'register', 'users', $userId, ['role' => $role, 'status' => $status]);

    return ['ok' => true, 'user_id' => $userId, 'status' => $status];
}

function login_user(string $email, string $password, string $ip, string $userAgent): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Account is not active.'];
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 60 * 60 * 6);

    $stmt = db()->prepare(
        'INSERT INTO user_sessions (user_id, session_token, ip, user_agent, created_at, expires_at)
         VALUES (:user_id, :session_token, :ip, :user_agent, :created_at, :expires_at)'
    );
    $stmt->execute([
        'user_id' => $user['id'],
        'session_token' => $token,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'expires_at' => $expiresAt,
    ]);

    $_SESSION['session_token'] = $token;
    $_SESSION['user_id'] = (int) $user['id'];

    $stmt = db()->prepare('UPDATE users SET last_login = :last_login WHERE id = :id');
    $stmt->execute([
        'last_login' => gmdate('Y-m-d H:i:s'),
        'id' => $user['id'],
    ]);

    audit_log((int) $user['id'], 'login', 'user_sessions', null, ['ip' => $ip]);

    return ['ok' => true, 'user' => $user];
}

function logout_user(): void
{
    $token = $_SESSION['session_token'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if ($token) {
        $stmt = db()->prepare('DELETE FROM user_sessions WHERE session_token = :token');
        $stmt->execute(['token' => $token]);
    }

    if ($userId) {
        audit_log((int) $userId, 'logout', 'user_sessions', null, []);
    }

    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
}

function current_user(): ?array
{
    $token = $_SESSION['session_token'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (!$token || !$userId) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT users.* FROM users
         JOIN user_sessions ON users.id = user_sessions.user_id
         WHERE user_sessions.session_token = :token
         AND user_sessions.expires_at > :now
         LIMIT 1'
    );
    $stmt->execute([
        'token' => $token,
        'now' => gmdate('Y-m-d H:i:s'),
    ]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        logout_user();
        return null;
    }

    return $user;
}

function disable_user(int $targetUserId, int $actorUserId): void
{
    $stmt = db()->prepare('UPDATE users SET status = :status WHERE id = :id');
    $stmt->execute([
        'status' => 'disabled',
        'id' => $targetUserId,
    ]);

    $stmt = db()->prepare('DELETE FROM user_sessions WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $targetUserId]);

    audit_log($actorUserId, 'disable', 'users', $targetUserId, []);
}