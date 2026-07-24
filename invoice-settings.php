<?php
declare(strict_types=1);

$pageTitle = 'Invoice Settings';

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/invoice-settings-functions.php';

if (!is_owner()) {
    http_response_code(403);
    exit('Only the owner can manage invoice settings.');
}

$businessStmt = $pdo->prepare(
    "SELECT *
     FROM businesses
     WHERE id = ?
     LIMIT 1"
);

$businessStmt->execute([$currentBusinessId]);
$business = $businessStmt->fetch();

if (!$business) {
    http_response_code(404);
    exit('Business not found.');
}

$bankStmt = $pdo->prepare(
    "SELECT *
     FROM business_bank_accounts
     WHERE business_id = ?
     ORDER BY is_default DESC, status = 'active' DESC, id ASC"
);

$bankStmt->execute([$currentBusinessId]);
$bankAccounts = $bankStmt->fetchAll();

$defaults = [
    'invoice_title' => 'BILL OF SUPPLY',
    'invoice_copy_label' => 'ORIGINAL',
    'invoice_brand_heading' => 'TELE RADIOLOGY REPORTING SOFTWARE',
    'invoice_sub_heading' => '',
    'invoice_theme_hex' => '#E6C8F2',
    'invoice_heading_hex' => '#7B169E',
    'invoice_text_hex' => '#111111',
    'invoice_show_logo' => '1',
    'invoice_logo_width_mm' => '27',
    'invoice_logo_height_mm' => '27',
    'invoice_show_patient' => '1',
    'invoice_show_bank' => '1',
    'invoice_show_qr' => '1',
    'invoice_qr_mode' => 'dynamic_upi',
    'invoice_qr_size_mm' => '25',
    'invoice_qr_amount_source' => 'grand_total',
    'invoice_uploaded_qr_path' => '',
    'invoice_show_signature' => '1',
    'invoice_signature_path' => '',
    'invoice_signature_width_mm' => '30',
    'invoice_signature_height_mm' => '18',
    'invoice_auto_print' => '1',
    'invoice_show_download_fallback' => '1',
    'invoice_number_padding' => '4',
    'invoice_terms' => "Payment methods: G-PAY, PhonePe, UPI or bank transfer.\nPayment due date: Payment is due on or before the invoice due date.\nLate payment: Delayed payments may attract charges as agreed.",
    'invoice_footer_text' => 'Thank you for choosing RAD LINK SCANS.',
    'invoice_contact_mobile' => (string)($business['mobile'] ?? ''),
    'invoice_contact_email' => (string)($business['email'] ?? ''),
    'invoice_address' => implode(', ', array_filter([
        $business['address_line_1'] ?? '',
        $business['address_line_2'] ?? '',
        $business['city'] ?? '',
        $business['district'] ?? '',
        $business['state'] ?? '',
        $business['postal_code'] ?? '',
    ], static fn ($value): bool => trim((string)$value) !== '')),
    'invoice_show_address' => '1',
];

$settings = [];

foreach ($defaults as $key => $default) {
    $settings[$key] = get_invoice_setting(
        $pdo,
        $currentBusinessId,
        $key,
        $default
    );
}

/*
|--------------------------------------------------------------------------
| Invoice prefix source of truth
|--------------------------------------------------------------------------
| Read the saved invoice setting first. The businesses.invoice_prefix value
| remains only as a fallback for older installations.
*/
$savedInvoicePrefix = get_invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_prefix',
    (string)($business['invoice_prefix'] ?? 'RLS-INV')
);

$savedInvoicePrefix = strtoupper(trim($savedInvoicePrefix));

if ($savedInvoicePrefix === '') {
    $savedInvoicePrefix = 'RLS-INV';
}

$defaultBank = get_default_bank_account($pdo, $currentBusinessId);

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.invoice-settings-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(380px, .85fr);
    gap: 18px;
    align-items: start;
}

.settings-section {
    background: var(--card-bg);
    border: 1px solid var(--border-soft);
    border-radius: 18px;
    box-shadow: var(--shadow);
}

.settings-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 17px 18px;
    border-bottom: 1px solid var(--border-soft);
}

.settings-section-header h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 850;
}

.settings-section-body {
    padding: 18px;
}

.settings-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.settings-tab {
    border: 1px solid var(--border-soft);
    background: var(--card-bg);
    color: var(--text-main);
    border-radius: 12px;
    padding: 9px 13px;
    font-weight: 750;
}

.settings-tab.active {
    background: var(--sidebar-active);
    color: var(--brand);
    border-color: transparent;
}

.settings-panel {
    display: none;
}

.settings-panel.active {
    display: block;
}

.image-preview-box {
    min-height: 150px;
    display: grid;
    place-items: center;
    border: 1px dashed var(--border-soft);
    border-radius: 16px;
    background: var(--body-bg);
    overflow: hidden;
    padding: 15px;
}

.image-preview-box img {
    max-width: 100%;
    max-height: 135px;
    object-fit: contain;
}

.settings-upload-note {
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.5;
}

.bank-card {
    border: 1px solid var(--border-soft);
    border-left: 4px solid var(--brand);
    border-radius: 15px;
    padding: 14px;
    background: var(--card-bg);
}

.bank-card.default {
    border-left-color: var(--success);
}

.bank-card.inactive {
    opacity: .65;
}

.bank-card + .bank-card {
    margin-top: 10px;
}

.preview-sticky {
    position: sticky;
    top: calc(var(--topbar-h, 68px) + 16px);
}

.invoice-preview {
    background: #fff;
    color: #111;
    border: 1px solid #dfe3eb;
    border-radius: 18px;
    padding: 18px;
    min-height: 690px;
    box-shadow: 0 16px 45px rgba(15, 23, 42, .10);
}

.invoice-preview-head {
    display: grid;
    grid-template-columns: 1fr .85fr;
    gap: 18px;
}

.preview-company {
    display: grid;
    grid-template-columns: 72px 1fr;
    gap: 12px;
}

.preview-logo {
    width: 68px;
    height: 68px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #f8f8fb;
    overflow: hidden;
}

.preview-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.preview-company-heading {
    font-size: 15px;
    font-weight: 900;
    color: var(--preview-heading);
}

.preview-copy-label {
    display: inline-flex;
    float: right;
    padding: 5px 9px;
    border: 1px solid #999;
    font-size: 10px;
    font-weight: 800;
}

.preview-invoice-title {
    font-size: 17px;
    font-weight: 900;
    margin-bottom: 10px;
}

.preview-meta-row {
    display: grid;
    grid-template-columns: 1fr 10px 1fr;
    gap: 4px;
    font-size: 11px;
    margin-bottom: 6px;
}

.preview-bill-to {
    margin-top: 18px;
}

.preview-bill-label {
    display: inline-flex;
    min-width: 105px;
    padding: 6px 9px;
    background: var(--preview-theme);
    font-weight: 900;
}

.preview-table {
    width: 100%;
    margin-top: 22px;
    border-collapse: collapse;
    font-size: 11px;
}

.preview-table th {
    padding: 9px 7px;
    background: var(--preview-theme);
    text-align: center;
}

.preview-table td {
    padding: 8px 7px;
    border-bottom: 1px solid #f1f1f1;
}

.preview-subtotal {
    margin-top: 100px;
    display: grid;
    grid-template-columns: 1fr 65px 100px;
    padding: 8px;
    background: var(--preview-theme);
    font-size: 11px;
    font-weight: 900;
}

.preview-footer-grid {
    display: grid;
    grid-template-columns: 1fr .85fr;
    gap: 18px;
    margin-top: 14px;
    font-size: 10px;
}

.preview-bank {
    margin-top: 15px;
}

.preview-qr {
    width: 72px;
    height: 72px;
    margin-top: 8px;
    border: 1px solid #ddd;
    display: grid;
    place-items: center;
    font-size: 9px;
    text-align: center;
}

.preview-total-row {
    display: flex;
    justify-content: space-between;
    padding: 7px 0;
    border-top: 1px solid #777;
}

.preview-signature {
    min-height: 62px;
    display: grid;
    place-items: end center;
    text-align: center;
    margin-top: 22px;
}

.preview-signature img {
    max-width: 95px;
    max-height: 46px;
    object-fit: contain;
}

.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--success);
    font-size: 12px;
    font-weight: 850;
}

.live-pill::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--success);
}

@media (max-width: 1199.98px) {
    .invoice-settings-grid {
        grid-template-columns: 1fr;
    }

    .preview-sticky {
        position: static;
    }
}

@media (max-width: 767.98px) {
    .invoice-preview {
        padding: 12px;
        min-height: auto;
    }

    .invoice-preview-head,
    .preview-footer-grid {
        grid-template-columns: 1fr;
    }

    .preview-company {
        grid-template-columns: 56px 1fr;
    }

    .preview-logo {
        width: 52px;
        height: 52px;
    }

    .preview-subtotal {
        margin-top: 40px;
    }
}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">MASTER</span>
        <h1 class="mt-2">Invoice Settings</h1>
        <p>
            Configure the invoice layout, logo, bank, UPI QR,
            signature and printing behaviour.
        </p>
    </div>

    <button
        type="submit"
        form="invoiceSettingsForm"
        class="btn btn-brand"
        id="saveSettingsButton"
    >
        <i data-lucide="save"></i>
        Save Settings
    </button>
</div>

<div class="invoice-settings-grid">
    <div>
        <div class="settings-tabs">
            <button class="settings-tab active" type="button" data-settings-tab="general">
                General
            </button>
            <button class="settings-tab" type="button" data-settings-tab="branding">
                Branding
            </button>
            <button class="settings-tab" type="button" data-settings-tab="payment">
                Bank & UPI
            </button>
            <button class="settings-tab" type="button" data-settings-tab="columns"><i data-lucide="columns-3"></i> Service Columns</button>
<button class="settings-tab" type="button" data-settings-tab="printing">
                Printing
            </button>
        </div>

        <form id="invoiceSettingsForm">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="settings-panel active" data-settings-panel="general">
                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="file-text"></i>
                        <h2>Invoice Identity</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Invoice Title</label>
                                <input
                                    class="form-control"
                                    name="invoice_title"
                                    data-preview-field="invoice_title"
                                    value="<?= e($settings['invoice_title']) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Copy Label</label>
                                <input
                                    class="form-control"
                                    name="invoice_copy_label"
                                    data-preview-field="invoice_copy_label"
                                    value="<?= e($settings['invoice_copy_label']) ?>"
                                >
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Business Heading</label>
                                <input
                                    class="form-control"
                                    name="invoice_brand_heading"
                                    data-preview-field="invoice_brand_heading"
                                    value="<?= e($settings['invoice_brand_heading']) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Invoice Prefix</label>
                                <input
                                    class="form-control text-uppercase"
                                    name="invoice_prefix"
                                    id="invoicePrefix"
                                    value="<?= e($savedInvoicePrefix) ?>"
                                    maxlength="30"
                                    pattern="[A-Za-z0-9/_-]+"
                                    placeholder="RLS-INV"
                                    required
                                >
                                <div class="settings-upload-note mt-1">
                                    Example: RLS-INV, RAD/INV or SCAN-INV
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Number Padding</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    name="invoice_number_padding"
                                    min="2"
                                    max="10"
                                    value="<?= e($settings['invoice_number_padding']) ?>"
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label">Sub Heading</label>
                                <input
                                    class="form-control"
                                    name="invoice_sub_heading"
                                    data-preview-field="invoice_sub_heading"
                                    value="<?= e($settings['invoice_sub_heading']) ?>"
                                    placeholder="Optional small line below the heading"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Invoice Mobile Number</label>
                                <input
                                    type="tel"
                                    class="form-control"
                                    name="invoice_contact_mobile"
                                    data-preview-field="invoice_contact_mobile"
                                    value="<?= e($settings['invoice_contact_mobile']) ?>"
                                    maxlength="20"
                                    placeholder="Enter mobile number"
                                >
                                <div class="settings-upload-note mt-1">
                                    This number will be shown in the invoice header.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Invoice Email Address</label>
                                <input
                                    type="email"
                                    class="form-control"
                                    name="invoice_contact_email"
                                    data-preview-field="invoice_contact_email"
                                    value="<?= e($settings['invoice_contact_email']) ?>"
                                    maxlength="150"
                                    placeholder="Enter email address"
                                >
                                <div class="settings-upload-note mt-1">
                                    This email will be shown in the invoice header.
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Invoice Address</label>
                                <textarea
                                    class="form-control"
                                    name="invoice_address"
                                    data-preview-field="invoice_address"
                                    rows="3"
                                    maxlength="500"
                                    placeholder="Enter the address to show in the invoice header"
                                ><?= e($settings['invoice_address']) ?></textarea>
                                <div class="settings-upload-note mt-1">
                                    This address is used only in the invoice header.
                                    It does not change the main business profile address.
                                </div>

                                <div class="form-check form-switch mt-3">
                                    <input
                                        type="hidden"
                                        name="invoice_show_address"
                                        value="0"
                                    >
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_address"
                                        id="invoiceShowAddress"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_address']) ? 'checked' : '' ?>
                                    >
                                    <label
                                        class="form-check-label"
                                        for="invoiceShowAddress"
                                    >
                                        Show invoice address in header
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Terms and Conditions</label>
                                <textarea
                                    class="form-control"
                                    name="invoice_terms"
                                    data-preview-field="invoice_terms"
                                    rows="6"
                                ><?= e($settings['invoice_terms']) ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Footer Text</label>
                                <textarea
                                    class="form-control"
                                    name="invoice_footer_text"
                                    rows="2"
                                ><?= e($settings['invoice_footer_text']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="settings-panel" data-settings-panel="branding">
                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="palette"></i>
                        <h2>Invoice Colours</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Table Colour</label>
                                <input
                                    type="color"
                                    class="form-control form-control-color"
                                    name="invoice_theme_hex"
                                    data-preview-colour="theme"
                                    value="<?= e($settings['invoice_theme_hex']) ?>"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Heading Colour</label>
                                <input
                                    type="color"
                                    class="form-control form-control-color"
                                    name="invoice_heading_hex"
                                    data-preview-colour="heading"
                                    value="<?= e($settings['invoice_heading_hex']) ?>"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Text Colour</label>
                                <input
                                    type="color"
                                    class="form-control form-control-color"
                                    name="invoice_text_hex"
                                    data-preview-colour="text"
                                    value="<?= e($settings['invoice_text_hex']) ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="image"></i>
                        <h2>Business Logo</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3 align-items-start">
                            <div class="col-md-5">
                                <div class="image-preview-box" id="businessLogoPreview">
                                    <?php if (!empty($business['logo_path'] ?? '')): ?>
                                        <img
                                            src="<?= e(app_url((string)($business['logo_path'] ?? ''))) ?>"
                                            alt="Business logo"
                                        >
                                    <?php else: ?>
                                        <span class="text-muted">No logo uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">Upload Logo</label>
                                <input
                                    type="file"
                                    class="form-control"
                                    id="businessLogoFile"
                                    accept=".png,.jpg,.jpeg"
                                >

                                <div class="settings-upload-note mt-2">
                                    PNG, JPG or JPEG. Maximum 2 MB. Transparent PNG is recommended for FPDF.
                                </div>

                                <div class="row g-3 mt-1">
                                    <div class="col-6">
                                        <label class="form-label">Width (mm)</label>
                                        <input
                                            type="number"
                                            step=".1"
                                            min="10"
                                            max="80"
                                            class="form-control"
                                            name="invoice_logo_width_mm"
                                            value="<?= e($settings['invoice_logo_width_mm']) ?>"
                                        >
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label">Height (mm)</label>
                                        <input
                                            type="number"
                                            step=".1"
                                            min="10"
                                            max="50"
                                            class="form-control"
                                            name="invoice_logo_height_mm"
                                            value="<?= e($settings['invoice_logo_height_mm']) ?>"
                                        >
                                    </div>
                                </div>

                                <div class="form-check form-switch mt-3">
                                    <input type="hidden" name="invoice_show_logo" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_logo"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_logo']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">Show logo on invoice</label>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button
                                        class="btn btn-outline-primary"
                                        type="button"
                                        data-upload-asset="logo"
                                    >
                                        Upload Logo
                                    </button>

                                    <?php if (!empty($business['logo_path'] ?? '')): ?>
                                        <button
                                            class="btn btn-outline-danger"
                                            type="button"
                                            data-delete-asset="logo"
                                        >
                                            Remove Logo
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="signature"></i>
                        <h2>Authorised Signature</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3 align-items-start">
                            <div class="col-md-5">
                                <div class="image-preview-box" id="signaturePreview">
                                    <?php if (!empty($settings['invoice_signature_path'])): ?>
                                        <img
                                            src="<?= e(app_url($settings['invoice_signature_path'])) ?>"
                                            alt="Signature"
                                        >
                                    <?php else: ?>
                                        <span class="text-muted">No signature uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">Upload Signature</label>
                                <input
                                    type="file"
                                    class="form-control"
                                    id="signatureFile"
                                    accept=".png,.jpg,.jpeg"
                                >

                                <div class="settings-upload-note mt-2">
                                    Use a clean PNG or JPG image. Maximum 2 MB.
                                </div>

                                <div class="row g-3 mt-1">
                                    <div class="col-6">
                                        <label class="form-label">Width (mm)</label>
                                        <input
                                            type="number"
                                            step=".1"
                                            min="10"
                                            max="80"
                                            class="form-control"
                                            name="invoice_signature_width_mm"
                                            value="<?= e($settings['invoice_signature_width_mm']) ?>"
                                        >
                                    </div>

                                    <div class="col-6">
                                        <label class="form-label">Height (mm)</label>
                                        <input
                                            type="number"
                                            step=".1"
                                            min="8"
                                            max="40"
                                            class="form-control"
                                            name="invoice_signature_height_mm"
                                            value="<?= e($settings['invoice_signature_height_mm']) ?>"
                                        >
                                    </div>
                                </div>

                                <div class="form-check form-switch mt-3">
                                    <input type="hidden" name="invoice_show_signature" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_signature"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_signature']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">Show signature on invoice</label>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button
                                        class="btn btn-outline-primary"
                                        type="button"
                                        data-upload-asset="signature"
                                    >
                                        Upload Signature
                                    </button>

                                    <?php if (!empty($settings['invoice_signature_path'])): ?>
                                        <button
                                            class="btn btn-outline-danger"
                                            type="button"
                                            data-delete-asset="signature"
                                        >
                                            Remove Signature
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="settings-panel" data-settings-panel="payment">
                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="landmark"></i>
                        <h2>Bank Accounts and UPI</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <div class="fw-bold">Payment Accounts</div>
                                <small class="text-muted">
                                    The default active account is printed on the invoice.
                                </small>
                            </div>

                            <button
                                class="btn btn-brand btn-sm"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#bankModal"
                                id="addBankButton"
                            >
                                <i data-lucide="plus"></i>
                                Add Account
                            </button>
                        </div>

                        <?php if (!$bankAccounts): ?>
                            <div class="alert alert-warning mb-0">
                                No bank or UPI account is configured.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($bankAccounts as $account): ?>
                            <article class="bank-card <?= $account['is_default'] ? 'default' : '' ?> <?= $account['status'] !== 'active' ? 'inactive' : '' ?>">
                                <div class="d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-bold">
                                            <?= e($account['bank_name']) ?>
                                        </div>

                                        <small class="text-muted">
                                            <?= e($account['account_name']) ?>
                                        </small>
                                    </div>

                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($account['is_default']): ?>
                                            <span class="badge bg-success">Default</span>
                                        <?php endif; ?>

                                        <span class="badge <?= $account['status'] === 'active' ? 'bg-primary' : 'bg-secondary' ?>">
                                            <?= e(ucfirst($account['status'])) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="row g-2 mt-2 small">
                                    <div class="col-md-6">
                                        A/C:
                                        <strong><?= e($account['account_number']) ?></strong>
                                    </div>

                                    <div class="col-md-6">
                                        IFSC:
                                        <strong><?= e($account['ifsc_code']) ?></strong>
                                    </div>

                                    <div class="col-md-6">
                                        Branch:
                                        <strong><?= e($account['branch_name'] ?: '—') ?></strong>
                                    </div>

                                    <div class="col-md-6">
                                        UPI:
                                        <strong><?= e($account['upi_id'] ?: '—') ?></strong>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button
                                        class="btn btn-sm btn-light"
                                        type="button"
                                        data-edit-bank
                                        data-record='<?= e(json_encode($account, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                    >
                                        Edit
                                    </button>

                                    <button
                                        class="btn btn-sm btn-outline-secondary"
                                        type="button"
                                        data-toggle-bank="<?= (int)$account['id'] ?>"
                                    >
                                        Change Status
                                    </button>

                                    <?php if (!$account['is_default']): ?>
                                        <button
                                            class="btn btn-sm btn-outline-success"
                                            type="button"
                                            data-default-bank="<?= (int)$account['id'] ?>"
                                        >
                                            Make Default
                                        </button>
                                    <?php endif; ?>

                                    <button
                                        class="btn btn-sm btn-outline-danger ms-md-auto"
                                        type="button"
                                        data-delete-bank="<?= (int)$account['id'] ?>"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="qr-code"></i>
                        <h2>QR Code Behaviour</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label">QR Mode</label>
                                <select
                                    class="form-select"
                                    name="invoice_qr_mode"
                                    id="invoiceQrMode"
                                >
                                    <option
                                        value="dynamic_upi"
                                        <?= $settings['invoice_qr_mode'] === 'dynamic_upi' ? 'selected' : '' ?>
                                    >
                                        Dynamic UPI QR with invoice balance
                                    </option>

                                    <option
                                        value="uploaded_qr"
                                        <?= $settings['invoice_qr_mode'] === 'uploaded_qr' ? 'selected' : '' ?>
                                    >
                                        Uploaded static QR image
                                    </option>

                                    <option
                                        value="upi_text_only"
                                        <?= $settings['invoice_qr_mode'] === 'upi_text_only' ? 'selected' : '' ?>
                                    >
                                        UPI text only
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">QR Size (mm)</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    name="invoice_qr_size_mm"
                                    min="18"
                                    max="45"
                                    step=".1"
                                    value="<?= e($settings['invoice_qr_size_mm']) ?>"
                                >
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">QR Payment Amount</label>
                                <select
                                    class="form-select"
                                    name="invoice_qr_amount_source"
                                >
                                    <option
                                        value="grand_total"
                                        <?= $settings['invoice_qr_amount_source'] === 'grand_total' ? 'selected' : '' ?>
                                    >
                                        Invoice Grand Total
                                    </option>
                                    <option
                                        value="balance_amount"
                                        <?= $settings['invoice_qr_amount_source'] === 'balance_amount' ? 'selected' : '' ?>
                                    >
                                        Current Balance Amount
                                    </option>
                                </select>
                                <div class="settings-upload-note mt-1">
                                    Grand Total is recommended for a newly generated invoice.
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="invoice_show_qr" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_qr"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_qr']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">
                                        Show QR section on invoice
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="invoice_show_bank" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_bank"
                                        id="invoiceShowBank"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_bank']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">
                                        Show bank details on invoice
                                    </label>
                                </div>
                            </div>
                        </div>


                        <div
                            class="row g-3 mt-1"
                            id="staticQrUploadSection"
                        >
                            <div class="col-md-5">
                                <div class="image-preview-box">
                                    <?php if (!empty($settings['invoice_uploaded_qr_path'])): ?>
                                        <img
                                            src="<?= e(app_url($settings['invoice_uploaded_qr_path'])) ?>"
                                            alt="Static QR"
                                        >
                                    <?php else: ?>
                                        <span class="text-muted">No static QR uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">Upload Static QR</label>
                                <input
                                    type="file"
                                    class="form-control"
                                    id="qrFile"
                                    accept=".png,.jpg,.jpeg"
                                >

                                <div class="settings-upload-note mt-2">
                                    Used only when QR Mode is set to Uploaded static QR image.
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button
                                        class="btn btn-outline-primary"
                                        type="button"
                                        data-upload-asset="qr"
                                    >
                                        Upload QR
                                    </button>

                                    <?php if (!empty($settings['invoice_uploaded_qr_path'])): ?>
                                        <button
                                            class="btn btn-outline-danger"
                                            type="button"
                                            data-delete-asset="qr"
                                        >
                                            Remove QR
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info mt-3 mb-0">
                            Dynamic UPI QR uses the default account UPI ID and the selected invoice amount.
                            Static uploaded QR is used only when QR Mode is set to Uploaded Static QR.
                        </div>
                    </div>
                </section>
            </div>

            
<div class="settings-panel" data-settings-panel="columns">
<section class="settings-section mb-3">
<div class="settings-section-header"><i data-lucide="columns-3"></i><h2>Invoice Service Columns</h2></div>
<div class="settings-section-body">
<p class="text-muted">Configure standard and custom columns used in Create Invoice. Custom values are stored per service row.</p>
<div class="table-responsive"><table class="table align-middle" id="columnSettingsTable">
<thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Visible</th><th>Required</th><th>Print</th><th>Order</th><th></th></tr></thead><tbody></tbody></table></div>
<button class="btn btn-brand btn-sm" type="button" id="addColumnRow"><i data-lucide="plus"></i> Add Custom Column</button>
<button class="btn btn-outline-primary btn-sm" type="button" id="saveColumnRows"><i data-lucide="save"></i> Save Columns</button>
</div></section></div>

<div class="settings-panel" data-settings-panel="printing">
                <section class="settings-section mb-3">
                    <div class="settings-section-header">
                        <i data-lucide="printer"></i>
                        <h2>Printing Behaviour</h2>
                    </div>

                    <div class="settings-section-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="invoice_auto_print" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_auto_print"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_auto_print']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">
                                        Attempt automatic print
                                    </label>
                                </div>

                                <div class="settings-upload-note mt-2">
                                    The browser may block automatic printing. Manual print remains available.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="invoice_show_download_fallback" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_download_fallback"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_download_fallback']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">
                                        Show PDF download fallback
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="invoice_show_patient" value="0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="invoice_show_patient"
                                        value="1"
                                        <?= invoice_boolean($settings['invoice_show_patient']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label">
                                        Show patient and reference fields
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </form>
    </div>

    <aside class="preview-sticky">
        <section class="settings-section">
            <div class="settings-section-header justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i data-lucide="eye"></i>
                    <h2>Live Invoice Preview</h2>
                </div>

                <span class="live-pill">Live</span>
            </div>

            <div class="settings-section-body">
                <div
                    class="invoice-preview"
                    id="invoicePreview"
                    style="
                        --preview-theme: <?= e($settings['invoice_theme_hex']) ?>;
                        --preview-heading: <?= e($settings['invoice_heading_hex']) ?>;
                        color: <?= e($settings['invoice_text_hex']) ?>;
                    "
                >
                    <div class="invoice-preview-head">
                        <div class="preview-company">
                            <div class="preview-logo" id="previewLogo">
                                <?php if (!empty($business['logo_path'] ?? '')): ?>
                                    <img
                                        src="<?= e(app_url((string)($business['logo_path'] ?? ''))) ?>"
                                        alt="Logo"
                                    >
                                <?php else: ?>
                                    <span>LOGO</span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div
                                    class="preview-company-heading"
                                    id="previewBusinessHeading"
                                >
                                    <?= e($settings['invoice_brand_heading']) ?>
                                </div>

                                <div
                                    class="small text-muted"
                                    id="previewSubHeading"
                                >
                                    <?= e($settings['invoice_sub_heading']) ?>
                                </div>

                                <div
                                    class="small mt-1"
                                    id="previewInvoiceAddress"
                                    style="white-space: pre-line"
                                    <?= invoice_boolean($settings['invoice_show_address']) ? '' : 'hidden' ?>
                                ><?= e($settings['invoice_address'] !== '' ? $settings['invoice_address'] : '—') ?></div>

                                <div class="small">
                                    Mobile:
                                    <span id="previewInvoiceMobile">
                                        <?= e($settings['invoice_contact_mobile'] !== '' ? $settings['invoice_contact_mobile'] : '—') ?>
                                    </span>
                                </div>

                                <div class="small">
                                    Email:
                                    <span id="previewInvoiceEmail">
                                        <?= e($settings['invoice_contact_email'] !== '' ? $settings['invoice_contact_email'] : '—') ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <span
                                class="preview-copy-label"
                                id="previewCopyLabel"
                            >
                                <?= e($settings['invoice_copy_label']) ?>
                            </span>

                            <div
                                class="preview-invoice-title"
                                id="previewInvoiceTitle"
                            >
                                <?= e($settings['invoice_title']) ?>
                            </div>

                            <div class="preview-meta-row">
                                <span>Invoice No.</span>
                                <span>:</span>
                                <strong id="previewInvoiceNumber"><?= e($savedInvoicePrefix . ' ' . str_pad('58', (int)$settings['invoice_number_padding'], '0', STR_PAD_LEFT)) ?></strong>
                            </div>

                            <div class="preview-meta-row">
                                <span>Invoice Date</span>
                                <span>:</span>
                                <strong><?= e(date('d/m/Y')) ?></strong>
                            </div>

                            <div class="preview-meta-row">
                                <span>Due Date</span>
                                <span>:</span>
                                <strong><?= e(date('d/m/Y', strtotime('+7 days'))) ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="preview-bill-to">
                        <span class="preview-bill-label">BILL TO</span>
                        <div class="fw-bold mt-2">
                            Sample Hospital
                        </div>
                    </div>

                    <table class="preview-table" id="previewServiceTable">
                        <thead id="previewServiceHead">
                            <tr>
                                <th>S.NO.</th>
                                <th>SERVICES</th>
                                <th>QTY.</th>
                                <th>RATE</th>
                                <th>AMOUNT</th>
                            </tr>
                        </thead>

                        <tbody id="previewServiceBody">
                            <tr>
                                <td class="text-center">1</td>
                                <td>CT BRAIN REPORTING</td>
                                <td class="text-center">1 CASE</td>
                                <td class="text-end">250.00</td>
                                <td class="text-end">250.00</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="preview-subtotal">
                        <span class="text-center">SUBTOTAL</span>
                        <span class="text-center">1</span>
                        <span class="text-end">₹ 250.00</span>
                    </div>

                    <div class="preview-footer-grid">
                        <div>
                            <div class="fw-bold">TERMS AND CONDITIONS</div>
                            <div
                                class="mt-1"
                                id="previewTerms"
                                style="white-space: pre-line"
                            ><?= e($settings['invoice_terms']) ?></div>

                            <div
                                class="preview-bank"
                                id="previewBankSection"
                                <?= invoice_boolean($settings['invoice_show_bank']) ? '' : 'hidden' ?>
                            >
                                <div class="fw-bold">BANK DETAILS</div>

                                <div>
                                    Name:
                                    <?= e($defaultBank['account_name'] ?? 'Not configured') ?>
                                </div>

                                <div>
                                    IFSC:
                                    <?= e($defaultBank['ifsc_code'] ?? '—') ?>
                                </div>

                                <div>
                                    Account:
                                    <?= e($defaultBank['account_number'] ?? '—') ?>
                                </div>

                                <div>
                                    Bank:
                                    <?= e($defaultBank['bank_name'] ?? '—') ?>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="fw-bold">PAYMENT QR CODE</div>

                                <div>
                                    UPI ID:
                                    <?= e($defaultBank['upi_id'] ?? '9787457070-kf4c@ybl') ?>
                                </div>

                                <div class="preview-qr" id="previewQr">
                                    Dynamic UPI QR
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="preview-total-row">
                                <strong>Total Amount</strong>
                                <strong>₹ 250.00</strong>
                            </div>

                            <div class="preview-total-row">
                                <span>Received Amount</span>
                                <span>₹ 0.00</span>
                            </div>

                            <div class="text-center mt-3">
                                <strong>Total Amount (in words)</strong>
                                <div>Two Hundred Fifty Rupees Only</div>
                            </div>

                            <div class="preview-signature" id="previewSignature">
                                <?php if (!empty($settings['invoice_signature_path'])): ?>
                                    <img
                                        src="<?= e(app_url($settings['invoice_signature_path'])) ?>"
                                        alt="Signature"
                                    >
                                <?php endif; ?>

                                <div>
                                    Authorised Signature for<br>
                                    <strong><?= e($settings['invoice_brand_heading']) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </aside>
</div>

<div class="modal fade" id="bankModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content card-ui">
            <form id="bankForm">
                <div class="modal-header">
                    <h5 class="modal-title">Bank / UPI Account</h5>
                    <button
                        class="btn-close"
                        type="button"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="bankId"
                    >

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Name</label>
                            <input
                                class="form-control"
                                name="account_name"
                                id="bankAccountName"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bank Name</label>
                            <input
                                class="form-control"
                                name="bank_name"
                                id="bankName"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Account Number</label>
                            <input
                                class="form-control"
                                name="account_number"
                                id="bankAccountNumber"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">IFSC Code</label>
                            <input
                                class="form-control text-uppercase"
                                name="ifsc_code"
                                id="bankIfsc"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Branch Name</label>
                            <input
                                class="form-control"
                                name="branch_name"
                                id="bankBranch"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">UPI ID</label>
                            <input
                                class="form-control"
                                name="upi_id"
                                id="bankUpi"
                                placeholder="9787457070-kf4c@ybl"
                            >
                            <div class="settings-upload-note mt-1">
                                Example: 9787457070-kf4c@ybl
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select
                                class="form-select"
                                name="status"
                                id="bankStatus"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="is_default"
                                    value="1"
                                    id="bankDefault"
                                >
                                <label class="form-check-label">
                                    Default invoice account
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        class="btn btn-light"
                        type="button"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        class="btn btn-brand"
                        type="submit"
                    >
                        Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('[data-settings-tab]');
    const panels = document.querySelectorAll('[data-settings-panel]');
    const settingsForm = document.getElementById('invoiceSettingsForm');
    const bankForm = document.getElementById('bankForm');
    const invoiceShowBank = document.getElementById('invoiceShowBank');
    const previewBankSection = document.getElementById('previewBankSection');

    function updateBankPreviewVisibility() {
        if (!previewBankSection || !invoiceShowBank) {
            return;
        }

        previewBankSection.hidden = !invoiceShowBank.checked;
    }

    if (invoiceShowBank) {
        invoiceShowBank.addEventListener(
            'change',
            updateBankPreviewVisibility
        );

        updateBankPreviewVisibility();
    }
    const bankModal = bootstrap.Modal.getOrCreateInstance(
        document.getElementById('bankModal')
    );

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(item => item.classList.remove('active'));
            panels.forEach(panel => panel.classList.remove('active'));

            tab.classList.add('active');

            document
                .querySelector(
                    '[data-settings-panel="' +
                    tab.dataset.settingsTab +
                    '"]'
                )
                .classList
                .add('active');
        });
    });

    const previewFieldMap = {
        invoice_title: 'previewInvoiceTitle',
        invoice_copy_label: 'previewCopyLabel',
        invoice_brand_heading: 'previewBusinessHeading',
        invoice_sub_heading: 'previewSubHeading',
        invoice_contact_mobile: 'previewInvoiceMobile',
        invoice_contact_email: 'previewInvoiceEmail',
        invoice_address: 'previewInvoiceAddress',
        invoice_terms: 'previewTerms'
    };

    document
        .querySelectorAll('[data-preview-field]')
        .forEach(input => {
            input.addEventListener('input', () => {
                const target = document.getElementById(
                    previewFieldMap[input.dataset.previewField]
                );

                if (target) {
                    const useDashWhenEmpty = [
                        'invoice_contact_mobile',
                        'invoice_contact_email',
                        'invoice_address'
                    ].includes(input.dataset.previewField);

                    target.textContent = useDashWhenEmpty
                        ? (input.value.trim() || '—')
                        : input.value;
                }
            });
        });

    const invoicePrefixInput = document.getElementById('invoicePrefix');

    if (invoicePrefixInput) {
        invoicePrefixInput.addEventListener('input', () => {
            const normalized = invoicePrefixInput.value
                .toUpperCase()
                .replace(/[^A-Z0-9/_-]/g, '');

            invoicePrefixInput.value = normalized;

            const preview = document.getElementById('previewInvoiceNumber');

            if (preview) {
                preview.textContent = (normalized || 'RLS-INV') + ' 0058';
            }
        });
    }

    document
        .querySelectorAll('[data-preview-colour]')
        .forEach(input => {
            input.addEventListener('input', () => {
                const preview = document.getElementById('invoicePreview');

                if (input.dataset.previewColour === 'theme') {
                    preview.style.setProperty(
                        '--preview-theme',
                        input.value
                    );
                }

                if (input.dataset.previewColour === 'heading') {
                    preview.style.setProperty(
                        '--preview-heading',
                        input.value
                    );
                }

                if (input.dataset.previewColour === 'text') {
                    preview.style.color = input.value;
                }
            });
        });

    async function postForm(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const raw = await response.text();

        try {
            return JSON.parse(raw);
        } catch (error) {
            throw new Error(
                raw.trim() !== ''
                    ? raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
                    : 'The server returned an empty response.'
            );
        }
    }

    settingsForm.addEventListener('submit', async event => {
        event.preventDefault();

        const button = document.getElementById('saveSettingsButton');
        const original = button.innerHTML;

        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Saving...';

        try {
            const formData = new FormData(settingsForm);
            formData.set('backend_action', 'save_settings');

            [
                'invoice_show_logo',
                'invoice_show_patient',
                'invoice_show_bank',
                'invoice_show_qr',
                'invoice_show_signature',
                'invoice_auto_print',
                'invoice_show_download_fallback'
            ].forEach(name => {
                const checkbox = settingsForm.querySelector(
                    'input[type="checkbox"][name="' + name + '"]'
                );

                formData.set(
                    name,
                    checkbox && checkbox.checked ? '1' : '0'
                );
            });

            const prefixInput = document.getElementById('invoicePrefix');

            if (prefixInput) {
                formData.set(
                    'invoice_prefix',
                    prefixInput.value.trim().toUpperCase()
                );
            }

            const result = await postForm(
                '<?= e(app_url('api/invoice.php')) ?>',
                formData
            );

            AppToast.show(
                result.success ? 'success' : 'error',
                result.message
            );

            if (result.success) {
                setTimeout(() => location.reload(), 450);
            }
        } catch (error) {
            AppToast.show(
                'error',
                error.message || 'Unable to save invoice settings.'
            );
        } finally {
            button.disabled = false;
            button.innerHTML = original;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    });

    document
        .querySelectorAll('[data-upload-asset]')
        .forEach(button => {
            button.addEventListener('click', async () => {
                const type = button.dataset.uploadAsset;
                let input;

                if (type === 'logo') {
                    input = document.getElementById('businessLogoFile');
                } else if (type === 'signature') {
                    input = document.getElementById('signatureFile');
                } else {
                    input = document.getElementById('qrFile');
                }

                if (!input.files.length) {
                    AppToast.show(
                        'warning',
                        'Select an image before uploading.'
                    );
                    return;
                }

                const formData = new FormData();
                formData.append('csrf_token', window.APP_CSRF);
                formData.append('backend_action', 'upload_asset');
                formData.append('asset_type', type);
                formData.append('asset_file', input.files[0]);

                try {
                    const result = await postForm(
                        '<?= e(app_url('api/invoice.php')) ?>',
                        formData
                    );

                    AppToast.show(
                        result.success ? 'success' : 'error',
                        result.message
                    );

                    if (result.success) {
                        setTimeout(() => location.reload(), 400);
                    }
                } catch (error) {
                    AppToast.show('error', 'Unable to upload image.');
                }
            });
        });

    document
        .querySelectorAll('[data-delete-asset]')
        .forEach(button => {
            button.addEventListener('click', async () => {
                if (!confirm('Remove this image?')) {
                    return;
                }

                const formData = new FormData();
                formData.append('csrf_token', window.APP_CSRF);
                formData.append(
                    'asset_type',
                    button.dataset.deleteAsset
                );
                formData.append('backend_action', 'delete_asset');

                const result = await postForm(
                    '<?= e(app_url('api/invoice.php')) ?>',
                    formData
                );

                AppToast.show(
                    result.success ? 'success' : 'error',
                    result.message
                );

                if (result.success) {
                    setTimeout(() => location.reload(), 400);
                }
            });
        });

    document
        .getElementById('addBankButton')
        .addEventListener('click', () => {
            bankForm.reset();
            bankId.value = '';
            bankStatus.value = 'active';
            bankDefault.checked = false;
        });

    document
        .querySelectorAll('[data-edit-bank]')
        .forEach(button => {
            button.addEventListener('click', () => {
                const row = JSON.parse(button.dataset.record);

                bankId.value = row.id;
                bankAccountName.value = row.account_name;
                bankName.value = row.bank_name;
                bankAccountNumber.value = row.account_number;
                bankIfsc.value = row.ifsc_code;
                bankBranch.value = row.branch_name || '';
                bankUpi.value = row.upi_id || '';
                bankStatus.value = row.status;
                bankDefault.checked = Number(row.is_default) === 1;

                bankModal.show();
            });
        });

    bankForm.addEventListener('submit', async event => {
        event.preventDefault();

        const result = await postForm(
            '<?= e(app_url('api/invoice.php')) ?>',
            (() => {
                const formData = new FormData(bankForm);
                formData.set('backend_action', 'save_bank');
                return formData;
            })()
        );

        AppToast.show(
            result.success ? 'success' : 'error',
            result.message
        );

        if (result.success) {
            setTimeout(() => location.reload(), 400);
        }
    });

    async function bankAction(action, id) {
        const formData = new FormData();

        formData.append('csrf_token', window.APP_CSRF);
        const actionMap = {
            toggle: 'toggle_bank',
            default: 'default_bank',
            delete: 'delete_bank'
        };

        formData.append('backend_action', actionMap[action] || action);
        formData.append('id', id);

        const result = await postForm(
            '<?= e(app_url('api/invoice.php')) ?>',
            formData
        );

        AppToast.show(
            result.success ? 'success' : 'error',
            result.message
        );

        if (result.success) {
            setTimeout(() => location.reload(), 400);
        }
    }

    document
        .querySelectorAll('[data-toggle-bank]')
        .forEach(button => {
            button.addEventListener('click', () => {
                bankAction('toggle', button.dataset.toggleBank);
            });
        });

    document
        .querySelectorAll('[data-default-bank]')
        .forEach(button => {
            button.addEventListener('click', () => {
                bankAction('default', button.dataset.defaultBank);
            });
        });

    document
        .querySelectorAll('[data-delete-bank]')
        .forEach(button => {
            button.addEventListener('click', () => {
                if (confirm('Delete this bank account?')) {
                    bankAction('delete', button.dataset.deleteBank);
                }
            });
        });


    const invoiceShowAddress = document.getElementById('invoiceShowAddress');
    const previewInvoiceAddress = document.getElementById('previewInvoiceAddress');

    if (invoiceShowAddress && previewInvoiceAddress) {
        const syncInvoiceAddressVisibility = () => {
            previewInvoiceAddress.hidden = !invoiceShowAddress.checked;
        };

        invoiceShowAddress.addEventListener(
            'change',
            syncInvoiceAddressVisibility
        );

        syncInvoiceAddressVisibility();
    }

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>


<!-- RADLINK_DYNAMIC_COLUMNS_SCRIPT_START -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.querySelector('#columnSettingsTable tbody');
    const addButton = document.getElementById('addColumnRow');
    const saveButton = document.getElementById('saveColumnRows');

    if (!tableBody || !addButton || !saveButton) {
        return;
    }

    const endpoint = <?= json_encode(app_url('api/invoice-columns.php')) ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    const escapeHtml = value => String(value ?? '').replace(
        /[&<>"']/g,
        character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]
    );

    const typeOptions = [
        'system',
        'text',
        'number',
        'date',
        'select',
        'checkbox',
        'textarea'
    ];

    function buildRow(column = {}) {
        const isSystem = column.column_type === 'system';
        const id = Number(column.id || 0);
        const key = escapeHtml(column.column_key || '');
        const label = escapeHtml(column.column_label || '');
        const selectedType = column.column_type || 'text';

        const options = typeOptions.map(type => {
            const selected = type === selectedType ? ' selected' : '';
            return `<option value="${type}"${selected}>${type}</option>`;
        }).join('');

        return `
            <tr data-column-id="${id}">
                <td>
                    <input type="text"
                           class="form-control form-control-sm column-label"
                           value="${label}"
                           placeholder="Column label">
                </td>
                <td>
                    <input type="text"
                           class="form-control form-control-sm column-key"
                           value="${key}"
                           placeholder="column_key"
                           ${isSystem ? 'readonly' : ''}>
                </td>
                <td>
                    <select class="form-select form-select-sm column-type"
                            ${isSystem ? 'disabled' : ''}>
                        ${options}
                    </select>
                    ${isSystem
                        ? `<input type="hidden" class="column-type-hidden" value="system">`
                        : ''}
                </td>
                <td class="text-center">
                    <input type="checkbox"
                           class="form-check-input column-visible"
                           ${Number(column.is_visible ?? 1) === 1 ? 'checked' : ''}>
                </td>
                <td class="text-center">
                    <input type="checkbox"
                           class="form-check-input column-required"
                           ${Number(column.is_required || 0) === 1 ? 'checked' : ''}>
                </td>
                <td class="text-center">
                    <input type="checkbox"
                           class="form-check-input column-print"
                           ${Number(column.show_in_print || 0) === 1 ? 'checked' : ''}>
                </td>
                <td>
                    <input type="number"
                           class="form-control form-control-sm column-order"
                           value="${Number(column.sort_order || 0)}"
                           min="0"
                           step="1">
                </td>
                <td class="text-end">
                    <div class="d-flex align-items-center justify-content-end gap-1">
                        ${isSystem ? '<span class="badge text-bg-light">System</span>' : ''}
                        <button type="button"
                                class="btn btn-sm btn-outline-danger delete-column"
                                title="Delete column"
                                ${column.column_key === 'service' ? 'disabled' : ''}>
                            <i data-lucide="trash-2"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }

    function showMessage(type, message) {
        if (window.AppToast && typeof window.AppToast.show === 'function') {
            window.AppToast.show(type, message);
            return;
        }

        alert(message);
    }

    async function parseResponse(response) {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (error) {
            throw new Error(
                text.trim() !== ''
                    ? text
                    : 'The server returned an invalid response.'
            );
        }
    }

    async function loadColumns() {
        tableBody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    Loading columns...
                </td>
            </tr>
        `;

        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await parseResponse(response);

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load invoice columns.');
            }

            const columns = Array.isArray(result.data?.columns)
                ? result.data.columns
                : [];

            tableBody.innerHTML = columns.length
                ? columns.map(buildRow).join('')
                : `<tr>
                       <td colspan="8" class="text-center text-muted py-4">
                           No columns configured. Run the supplied database migration.
                       </td>
                   </tr>`;

            if (window.lucide) {
                window.lucide.createIcons();
            }
        } catch (error) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-danger py-4">
                        ${escapeHtml(error.message)}
                    </td>
                </tr>
            `;

            showMessage('error', error.message);
        }
    }

    addButton.addEventListener('click', function () {
        const emptyRow = tableBody.querySelector('td[colspan="8"]');

        if (emptyRow) {
            tableBody.innerHTML = '';
        }

        const nextOrder = tableBody.querySelectorAll('tr').length * 10 + 100;

        tableBody.insertAdjacentHTML(
            'beforeend',
            buildRow({
                id: 0,
                column_label: '',
                column_key: '',
                column_type: 'text',
                is_visible: 1,
                is_required: 0,
                show_in_print: 0,
                sort_order: nextOrder
            })
        );

        if (window.lucide) {
            window.lucide.createIcons();
        }

        const lastRow = tableBody.lastElementChild;
        lastRow?.querySelector('.column-label')?.focus();
    });

    tableBody.addEventListener('click', async function (event) {
        const deleteButton = event.target.closest('.delete-column');

        if (!deleteButton || deleteButton.disabled) {
            return;
        }

        const row = deleteButton.closest('tr');
        const columnId = Number(row?.dataset.columnId || 0);
        const label = row?.querySelector('.column-label')?.value.trim() || 'this column';

        if (!confirm(`Delete "${label}"? Existing saved custom values will remain in old invoice JSON, but the column will no longer appear.`)) {
            return;
        }

        if (columnId <= 0) {
            row?.remove();
            return;
        }

        deleteButton.disabled = true;

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'delete_column');
            formData.append('column_id', String(columnId));

            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await parseResponse(response);

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to delete invoice column.');
            }

            row?.remove();
            showMessage('success', result.message || 'Invoice column deleted.');
        } catch (error) {
            deleteButton.disabled = false;
            showMessage('error', error.message);
        }
    });

    saveButton.addEventListener('click', async function () {
        const rows = Array.from(tableBody.querySelectorAll('tr[data-column-id]'));

        if (rows.length === 0) {
            showMessage('error', 'Add at least one invoice service column.');
            return;
        }

        const columns = rows.map(row => {
            const typeSelect = row.querySelector('.column-type');
            const hiddenType = row.querySelector('.column-type-hidden');

            return {
                id: Number(row.dataset.columnId || 0),
                column_label: row.querySelector('.column-label')?.value.trim() || '',
                column_key: row.querySelector('.column-key')?.value.trim() || '',
                column_type: hiddenType?.value || typeSelect?.value || 'text',
                is_visible: row.querySelector('.column-visible')?.checked ? 1 : 0,
                is_required: row.querySelector('.column-required')?.checked ? 1 : 0,
                show_in_print: row.querySelector('.column-print')?.checked ? 1 : 0,
                sort_order: Number(row.querySelector('.column-order')?.value || 0)
            };
        });

        const invalid = columns.find(column =>
            column.column_label === '' || column.column_key === ''
        );

        if (invalid) {
            showMessage('error', 'Every column requires a label and key.');
            return;
        }

        saveButton.disabled = true;
        const originalHtml = saveButton.innerHTML;
        saveButton.innerHTML = 'Saving...';

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('columns_json', JSON.stringify(columns));

            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await parseResponse(response);

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save invoice columns.');
            }

            showMessage('success', result.message || 'Invoice columns saved.');
            await loadColumns();
        } catch (error) {
            showMessage('error', error.message);
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = originalHtml;

            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    });

    loadColumns();
});
</script>
<!-- RADLINK_DYNAMIC_COLUMNS_SCRIPT_END -->




<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('invoiceSettingsForm');
    const preview = document.getElementById('invoicePreview');

    if (!form || !preview) {
        return;
    }

    const defaultUpiId = <?= json_encode((string)($defaultBank['upi_id'] ?? '')) ?>;
    const staticQrPath = <?= json_encode(
        !empty($settings['invoice_uploaded_qr_path'])
            ? app_url($settings['invoice_uploaded_qr_path'])
            : ''
    ) ?>;

    const fieldTargets = {
        invoice_title: 'previewInvoiceTitle',
        invoice_copy_label: 'previewCopyLabel',
        invoice_brand_heading: 'previewBusinessHeading',
        invoice_sub_heading: 'previewSubHeading',
        invoice_contact_mobile: 'previewInvoiceMobile',
        invoice_contact_email: 'previewInvoiceEmail',
        invoice_address: 'previewInvoiceAddress',
        invoice_terms: 'previewTerms'
    };

    const currency = new Intl.NumberFormat('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]);
    }

    function checked(name) {
        return Boolean(
            form.querySelector(`[name="${name}"][type="checkbox"]`)?.checked
        );
    }

    function value(name, fallback = '') {
        const field = form.elements[name];

        if (!field) {
            return fallback;
        }

        return String(field.value ?? '').trim();
    }

    function setText(id, text, fallback = '') {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = text !== '' ? text : fallback;
        }
    }

    function setVisible(id, visible) {
        const element = document.getElementById(id);

        if (element) {
            element.hidden = !visible;
        }
    }

    function sampleValue(columnKey, columnLabel) {
        const key = String(columnKey || '').toLowerCase();
        const label = String(columnLabel || '').toLowerCase();

        if (key === 'service' || label.includes('service')) {
            return 'CT BRAIN REPORTING';
        }

        if (key === 'quantity' || label.includes('count') || label.includes('qty')) {
            return '1';
        }

        if (key === 'rate' || label.includes('rate')) {
            return '250.00';
        }

        if (key === 'gross_amount' || label.includes('gross')) {
            return '250.00';
        }

        if (key === 'discount_type') {
            return 'None';
        }

        if (key === 'discount_value' || label === 'value') {
            return '0.00';
        }

        if (
            key === 'final_amount' ||
            label.includes('final') ||
            label === 'amount'
        ) {
            return '250.00';
        }

        return 'Sample';
    }

    function renderColumnsPreview() {
        const columnBody = document.querySelector('#columnSettingsTable tbody');
        const previewHead = document.getElementById('previewServiceHead');
        const previewBody = document.getElementById('previewServiceBody');

        if (!columnBody || !previewHead || !previewBody) {
            return;
        }

        const columns = Array.from(
            columnBody.querySelectorAll('tr[data-column-id]')
        )
            .map(row => ({
                label: row.querySelector('.column-label')?.value.trim() || '',
                key: row.querySelector('.column-key')?.value.trim() || '',
                visible: row.querySelector('.column-visible')?.checked ?? true,
                print: row.querySelector('.column-print')?.checked ?? false,
                order: Number(row.querySelector('.column-order')?.value || 0)
            }))
            .filter(column => column.label !== '' && column.visible && column.print)
            .sort((first, second) => first.order - second.order);

        if (columns.length === 0) {
            previewHead.innerHTML =
                '<tr><th>S.NO.</th><th>NO PRINT COLUMNS SELECTED</th></tr>';

            previewBody.innerHTML =
                '<tr><td class="text-center">1</td><td class="text-muted">Enable Print for a column</td></tr>';

            return;
        }

        previewHead.innerHTML =
            '<tr><th>S.NO.</th>' +
            columns.map(column =>
                `<th>${escapeHtml(column.label)}</th>`
            ).join('') +
            '</tr>';

        previewBody.innerHTML =
            '<tr><td class="text-center">1</td>' +
            columns.map(column =>
                `<td>${escapeHtml(sampleValue(column.key, column.label))}</td>`
            ).join('') +
            '</tr>';
    }

    function renderQrPreview() {
        const qr = document.getElementById('previewQr');

        if (!qr) {
            return;
        }

        const showQr = checked('invoice_show_qr');
        const mode = value('invoice_qr_mode', 'dynamic_upi');
        const amountSource = value(
            'invoice_qr_amount_source',
            'grand_total'
        );
        const qrSize = Math.max(
            18,
            Math.min(45, Number(value('invoice_qr_size_mm', '25')) || 25)
        );

        qr.hidden = !showQr;
        qr.style.width = `${Math.round(qrSize * 2.85)}px`;
        qr.style.height = `${Math.round(qrSize * 2.85)}px`;

        if (!showQr) {
            return;
        }

        if (mode === 'uploaded_qr') {
            qr.innerHTML = staticQrPath
                ? `<img src="${escapeHtml(staticQrPath)}"
                        alt="Uploaded QR"
                        style="width:100%;height:100%;object-fit:contain">`
                : '<span class="text-muted">No static QR uploaded</span>';

            return;
        }

        if (mode === 'upi_text_only') {
            qr.innerHTML = defaultUpiId
                ? `<div><strong>UPI</strong><br>${escapeHtml(defaultUpiId)}</div>`
                : '<span class="text-danger">No active default UPI ID</span>';

            return;
        }

        qr.innerHTML = defaultUpiId
            ? `<div>
                   <i data-lucide="qr-code" style="width:32px;height:32px"></i>
                   <div class="mt-1"><strong>Dynamic UPI QR</strong></div>
                   <small>${escapeHtml(defaultUpiId)}</small><br>
                   <small>${amountSource === 'balance_amount' ? 'Balance amount' : 'Grand total'}</small>
               </div>`
            : '<span class="text-danger">Activate the default bank account and add its UPI ID</span>';

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    function renderImagePreview(inputId, previewId, emptyText) {
        const input = document.getElementById(inputId);
        const box = document.getElementById(previewId);

        if (!input || !box || !input.files?.[0]) {
            return;
        }

        const file = input.files[0];

        if (!file.type.startsWith('image/')) {
            return;
        }

        const reader = new FileReader();

        reader.addEventListener('load', function () {
            box.innerHTML =
                `<img src="${reader.result}"
                      alt="${escapeHtml(emptyText)}"
                      style="max-width:100%;max-height:135px;object-fit:contain">`;
        });

        reader.readAsDataURL(file);
    }

    function renderPreview() {
        Object.entries(fieldTargets).forEach(([fieldName, targetId]) => {
            const fallback = [
                'invoice_contact_mobile',
                'invoice_contact_email',
                'invoice_address'
            ].includes(fieldName) ? '—' : '';

            setText(targetId, value(fieldName), fallback);
        });

        preview.style.setProperty(
            '--preview-theme',
            value('invoice_theme_hex', '#E6C8F2')
        );

        preview.style.setProperty(
            '--preview-heading',
            value('invoice_heading_hex', '#7B169E')
        );

        preview.style.color = value('invoice_text_hex', '#111111');

        setVisible('previewInvoiceAddress', checked('invoice_show_address'));
        setVisible('previewLogo', checked('invoice_show_logo'));
        setVisible('previewBankSection', checked('invoice_show_bank'));
        setVisible('previewSignature', checked('invoice_show_signature'));

        const prefix = value('invoice_prefix', 'RLS-INV').toUpperCase();
        const padding = Math.max(
            2,
            Math.min(10, Number(value('invoice_number_padding', '4')) || 4)
        );

        setText(
            'previewInvoiceNumber',
            `${prefix} ${String(58).padStart(padding, '0')}`
        );

        const subtotalAmount = 250;
        const subtotal = document.querySelector('.preview-subtotal');

        if (subtotal) {
            subtotal.innerHTML =
                `<span class="text-center">SUBTOTAL</span>
                 <span class="text-center">1</span>
                 <span class="text-end">₹ ${currency.format(subtotalAmount)}</span>`;
        }

        const qrMode = document.getElementById('invoiceQrMode');
        const staticSection = document.getElementById('staticQrUploadSection');

        if (staticSection && qrMode) {
            staticSection.hidden = qrMode.value !== 'uploaded_qr';
        }

        renderQrPreview();
        renderColumnsPreview();
    }

    let frameId = 0;

    function schedulePreview() {
        window.cancelAnimationFrame(frameId);
        frameId = window.requestAnimationFrame(renderPreview);
    }

    form.addEventListener('input', schedulePreview);
    form.addEventListener('change', schedulePreview);

    document
        .getElementById('columnSettingsTable')
        ?.addEventListener('input', schedulePreview);

    document
        .getElementById('columnSettingsTable')
        ?.addEventListener('change', schedulePreview);

    const columnBody = document.querySelector('#columnSettingsTable tbody');

    if (columnBody) {
        new MutationObserver(schedulePreview).observe(columnBody, {
            childList: true,
            subtree: true
        });
    }

    document
        .getElementById('businessLogoFile')
        ?.addEventListener('change', function () {
            renderImagePreview(
                'businessLogoFile',
                'businessLogoPreview',
                'Business logo'
            );

            renderImagePreview(
                'businessLogoFile',
                'previewLogo',
                'Business logo'
            );
        });

    document
        .getElementById('signatureFile')
        ?.addEventListener('change', function () {
            renderImagePreview(
                'signatureFile',
                'signaturePreview',
                'Signature'
            );

            renderImagePreview(
                'signatureFile',
                'previewSignature',
                'Signature'
            );
        });

    document
        .getElementById('qrFile')
        ?.addEventListener('change', function () {
            const qr = document.getElementById('previewQr');
            const input = document.getElementById('qrFile');

            if (!qr || !input?.files?.[0]) {
                return;
            }

            const reader = new FileReader();

            reader.addEventListener('load', function () {
                qr.innerHTML =
                    `<img src="${reader.result}"
                          alt="Uploaded QR"
                          style="width:100%;height:100%;object-fit:contain">`;
            });

            reader.readAsDataURL(input.files[0]);
        });

    renderPreview();
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
