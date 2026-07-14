<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

$action = trim((string)($_POST['action'] ?? ''));

function user_activity(PDO $pdo, int $businessId, string $action, int $userId, string $description): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs
                (business_id, user_id, module_key, action_type, entity_type,
                 entity_id, description, ip_address, user_agent)
             VALUES (?, ?, 'users', ?, 'user', ?, ?, ?, ?)"
        );
        $stmt->execute([
            $businessId,
            current_user_id(),
            $action,
            $userId,
            substr($description, 0, 500),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000),
        ]);
    } catch (Throwable $e) {
        error_log('[USER ACTIVITY] ' . $e->getMessage());
    }
}

if ($action === 'save_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    require_page_permission('users', $userId > 0 ? 'edit' : 'create');

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $mobile = trim((string)($_POST['mobile'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $accessRole = strtolower(trim((string)($_POST['access_role'] ?? '')));
    $status = trim((string)($_POST['status'] ?? 'active'));

    if ($fullName === '' || $username === '' || $accessRole === '') {
        json_response(false, 'Name, username and business role are required.', [], 422);
    }
    if (!preg_match('/^[a-z0-9._-]{3,100}$/', $username)) {
        json_response(false, 'Username must contain 3–100 lowercase letters, numbers, dot, underscore or hyphen.', [], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Enter a valid email address.', [], 422);
    }
    if (!in_array($status, ['active','inactive','blocked'], true)) {
        $status = 'active';
    }
    if ($userId <= 0 && strlen($password) < 8) {
        json_response(false, 'Password must contain at least 8 characters.', [], 422);
    }
    if ($password !== '' && strlen($password) < 8) {
        json_response(false, 'Password must contain at least 8 characters.', [], 422);
    }
    if ($password !== $confirmPassword) {
        json_response(false, 'Password and confirmation do not match.', [], 422);
    }

    $roleStmt = $pdo->prepare(
        "SELECT role_key
         FROM business_roles
         WHERE business_id = ?
           AND role_key = ?
           AND status = 'active'
         LIMIT 1"
    );
    $roleStmt->execute([$currentBusinessId, $accessRole]);
    if (!$roleStmt->fetch()) {
        json_response(false, 'Selected role is invalid or inactive.', [], 422);
    }

    $duplicateStmt = $pdo->prepare(
        "SELECT id FROM users
         WHERE (username = ? OR (? <> '' AND email = ?))
           AND id <> ?
         LIMIT 1"
    );
    $duplicateStmt->execute([$username, $email, $email, $userId]);
    if ($duplicateStmt->fetch()) {
        json_response(false, 'Username or email is already used by another account.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        if ($userId > 0) {
            $accessStmt = $pdo->prepare(
                "SELECT id
                 FROM user_business_access
                 WHERE user_id = ?
                   AND business_id = ?
                 FOR UPDATE"
            );
            $accessStmt->execute([$userId, $currentBusinessId]);
            if (!$accessStmt->fetch()) {
                throw new RuntimeException('User does not belong to the selected business.');
            }

            $sql = "UPDATE users
                    SET full_name = ?, username = ?, email = ?, mobile = ?,
                        role_key = ?, status = ?";
            $params = [
                $fullName,
                $username,
                $email ?: null,
                $mobile ?: null,
                $accessRole,
                $status,
            ];

            if ($password !== '') {
                $sql .= ", password_hash = ?, password_changed_at = NOW()";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = ?";
            $params[] = $userId;

            $pdo->prepare($sql)->execute($params);

            $pdo->prepare(
                "UPDATE user_business_access
                 SET access_role = ?, status = ?
                 WHERE user_id = ? AND business_id = ?"
            )->execute([
                $accessRole,
                $status === 'active' ? 'active' : 'inactive',
                $userId,
                $currentBusinessId,
            ]);

            user_activity(
                $pdo,
                $currentBusinessId,
                'update',
                $userId,
                'Updated user ' . $username
            );
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO users
                    (full_name, username, email, mobile, password_hash,
                     role_key, is_super_admin, status)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
            );
            $insert->execute([
                $fullName,
                $username,
                $email ?: null,
                $mobile ?: null,
                password_hash($password, PASSWORD_DEFAULT),
                $accessRole,
                $status,
            ]);
            $userId = (int)$pdo->lastInsertId();

            $defaultStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM user_business_access
                 WHERE user_id = ?
                   AND status = 'active'"
            );
            $defaultStmt->execute([$userId]);
            $isDefault = (int)$defaultStmt->fetchColumn() === 0 ? 1 : 0;

            $pdo->prepare(
                "INSERT INTO user_business_access
                    (user_id, business_id, access_role, is_default, status)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                $userId,
                $currentBusinessId,
                $accessRole,
                $isDefault,
                $status === 'active' ? 'active' : 'inactive',
            ]);

            user_activity(
                $pdo,
                $currentBusinessId,
                'create',
                $userId,
                'Created user ' . $username
            );
        }

        $pdo->commit();

        json_response(true, 'User saved successfully.', [
            'user_id' => $userId,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[USER SAVE] ' . $e->getMessage());
        json_response(false, $e->getMessage(), [], 422);
    }
}

if ($action === 'toggle_status') {
    require_page_permission('users', 'approve');

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === current_user_id()) {
        json_response(false, 'You cannot change your own account status here.', [], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT u.status
         FROM users u
         INNER JOIN user_business_access uba ON uba.user_id = u.id
         WHERE u.id = ? AND uba.business_id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId, $currentBusinessId]);
    $currentStatus = $stmt->fetchColumn();
    if ($currentStatus === false) {
        json_response(false, 'User not found.', [], 404);
    }

    $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
        $pdo->prepare(
            "UPDATE user_business_access SET status = ?
             WHERE user_id = ? AND business_id = ?"
        )->execute([
            $newStatus === 'active' ? 'active' : 'inactive',
            $userId,
            $currentBusinessId,
        ]);
        user_activity($pdo, $currentBusinessId, 'status', $userId, 'Changed user status to ' . $newStatus);
        $pdo->commit();
        json_response(true, 'User status changed successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(false, $e->getMessage(), [], 422);
    }
}

if ($action === 'remove_access') {
    require_page_permission('users', 'delete');

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === current_user_id()) {
        json_response(false, 'You cannot remove your own business access.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        $delete = $pdo->prepare(
            "DELETE FROM user_business_access
             WHERE user_id = ?
               AND business_id = ?"
        );
        $delete->execute([$userId, $currentBusinessId]);

        if ($delete->rowCount() === 0) {
            throw new RuntimeException('User access was not found.');
        }

        $defaultStmt = $pdo->prepare(
            "SELECT id
             FROM user_business_access
             WHERE user_id = ?
               AND status = 'active'
             ORDER BY is_default DESC, id
             LIMIT 1"
        );
        $defaultStmt->execute([$userId]);
        $remainingAccessId = (int)($defaultStmt->fetchColumn() ?: 0);

        if ($remainingAccessId > 0) {
            $pdo->prepare(
                "UPDATE user_business_access
                 SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END
                 WHERE user_id = ?"
            )->execute([$remainingAccessId, $userId]);
        } else {
            $pdo->prepare(
                "UPDATE users SET status = 'inactive' WHERE id = ?"
            )->execute([$userId]);
        }

        user_activity($pdo, $currentBusinessId, 'delete', $userId, 'Removed user access from business');
        $pdo->commit();
        json_response(true, 'User access removed successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(false, $e->getMessage(), [], 422);
    }
}

json_response(false, 'Invalid user action.', [], 422);
