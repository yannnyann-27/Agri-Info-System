<?php
// =============================================================================
// includes/csrf.php  |  CSRF protection for actions that change data (delete)
// A random token is kept in the session and sent as a hidden field with the form.
// The server only accepts the POST if the token it receives matches the session's.
// A forged link or a page on another website cannot know the token.
// =============================================================================

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(): bool {
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && $sent !== ''
        && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
}
