<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/admin_guard.php';

$currentUser = require_admin();
$pageTitle = 'Users';
$activeNav = 'users';

$stmt = db()->query('SELECT id, fullname, email, role, status, created_at, last_login FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll();

require __DIR__ . '/../../includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">User Directory</h1>
        <p class="text-muted mb-0">Audit platform access by role and status.</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Last login</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $user['role']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($user['last_login'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/admin_footer.php'; ?>