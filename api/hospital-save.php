<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['client_name'] ?? ''));
$code = strtoupper(trim((string)($_POST['client_code'] ?? '')));
$contactPerson = trim((string)($_POST['contact_person'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address1 = trim((string)($_POST['address_line_1'] ?? ''));
$address2 = trim((string)($_POST['address_line_2'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$district = trim((string)($_POST['district'] ?? ''));
$state = trim((string)($_POST['state'] ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));
$gstNumber = strtoupper(trim((string)($_POST['gst_number'] ?? '')));
$creditDays = max(0, (int)($_POST['credit_period_days'] ?? 0));
$billingMode = (string)($_POST['default_billing_mode'] ?? 'credit');
$notes = trim((string)($_POST['notes'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');

if ($name === '') {
    json_response(false, 'Hospital name is required.', [], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(false, 'Enter a valid email address.', [], 422);
}

if (!in_array($billingMode, ['direct', 'credit', 'mixed'], true)) {
    $billingMode = 'credit';
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    $pdo->beginTransaction();

    $typeStmt = $pdo->prepare(
        "SELECT id
         FROM client_types
         WHERE type_key = 'hospital'
            OR LOWER(type_name) = 'hospital'
         ORDER BY CASE WHEN type_key = 'hospital' THEN 0 ELSE 1 END, id
         LIMIT 1"
    );
    $typeStmt->execute();
    $clientTypeId = $typeStmt->fetchColumn();

    if ($clientTypeId === false) {
        throw new RuntimeException('Hospital client type is not configured.');
    }

    $duplicate = $pdo->prepare(
        "SELECT id
         FROM clients
         WHERE business_id = ?
           AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
           AND id <> ?
         LIMIT 1"
    );
    $duplicate->execute([$currentBusinessId, $name, $id]);

    if ($duplicate->fetchColumn() !== false) {
        throw new RuntimeException('A hospital with this name already exists.');
    }

    if ($code === '') {
        $codeStmt = $pdo->prepare(
            "SELECT client_code
             FROM clients
             WHERE business_id = ?
               AND client_code LIKE 'HOS-%'
             ORDER BY CAST(SUBSTRING(client_code, 5) AS UNSIGNED) DESC, id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $codeStmt->execute([$currentBusinessId]);
        $lastCode = (string)($codeStmt->fetchColumn() ?: '');

        $next = 1;
        if (preg_match('/^HOS-(\d+)$/', $lastCode, $matches)) {
            $next = (int)$matches[1] + 1;
        }

        $code = 'HOS-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    if (!preg_match('/^[A-Z0-9\/_-]+$/', $code)) {
        throw new RuntimeException('Hospital code contains invalid characters.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE clients SET
                client_code = ?,
                client_name = ?,
                contact_person = ?,
                mobile = ?,
                alternate_mobile = ?,
                email = ?,
                address_line_1 = ?,
                address_line_2 = ?,
                city = ?,
                district = ?,
                state = ?,
                postal_code = ?,
                gst_number = ?,
                credit_period_days = ?,
                default_billing_mode = ?,
                notes = ?,
                status = ?
             WHERE id = ?
               AND business_id = ?"
        );

        $stmt->execute([
            $code, $name, $contactPerson ?: null, $mobile ?: null,
            $alternateMobile ?: null, $email ?: null, $address1 ?: null,
            $address2 ?: null, $city ?: null, $district ?: null,
            $state ?: null, $postalCode ?: null, $gstNumber ?: null,
            $creditDays, $billingMode, $notes ?: null, $status,
            $id, $currentBusinessId
        ]);

        $hospitalId = $id;
        $message = 'Hospital updated successfully.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO clients
            (
                business_id,
                client_type_id,
                client_code,
                client_name,
                contact_person,
                mobile,
                alternate_mobile,
                email,
                address_line_1,
                address_line_2,
                city,
                district,
                state,
                postal_code,
                gst_number,
                credit_period_days,
                opening_balance,
                opening_balance_type,
                default_billing_mode,
                notes,
                status,
                created_by
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'none', ?, ?, ?, ?)"
        );

        $stmt->execute([
            $currentBusinessId, (int)$clientTypeId, $code, $name,
            $contactPerson ?: null, $mobile ?: null, $alternateMobile ?: null,
            $email ?: null, $address1 ?: null, $address2 ?: null,
            $city ?: null, $district ?: null, $state ?: null,
            $postalCode ?: null, $gstNumber ?: null, $creditDays,
            $billingMode, $notes ?: null, $status, current_user_id()
        ]);

        $hospitalId = (int)$pdo->lastInsertId();
        $message = 'Hospital created successfully.';
    }

    $pdo->commit();

    $address = implode(', ', array_filter([
        $address1, $address2, $city, $district, $state, $postalCode
    ], static fn ($value): bool => trim((string)$value) !== ''));

    json_response(true, $message, [
        'id' => $hospitalId,
        'client_code' => $code,
        'client_name' => $name,
        'mobile' => $mobile,
        'email' => $email,
        'address' => $address,
        'credit_days' => $creditDays,
        'billing_mode' => match ($billingMode) {
            'direct' => 'Direct',
            'mixed' => 'Mixed',
            default => 'Hospital Credit',
        },
        'status' => $status,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[HOSPITAL SAVE] ' . $exception->getMessage());
    json_response(false, $exception->getMessage(), [], 422);
}
