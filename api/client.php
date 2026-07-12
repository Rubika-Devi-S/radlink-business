<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

$action = (string)($_POST['action'] ?? 'save');

if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        json_response(false, 'Invalid client.', [], 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE clients
         SET status = IF(status = 'active', 'inactive', 'active')
         WHERE id = ?
           AND business_id = ?
           AND parent_hospital_id IS NOT NULL"
    );
    $stmt->execute([$id, $currentBusinessId]);

    json_response(true, 'Client status changed.');
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        json_response(false, 'Invalid client.', [], 422);
    }

    foreach ([
        "SELECT COUNT(*) FROM invoices WHERE business_id = ? AND client_id = ?",
        "SELECT COUNT(*) FROM client_service_rates WHERE business_id = ? AND client_id = ?",
        "SELECT COUNT(*) FROM payments WHERE business_id = ? AND client_id = ?"
    ] as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$currentBusinessId, $id]);

            if ((int)$stmt->fetchColumn() > 0) {
                json_response(
                    false,
                    'This client is already used. Set it inactive instead.',
                    [],
                    422
                );
            }
        } catch (PDOException $exception) {
            // Optional table may not exist yet.
        }
    }

    $stmt = $pdo->prepare(
        "DELETE FROM clients
         WHERE id = ?
           AND business_id = ?
           AND parent_hospital_id IS NOT NULL"
    );
    $stmt->execute([$id, $currentBusinessId]);

    json_response(true, 'Client deleted.');
}

if ($action !== 'save') {
    json_response(false, 'Invalid client action.', [], 422);
}

$id = (int)($_POST['id'] ?? 0);
$hospitalId = (int)($_POST['parent_hospital_id'] ?? 0);
$clientTypeId = (int)($_POST['client_type_id'] ?? 0);
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

if ($hospitalId <= 0) {
    json_response(false, 'Select the parent hospital.', [], 422);
}

if ($clientTypeId <= 0) {
    json_response(false, 'Select the client type.', [], 422);
}

if ($name === '') {
    json_response(false, 'Client name is required.', [], 422);
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

    $hospitalStmt = $pdo->prepare(
        "SELECT c.id
         FROM clients c
         INNER JOIN client_types ct ON ct.id = c.client_type_id
         WHERE c.id = ?
           AND c.business_id = ?
           AND (ct.type_key = 'hospital' OR LOWER(ct.type_name) = 'hospital')
         LIMIT 1"
    );
    $hospitalStmt->execute([$hospitalId, $currentBusinessId]);

    if ($hospitalStmt->fetchColumn() === false) {
        throw new RuntimeException('Selected hospital is invalid.');
    }

    $typeStmt = $pdo->prepare(
        "SELECT id
         FROM client_types
         WHERE id = ?
           AND type_key <> 'hospital'
         LIMIT 1"
    );
    $typeStmt->execute([$clientTypeId]);

    if ($typeStmt->fetchColumn() === false) {
        throw new RuntimeException('Select a non-hospital client type.');
    }

    $duplicate = $pdo->prepare(
        "SELECT id
         FROM clients
         WHERE business_id = ?
           AND parent_hospital_id = ?
           AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
           AND id <> ?
         LIMIT 1"
    );
    $duplicate->execute([$currentBusinessId, $hospitalId, $name, $id]);

    if ($duplicate->fetchColumn() !== false) {
        throw new RuntimeException('This client already exists under the selected hospital.');
    }

    if ($code === '') {
        $codeStmt = $pdo->prepare(
            "SELECT client_code
             FROM clients
             WHERE business_id = ?
               AND client_code LIKE 'CLI-%'
             ORDER BY CAST(SUBSTRING(client_code, 5) AS UNSIGNED) DESC, id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $codeStmt->execute([$currentBusinessId]);
        $lastCode = (string)($codeStmt->fetchColumn() ?: '');

        $next = 1;
        if (preg_match('/^CLI-(\d+)$/', $lastCode, $matches)) {
            $next = (int)$matches[1] + 1;
        }

        $code = 'CLI-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    if (!preg_match('/^[A-Z0-9\/_-]+$/', $code)) {
        throw new RuntimeException('Client code contains invalid characters.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE clients SET
                client_type_id = ?,
                parent_hospital_id = ?,
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
            $clientTypeId, $hospitalId, $code, $name, $contactPerson ?: null,
            $mobile ?: null, $alternateMobile ?: null, $email ?: null,
            $address1 ?: null, $address2 ?: null, $city ?: null,
            $district ?: null, $state ?: null, $postalCode ?: null,
            $gstNumber ?: null, $creditDays, $billingMode, $notes ?: null,
            $status, $id, $currentBusinessId
        ]);

        $clientId = $id;
        $message = 'Client updated successfully.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO clients
            (
                business_id,
                client_type_id,
                parent_hospital_id,
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
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'none', ?, ?, ?, ?)"
        );

        $stmt->execute([
            $currentBusinessId, $clientTypeId, $hospitalId, $code, $name,
            $contactPerson ?: null, $mobile ?: null, $alternateMobile ?: null,
            $email ?: null, $address1 ?: null, $address2 ?: null,
            $city ?: null, $district ?: null, $state ?: null,
            $postalCode ?: null, $gstNumber ?: null, $creditDays,
            $billingMode, $notes ?: null, $status, current_user_id()
        ]);

        $clientId = (int)$pdo->lastInsertId();
        $message = 'Client created successfully.';
    }

    $pdo->commit();

    json_response(true, $message, ['id' => $clientId]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[CLIENT API] ' . $exception->getMessage());
    json_response(false, $exception->getMessage(), [], 422);
}
