<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $marker = '/modules/';
    $pos = strpos($script, $marker);

    if ($pos !== false) {
        $base = rtrim(substr($script, 0, $pos), '/');
    } else {
        $base = rtrim(dirname($script), '/.');
    }

    return $base;
}

function app_url(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

function current_business_id(): int
{
    return (int)($_SESSION['business_id'] ?? 0);
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function is_owner(): bool
{
    return !empty($_SESSION['is_super_admin']) || ($_SESSION['role_key'] ?? '') === 'owner';
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function json_response(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_businesses_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT b.id,b.business_name,b.business_code,b.logo_path,uba.is_default
         FROM user_business_access uba
         JOIN businesses b ON b.id=uba.business_id
         WHERE uba.user_id=? AND uba.status='active' AND b.status='active'
         ORDER BY uba.is_default DESC,b.business_name"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function resolve_current_business(PDO $pdo, int $userId): ?array
{
    $businesses = get_businesses_for_user($pdo, $userId);
    if (!$businesses) return null;

    $current = current_business_id();
    foreach ($businesses as $business) {
        if ((int)$business['id'] === $current) return $business;
    }

    $_SESSION['business_id'] = (int)$businesses[0]['id'];
    $_SESSION['business_name'] = $businesses[0]['business_name'];
    return $businesses[0];
}

function ui_setting(PDO $pdo, string $key, string $default = ''): string
{
    if (!table_exists($pdo, 'ui_settings')) return $default;
    $businessId = current_business_id();
    $stmt = $pdo->prepare(
        "SELECT setting_value FROM ui_settings
         WHERE setting_key=? AND (business_id=? OR business_id IS NULL)
         ORDER BY business_id IS NULL ASC LIMIT 1"
    );
    $stmt->execute([$key, $businessId]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function save_ui_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO ui_settings (business_id,setting_key,setting_value,updated_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)"
    );
    $stmt->execute([current_business_id(),$key,$value,current_user_id()]);
}
