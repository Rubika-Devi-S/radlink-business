<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO login_activity_logs
                (user_id, username_entered, event_type, ip_address, user_agent)
             VALUES
                (:user_id, :username_entered, 'logout', :ip_address, :user_agent)"
        );

        $stmt->execute([
            'user_id' => $_SESSION['user_id'],
            'username_entered' => $_SESSION['username'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $exception) {
        error_log('Logout log failed: ' . $exception->getMessage());
    }
}

logout_user();

header('Location: login.php');
exit;
