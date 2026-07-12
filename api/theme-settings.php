<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_owner()) {
    json_response(false, 'Only the owner can update theme settings.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

$allowedKeys = [
    'body_bg','topbar_bg','card_bg','border_soft','text_main','text_muted',
    'sidebar_bg','sidebar_text','sidebar_hover_bg','sidebar_active_bg',
    'brand_1','brand_2','success_color','warning_color','danger_color','info_color'
];

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        "INSERT INTO website_color_settings
            (business_id, setting_key, setting_value, setting_label, setting_group, is_active, updated_by)
         VALUES
            (?, ?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            setting_label = VALUES(setting_label),
            setting_group = VALUES(setting_group),
            is_active = 1,
            updated_by = VALUES(updated_by)"
    );

    foreach ($allowedKeys as $key) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            throw new RuntimeException('Invalid colour value for ' . $key);
        }

        $label = ucwords(str_replace('_', ' ', $key));
        $group = str_starts_with($key, 'sidebar_') ? 'sidebar'
            : (str_starts_with($key, 'brand_') ? 'brand'
            : (str_ends_with($key, '_color') ? 'status'
            : (str_starts_with($key, 'text_') ? 'text' : 'layout')));

        $stmt->execute([
            $currentBusinessId,
            $key,
            strtoupper($value),
            $label,
            $group,
            current_user_id()
        ]);
    }

    $pdo->commit();
    json_response(true, 'Theme settings updated successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(false, $e->getMessage(), [], 422);
}
