<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/invoice-functions.php';
require_once __DIR__ . '/../includes/invoice-settings-functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

$backendAction = trim((string)(
    $_POST['backend_action']
    ?? $_POST['action']
    ?? ''
));

/*
|--------------------------------------------------------------------------
| Backward-compatible action detection
|--------------------------------------------------------------------------
| Older invoice-form.php copies did not submit backend_action. When the
| request clearly contains invoice fields, treat it as save_invoice.
*/
if (
    $backendAction === ''
    && (
        isset($_POST['items_json'])
        || isset($_POST['client_id'])
        || isset($_POST['invoice_id'])
    )
) {
    $backendAction = 'save_invoice';
}

if ($backendAction === '') {
    json_response(
        false,
        'Invoice backend action is required.',
        [
            'received_fields' => array_keys($_POST),
        ],
        422
    );
}

function invoice_api_delete_local_asset(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}



/**
 * Generate the next invoice number using the actual RAD LINK
 * document_sequences schema.
 *
 * The current database uses `next_number`, not `current_number`.
 * This helper locks the sequence row inside the active transaction,
 * increments it safely, and creates the row when it does not exist.
 */
function invoice_api_next_invoice_number(
    PDO $pdo,
    int $businessId,
    int $financialYearId,
    string $prefix,
    int $padding = 4
): string {
    $prefix = trim($prefix);
    $padding = max(1, min(10, $padding));

    if ($prefix === '') {
        $prefix = 'RLS-INV';
    }

    $sequenceStmt = $pdo->prepare(
        "SELECT id, next_number, padding_length
         FROM document_sequences
         WHERE business_id = ?
           AND financial_year_id = ?
           AND document_type = 'invoice'
         LIMIT 1
         FOR UPDATE"
    );

    $sequenceStmt->execute([
        $businessId,
        $financialYearId,
    ]);

    $sequence = $sequenceStmt->fetch();

    if ($sequence) {
        $number = max(1, (int)$sequence['next_number']);

        $updateStmt = $pdo->prepare(
            "UPDATE document_sequences
             SET prefix = ?,
                 next_number = ?,
                 padding_length = ?
             WHERE id = ?"
        );

        $updateStmt->execute([
            $prefix,
            $number + 1,
            $padding,
            $sequence['id'],
        ]);
    } else {
        $number = 1;

        $insertStmt = $pdo->prepare(
            "INSERT INTO document_sequences
            (
                business_id,
                financial_year_id,
                document_type,
                prefix,
                next_number,
                padding_length
            )
            VALUES (?, ?, 'invoice', ?, 2, ?)"
        );

        $insertStmt->execute([
            $businessId,
            $financialYearId,
            $prefix,
            $padding,
        ]);
    }

    return rtrim($prefix, '-/ ') . '-' .
        str_pad((string)$number, $padding, '0', STR_PAD_LEFT);
}

function invoice_api_activity(
    PDO $pdo,
    int $businessId,
    string $actionType,
    ?int $entityId,
    string $description,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
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
            (?, ?, 'invoices', ?, 'invoice', ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $businessId,
            current_user_id(),
            $actionType,
            $entityId && $entityId > 0 ? $entityId : null,
            substr($description, 0, 500),
            $oldValues !== null
                ? json_encode(
                    $oldValues,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                )
                : null,
            $newValues !== null
                ? json_encode(
                    $newValues,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
                )
                : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000),
        ]);
    } catch (Throwable $exception) {
        error_log('[INVOICE API ACTIVITY] ' . $exception->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Save or update invoice
|--------------------------------------------------------------------------
*/
if ($backendAction === 'save_invoice') {
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $invoiceDate = trim((string)($_POST['invoice_date'] ?? ''));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $financialYearId = (int)($_POST['financial_year_id'] ?? 0);
    $billing = trim((string)(
        $_POST['billing_responsibility'] ?? 'client_credit'
    ));
    $patientName = trim((string)($_POST['patient_name'] ?? ''));
    $patientReference = trim((string)(
        $_POST['patient_reference_no'] ?? ''
    ));
    $hospitalReference = trim((string)(
        $_POST['hospital_reference_no'] ?? ''
    ));

    $totalPatientCount = max(
        0,
        (int)($_POST['total_patient_count'] ?? 0)
    );
    $notes = trim((string)($_POST['notes'] ?? ''));
    $invoiceStatus = trim((string)(
        $_POST['invoice_status'] ?? 'issued'
    ));
    $items = json_decode(
        (string)($_POST['items_json'] ?? '[]'),
        true
    );

    $allowedBilling = [
        'client_credit',
        'patient_direct',
        'patient_paid_to_client',
        'split',
        'complimentary',
    ];

    if (!in_array($billing, $allowedBilling, true)) {
        $billing = 'client_credit';
    }

    if (!in_array($invoiceStatus, ['draft', 'issued'], true)) {
        $invoiceStatus = 'issued';
    }

    if (
        $clientId <= 0
        || $invoiceDate === ''
        || !is_array($items)
        || count($items) === 0
    ) {
        json_response(
            false,
            'Hospital, invoice date and at least one service are required.',
            [],
            422
        );
    }

    $dateCheck = DateTimeImmutable::createFromFormat(
        'Y-m-d',
        $invoiceDate
    );

    if (
        !$dateCheck
        || $dateCheck->format('Y-m-d') !== $invoiceDate
    ) {
        json_response(false, 'Enter a valid invoice date.', [], 422);
    }

    $clientStmt = $pdo->prepare(
        "SELECT *
         FROM clients
         WHERE id = ?
           AND business_id = ?
           AND status = 'active'
         LIMIT 1"
    );
    $clientStmt->execute([$clientId, $currentBusinessId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        json_response(false, 'Invalid hospital.', [], 422);
    }

    if ($financialYearId > 0) {
        $fyStmt = $pdo->prepare(
            "SELECT *
             FROM financial_years
             WHERE id = ?
               AND business_id = ?
             LIMIT 1"
        );
        $fyStmt->execute([$financialYearId, $currentBusinessId]);
        $financialYear = $fyStmt->fetch();

        if (!$financialYear) {
            json_response(false, 'Invalid financial year.', [], 422);
        }
    } else {
        $financialYear = current_financial_year(
            $pdo,
            $currentBusinessId,
            $invoiceDate
        );
    }

    if (!$financialYear) {
        json_response(
            false,
            'No financial year is configured for the invoice date.',
            [],
            422
        );
    }

    $subtotal = 0.0;
    $discountTotal = 0.0;
    $taxTotal = 0.0;
    $cleanItems = [];

    $serviceStmt = $pdo->prepare(
        "SELECT *
         FROM services
         WHERE id = ?
           AND business_id = ?
           AND status = 'active'
         LIMIT 1"
    );

    $agreedRateStmt = $pdo->prepare(
        "SELECT agreed_rate
         FROM client_service_rates
         WHERE business_id = ?
           AND client_id = ?
           AND service_id = ?
           AND status = 'active'
           AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC, id DESC
         LIMIT 1"
    );

    foreach ($items as $index => $item) {
        $serviceId = (int)($item['service_id'] ?? 0);

        /*
         * The existing quantity column is retained internally, but now stores
         * the number of patients/cases. Only whole positive counts are allowed.
         */
        $patientCount = (int)round(
            (float)($item['quantity'] ?? 0)
        );

        // Service rate and tax are always resolved by the server.
        // Only the discount selection/value is accepted from the invoice form.
        $rate = 0.0;
        $discountType = trim((string)($item['discount_type'] ?? 'none'));
        $discountValue = max(
            0,
            (float)($item['discount_value'] ?? 0)
        );
        $taxPercent = 0.0;
        $description = trim((string)($item['description'] ?? ''));
        $billingFrom = $invoiceDate;
        $billingTo = $invoiceDate;
        $rateBasis = 'fixed';

        $allowedRateBasis = [
            'agreement',
            'weekly',
            'monthly',
            'package',
            'fixed',
            'special',
        ];

        if (!in_array($rateBasis, $allowedRateBasis, true)) {
            $rateBasis = 'monthly';
        }


        if (!in_array(
            $discountType,
            ['none', 'amount', 'percentage'],
            true
        )) {
            $discountType = 'none';
            $discountValue = 0.0;
        }

        if ($discountType === 'percentage') {
            $discountValue = min(100.0, $discountValue);
        }

        if ($discountType === 'none') {
            $discountValue = 0.0;
        }

        $serviceStmt->execute([
            $serviceId,
            $currentBusinessId,
        ]);
        $service = $serviceStmt->fetch();

        if (!$service) {
            json_response(
                false,
                'Invalid service on row ' . ($index + 1) . '.',
                [],
                422
            );
        }

        $agreedRateStmt->execute([
            $currentBusinessId,
            $clientId,
            $serviceId,
            $invoiceDate,
            $invoiceDate,
        ]);

        $agreedRate = $agreedRateStmt->fetchColumn();
        $rate = $agreedRate !== false
            ? max(0, (float)$agreedRate)
            : max(0, (float)$service['standard_rate']);

        $taxPercent = max(
            0,
            min(100, (float)($service['tax_percent'] ?? 0))
        );

        /*
         * The simplified service bill stores one service row without exposing
         * quantity to the user. The resolved service rate is the line amount.
         */
        $patientCount = 1;
        $gross = $rate;

        $discountAmount = match ($discountType) {
            'percentage' => $gross * $discountValue / 100,
            'amount' => min($discountValue, $gross),
            default => 0.0,
        };

        $taxableAmount = max(0, $gross - $discountAmount);
        $taxAmount = $taxableAmount * $taxPercent / 100;
        $lineTotal = $taxableAmount + $taxAmount;

        $subtotal += $gross;
        $discountTotal += $discountAmount;
        $taxTotal += $taxAmount;

        $cleanItems[] = [
            'service' => $service,
            'patient_count' => $patientCount,
            'rate' => $rate,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'sort_order' => $index + 1,
            'description' => $description,
            'billing_from' => $billingFrom,
            'billing_to' => $billingTo,
            'rate_basis' => $rateBasis,
        ];
    }

    $beforeRound = $subtotal - $discountTotal + $taxTotal;
    $roundedTotal = round($beforeRound);
    $roundOff = round($roundedTotal - $beforeRound, 2);
    $grandTotal = round($beforeRound + $roundOff, 2);

    $patientPayable = in_array(
        $billing,
        ['patient_direct', 'patient_paid_to_client'],
        true
    )
        ? $grandTotal
        : (
            $billing === 'split'
                ? (float)($_POST['patient_payable_amount'] ?? 0)
                : 0
        );

    $patientPayable = max(
        0,
        min($grandTotal, $patientPayable)
    );

    $clientPayable = $billing === 'complimentary'
        ? 0
        : $grandTotal - $patientPayable;

    try {
        $pdo->beginTransaction();

        $oldInvoice = null;
        $isUpdate = $invoiceId > 0;

        if ($isUpdate) {
            $checkStmt = $pdo->prepare(
                "SELECT *
                 FROM invoices
                 WHERE id = ?
                   AND business_id = ?
                 FOR UPDATE"
            );
            $checkStmt->execute([
                $invoiceId,
                $currentBusinessId,
            ]);
            $oldInvoice = $checkStmt->fetch();

            if (!$oldInvoice) {
                throw new RuntimeException('Invoice not found.');
            }

            if ($oldInvoice['invoice_status'] === 'cancelled') {
                throw new RuntimeException(
                    'Cancelled invoice cannot be edited.'
                );
            }

            $invoiceNumber = (string)$oldInvoice['invoice_number'];
        } else {
            $businessStmt = $pdo->prepare(
                "SELECT *
                 FROM businesses
                 WHERE id = ?
                 LIMIT 1"
            );
            $businessStmt->execute([$currentBusinessId]);
            $business = $businessStmt->fetch();

            if (!$business) {
                throw new RuntimeException('Business not found.');
            }

            $padding = (int)get_invoice_setting(
                $pdo,
                $currentBusinessId,
                'invoice_number_padding',
                '4'
            );

            $prefix = trim(get_invoice_setting(
                $pdo,
                $currentBusinessId,
                'invoice_prefix',
                (string)($business['invoice_prefix'] ?? 'RLS-INV')
            ));

            if ($prefix === '') {
                $prefix = 'RLS-INV';
            }

            $invoiceNumber = invoice_api_next_invoice_number(
                $pdo,
                $currentBusinessId,
                (int)$financialYear['id'],
                $prefix,
                $padding
            );
        }

        $terms = get_invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_terms',
            ''
        );

        $billToAddress = trim(implode(', ', array_filter([
            $client['address_line_1'] ?? '',
            $client['address_line_2'] ?? '',
            $client['city'] ?? '',
            $client['district'] ?? '',
            $client['state'] ?? '',
            $client['postal_code'] ?? '',
        ], static fn ($value): bool =>
            trim((string)$value) !== ''
        )));

        if ($isUpdate) {
            $updateStmt = $pdo->prepare(
                "UPDATE invoices
                 SET financial_year_id = ?,
                     client_id = ?,
                     invoice_date = ?,
                     due_date = ?,
                     bill_to_name = ?,
                     bill_to_address = ?,
                     bill_to_mobile = ?,
                     bill_to_email = ?,
                     patient_name = ?,
                     patient_reference_no = ?,
                     hospital_reference_no = ?,
                     total_patient_count = ?,
                     billing_responsibility = ?,
                     subtotal = ?,
                     discount_amount = ?,
                     tax_amount = ?,
                     round_off = ?,
                     grand_total = ?,
                     patient_payable_amount = ?,
                     client_payable_amount = ?,
                     balance_amount = ? - received_amount,
                     payment_status = CASE
                        WHEN received_amount <= 0 THEN 'unpaid'
                        WHEN received_amount >= ? THEN 'paid'
                        ELSE 'partially_paid'
                     END,
                     invoice_status = ?,
                     notes = ?,
                     terms_snapshot = ?
                 WHERE id = ?
                   AND business_id = ?"
            );

            $updateStmt->execute([
                $financialYear['id'],
                $clientId,
                $invoiceDate,
                $dueDate ?: null,
                $client['client_name'],
                $billToAddress,
                $client['mobile'],
                $client['email'],
                $patientName ?: null,
                $patientReference ?: null,
                $hospitalReference ?: null,
                $totalPatientCount,
                $billing,
                $subtotal,
                $discountTotal,
                $taxTotal,
                $roundOff,
                $grandTotal,
                $patientPayable,
                $clientPayable,
                $grandTotal,
                $grandTotal,
                $invoiceStatus,
                $notes ?: null,
                $terms,
                $invoiceId,
                $currentBusinessId,
            ]);

            $deleteItemsStmt = $pdo->prepare(
                "DELETE FROM invoice_items
                 WHERE invoice_id = ?
                   AND business_id = ?"
            );
            $deleteItemsStmt->execute([
                $invoiceId,
                $currentBusinessId,
            ]);
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO invoices
                (
                    business_id,
                    financial_year_id,
                    client_id,
                    invoice_number,
                    invoice_date,
                    due_date,
                    bill_to_name,
                    bill_to_address,
                    bill_to_mobile,
                    bill_to_email,
                    patient_name,
                    patient_reference_no,
                    hospital_reference_no,
                    total_patient_count,
                    billing_responsibility,
                    subtotal,
                    discount_amount,
                    tax_amount,
                    round_off,
                    grand_total,
                    patient_payable_amount,
                    client_payable_amount,
                    received_amount,
                    balance_amount,
                    payment_status,
                    invoice_status,
                    notes,
                    terms_snapshot,
                    created_by
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, ?, 'unpaid', ?, ?, ?, ?
                )"
            );

            $insertStmt->execute([
                $currentBusinessId,
                $financialYear['id'],
                $clientId,
                $invoiceNumber,
                $invoiceDate,
                $dueDate ?: null,
                $client['client_name'],
                $billToAddress,
                $client['mobile'],
                $client['email'],
                $patientName ?: null,
                $patientReference ?: null,
                $hospitalReference ?: null,
                $totalPatientCount,
                $billing,
                $subtotal,
                $discountTotal,
                $taxTotal,
                $roundOff,
                $grandTotal,
                $patientPayable,
                $clientPayable,
                $grandTotal,
                $invoiceStatus,
                $notes ?: null,
                $terms,
                current_user_id(),
            ]);

            $invoiceId = (int)$pdo->lastInsertId();
        }

        $itemInsertStmt = $pdo->prepare(
            "INSERT INTO invoice_items
            (
                business_id,
                invoice_id,
                service_id,
                service_code_snapshot,
                service_name_snapshot,
                description,
                billing_from,
                billing_to,
                rate_basis,
                quantity,
                unit_name,
                standard_rate,
                applied_rate,
                discount_type,
                discount_value,
                discount_amount,
                tax_percent,
                tax_amount,
                line_total,
                sort_order
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($cleanItems as $item) {
            $service = $item['service'];

            $itemInsertStmt->execute([
                $currentBusinessId,
                $invoiceId,
                $service['id'],
                $service['service_code'],
                $service['service_name'],
                $item['description'] ?: $service['description'],
                $item['billing_from'],
                $item['billing_to'],
                $item['rate_basis'],
                1,
                (string)($service['unit_name'] ?? 'Service'),
                $service['standard_rate'],
                $item['rate'],
                $item['discount_type'],
                $item['discount_value'],
                $item['discount_amount'],
                $item['tax_percent'],
                $item['tax_amount'],
                $item['line_total'],
                $item['sort_order'],
            ]);
        }

        invoice_api_activity(
            $pdo,
            $currentBusinessId,
            $isUpdate ? 'update' : 'create',
            $invoiceId,
            ($isUpdate ? 'Updated invoice ' : 'Created invoice ') .
            $invoiceNumber,
            $oldInvoice ?: null,
            [
                'invoice_number' => $invoiceNumber,
                'hospital_id' => $clientId,
                'invoice_date' => $invoiceDate,
                'patient_count' => $totalPatientCount,
                'grand_total' => $grandTotal,
                'invoice_status' => $invoiceStatus,
            ]
        );

        $pdo->commit();

        json_response(
            true,
            $isUpdate
                ? 'Invoice updated successfully.'
                : 'Invoice created successfully.',
            [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'patient_count' => $totalPatientCount,
                'grand_total' => $grandTotal,
            ]
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[SINGLE INVOICE API SAVE] ' . $exception->getMessage());

        json_response(
            false,
            $exception->getMessage(),
            [],
            422
        );
    }
}

/*
|--------------------------------------------------------------------------
| Save invoice settings
|--------------------------------------------------------------------------
*/
if ($backendAction === 'save_settings') {
    if (!is_owner()) {
        json_response(
            false,
            'Only the owner can update invoice settings.',
            [],
            403
        );
    }

    $settingDefaults = [
        'invoice_title' => 'BILL OF SUPPLY',
        'invoice_copy_label' => 'ORIGINAL',
        'invoice_brand_heading' => 'TELE RADIOLOGY REPORTING SOFTWARE',
        'invoice_sub_heading' => '',
        'invoice_theme_hex' => '#E6C8F2',
        'invoice_heading_hex' => '#7B169E',
        'invoice_text_hex' => '#111111',
        'invoice_show_logo' => '0',
        'invoice_logo_width_mm' => '27',
        'invoice_logo_height_mm' => '27',
        'invoice_show_address' => '0',
        'invoice_address' => '',
        'invoice_show_patient' => '0',
        'invoice_show_bank' => '0',
        'invoice_show_qr' => '0',
        'invoice_qr_mode' => 'dynamic_upi',
        'invoice_qr_size_mm' => '25',
        'invoice_qr_amount_source' => 'grand_total',
        'invoice_uploaded_qr_path' => '',
        'invoice_show_signature' => '0',
        'invoice_signature_path' => '',
        'invoice_signature_width_mm' => '30',
        'invoice_signature_height_mm' => '18',
        'invoice_auto_print' => '0',
        'invoice_show_download_fallback' => '0',
        'invoice_number_padding' => '4',
        'invoice_terms' => '',
        'invoice_footer_text' => '',
        'invoice_contact_mobile' => '',
        'invoice_contact_email' => '',
    ];

    $booleanKeys = [
        'invoice_show_logo',
        'invoice_show_address',
        'invoice_show_patient',
        'invoice_show_bank',
        'invoice_show_qr',
        'invoice_show_signature',
        'invoice_auto_print',
        'invoice_show_download_fallback',
    ];

    $colourKeys = [
        'invoice_theme_hex',
        'invoice_heading_hex',
        'invoice_text_hex',
    ];

    $numericRules = [
        'invoice_logo_width_mm' => [10, 80],
        'invoice_logo_height_mm' => [10, 50],
        'invoice_qr_size_mm' => [18, 45],
        'invoice_signature_width_mm' => [10, 80],
        'invoice_signature_height_mm' => [8, 40],
        'invoice_number_padding' => [2, 10],
    ];

    try {
        $pdo->beginTransaction();

        foreach ($settingDefaults as $key => $defaultValue) {
            $value = array_key_exists($key, $_POST)
                ? trim((string)$_POST[$key])
                : $defaultValue;

            if (in_array($key, $booleanKeys, true)) {
                $value = $value === '1' ? '1' : '0';
            }

            if (in_array($key, $colourKeys, true)) {
                if (!validate_hex_colour($value)) {
                    throw new RuntimeException(
                        'Invalid colour value for ' .
                        str_replace('_', ' ', $key) . '.'
                    );
                }

                $value = strtoupper($value);
            }

            if (isset($numericRules[$key])) {
                [$minimum, $maximum] = $numericRules[$key];
                $number = (float)$value;

                if ($number < $minimum || $number > $maximum) {
                    throw new RuntimeException(
                        'Invalid value for ' .
                        str_replace('_', ' ', $key) . '.'
                    );
                }

                $value = (string)$number;
            }

            if (
                $key === 'invoice_qr_mode'
                && !in_array(
                    $value,
                    ['dynamic_upi', 'uploaded_qr', 'upi_text_only'],
                    true
                )
            ) {
                throw new RuntimeException('Invalid QR mode.');
            }

            if (
                $key === 'invoice_qr_amount_source'
                && !in_array(
                    $value,
                    ['grand_total', 'balance_amount'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid QR amount source.'
                );
            }

            if (
                $key === 'invoice_contact_email'
                && $value !== ''
                && !filter_var($value, FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException(
                    'Enter a valid invoice email address.'
                );
            }

            if (
                $key === 'invoice_contact_mobile'
                && $value !== ''
                && !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)
            ) {
                throw new RuntimeException(
                    'Enter a valid invoice mobile number.'
                );
            }

            if (
                $key === 'invoice_address'
                && mb_strlen($value) > 500
            ) {
                throw new RuntimeException(
                    'Invoice address cannot exceed 500 characters.'
                );
            }

            save_invoice_setting(
                $pdo,
                $currentBusinessId,
                current_user_id(),
                $key,
                $value
            );
        }

        $invoicePrefix = strtoupper(trim((string)(
            $_POST['invoice_prefix'] ?? ''
        )));

        if (
            $invoicePrefix === ''
            || !preg_match('/^[A-Z0-9\/_-]+$/', $invoicePrefix)
        ) {
            throw new RuntimeException(
                'Invoice prefix may contain only letters, numbers, slash, ' .
                'hyphen and underscore.'
            );
        }

        save_invoice_setting(
            $pdo,
            $currentBusinessId,
            current_user_id(),
            'invoice_prefix',
            $invoicePrefix
        );

        $businessPrefixStmt = $pdo->prepare(
            "UPDATE businesses
             SET invoice_prefix = ?
             WHERE id = ?"
        );
        $businessPrefixStmt->execute([
            $invoicePrefix,
            $currentBusinessId,
        ]);

        invoice_api_activity(
            $pdo,
            $currentBusinessId,
            'update_settings',
            null,
            'Updated invoice settings',
            null,
            ['invoice_prefix' => $invoicePrefix]
        );

        $pdo->commit();

        json_response(
            true,
            'Invoice settings saved successfully.',
            ['invoice_prefix' => $invoicePrefix]
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            '[SINGLE INVOICE API SETTINGS] ' .
            $exception->getMessage()
        );

        json_response(
            false,
            $exception->getMessage(),
            [],
            422
        );
    }
}

/*
|--------------------------------------------------------------------------
| Upload or delete invoice assets
|--------------------------------------------------------------------------
*/
if (in_array(
    $backendAction,
    ['upload_asset', 'delete_asset'],
    true
)) {
    if (!is_owner()) {
        json_response(
            false,
            'Only the owner can manage invoice images.',
            [],
            403
        );
    }

    $assetType = trim((string)($_POST['asset_type'] ?? ''));

    if (!in_array($assetType, ['logo', 'signature', 'qr'], true)) {
        json_response(false, 'Invalid asset type.', [], 422);
    }

    $businessStmt = $pdo->prepare(
        "SELECT logo_path
         FROM businesses
         WHERE id = ?
         LIMIT 1"
    );
    $businessStmt->execute([$currentBusinessId]);
    $business = $businessStmt->fetch();

    if (!$business) {
        json_response(false, 'Business not found.', [], 404);
    }

    if ($backendAction === 'delete_asset') {
        if ($assetType === 'logo') {
            invoice_api_delete_local_asset(
                $business['logo_path'] ?? null
            );

            $deleteLogoStmt = $pdo->prepare(
                "UPDATE businesses
                 SET logo_path = NULL
                 WHERE id = ?"
            );
            $deleteLogoStmt->execute([$currentBusinessId]);
        } else {
            $settingKey = $assetType === 'signature'
                ? 'invoice_signature_path'
                : 'invoice_uploaded_qr_path';

            $currentPath = get_invoice_setting(
                $pdo,
                $currentBusinessId,
                $settingKey,
                ''
            );

            invoice_api_delete_local_asset($currentPath);

            save_invoice_setting(
                $pdo,
                $currentBusinessId,
                current_user_id(),
                $settingKey,
                ''
            );
        }

        invoice_api_activity(
            $pdo,
            $currentBusinessId,
            'delete_asset',
            null,
            'Removed invoice ' . $assetType . ' image'
        );

        json_response(
            true,
            ucfirst($assetType) . ' image removed.'
        );
    }

    if (
        !isset($_FILES['asset_file'])
        || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK
    ) {
        json_response(
            false,
            'Select a valid image file.',
            [],
            422
        );
    }

    $file = $_FILES['asset_file'];

    if ((int)$file['size'] > 2 * 1024 * 1024) {
        json_response(
            false,
            'Image size must not exceed 2 MB.',
            [],
            422
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        json_response(
            false,
            'Only PNG, JPG and JPEG images are allowed.',
            [],
            422
        );
    }

    $folder = match ($assetType) {
        'logo' => 'assets/uploads/logos',
        'signature' => 'assets/uploads/signatures',
        'qr' => 'assets/uploads/qr',
    };

    $absoluteFolder = dirname(__DIR__) . '/' . $folder;

    if (
        !is_dir($absoluteFolder)
        && !mkdir($absoluteFolder, 0755, true)
    ) {
        json_response(
            false,
            'Unable to create upload folder.',
            [],
            422
        );
    }

    $extension = $allowedTypes[$mimeType];
    $filename = $assetType . '_' .
        $currentBusinessId . '_' .
        bin2hex(random_bytes(8)) . '.' .
        $extension;

    $absolutePath = $absoluteFolder . '/' . $filename;
    $relativePath = $folder . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        json_response(
            false,
            'Unable to store the uploaded image.',
            [],
            422
        );
    }

    if ($assetType === 'logo') {
        invoice_api_delete_local_asset(
            $business['logo_path'] ?? null
        );

        $logoStmt = $pdo->prepare(
            "UPDATE businesses
             SET logo_path = ?
             WHERE id = ?"
        );
        $logoStmt->execute([
            $relativePath,
            $currentBusinessId,
        ]);
    } else {
        $settingKey = $assetType === 'signature'
            ? 'invoice_signature_path'
            : 'invoice_uploaded_qr_path';

        $oldPath = get_invoice_setting(
            $pdo,
            $currentBusinessId,
            $settingKey,
            ''
        );

        invoice_api_delete_local_asset($oldPath);

        save_invoice_setting(
            $pdo,
            $currentBusinessId,
            current_user_id(),
            $settingKey,
            $relativePath
        );
    }

    invoice_api_activity(
        $pdo,
        $currentBusinessId,
        'upload_asset',
        null,
        'Uploaded invoice ' . $assetType . ' image',
        null,
        ['path' => $relativePath]
    );

    json_response(
        true,
        ucfirst($assetType) . ' image uploaded successfully.',
        ['path' => $relativePath]
    );
}

/*
|--------------------------------------------------------------------------
| Bank account actions
|--------------------------------------------------------------------------
*/
if (in_array(
    $backendAction,
    [
        'save_bank',
        'toggle_bank',
        'default_bank',
        'delete_bank',
    ],
    true
)) {
    if (!is_owner()) {
        json_response(
            false,
            'Only the owner can manage bank accounts.',
            [],
            403
        );
    }

    $bankId = (int)($_POST['id'] ?? 0);

    if ($backendAction === 'save_bank') {
        $accountName = trim((string)(
            $_POST['account_name'] ?? ''
        ));
        $bankName = trim((string)(
            $_POST['bank_name'] ?? ''
        ));
        $branchName = trim((string)(
            $_POST['branch_name'] ?? ''
        ));
        $accountNumber = trim((string)(
            $_POST['account_number'] ?? ''
        ));
        $ifscCode = strtoupper(trim((string)(
            $_POST['ifsc_code'] ?? ''
        )));
        $upiId = trim((string)($_POST['upi_id'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'active'));
        $isDefault = isset($_POST['is_default'])
            && (string)$_POST['is_default'] === '1'
                ? 1
                : 0;

        if (
            $accountName === ''
            || $bankName === ''
            || $accountNumber === ''
            || $ifscCode === ''
        ) {
            json_response(
                false,
                'Account name, bank, account number and IFSC are required.',
                [],
                422
            );
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        try {
            $pdo->beginTransaction();

            if ($isDefault) {
                $clearStmt = $pdo->prepare(
                    "UPDATE business_bank_accounts
                     SET is_default = 0
                     WHERE business_id = ?"
                );
                $clearStmt->execute([$currentBusinessId]);
            }

            if ($bankId > 0) {
                $bankStmt = $pdo->prepare(
                    "UPDATE business_bank_accounts
                     SET account_name = ?,
                         bank_name = ?,
                         branch_name = ?,
                         account_number = ?,
                         ifsc_code = ?,
                         upi_id = ?,
                         is_default = ?,
                         status = ?
                     WHERE id = ?
                       AND business_id = ?"
                );

                $bankStmt->execute([
                    $accountName,
                    $bankName,
                    $branchName ?: null,
                    $accountNumber,
                    $ifscCode,
                    $upiId ?: null,
                    $isDefault,
                    $status,
                    $bankId,
                    $currentBusinessId,
                ]);
            } else {
                $bankStmt = $pdo->prepare(
                    "INSERT INTO business_bank_accounts
                    (
                        business_id,
                        account_name,
                        bank_name,
                        branch_name,
                        account_number,
                        ifsc_code,
                        upi_id,
                        is_default,
                        status
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $bankStmt->execute([
                    $currentBusinessId,
                    $accountName,
                    $bankName,
                    $branchName ?: null,
                    $accountNumber,
                    $ifscCode,
                    $upiId ?: null,
                    $isDefault,
                    $status,
                ]);

                $bankId = (int)$pdo->lastInsertId();
            }

            $defaultCheckStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM business_bank_accounts
                 WHERE business_id = ?
                   AND is_default = 1"
            );
            $defaultCheckStmt->execute([$currentBusinessId]);

            if ((int)$defaultCheckStmt->fetchColumn() === 0) {
                $makeDefaultStmt = $pdo->prepare(
                    "UPDATE business_bank_accounts
                     SET is_default = 1,
                         status = 'active'
                     WHERE id = ?
                       AND business_id = ?"
                );
                $makeDefaultStmt->execute([
                    $bankId,
                    $currentBusinessId,
                ]);
            }

            invoice_api_activity(
                $pdo,
                $currentBusinessId,
                'save_bank',
                null,
                'Saved invoice bank account',
                null,
                ['bank_account_id' => $bankId]
            );

            $pdo->commit();

            json_response(
                true,
                'Bank account saved successfully.',
                ['id' => $bankId]
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                '[SINGLE INVOICE API BANK SAVE] ' .
                $exception->getMessage()
            );

            json_response(
                false,
                'Unable to save bank account.',
                [],
                422
            );
        }
    }

    if ($bankId <= 0) {
        json_response(false, 'Invalid bank account.', [], 422);
    }

    if ($backendAction === 'toggle_bank') {
        $toggleStmt = $pdo->prepare(
            "UPDATE business_bank_accounts
             SET status = IF(status = 'active', 'inactive', 'active')
             WHERE id = ?
               AND business_id = ?"
        );
        $toggleStmt->execute([
            $bankId,
            $currentBusinessId,
        ]);

        json_response(true, 'Bank account status changed.');
    }

    if ($backendAction === 'default_bank') {
        try {
            $pdo->beginTransaction();

            $clearStmt = $pdo->prepare(
                "UPDATE business_bank_accounts
                 SET is_default = 0
                 WHERE business_id = ?"
            );
            $clearStmt->execute([$currentBusinessId]);

            $defaultStmt = $pdo->prepare(
                "UPDATE business_bank_accounts
                 SET is_default = 1,
                     status = 'active'
                 WHERE id = ?
                   AND business_id = ?"
            );
            $defaultStmt->execute([
                $bankId,
                $currentBusinessId,
            ]);

            if ($defaultStmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Bank account not found.'
                );
            }

            $pdo->commit();

            json_response(
                true,
                'Default invoice account updated.'
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            json_response(
                false,
                $exception->getMessage(),
                [],
                422
            );
        }
    }

    if ($backendAction === 'delete_bank') {
        $checkStmt = $pdo->prepare(
            "SELECT is_default
             FROM business_bank_accounts
             WHERE id = ?
               AND business_id = ?
             LIMIT 1"
        );
        $checkStmt->execute([
            $bankId,
            $currentBusinessId,
        ]);
        $account = $checkStmt->fetch();

        if (!$account) {
            json_response(
                false,
                'Bank account not found.',
                [],
                404
            );
        }

        if ((int)$account['is_default'] === 1) {
            json_response(
                false,
                'Set another account as default before deleting this account.',
                [],
                422
            );
        }

        $deleteStmt = $pdo->prepare(
            "DELETE FROM business_bank_accounts
             WHERE id = ?
               AND business_id = ?"
        );
        $deleteStmt->execute([
            $bankId,
            $currentBusinessId,
        ]);

        json_response(true, 'Bank account deleted.');
    }
}

json_response(false, 'Invalid invoice backend action.', [], 422);