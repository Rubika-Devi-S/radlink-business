<?php
declare(strict_types=1);

function get_invoice_setting(
    PDO $pdo,
    int $businessId,
    string $key,
    string $default = ''
): string {
    $stmt = $pdo->prepare(
        "SELECT setting_value
         FROM business_settings
         WHERE business_id = ?
           AND setting_group = 'invoice'
           AND setting_key = ?
         ORDER BY id DESC
         LIMIT 1"
    );

    $stmt->execute([$businessId, $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function save_invoice_setting(
    PDO $pdo,
    int $businessId,
    int $userId,
    string $key,
    string $value
): void {
    $find = $pdo->prepare(
        "SELECT id
         FROM business_settings
         WHERE business_id = ?
           AND setting_group = 'invoice'
           AND setting_key = ?
         ORDER BY id DESC
         LIMIT 1"
    );

    $find->execute([$businessId, $key]);
    $id = $find->fetchColumn();

    if ($id !== false) {
        $update = $pdo->prepare(
            "UPDATE business_settings
             SET setting_value = ?,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        $update->execute([$value, $userId, $id]);
        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO business_settings
            (
                business_id,
                setting_group,
                setting_key,
                setting_value,
                updated_by
            )
         VALUES
            (?, 'invoice', ?, ?, ?)"
    );

    $insert->execute([
        $businessId,
        $key,
        $value,
        $userId,
    ]);
}

function get_default_bank_account(
    PDO $pdo,
    int $businessId
): ?array {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM business_bank_accounts
         WHERE business_id = ?
           AND status = 'active'
         ORDER BY is_default DESC, id ASC
         LIMIT 1"
    );

    $stmt->execute([$businessId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function invoice_boolean(string $value): bool
{
    return $value === '1';
}

function validate_hex_colour(string $value): bool
{
    return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
}

function normalize_relative_upload_path(string $path): string
{
    return ltrim(str_replace('\\', '/', $path), '/');
}
