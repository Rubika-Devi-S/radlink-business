<?php
declare(strict_types=1);

$pageTitle = 'Create Hospital Invoice';

require_once __DIR__ . '/includes/bootstrap.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$invoice = null;
$existingItems = [];

if ($invoiceId > 0) {
    $invoiceStmt = $pdo->prepare(
        "SELECT *
         FROM invoices
         WHERE id = ?
           AND business_id = ?
         LIMIT 1"
    );

    $invoiceStmt->execute([
        $invoiceId,
        $currentBusinessId,
    ]);

    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        http_response_code(404);
        exit('Invoice not found.');
    }

    $itemStmt = $pdo->prepare(
        "SELECT *
         FROM invoice_items
         WHERE invoice_id = ?
           AND business_id = ?
         ORDER BY sort_order, id"
    );

    $itemStmt->execute([
        $invoiceId,
        $currentBusinessId,
    ]);

    $existingItems = $itemStmt->fetchAll();
    $pageTitle = 'Edit Hospital Invoice';
}

/*
|--------------------------------------------------------------------------
| Hospital clients
|--------------------------------------------------------------------------
| First preference: clients mapped to a client type containing "hospital".
| Fallback: all active clients, so the page still works on older databases.
*/
$hospitals = [];

try {
    $hospitalStmt = $pdo->prepare(
        "SELECT
            c.*,
            ct.type_name AS client_type_name
         FROM clients c
         LEFT JOIN client_types ct
            ON ct.id = c.client_type_id
         WHERE c.business_id = ?
           AND c.status = 'active'
           AND (
                LOWER(COALESCE(ct.type_name, '')) LIKE '%hospital%'
                OR LOWER(COALESCE(c.client_code, '')) LIKE 'hos%'
           )
         ORDER BY c.client_name"
    );

    $hospitalStmt->execute([$currentBusinessId]);
    $hospitals = $hospitalStmt->fetchAll();
} catch (Throwable $exception) {
    $hospitalStmt = $pdo->prepare(
        "SELECT *
         FROM clients
         WHERE business_id = ?
           AND status = 'active'
         ORDER BY client_name"
    );

    $hospitalStmt->execute([$currentBusinessId]);
    $hospitals = $hospitalStmt->fetchAll();
}

$serviceStmt = $pdo->prepare(
    "SELECT
        s.*,
        sc.category_name
     FROM services s
     INNER JOIN service_categories sc
        ON sc.id = s.service_category_id
       AND sc.business_id = s.business_id
     WHERE s.business_id = ?
       AND s.status = 'active'
     ORDER BY
        sc.sort_order,
        sc.category_name,
        s.service_name"
);

$serviceStmt->execute([$currentBusinessId]);
$services = $serviceStmt->fetchAll();

$selectedHospitalId = (int)($invoice['client_id'] ?? 0);
$invoiceDate = (string)($invoice['invoice_date'] ?? date('Y-m-d'));
$dueDate = (string)($invoice['due_date'] ?? date('Y-m-d', strtotime('+7 days')));

$financialYearStmt = $pdo->prepare(
    "SELECT id, year_label, start_date, end_date, is_current, status
     FROM financial_years
     WHERE business_id = ?
     ORDER BY is_current DESC, start_date DESC"
);
$financialYearStmt->execute([$currentBusinessId]);
$financialYears = $financialYearStmt->fetchAll();

$selectedFinancialYearId = (int)($invoice['financial_year_id'] ?? 0);

if ($selectedFinancialYearId <= 0) {
    foreach ($financialYears as $financialYear) {
        if (
            $invoiceDate >= $financialYear['start_date']
            && $invoiceDate <= $financialYear['end_date']
        ) {
            $selectedFinancialYearId = (int)$financialYear['id'];
            break;
        }
    }
}

include __DIR__ . '/includes/layout-start.php';
?>

<link
    href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
    rel="stylesheet"
>

<style>
.hospital-invoice-page {
    --invoice-radius: 18px;
}

.invoice-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.invoice-page-head h1 {
    margin: 8px 0 4px;
}

.invoice-card {
    background: var(--card-bg);
    border: 1px solid var(--border-soft);
    border-radius: var(--invoice-radius);
    box-shadow: var(--shadow);
}

.invoice-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-soft);
}

.invoice-card-header h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 850;
}

.invoice-card-body {
    padding: 20px;
}

.hospital-summary {
    display: none;
    margin-top: 16px;
    padding: 16px;
    border: 1px solid var(--border-soft);
    border-left: 4px solid var(--brand);
    border-radius: 15px;
    background: var(--body-bg);
}

.hospital-summary.show {
    display: block;
}

.hospital-summary-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.hospital-summary-title strong {
    font-size: 16px;
}

.hospital-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 14px;
}

.hospital-meta-item small {
    display: block;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 3px;
}

.hospital-meta-item span {
    display: block;
    color: var(--text-main);
    font-weight: 750;
    overflow-wrap: anywhere;
}

.invoice-item {
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    padding: 15px;
    background: var(--card-bg);
}

.invoice-item + .invoice-item {
    margin-top: 12px;
}

.invoice-item-grid {
    display: grid;
    grid-template-columns:
        minmax(260px, 2.1fr)
        minmax(90px, .55fr)
        minmax(120px, .8fr)
        minmax(120px, .8fr)
        minmax(110px, .75fr)
        minmax(100px, .65fr)
        minmax(120px, .8fr)
        auto;
    gap: 10px;
    align-items: end;
}

.rate-caption {
    min-height: 18px;
    margin-top: 5px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
}

.rate-caption .hospital-rate {
    color: var(--success);
}

.line-total-box {
    min-height: 46px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 10px 12px;
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--body-bg);
    font-weight: 900;
    white-space: nowrap;
}

.invoice-empty {
    padding: 34px 16px;
    text-align: center;
    border: 1px dashed var(--border-soft);
    border-radius: 16px;
    color: var(--text-muted);
}

.invoice-summary {
    max-width: 440px;
    margin-left: auto;
}

.invoice-summary-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-soft);
}

.invoice-summary-row.total {
    padding-top: 14px;
    border-bottom: 0;
    font-size: 19px;
    font-weight: 900;
}

.invoice-actions {
    position: sticky;
    bottom: 10px;
    z-index: 20;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px;
    margin-top: 16px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: color-mix(in srgb, var(--card-bg) 94%, transparent);
    backdrop-filter: blur(12px);
    box-shadow: var(--shadow);
}

.modal-dialog-scrollable .modal-content {
    max-height: calc(100vh - 24px);
}

.modal-dialog-scrollable .modal-body {
    overflow-y: auto;
    padding-bottom: 24px;
}

@media (max-width: 767.98px) {
    #quickHospitalModal .modal-dialog {
        margin: 8px;
    }

    #quickHospitalModal .modal-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    #quickHospitalModal .modal-footer .btn {
        width: 100%;
    }
}

.select2-container {
    width: 100% !important;
}

.select2-container .select2-selection--single {
    height: 46px;
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--card-bg);
}

.select2-container--default
.select2-selection--single
.select2-selection__rendered {
    line-height: 44px;
    padding-left: 13px;
    padding-right: 36px;
    color: var(--text-main);
}

.select2-container--default
.select2-selection--single
.select2-selection__arrow {
    height: 44px;
    right: 7px;
}

.select2-dropdown {
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
}

.select2-search--dropdown {
    padding: 9px;
}

.select2-search--dropdown .select2-search__field {
    min-height: 40px;
    border: 1px solid var(--border-soft) !important;
    border-radius: 9px;
}

.select2-results__option {
    padding: 10px 12px;
}

.select2-results__option--highlighted.select2-results__option--selectable {
    background: var(--brand) !important;
}

.hospital-option-name {
    font-weight: 800;
}

.hospital-option-meta {
    margin-top: 2px;
    font-size: 11px;
    opacity: .78;
}

@media (max-width: 1250px) {
    .invoice-item-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .invoice-item-grid .service-field {
        grid-column: span 2;
    }
}

@media (max-width: 991.98px) {
    .hospital-meta-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .invoice-item-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .invoice-item-grid .service-field {
        grid-column: 1 / -1;
    }

    .invoice-item-grid .remove-field {
        display: flex;
        justify-content: flex-end;
        align-items: end;
    }
}

@media (max-width: 767.98px) {
    .invoice-page-head {
        align-items: stretch;
        flex-direction: column;
    }

    .invoice-page-head .btn {
        width: 100%;
    }

    .invoice-card-header,
    .invoice-card-body {
        padding: 15px;
    }

    .invoice-card-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .hospital-meta-grid {
        grid-template-columns: 1fr;
    }

    .invoice-item-grid {
        grid-template-columns: 1fr;
    }

    .invoice-item-grid .service-field {
        grid-column: auto;
    }

    .invoice-item-grid .remove-field {
        justify-content: stretch;
    }

    .invoice-item-grid .remove-field .btn {
        width: 100%;
    }

    .invoice-summary {
        max-width: none;
    }

    .invoice-actions {
        flex-direction: column-reverse;
    }

    .invoice-actions .btn {
        width: 100%;
    }
}
</style>

<div class="hospital-invoice-page">
    <div class="invoice-page-head">
        <div>
            <span class="badge-soft">INVOICES</span>

            <h1>
                <?= $invoiceId > 0
                    ? 'Edit Hospital Invoice'
                    : 'Create Hospital Invoice' ?>
            </h1>

            <p class="mb-0 text-muted">
                Select a hospital and add reporting services.
                Hospital-specific rates are loaded automatically.
            </p>
        </div>

        <a
            class="btn btn-light"
            href="<?= e(app_url('invoices.php')) ?>"
        >
            <i data-lucide="arrow-left"></i>
            Back to Invoice List
        </a>
    </div>

    <form id="invoiceForm" novalidate>
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="invoice_id"
            value="<?= $invoiceId ?>"
        >

        <input
            type="hidden"
            name="billing_responsibility"
            value="client_credit"
        >

        <input
            type="hidden"
            name="invoice_status"
            id="invoiceStatus"
            value="<?= e($invoice['invoice_status'] ?? 'issued') ?>"
        >

        <input
            type="hidden"
            name="items_json"
            id="itemsJson"
        >

        <section class="invoice-card mb-3">
            <div class="invoice-card-header">
                <div>
                    <h2>Hospital and Invoice Information</h2>
                    <small class="text-muted">
                        Search and select a registered hospital client.
                    </small>
                </div>

                <a
                    href="#"
                    class="btn btn-sm btn-outline-primary disabled"
                    id="previousInvoicesLink"
                    aria-disabled="true"
                >
                    <i data-lucide="history"></i>
                    Previous Invoices
                </a>
            </div>

            <div class="invoice-card-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <label
                            class="form-label fw-semibold"
                            for="hospitalId"
                        >
                            Hospital
                            <span class="text-danger">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="client_id"
                            id="hospitalId"
                            required
                        >
                            <option value="">Search hospital...</option>

                            <?php foreach ($hospitals as $hospital): ?>
                                <?php
                                $address = implode(', ', array_filter([
                                    $hospital['address_line_1'] ?? '',
                                    $hospital['address_line_2'] ?? '',
                                    $hospital['city'] ?? '',
                                    $hospital['district'] ?? '',
                                    $hospital['state'] ?? '',
                                    $hospital['postal_code'] ?? '',
                                ], static fn ($value): bool =>
                                    trim((string)$value) !== ''
                                ));

                                $creditDays = (int)(
                                    $hospital['credit_period_days']
                                    ?? $hospital['credit_days']
                                    ?? 0
                                );

                                $billingMode = (string)(
                                    $hospital['billing_mode']
                                    ?? 'Hospital Credit'
                                );
                                ?>

                                <option
                                    value="<?= (int)$hospital['id'] ?>"
                                    data-code="<?= e($hospital['client_code'] ?? '') ?>"
                                    data-name="<?= e($hospital['client_name'] ?? '') ?>"
                                    data-mobile="<?= e($hospital['mobile'] ?? '') ?>"
                                    data-email="<?= e($hospital['email'] ?? '') ?>"
                                    data-address="<?= e($address) ?>"
                                    data-credit-days="<?= $creditDays ?>"
                                    data-billing-mode="<?= e($billingMode) ?>"
                                    <?= $selectedHospitalId === (int)$hospital['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e(
                                        ($hospital['client_code'] ?? '') .
                                        ' - ' .
                                        ($hospital['client_name'] ?? '')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-2">
                        <label
                            class="form-label fw-semibold"
                            for="invoiceDate"
                        >
                            Invoice Date
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="date"
                            name="invoice_date"
                            id="invoiceDate"
                            value="<?= e($invoiceDate) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4 col-lg-2">
                        <label
                            class="form-label fw-semibold"
                            for="dueDate"
                        >
                            Due Date
                        </label>

                        <input
                            class="form-control"
                            type="date"
                            name="due_date"
                            id="dueDate"
                            value="<?= e($dueDate) ?>"
                        >
                    </div>

                    <div class="col-md-4 col-lg-2">
                        <label
                            class="form-label fw-semibold"
                            for="financialYearId"
                        >
                            Financial Year
                        </label>

                        <select
                            class="form-select"
                            name="financial_year_id"
                            id="financialYearId"
                        >
                            <option value="0">Auto by invoice date</option>

                            <?php foreach ($financialYears as $financialYear): ?>
                                <option
                                    value="<?= (int)$financialYear['id'] ?>"
                                    <?= $selectedFinancialYearId === (int)$financialYear['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($financialYear['year_label']) ?>
                                    <?= $financialYear['is_current'] ? ' (Current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$financialYears): ?>
                            <div class="form-text text-warning">
                                No financial year exists. It will be created automatically when the invoice is saved.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4 col-lg-3">
                        <label
                            class="form-label fw-semibold"
                            for="hospitalReference"
                        >
                            Hospital Reference
                        </label>

                        <input
                            class="form-control"
                            type="text"
                            name="hospital_reference_no"
                            id="hospitalReference"
                            value="<?= e($invoice['hospital_reference_no'] ?? '') ?>"
                            placeholder="Optional"
                        >
                    </div>
                </div>

                <div
                    class="hospital-summary"
                    id="hospitalSummary"
                >
                    <div class="hospital-summary-title">
                        <div>
                            <strong id="summaryHospitalName">—</strong>
                            <div class="text-muted small" id="summaryHospitalCode">
                                —
                            </div>
                        </div>

                        <span class="status-pill">
                            Registered Hospital
                        </span>
                    </div>

                    <div class="hospital-meta-grid">
                        <div class="hospital-meta-item">
                            <small>Mobile</small>
                            <span id="summaryMobile">—</span>
                        </div>

                        <div class="hospital-meta-item">
                            <small>Email</small>
                            <span id="summaryEmail">—</span>
                        </div>

                        <div class="hospital-meta-item">
                            <small>Credit Period</small>
                            <span id="summaryCredit">—</span>
                        </div>

                        <div class="hospital-meta-item">
                            <small>Billing Mode</small>
                            <span id="summaryBillingMode">Hospital Credit</span>
                        </div>

                        <div class="hospital-meta-item" style="grid-column: 1 / -1">
                            <small>Billing Address</small>
                            <span id="summaryAddress">—</span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Patient Name
                        </label>

                        <input
                            class="form-control"
                            name="patient_name"
                            value="<?= e($invoice['patient_name'] ?? '') ?>"
                            placeholder="Optional"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Patient Reference No.
                        </label>

                        <input
                            class="form-control"
                            name="patient_reference_no"
                            value="<?= e($invoice['patient_reference_no'] ?? '') ?>"
                            placeholder="Optional"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Billing Responsibility
                        </label>

                        <input
                            class="form-control"
                            value="Hospital Credit"
                            readonly
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="invoice-card mb-3">
            <div class="invoice-card-header">
                <div>
                    <h2>Invoice Services</h2>
                    <small class="text-muted">
                        Add one or more hospital reporting services.
                    </small>
                </div>

                <button
                    class="btn btn-sm btn-brand"
                    type="button"
                    id="addServiceButton"
                >
                    <i data-lucide="plus"></i>
                    Add Service
                </button>
            </div>

            <div class="invoice-card-body">
                <div id="invoiceItems"></div>

                <div
                    class="invoice-empty"
                    id="emptyItems"
                    hidden
                >
                    <i data-lucide="receipt-text"></i>
                    <div class="fw-bold mt-2">
                        No services added
                    </div>
                    <small>
                        Click Add Service to create the first invoice line.
                    </small>
                </div>
            </div>
        </section>

        <section class="invoice-card mb-3">
            <div class="invoice-card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold">
                            Notes
                        </label>

                        <textarea
                            class="form-control"
                            name="notes"
                            rows="5"
                            placeholder="Enter invoice notes, billing period or instructions..."
                        ><?= e($invoice['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="col-lg-6">
                        <div class="invoice-summary">
                            <div class="invoice-summary-row">
                                <span>Subtotal</span>
                                <strong id="subtotalText">₹0.00</strong>
                            </div>

                            <div class="invoice-summary-row">
                                <span>Discount</span>
                                <strong id="discountText">₹0.00</strong>
                            </div>

                            <div class="invoice-summary-row">
                                <span>Tax</span>
                                <strong id="taxText">₹0.00</strong>
                            </div>

                            <div class="invoice-summary-row">
                                <span>Round Off</span>
                                <strong id="roundOffText">₹0.00</strong>
                            </div>

                            <div class="invoice-summary-row total">
                                <span>Grand Total</span>
                                <strong id="grandTotalText">₹0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="invoice-actions">
            <button
                class="btn btn-outline-secondary"
                type="button"
                data-save-mode="draft"
            >
                <i data-lucide="file-clock"></i>
                Save Draft
            </button>

            <button
                class="btn btn-brand"
                type="button"
                data-save-mode="issued"
                id="saveIssueButton"
            >
                <i data-lucide="badge-check"></i>
                Save & Issue
            </button>
        </div>
    </form>
</div>

<template id="invoiceItemTemplate">
    <article class="invoice-item">
        <div class="invoice-item-grid">
            <div class="service-field">
                <label class="form-label fw-semibold">
                    Service
                </label>

                <select class="form-select item-service">
                    <option value="">Select service...</option>

                    <?php foreach ($services as $service): ?>
                        <option
                            value="<?= (int)$service['id'] ?>"
                            data-standard-rate="<?= e($service['standard_rate']) ?>"
                            data-tax="<?= e($service['tax_percent']) ?>"
                            data-unit="<?= e($service['unit_name']) ?>"
                        >
                            <?= e(
                                $service['service_code'] .
                                ' - ' .
                                $service['service_name'] .
                                ' (' .
                                $service['category_name'] .
                                ')'
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="rate-caption">
                    Select a service to load the hospital rate.
                </div>
            </div>

            <div>
                <label class="form-label fw-semibold">Qty</label>

                <input
                    type="number"
                    class="form-control item-quantity"
                    value="1"
                    min="0.001"
                    step="0.001"
                >
            </div>

            <div>
                <label class="form-label fw-semibold">
                    Standard Rate
                </label>

                <input
                    type="number"
                    class="form-control item-standard-rate"
                    value="0"
                    readonly
                >
            </div>

            <div>
                <label class="form-label fw-semibold">
                    Applied Rate
                </label>

                <input
                    type="number"
                    class="form-control item-applied-rate"
                    value="0"
                    min="0"
                    step="0.01"
                >
            </div>

            <div>
                <label class="form-label fw-semibold">
                    Discount
                </label>

                <select class="form-select item-discount-type">
                    <option value="none">None</option>
                    <option value="amount">Amount</option>
                    <option value="percentage">Percentage</option>
                </select>
            </div>

            <div>
                <label class="form-label fw-semibold">
                    Value
                </label>

                <input
                    type="number"
                    class="form-control item-discount-value"
                    value="0"
                    min="0"
                    step="0.01"
                >
            </div>

            <div>
                <label class="form-label fw-semibold">
                    Line Total
                </label>

                <div class="line-total-box item-line-total">
                    ₹0.00
                </div>
            </div>

            <div class="remove-field">
                <button
                    type="button"
                    class="btn btn-outline-danger remove-item"
                    aria-label="Remove service"
                >
                    <i data-lucide="trash-2"></i>
                </button>
            </div>
        </div>

        <input
            type="hidden"
            class="item-tax"
            value="0"
        >
    </article>
</template>


<div class="modal fade" id="quickHospitalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <form id="quickHospitalForm">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">Quick Add Hospital</h5>
                        <small class="text-muted">
                            Complete the hospital details and continue the invoice.
                        </small>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Hospital Name <span class="text-danger">*</span></label>
                            <input class="form-control" name="client_name" id="quickHospitalName" maxlength="200" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Hospital Code</label>
                            <input class="form-control text-uppercase" name="client_code" id="quickHospitalCode" maxlength="40" placeholder="Auto-generated">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Contact Person</label>
                            <input class="form-control" name="contact_person" maxlength="150">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Mobile</label>
                            <input class="form-control" type="tel" name="mobile" maxlength="20">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Alternate Mobile</label>
                            <input class="form-control" type="tel" name="alternate_mobile" maxlength="20">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input class="form-control" type="email" name="email" maxlength="190">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">GST Number</label>
                            <input class="form-control text-uppercase" name="gst_number" maxlength="30">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Address Line 1</label>
                            <input class="form-control" name="address_line_1" maxlength="255">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Address Line 2</label>
                            <input class="form-control" name="address_line_2" maxlength="255">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">City</label>
                            <input class="form-control" name="city" maxlength="100">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">District</label>
                            <input class="form-control" name="district" maxlength="100">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">State</label>
                            <input class="form-control" name="state" maxlength="100" value="Tamil Nadu">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Postal Code</label>
                            <input class="form-control" name="postal_code" maxlength="15">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Credit Period (Days)</label>
                            <input class="form-control" type="number" name="credit_period_days" id="quickHospitalCredit" min="0" max="3650" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Billing Mode</label>
                            <select class="form-select" name="default_billing_mode" id="quickHospitalBillingMode">
                                <option value="credit">Hospital Credit</option>
                                <option value="direct">Direct</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" id="quickHospitalStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer position-sticky bottom-0 bg-body border-top" style="z-index:5">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-brand px-4" type="submit" id="quickHospitalSaveButton" style="min-width:160px">
                        <i data-lucide="save"></i>
                        <span>Save Hospital</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('invoiceForm');
    const hospitalSelect = document.getElementById('hospitalId');
    const invoiceDate = document.getElementById('invoiceDate');
    const dueDate = document.getElementById('dueDate');
    const previousInvoicesLink = document.getElementById(
        'previousInvoicesLink'
    );

    const itemsContainer = document.getElementById('invoiceItems');
    const emptyItems = document.getElementById('emptyItems');
    const itemTemplate = document.getElementById(
        'invoiceItemTemplate'
    );

    const existingItems = <?= json_encode(
        array_map(
            static fn (array $item): array => [
                'service_id' => (int)$item['service_id'],
                'quantity' => (float)$item['quantity'],
                'standard_rate' => (float)$item['standard_rate'],
                'applied_rate' => (float)$item['applied_rate'],
                'discount_type' => (string)$item['discount_type'],
                'discount_value' => (float)$item['discount_value'],
                'tax_percent' => (float)$item['tax_percent'],
            ],
            $existingItems
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    function hospitalTemplate(option) {
        if (!option.id) {
            return option.text;
        }

        const element = option.element;

        const name = element.dataset.name || option.text;
        const code = element.dataset.code || '';
        const mobile = element.dataset.mobile || '';
        const address = element.dataset.address || '';

        return $(`
            <div>
                <div class="hospital-option-name"></div>
                <div class="hospital-option-meta"></div>
            </div>
        `)
            .find('.hospital-option-name')
            .text(name)
            .end()
            .find('.hospital-option-meta')
            .text(
                [code, mobile, address]
                    .filter(Boolean)
                    .join(' · ')
            )
            .end();
    }

    let pendingHospitalTag = null;

    $('#hospitalId').select2({
        placeholder: 'Search or type a new hospital...',
        allowClear: true,
        width: '100%',
        tags: true,
        createTag: params => {
            const term = String(params.term || '').trim();

            if (term.length < 2) {
                return null;
            }

            const duplicate = Array.from(hospitalSelect.options).some(option => {
                return String(option.dataset.name || option.text || '')
                    .trim()
                    .toLowerCase() === term.toLowerCase();
            });

            if (duplicate) {
                return null;
            }

            return {
                id: '__new__:' + term,
                text: term,
                newHospital: true
            };
        },
        insertTag: (data, tag) => data.unshift(tag),
        templateResult: option => {
            if (option.newHospital) {
                return $(`
                    <div>
                        <div class="hospital-option-name">
                            + Create "${option.text}"
                        </div>
                        <div class="hospital-option-meta">
                            Open quick hospital form
                        </div>
                    </div>
                `);
            }

            return hospitalTemplate(option);
        },
        templateSelection: option => {
            if (!option.id) {
                return option.text;
            }

            if (option.newHospital || String(option.id).startsWith('__new__:')) {
                return option.text;
            }

            const element = option.element;
            const code = element?.dataset.code || '';
            const name = element?.dataset.name || option.text;

            return code ? `${code} - ${name}` : name;
        }
    });

    $('#hospitalId').on('select2:select', event => {
        const selected = event.params.data;

        if (
            !selected.newHospital
            && !String(selected.id || '').startsWith('__new__:')
        ) {
            updateHospitalSummary(true);
            return;
        }

        pendingHospitalTag = String(selected.id || '');
        const hospitalName = String(selected.text || '').trim();

        $('#hospitalId').val(null).trigger('change.select2');

        document.getElementById('quickHospitalForm').reset();
        document.getElementById('quickHospitalName').value = hospitalName;
        document.getElementById('quickHospitalCode').value = '';
        document.getElementById('quickHospitalCredit').value = '0';
        document.getElementById('quickHospitalBillingMode').value = 'credit';
        document.getElementById('quickHospitalStatus').value = 'active';

        bootstrap.Modal
            .getOrCreateInstance(document.getElementById('quickHospitalModal'))
            .show();
    });

    function setDueDateByCreditDays(days) {
        if (!invoiceDate.value) {
            return;
        }

        const date = new Date(`${invoiceDate.value}T00:00:00`);
        date.setDate(date.getDate() + Number(days || 0));

        dueDate.value = date.toISOString().slice(0, 10);
    }

    function updateHospitalSummary(updateDueDate = true) {
        const option = hospitalSelect.options[
            hospitalSelect.selectedIndex
        ];

        const summary = document.getElementById('hospitalSummary');

        if (!option || !option.value) {
            summary.classList.remove('show');

            previousInvoicesLink.classList.add('disabled');
            previousInvoicesLink.setAttribute('aria-disabled', 'true');
            previousInvoicesLink.href = '#';

            return;
        }

        document.getElementById('summaryHospitalName').textContent =
            option.dataset.name || option.text;

        document.getElementById('summaryHospitalCode').textContent =
            option.dataset.code || '—';

        document.getElementById('summaryMobile').textContent =
            option.dataset.mobile || '—';

        document.getElementById('summaryEmail').textContent =
            option.dataset.email || '—';

        document.getElementById('summaryAddress').textContent =
            option.dataset.address || '—';

        const creditDays = Number(option.dataset.creditDays || 0);

        document.getElementById('summaryCredit').textContent =
            creditDays > 0
                ? `${creditDays} Days`
                : 'No credit period';

        document.getElementById('summaryBillingMode').textContent =
            option.dataset.billingMode || 'Hospital Credit';

        summary.classList.add('show');

        previousInvoicesLink.classList.remove('disabled');
        previousInvoicesLink.removeAttribute('aria-disabled');
        previousInvoicesLink.href =
            '<?= e(app_url('invoices.php?client_id=')) ?>' +
            encodeURIComponent(option.value);

        if (updateDueDate) {
            setDueDateByCreditDays(creditDays || 7);
        }

        reloadAllRates();
    }

    hospitalSelect.addEventListener('change', () => {
        updateHospitalSummary(true);
    });

    invoiceDate.addEventListener('change', () => {
        const option = hospitalSelect.options[
            hospitalSelect.selectedIndex
        ];

        if (option && option.value) {
            setDueDateByCreditDays(
                Number(option.dataset.creditDays || 7)
            );
        }

        reloadAllRates();
    });

    function initializeServiceSelect(row) {
        const select = row.querySelector('.item-service');

        $(select).select2({
            placeholder: 'Search service...',
            allowClear: true,
            width: '100%'
        });

        $(select).on('change', () => {
            loadHospitalRate(row);
        });
    }

    function addInvoiceItem(data = {}) {
        const row = itemTemplate.content
            .firstElementChild
            .cloneNode(true);

        itemsContainer.appendChild(row);
        initializeServiceSelect(row);

        row.querySelector('.item-service').value =
            data.service_id || '';

        $(row.querySelector('.item-service')).trigger('change.select2');

        row.querySelector('.item-quantity').value =
            data.quantity ?? 1;

        row.querySelector('.item-standard-rate').value =
            data.standard_rate ?? 0;

        row.querySelector('.item-applied-rate').value =
            data.applied_rate ?? 0;

        row.querySelector('.item-discount-type').value =
            data.discount_type || 'none';

        row.querySelector('.item-discount-value').value =
            data.discount_value ?? 0;

        row.querySelector('.item-tax').value =
            data.tax_percent ?? 0;

        row
            .querySelectorAll(
                '.item-quantity,' +
                '.item-applied-rate,' +
                '.item-discount-type,' +
                '.item-discount-value'
            )
            .forEach(input => {
                input.addEventListener('input', calculateInvoice);
                input.addEventListener('change', calculateInvoice);
            });

        row
            .querySelector('.remove-item')
            .addEventListener('click', () => {
                $(row.querySelector('.item-service'))
                    .select2('destroy');

                row.remove();
                updateEmptyState();
                calculateInvoice();
            });

        updateEmptyState();
        calculateInvoice();

        if (data.service_id) {
            loadHospitalRate(row, true);
        }

        if (window.lucide) {
            lucide.createIcons();
        }
    }

    async function loadHospitalRate(row, preserveExisting = false) {
        const hospitalId = hospitalSelect.value;
        const serviceSelect = row.querySelector('.item-service');
        const serviceId = serviceSelect.value;

        const selectedOption = serviceSelect.options[
            serviceSelect.selectedIndex
        ];

        const standardRate = Number(
            selectedOption?.dataset.standardRate || 0
        );

        const standardInput = row.querySelector(
            '.item-standard-rate'
        );

        const appliedInput = row.querySelector(
            '.item-applied-rate'
        );

        const taxInput = row.querySelector('.item-tax');
        const caption = row.querySelector('.rate-caption');

        standardInput.value = standardRate.toFixed(2);

        if (!serviceId) {
            appliedInput.value = '0.00';
            caption.textContent =
                'Select a service to load the hospital rate.';

            calculateInvoice();
            return;
        }

        if (!hospitalId) {
            appliedInput.value = standardRate.toFixed(2);
            taxInput.value = selectedOption?.dataset.tax || 0;

            caption.innerHTML =
                'Standard rate applied. ' +
                '<span>Select a hospital to load its agreed rate.</span>';

            calculateInvoice();
            return;
        }

        caption.textContent = 'Loading hospital rate...';

        try {
            const url =
                '<?= e(app_url('api/service-rate.php')) ?>' +
                '?client_id=' +
                encodeURIComponent(hospitalId) +
                '&service_id=' +
                encodeURIComponent(serviceId) +
                '&date=' +
                encodeURIComponent(invoiceDate.value);

            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message);
            }

            const appliedRate = Number(
                result.data.applied_rate || standardRate
            );

            if (!preserveExisting || Number(appliedInput.value) <= 0) {
                appliedInput.value = appliedRate.toFixed(2);
            }

            taxInput.value = Number(
                result.data.tax_percent || 0
            );

            const isHospitalRate =
                Math.abs(appliedRate - standardRate) > 0.0001;

            caption.innerHTML = isHospitalRate
                ? `Standard ₹${standardRate.toFixed(2)} · ` +
                  `<span class="hospital-rate">Hospital rate ` +
                  `₹${appliedRate.toFixed(2)}</span>`
                : `Standard rate ₹${standardRate.toFixed(2)} applied`;

            calculateInvoice();
        } catch (error) {
            if (!preserveExisting || Number(appliedInput.value) <= 0) {
                appliedInput.value = standardRate.toFixed(2);
            }

            taxInput.value = selectedOption?.dataset.tax || 0;

            caption.textContent =
                'Hospital rate unavailable. Standard rate applied.';

            calculateInvoice();
        }
    }

    function reloadAllRates() {
        itemsContainer
            .querySelectorAll('.invoice-item')
            .forEach(row => {
                if (row.querySelector('.item-service').value) {
                    loadHospitalRate(row, true);
                }
            });
    }

    function updateEmptyState() {
        emptyItems.hidden =
            itemsContainer.querySelectorAll('.invoice-item').length > 0;
    }

    function collectItems() {
        return Array.from(
            itemsContainer.querySelectorAll('.invoice-item')
        )
            .map(row => {
                return {
                    service_id: Number(
                        row.querySelector('.item-service').value
                    ),
                    quantity: Number(
                        row.querySelector('.item-quantity').value || 0
                    ),
                    standard_rate: Number(
                        row.querySelector('.item-standard-rate').value || 0
                    ),
                    applied_rate: Number(
                        row.querySelector('.item-applied-rate').value || 0
                    ),
                    discount_type:
                        row.querySelector('.item-discount-type').value,
                    discount_value: Number(
                        row.querySelector('.item-discount-value').value || 0
                    ),
                    tax_percent: Number(
                        row.querySelector('.item-tax').value || 0
                    )
                };
            })
            .filter(item => item.service_id > 0);
    }

    function calculateInvoice() {
        let subtotal = 0;
        let discountTotal = 0;
        let taxTotal = 0;

        itemsContainer
            .querySelectorAll('.invoice-item')
            .forEach(row => {
                const quantity = Number(
                    row.querySelector('.item-quantity').value || 0
                );

                const rate = Number(
                    row.querySelector('.item-applied-rate').value || 0
                );

                const discountType =
                    row.querySelector('.item-discount-type').value;

                const discountValue = Number(
                    row.querySelector('.item-discount-value').value || 0
                );

                const taxPercent = Number(
                    row.querySelector('.item-tax').value || 0
                );

                const gross = quantity * rate;

                const discount =
                    discountType === 'percentage'
                        ? gross * discountValue / 100
                        : discountType === 'amount'
                            ? Math.min(gross, discountValue)
                            : 0;

                const taxable = Math.max(0, gross - discount);
                const tax = taxable * taxPercent / 100;
                const lineTotal = taxable + tax;

                subtotal += gross;
                discountTotal += discount;
                taxTotal += tax;

                row.querySelector('.item-line-total').textContent =
                    '₹' + lineTotal.toFixed(2);
            });

        const beforeRound = subtotal - discountTotal + taxTotal;
        const rounded = Math.round(beforeRound);
        const roundOff = rounded - beforeRound;

        document.getElementById('subtotalText').textContent =
            '₹' + subtotal.toFixed(2);

        document.getElementById('discountText').textContent =
            '₹' + discountTotal.toFixed(2);

        document.getElementById('taxText').textContent =
            '₹' + taxTotal.toFixed(2);

        document.getElementById('roundOffText').textContent =
            '₹' + roundOff.toFixed(2);

        document.getElementById('grandTotalText').textContent =
            '₹' + rounded.toFixed(2);
    }

    async function saveInvoice(mode, button) {
        const hospitalId = hospitalSelect.value;
        const items = collectItems();

        if (!hospitalId) {
            AppToast.show(
                'warning',
                'Select a registered hospital.'
            );

            $('#hospitalId').select2('open');
            return;
        }

        if (!invoiceDate.value) {
            AppToast.show(
                'warning',
                'Select the invoice date.'
            );

            invoiceDate.focus();
            return;
        }

        if (items.length === 0) {
            AppToast.show(
                'warning',
                'Add at least one service.'
            );

            return;
        }

        const invalidItem = items.find(item =>
            item.quantity <= 0 || item.applied_rate < 0
        );

        if (invalidItem) {
            AppToast.show(
                'warning',
                'Check service quantity and rate.'
            );

            return;
        }

        document.getElementById('invoiceStatus').value = mode;
        document.getElementById('itemsJson').value =
            JSON.stringify(items);

        const originalButtonHtml = button.innerHTML;

        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Saving...';

        try {
            const response = await fetch(
                '<?= e(app_url('api/invoice-save.php')) ?>',
                {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            const raw = await response.text();

            let result;

            try {
                result = JSON.parse(raw);
            } catch (error) {
                throw new Error(
                    raw.replace(/<[^>]*>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim()
                    || 'Invalid server response.'
                );
            }

            AppToast.show(
                result.success ? 'success' : 'error',
                result.message
            );

            if (result.success) {
                window.setTimeout(() => {
                    window.location.href =
                        '<?= e(app_url('invoice-view.php?id=')) ?>' +
                        result.data.invoice_id;
                }, 450);
            }
        } catch (error) {
            AppToast.show(
                'error',
                error.message || 'Unable to save invoice.'
            );
        } finally {
            button.disabled = false;
            button.innerHTML = originalButtonHtml;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    }

    document
        .getElementById('addServiceButton')
        .addEventListener('click', () => {
            addInvoiceItem();
        });

    document
        .querySelectorAll('[data-save-mode]')
        .forEach(button => {
            button.addEventListener('click', () => {
                saveInvoice(
                    button.dataset.saveMode,
                    button
                );
            });
        });

    if (existingItems.length > 0) {
        existingItems.forEach(item => {
            addInvoiceItem(item);
        });
    } else {
        addInvoiceItem();
    }

    updateHospitalSummary(false);
    updateEmptyState();
    calculateInvoice();

    const quickHospitalForm = document.getElementById('quickHospitalForm');

    quickHospitalForm.addEventListener('submit', async event => {
        event.preventDefault();

        const button = document.getElementById('quickHospitalSaveButton');
        const originalHtml = button.innerHTML;

        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const response = await fetch(
                '<?= e(app_url('api/hospital.php')) ?>',
                {
                    method: 'POST',
                    body: (() => {
                        const formData = new FormData(quickHospitalForm);
                        formData.set('action', 'save');
                        return formData;
                    })(),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            const raw = await response.text();
            let result;

            try {
                result = JSON.parse(raw);
            } catch (error) {
                throw new Error(
                    raw.replace(/<[^>]*>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim()
                    || 'Invalid server response.'
                );
            }

            if (!result.success) {
                throw new Error(result.message);
            }

            const hospital = result.data;

            const option = new Option(
                `${hospital.client_code} - ${hospital.client_name}`,
                String(hospital.id),
                true,
                true
            );

            option.dataset.code = hospital.client_code || '';
            option.dataset.name = hospital.client_name || '';
            option.dataset.mobile = hospital.mobile || '';
            option.dataset.email = hospital.email || '';
            option.dataset.address = hospital.address || '';
            option.dataset.creditDays = String(hospital.credit_days || 0);
            option.dataset.billingMode = hospital.billing_mode || 'Hospital Credit';

            hospitalSelect.add(option);

            $('#hospitalId')
                .val(String(hospital.id))
                .trigger('change.select2');

            updateHospitalSummary(true);

            bootstrap.Modal
                .getOrCreateInstance(document.getElementById('quickHospitalModal'))
                .hide();

            AppToast.show(
                'success',
                result.message || 'Hospital created successfully.'
            );
        } catch (error) {
            AppToast.show(
                'error',
                error.message || 'Unable to save hospital.'
            );
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    });

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
