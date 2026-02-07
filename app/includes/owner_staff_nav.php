<?php
if (!isset($currentPath)) {
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}
?>

<nav class="nav nav-pills flex-column flex-md-row gap-2 mb-4">
    <?php foreach ($staffNavItems as $item): ?>
        <?php if (!empty($item['owner_only']) && !$isOwner): ?>
            <?php continue; ?>
        <?php endif; ?>
        <?php $isActive = $currentPath === $item['path']; ?>
        <a class="nav-link <?= $isActive ? 'active' : 'text-dark' ?>" href="<?= htmlspecialchars($item['path'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
</nav>
