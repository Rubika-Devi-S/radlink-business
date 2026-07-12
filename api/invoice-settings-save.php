<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/invoice-settings-functions.php';

if (!is_owner()) {
    json_response(false, 'Only the owner can update invoice settings.', [], 403);
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

$settings = [
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
    'invoice_show_patient' => '0',
    'invoice_show_bank' => '0',
    'invoice_show_qr' => '0',
    'invoice_qr_mode' => 'dynamic_upi',
    'invoice_qr_size_mm' => '25',
    'invoice_qr_amount_source' => 'grand_total',
    'invoice_show_signature' => '0',
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

$qrModes = ['dynamic_upi', 'uploaded_qr', 'upi_text_only'];
$qrAmountSources = ['grand_total', 'balance_amount'];

try {
    $pdo->beginTransaction();

    foreach ($settings as $key => $default) {
        $value = array_key_exists($key, $_POST)
            ? trim((string)$_POST[$key])
            : $default;

        if (in_array($key, $booleanKeys, true)) {
            $value = $value === '1' ? '1' : '0';
        }

        if (in_array($key, $colourKeys, true)) {
            if (!validate_hex_colour($value)) {
                throw new RuntimeException(
                    'Invalid colour value for ' . str_replace('_', ' ', $key) . '.'
                );
            }

            $value = strtoupper($value);
        }

        if (isset($numericRules[$key])) {
            [$min, $max] = $numericRules[$key];
            $number = (float)$value;

            if ($number < $min || $number > $max) {
                throw new RuntimeException(
                    'Invalid value for ' . str_replace('_', ' ', $key) . '.'
                );
            }

            $value = (string)$number;
        }

        if ($key === 'invoice_qr_mode' && !in_array($value, $qrModes, true)) {
            throw new RuntimeException('Invalid QR mode.');
        }

        if (
            $key === 'invoice_contact_email'
            && $value !== ''
            && !filter_var($value, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('Enter a valid invoice email address.');
        }

        if (
            $key === 'invoice_contact_mobile'
            && $value !== ''
            && !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)
        ) {
            throw new RuntimeException('Enter a valid invoice mobile number.');
        }

        if (
            $key === 'invoice_qr_amount_source'
            && !in_array($value, $qrAmountSources, true)
        ) {
            throw new RuntimeException('Invalid QR amount source.');
        }

        save_invoice_setting(
            $pdo,
            $currentBusinessId,
            current_user_id(),
            $key,
            $value
        );
    }

    $invoicePrefix = strtoupper(
        trim((string)($_POST['invoice_prefix'] ?? ''))
    );

    if (
        $invoicePrefix === ''
        || !preg_match('/^[A-Z0-9\/_-]+$/', $invoicePrefix)
    ) {
        throw new RuntimeException(
            'Invoice prefix may contain only letters, numbers, slash, hyphen and underscore.'
        );
    }

    $prefixStmt = $pdo->prepare(
        "UPDATE businesses
         SET invoice_prefix = ?
         WHERE id = ?"
    );

    $prefixStmt->execute([
        $invoicePrefix,
        $currentBusinessId,
    ]);

    if ($prefixStmt->rowCount() === 0) {
        $verify = $pdo->prepare(
            "SELECT invoice_prefix
             FROM businesses
             WHERE id = ?
             LIMIT 1"
        );

        $verify->execute([$currentBusinessId]);

        if ($verify->fetchColumn() === false) {
            throw new RuntimeException('Business record was not found.');
        }
    }

    $pdo->commit();

    json_response(
        true,
        'Invoice settings saved successfully.',
        [
            'invoice_prefix' => $invoicePrefix,
        ]
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[INVOICE SETTINGS SAVE] ' . $exception->getMessage());

    json_response(
        false,
        $exception->getMessage(),
        [],
        422
    );
}
