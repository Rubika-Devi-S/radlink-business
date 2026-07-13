<?php
declare(strict_types=1);

/**
 * RAD LINK activity logger.
 *
 * This helper never throws an exception back to the main transaction.
 * A logging failure is written to the PHP error log instead.
 */

function activity_client_ip(): string
{
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));
        $candidate = $parts[0] ?? '';

        if (
            filter_var($candidate, FILTER_VALIDATE_IP) !== false
            && strlen($candidate) <= 45
        ) {
            return $candidate;
        }
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    return strlen($remote) <= 45 ? $remote : substr($remote, 0, 45);
}

function activity_normalize_value(mixed $value): mixed
{
    if ($value instanceof DateTimeInterface) {
        return $value->format(DateTimeInterface::ATOM);
    }

    if (is_array($value)) {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = activity_normalize_value($item);
        }

        return $normalized;
    }

    if (is_object($value)) {
        return activity_normalize_value((array)$value);
    }

    if (is_resource($value)) {
        return '[resource]';
    }

    return $value;
}

function activity_json(?array $values): ?string
{
    if ($values === null) {
        return null;
    }

    $json = json_encode(
        activity_normalize_value($values),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json === false ? null : $json;
}

/**
 * Record a business activity.
 *
 * Example:
 * activity_log($pdo, [
 *     'business_id' => $currentBusinessId,
 *     'module_key' => 'invoices',
 *     'action_type' => 'create',
 *     'entity_type' => 'invoice',
 *     'entity_id' => $invoiceId,
 *     'description' => 'Created invoice RAD-LINK 0058',
 *     'new_values' => ['invoice_number' => 'RAD-LINK 0058'],
 * ]);
 */
function activity_log(PDO $pdo, array $data): bool
{
    try {
        $businessId = array_key_exists('business_id', $data)
            ? (int)$data['business_id']
            : (int)($GLOBALS['currentBusinessId'] ?? 0);

        $userId = array_key_exists('user_id', $data)
            ? (int)$data['user_id']
            : (
                function_exists('current_user_id')
                    ? (int)current_user_id()
                    : (int)($_SESSION['user_id'] ?? 0)
            );

        $moduleKey = trim((string)($data['module_key'] ?? 'system'));
        $actionType = trim((string)($data['action_type'] ?? 'activity'));
        $entityType = trim((string)($data['entity_type'] ?? ''));
        $entityId = (int)($data['entity_id'] ?? 0);
        $description = trim((string)($data['description'] ?? ''));

        if ($moduleKey === '') {
            $moduleKey = 'system';
        }

        if ($actionType === '') {
            $actionType = 'activity';
        }

        $moduleKey = substr($moduleKey, 0, 80);
        $actionType = substr($actionType, 0, 80);
        $entityType = $entityType !== ''
            ? substr($entityType, 0, 80)
            : null;

        $description = $description !== ''
            ? substr($description, 0, 500)
            : null;

        $oldValues = isset($data['old_values'])
            && is_array($data['old_values'])
                ? $data['old_values']
                : null;

        $newValues = isset($data['new_values'])
            && is_array($data['new_values'])
                ? $data['new_values']
                : null;

        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs
            (
                business_id,
                user_id,
                module_key,
                action_type,
                entity_type,
                entity_id,
                description,
                old_values_json,
                new_values_json,
                ip_address,
                user_agent
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        return $stmt->execute([
            $businessId > 0 ? $businessId : null,
            $userId > 0 ? $userId : null,
            $moduleKey,
            $actionType,
            $entityType,
            $entityId > 0 ? $entityId : null,
            $description,
            activity_json($oldValues),
            activity_json($newValues),
            activity_client_ip(),
            substr(
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                2000
            ),
        ]);
    } catch (Throwable $exception) {
        error_log(
            '[RAD LINK ACTIVITY LOG] ' .
            $exception->getMessage()
        );

        return false;
    }
}

function activity_log_create(
    PDO $pdo,
    int $businessId,
    string $moduleKey,
    string $entityType,
    int $entityId,
    string $description,
    array $newValues = []
): bool {
    return activity_log($pdo, [
        'business_id' => $businessId,
        'module_key' => $moduleKey,
        'action_type' => 'create',
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'new_values' => $newValues,
    ]);
}

function activity_log_update(
    PDO $pdo,
    int $businessId,
    string $moduleKey,
    string $entityType,
    int $entityId,
    string $description,
    array $oldValues = [],
    array $newValues = []
): bool {
    return activity_log($pdo, [
        'business_id' => $businessId,
        'module_key' => $moduleKey,
        'action_type' => 'update',
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'old_values' => $oldValues,
        'new_values' => $newValues,
    ]);
}

function activity_log_delete(
    PDO $pdo,
    int $businessId,
    string $moduleKey,
    string $entityType,
    int $entityId,
    string $description,
    array $oldValues = []
): bool {
    return activity_log($pdo, [
        'business_id' => $businessId,
        'module_key' => $moduleKey,
        'action_type' => 'delete',
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'description' => $description,
        'old_values' => $oldValues,
    ]);
}
