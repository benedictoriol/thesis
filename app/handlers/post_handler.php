<?php

require_once __DIR__ . '/../core/db.php';

function list_staff_shops(int $userId, string $role): array
{
    if ($role === 'owner') {
        $stmt = db()->prepare('SELECT id, name FROM shops WHERE owner_user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    if ($role === 'hr' || $role === 'employee') {
        $stmt = db()->prepare(
            'SELECT shops.id, shops.name
             FROM shop_staff
             JOIN shops ON shops.id = shop_staff.shop_id
             WHERE shop_staff.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    return [];
}

function handle_post_attachments(array $files): array
{
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $maxSize = 5 * 1024 * 1024;
    $uploads = [];
    $errors = [];

    if (empty($files) || !isset($files['name'])) {
        return ['paths' => [], 'errors' => []];
    }

    $fileCount = is_array($files['name']) ? count($files['name']) : 0;
    if ($fileCount === 0) {
        return ['paths' => [], 'errors' => []];
    }

    $uploadDir = __DIR__ . '/../../public/uploads/posts';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['paths' => [], 'errors' => ['Unable to prepare upload directory.']];
    }

    for ($index = 0; $index < $fileCount; $index++) {
        $errorCode = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = 'One of the attachments could not be uploaded.';
            continue;
        }

        $tmpName = $files['tmp_name'][$index] ?? '';
        $originalName = $files['name'][$index] ?? '';
        $size = (int) ($files['size'][$index] ?? 0);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = sprintf('File type .%s is not supported.', $extension);
            continue;
        }

        if ($size > $maxSize) {
            $errors[] = sprintf('File %s exceeds the 5MB limit.', $originalName);
            continue;
        }

        $filename = sprintf(
            'post_%s_%s.%s',
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            $extension
        );
        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            $errors[] = 'Unable to save an attachment.';
            continue;
        }

        $uploads[] = '/uploads/posts/' . $filename;
    }

    return ['paths' => $uploads, 'errors' => $errors];
}