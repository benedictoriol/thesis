<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../handlers/message_handler.php';

require_role(['client', 'owner', 'hr', 'employee']);
$user = current_user();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId <= 0 || !$user) {
    http_response_code(404);
    echo 'Conversation not found.';
    exit;
}

$conversation = get_conversation_for_user($conversationId, $user);
if (!$conversation) {
    http_response_code(404);
    echo 'Conversation not found.';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $messageText = trim($_POST['message_text'] ?? '');
        $attachment = handle_message_attachment($_FILES['attachment'] ?? []);

        if ($attachment['error']) {
            $errors[] = $attachment['error'];
        }

        if ($messageText === '' && !$attachment['path']) {
            $errors[] = 'Please enter a message or attach a file.';
        }

        if (!$errors) {
            send_message(
                $conversationId,
                (int) $user['id'],
                $messageText,
                $attachment['path']
            );
            redirect_to('' . );
        }
    }
}

mark_conversation_read($conversationId, (int) $user['id']);
$messages = list_messages_for_conversation($conversationId);

$contextLabel = ucwords(str_replace('_', ' ', $conversation['context_type']));
$contextMeta = $conversation['context_id'] ? $contextLabel . ' #' . $conversation['context_id'] : $contextLabel;
$client = $user['role'] === 'client' ? $user : get_conversation_client((int) $conversation['client_user_id']);
$counterparty = $user['role'] === 'client'
    ? ($conversation['shop_name'] ?? 'Shop')
    : ($client['fullname'] ?? 'Client');

$pageTitle = 'Conversation';
require __DIR__ . '/../../includes/app_header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1"><?= htmlspecialchars($counterparty, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="text-muted small"><?= htmlspecialchars($contextMeta, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($conversation['context_type'] === 'quote_request'): ?>
            <div class="small text-primary mt-1">Policy: HR is the primary responder for quote requests.</div>
        <?php endif; ?>
    </div>
    <div class="text-end">
        <a class="btn btn-outline-secondary btn-sm" href="/messages">Back to inbox</a>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="list-group mb-4">
    <?php if (!$messages): ?>
        <div class="list-group-item text-muted">No messages yet. Start the conversation below.</div>
    <?php endif; ?>
    <?php foreach ($messages as $message): ?>
        <?php
        $isSender = (int) $message['sender_user_id'] === (int) $user['id'];
        $messageClass = $isSender ? 'list-group-item list-group-item-primary' : 'list-group-item';
        $attachmentPath = $message['attachment_path'] ?? '';
        $attachmentExtension = $attachmentPath ? strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION)) : '';
        ?>
        <div class="<?= $messageClass ?>">
            <div class="d-flex justify-content-between">
                <div class="fw-semibold"><?= htmlspecialchars($message['sender_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small text-muted"><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php if (trim($message['message_text']) !== ''): ?>
                <div class="mt-2"><?= nl2br(htmlspecialchars($message['message_text'], ENT_QUOTES, 'UTF-8')) ?></div>
            <?php endif; ?>
            <?php if ($attachmentPath): ?>
                <div class="mt-2">
                    <a href="<?= htmlspecialchars($attachmentPath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        View attachment
                    </a>
                </div>
                <?php if (in_array($attachmentExtension, ['jpg', 'jpeg', 'png'], true)): ?>
                    <img class="img-fluid rounded mt-2" src="<?= htmlspecialchars($attachmentPath, ENT_QUOTES, 'UTF-8') ?>" alt="Attachment preview">
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<form method="POST" enctype="multipart/form-data">
    <?= csrf_field(); ?>
    <div class="mb-3">
        <label class="form-label" for="message_text">Message</label>
        <textarea class="form-control" id="message_text" name="message_text" rows="3" placeholder="Type your message..."></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label" for="attachment">Attachment (JPG, PNG, or PDF)</label>
        <input class="form-control" id="attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
    </div>
    <button class="btn btn-primary" type="submit">Send message</button>
</form>

<?php require __DIR__ . '/../../includes/app_footer.php'; ?>
