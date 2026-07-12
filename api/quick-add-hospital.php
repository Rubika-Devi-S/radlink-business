<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(
        false,
        'Session expired. Refresh the page and try again.',
        [],
        419
    );
}

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

$hospitalName = trim((string)($_POST['hospital_name'] ?? ''));

if (mb_strlen($hospitalName) < 2 || mb_strlen($hospitalName) > 200) {
    json_response(
        false,
        'Enter a valid hospital name.',
        [],
        422
    );
}

try {
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Reuse an existing hospital having the same name
    |--------------------------------------------------------------------------
    | The clients table uses default_billing_mode, not billing_mode.
    */
    $existingStmt = $pdo->prepare(
        "SELECT
            id,
            client_code,
            client_name,
            mobile,
            email,
            address_line_1,
            address_line_2,
            city,
            district,
            state,
            postal_code,
            credit_period_days,
            default_billing_mode
         FROM clients
         WHERE business_id = ?
           AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
         LIMIT 1
         FOR UPDATE"
    );

    $existingStmt->execute([
        $currentBusinessId,
        $hospitalName,
    ]);

    $existing = $existingStmt->fetch();

    if ($existing) {
        $pdo->commit();

        $address = implode(', ', array_filter([
            $existing['address_line_1'] ?? '',
            $existing['address_line_2'] ?? '',
            $existing['city'] ?? '',
            $existing['district'] ?? '',
            $existing['state'] ?? '',
            $existing['postal_code'] ?? '',
        ], static fn ($value): bool =>
            trim((string)$value) !== ''
        ));

        json_response(
            true,
            'Existing hospital selected.',
            [
                'id' => (int)$existing['id'],
                'client_code' => (string)$existing['client_code'],
                'client_name' => (string)$existing['client_name'],
                'mobile' => (string)($existing['mobile'] ?? ''),
                'email' => (string)($existing['email'] ?? ''),
                'address' => $address,
                'credit_days' => (int)(
                    $existing['credit_period_days'] ?? 0
                ),
                'billing_mode' => match (
                    (string)($existing['default_billing_mode'] ?? 'credit')
                ) {
                    'direct' => 'Direct',
                    'mixed' => 'Mixed',
                    default => 'Hospital Credit',
                },
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Find the Hospital client type
    |--------------------------------------------------------------------------
    | client_types is a global master table and does not contain business_id.
    */
    $typeStmt = $pdo->prepare(
        "SELECT id
         FROM client_types
         WHERE type_key = 'hospital'
            OR LOWER(type_name) = 'hospital'
         ORDER BY
            CASE WHEN type_key = 'hospital' THEN 0 ELSE 1 END,
            id
         LIMIT 1"
    );

    $typeStmt->execute();
    $clientTypeId = $typeStmt->fetchColumn();

    if ($clientTypeId === false) {
        throw new RuntimeException(
            'Hospital client type is not configured.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate the next hospital code
    |--------------------------------------------------------------------------
    */
    $codeStmt = $pdo->prepare(
        "SELECT client_code
         FROM clients
         WHERE business_id = ?
           AND client_code LIKE 'HOS-%'
         ORDER BY
            CAST(SUBSTRING(client_code, 5) AS UNSIGNED) DESC,
            id DESC
         LIMIT 1
         FOR UPDATE"
    );

    $codeStmt->execute([$currentBusinessId]);
    $lastCode = (string)($codeStmt->fetchColumn() ?: '');

    $nextNumber = 1;

    if (preg_match('/^HOS-(\d+)$/', $lastCode, $matches)) {
        $nextNumber = (int)$matches[1] + 1;
    }

    $clientCode = 'HOS-' . str_pad(
        (string)$nextNumber,
        4,
        '0',
        STR_PAD_LEFT
    );

    /*
    |--------------------------------------------------------------------------
    | Create hospital using the actual clients table columns
    |--------------------------------------------------------------------------
    */
    $insert = $pdo->prepare(
        "INSERT INTO clients
            (
                business_id,
                client_type_id,
                client_code,
                client_name,
                credit_period_days,
                opening_balance,
                opening_balance_type,
                default_billing_mode,
                status,
                created_by
            )
         VALUES
            (?, ?, ?, ?, 0, 0.00, 'none', 'credit', 'active', ?)"
    );

    $insert->execute([
        $currentBusinessId,
        (int)$clientTypeId,
        $clientCode,
        $hospitalName,
        current_user_id(),
    ]);

    $hospitalId = (int)$pdo->lastInsertId();

    $pdo->commit();

    json_response(
        true,
        'Hospital created and selected.',
        [
            'id' => $hospitalId,
            'client_code' => $clientCode,
            'client_name' => $hospitalName,
            'mobile' => '',
            'email' => '',
            'address' => '',
            'credit_days' => 0,
            'billing_mode' => 'Hospital Credit',
        ]
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[QUICK ADD HOSPITAL] Business ID: ' .
        $currentBusinessId .
        ' | ' .
        $exception->getMessage()
    );

    json_response(
        false,
        $exception->getMessage(),
        [],
        422
    );
}