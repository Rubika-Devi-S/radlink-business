<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$permissionsFile = __DIR__ . '/../includes/permissions.php';
if (is_file($permissionsFile)) {
    require_once $permissionsFile;
}

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
    json_response(false, 'Select an active business first.', [], 422);
}

if (function_exists('can_access')) {
    $canEdit =
        can_access('business-settings', 'edit')
        || can_access('settings', 'edit')
        || can_access('master', 'edit');

    if (!$canEdit) {
        json_response(
            false,
            'You do not have permission to update Business Settings.',
            [],
            403
        );
    }
}

/*
|--------------------------------------------------------------------------
| Read current businesses table columns
|--------------------------------------------------------------------------
*/
$columnStmt = $pdo->query("SHOW COLUMNS FROM businesses");
$availableColumns = [];

foreach ($columnStmt->fetchAll() as $column) {
    $availableColumns[(string)$column['Field']] = true;
}

/*
|--------------------------------------------------------------------------
| Logical field to possible database-column mapping
|--------------------------------------------------------------------------
*/
$fieldMap = [
    'business_name' => [
        'business_name',
        'name',
        'company_name',
    ],
    'business_code' => [
        'business_code',
        'code',
        'company_code',
    ],
    'legal_name' => [
        'legal_name',
        'registered_name',
        'company_legal_name',
    ],
    'email' => [
        'email',
        'business_email',
        'company_email',
    ],
    'mobile' => [
        'mobile',
        'phone',
        'contact_number',
        'business_phone',
    ],
    'alternate_mobile' => [
        'alternate_mobile',
        'alternate_phone',
    ],
    'gst_number' => [
        'gst_number',
        'gstin',
        'tax_number',
    ],
    'pan_number' => [
        'pan_number',
        'pan',
    ],
    'address_line_1' => [
        'address_line_1',
        'address1',
        'address',
    ],
    'address_line_2' => [
        'address_line_2',
        'address2',
    ],
    'city' => ['city'],
    'district' => ['district'],
    'state' => ['state'],
    'postal_code' => [
        'postal_code',
        'pincode',
        'zip_code',
    ],
    'website' => [
        'website',
        'website_url',
    ],
    'currency_code' => [
        'currency_code',
        'currency',
    ],
    'timezone' => ['timezone'],
    'invoice_prefix' => ['invoice_prefix'],
    'receipt_prefix' => [
        'receipt_prefix',
        'payment_prefix',
    ],
    'financial_year_start_month' => [
        'financial_year_start_month',
        'fy_start_month',
    ],
    'status' => ['status'],
    'notes' => [
        'notes',
        'description',
    ],
];

$payload = [
    'business_name' => trim((string)($_POST['business_name'] ?? '')),
    'business_code' => strtoupper(
        trim((string)($_POST['business_code'] ?? ''))
    ),
    'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
    'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
    'mobile' => trim((string)($_POST['mobile'] ?? '')),
    'alternate_mobile' => trim(
        (string)($_POST['alternate_mobile'] ?? '')
    ),
    'gst_number' => strtoupper(
        trim((string)($_POST['gst_number'] ?? ''))
    ),
    'pan_number' => strtoupper(
        trim((string)($_POST['pan_number'] ?? ''))
    ),
    'address_line_1' => trim(
        (string)($_POST['address_line_1'] ?? '')
    ),
    'address_line_2' => trim(
        (string)($_POST['address_line_2'] ?? '')
    ),
    'city' => trim((string)($_POST['city'] ?? '')),
    'district' => trim((string)($_POST['district'] ?? '')),
    'state' => trim((string)($_POST['state'] ?? '')),
    'postal_code' => trim(
        (string)($_POST['postal_code'] ?? '')
    ),
    'website' => trim((string)($_POST['website'] ?? '')),
    'currency_code' => strtoupper(
        trim((string)($_POST['currency_code'] ?? 'INR'))
    ),
    'timezone' => trim(
        (string)($_POST['timezone'] ?? 'Asia/Kolkata')
    ),
    'invoice_prefix' => strtoupper(
        trim((string)($_POST['invoice_prefix'] ?? 'INV'))
    ),
    'receipt_prefix' => strtoupper(
        trim((string)($_POST['receipt_prefix'] ?? 'REC'))
    ),
    'financial_year_start_month' => max(
        1,
        min(
            12,
            (int)($_POST['financial_year_start_month'] ?? 4)
        )
    ),
    'status' => trim((string)($_POST['status'] ?? 'active')),
    'notes' => trim((string)($_POST['notes'] ?? '')),
];

if ($payload['business_name'] === '') {
    json_response(false, 'Business name is required.', [], 422);
}

if (
    $payload['email'] !== ''
    && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)
) {
    json_response(false, 'Enter a valid email address.', [], 422);
}

if (
    $payload['website'] !== ''
    && !filter_var($payload['website'], FILTER_VALIDATE_URL)
) {
    json_response(
        false,
        'Enter a valid website URL including http:// or https://.',
        [],
        422
    );
}

if (
    !in_array(
        $payload['currency_code'],
        ['INR', 'USD', 'AED', 'SAR', 'SGD'],
        true
    )
) {
    $payload['currency_code'] = 'INR';
}

if (
    !in_array(
        $payload['timezone'],
        ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Singapore', 'UTC'],
        true
    )
) {
    $payload['timezone'] = 'Asia/Kolkata';
}

if (!in_array($payload['status'], ['active', 'inactive'], true)) {
    $payload['status'] = 'active';
}

/*
|--------------------------------------------------------------------------
| Resolve posted logical fields to actual existing columns
|--------------------------------------------------------------------------
*/
$updates = [];
$params = [];
$updatedLogicalFields = [];

foreach ($fieldMap as $logicalField => $candidateColumns) {
    $actualColumn = null;

    foreach ($candidateColumns as $candidateColumn) {
        if (isset($availableColumns[$candidateColumn])) {
            $actualColumn = $candidateColumn;
            break;
        }
    }

    if ($actualColumn === null) {
        continue;
    }

    $updates[] = "`{$actualColumn}` = ?";
    $params[] = $payload[$logicalField];
    $updatedLogicalFields[] = $logicalField;
}

if (isset($availableColumns['updated_by'])) {
    $updates[] = "`updated_by` = ?";
    $params[] = current_user_id();
}

if (isset($availableColumns['updated_at'])) {
    $updates[] = "`updated_at` = NOW()";
}

if (!$updates) {
    json_response(
        false,
        'No compatible Business Settings columns were found in the businesses table.',
        [],
        422
    );
}

$params[] = $currentBusinessId;

try {
    $pdo->beginTransaction();

    $sql =
        "UPDATE businesses
         SET " . implode(', ', $updates) . "
         WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if (table_exists($pdo, 'activity_logs')) {
        try {
            $logStmt = $pdo->prepare(
                "INSERT INTO activity_logs
                    (
                        business_id,
                        user_id,
                        module_key,
                        action_type,
                        entity_type,
                        entity_id,
                        description,
                        ip_address,
                        user_agent
                    )
                 VALUES
                    (?, ?, 'business_settings', 'update',
                     'business', ?, ?, ?, ?)"
            );

            $logStmt->execute([
                $currentBusinessId,
                current_user_id(),
                $currentBusinessId,
                'Updated business settings.',
                substr(
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    0,
                    45
                ),
                substr(
                    (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    0,
                    2000
                ),
            ]);
        } catch (Throwable $logException) {
            error_log(
                '[BUSINESS SETTINGS LOG] '
                . $logException->getMessage()
            );
        }
    }

    $pdo->commit();

    json_response(
        true,
        'Business settings updated successfully.',
        [
            'updated_fields' => $updatedLogicalFields,
        ]
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[BUSINESS SETTINGS] '
        . $exception->getMessage()
    );

    json_response(
        false,
        $exception->getMessage(),
        [],
        422
    );
}
