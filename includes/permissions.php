<?php
declare(strict_types=1);

/**
 * RAD LINK role permission helper.
 *
 * Add this file to includes/bootstrap.php after the session and database are
 * available:
 *     require_once __DIR__ . '/permissions.php';
 */

function permission_action_column(string $action): string
{
    return match ($action) {
        'view' => 'can_view',
        'create' => 'can_create',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
        'approve' => 'can_approve',
        default => throw new InvalidArgumentException('Invalid permission action.'),
    };
}

function current_access_role(): string
{
    return (string)($_SESSION['access_role'] ?? $_SESSION['role_key'] ?? '');
}

function is_permission_bypass_user(): bool
{
    return (int)($_SESSION['is_super_admin'] ?? 0) === 1
        || current_access_role() === 'owner';
}

function can_access(string $menuSlug, string $action = 'view'): bool
{
    global $pdo, $currentBusinessId;

    if (is_permission_bypass_user()) {
        return true;
    }

    if ($currentBusinessId <= 0 || current_user_id() <= 0) {
        return false;
    }

    $column = permission_action_column($action);
    $roleKey = current_access_role();

    if ($roleKey === '') {
        return false;
    }

    static $cache = [];
    $cacheKey = $currentBusinessId . '|' . $roleKey . '|' . $menuSlug . '|' . $column;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        "SELECT bra.$column
         FROM business_role_sidebar_access bra
         INNER JOIN business_sidebar_menus menu
            ON menu.id = bra.menu_id
           AND menu.business_id = bra.business_id
         WHERE bra.business_id = ?
           AND bra.role_key = ?
           AND menu.menu_slug = ?
           AND menu.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([
        $currentBusinessId,
        $roleKey,
        $menuSlug,
    ]);

    return $cache[$cacheKey] = (int)$stmt->fetchColumn() === 1;
}

function require_page_permission(string $menuSlug, string $action = 'view'): void
{
    if (can_access($menuSlug, $action)) {
        return;
    }

    http_response_code(403);

    if (
        strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
        === 'xmlhttprequest'
        || str_contains(
            strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')),
            'application/json'
        )
    ) {
        json_response(
            false,
            'You do not have permission to perform this action.',
            [],
            403
        );
    }

    exit(
        '<div style="font-family:Arial;padding:40px">' .
        '<h2>Access Denied</h2>' .
        '<p>You do not have permission to access this page.</p>' .
        '</div>'
    );
}

function permitted_sidebar_menu_ids(): array
{
    global $pdo, $currentBusinessId;

    if (is_permission_bypass_user()) {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM business_sidebar_menus
             WHERE business_id = ?
               AND is_active = 1
               AND show_in_sidebar = 1"
        );
        $stmt->execute([$currentBusinessId]);

        return array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    $stmt = $pdo->prepare(
        "SELECT bra.menu_id
         FROM business_role_sidebar_access bra
         INNER JOIN business_sidebar_menus menu
            ON menu.id = bra.menu_id
           AND menu.business_id = bra.business_id
         WHERE bra.business_id = ?
           AND bra.role_key = ?
           AND bra.can_view = 1
           AND menu.is_active = 1
           AND menu.show_in_sidebar = 1"
    );
    $stmt->execute([
        $currentBusinessId,
        current_access_role(),
    ]);

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}
