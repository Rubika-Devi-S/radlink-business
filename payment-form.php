<?php
declare(strict_types=1);

$pageTitle = 'Record Payment';
require_once __DIR__ . '/includes/bootstrap.php';

$paymentId = max(0, (int)($_GET['id'] ?? 0));
$isExistingPayment = $paymentId > 0;

$clientsStmt = $pdo->prepare(
    "SELECT id, client_code, client_name, mobile
     FROM clients
     WHERE business_id = ?
       AND status = 'active'
     ORDER BY client_name"
);
$clientsStmt->execute([$currentBusinessId]);
$clients = $clientsStmt->fetchAll();

$modesStmt = $pdo->prepare(
    "SELECT
        pm.id,
        pm.mode_key,
        pm.mode_name,
        pm.requires_reference
     FROM payment_modes pm
     INNER JOIN (
        SELECT
            mode_key,
            COALESCE(
                MAX(CASE WHEN business_id = ? THEN id END),
                MAX(CASE WHEN business_id IS NULL THEN id END)
            ) AS selected_id
        FROM payment_modes
        WHERE status = 'active'
          AND (business_id IS NULL OR business_id = ?)
        GROUP BY mode_key
     ) selected_mode
        ON selected_mode.selected_id = pm.id
     ORDER BY pm.sort_order, pm.mode_name"
);
$modesStmt->execute([
    $currentBusinessId,
    $currentBusinessId,
]);
$paymentModes = $modesStmt->fetchAll();

$financialYearsStmt = $pdo->prepare(
    "SELECT id, year_label, start_date, end_date, is_current
     FROM financial_years
     WHERE business_id = ?
       AND status = 'open'
     ORDER BY is_current DESC, start_date DESC"
);
$financialYearsStmt->execute([$currentBusinessId]);
$financialYears = $financialYearsStmt->fetchAll();

$selectedFinancialYearId = 0;
$today = date('Y-m-d');
foreach ($financialYears as $fy) {
    if ($today >= $fy['start_date'] && $today <= $fy['end_date']) {
        $selectedFinancialYearId = (int)$fy['id'];
        break;
    }
}

include __DIR__ . '/includes/layout-start.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<style>
.payment-page {
    --pay-radius: 18px
}

.payment-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 18px
}

.payment-head h1 {
    margin: 8px 0 4px;
    font-weight: 850
}

.payment-card {
    background: var(--card-bg);
    border: 1px solid var(--border-soft);
    border-radius: var(--pay-radius);
    box-shadow: var(--shadow)
}

.payment-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-soft)
}

.payment-card-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 850
}

.payment-card-body {
    padding: 20px
}

.client-summary {
    display: none;
    margin-top: 15px;
    padding: 15px;
    border-left: 4px solid var(--brand);
    border-radius: 14px;
    background: var(--body-bg)
}

.client-summary.show {
    display: block
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px
}

.stat-box {
    padding: 12px;
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--card-bg)
}

.stat-box small {
    display: block;
    color: var(--text-muted);
    font-weight: 700
}

.stat-box strong {
    display: block;
    margin-top: 4px;
    font-size: 17px
}

.allocation-row {
    display: grid;
    grid-template-columns: 36px minmax(160px, 1.6fr) 120px 120px 120px 150px;
    gap: 12px;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-soft)
}

.allocation-row:last-child {
    border-bottom: 0
}

.invoice-meta small {
    display: block;
    color: var(--text-muted)
}

.amount-input {
    text-align: right;
    font-weight: 750
}

.payment-summary {
    max-width: 430px;
    margin-left: auto
}

.payment-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-soft)
}

.payment-summary-row.total {
    font-size: 18px;
    font-weight: 900;
    border-bottom: 0
}

.payment-actions {
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
    box-shadow: var(--shadow)
}

.select2-container {
    width: 100% !important
}

.select2-container .select2-selection--single {
    height: 46px;
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--card-bg)
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 44px;
    padding-left: 13px;
    color: var(--text-main)
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 44px
}

.empty-box {
    text-align: center;
    padding: 28px;
    border: 1px dashed var(--border-soft);
    border-radius: 14px;
    color: var(--text-muted)
}

@media(max-width:991.98px) {
    .allocation-row {
        grid-template-columns: 36px 1fr 1fr
    }

    .allocation-row .invoice-meta {
        grid-column: 2/-1
    }

    .stat-grid {
        grid-template-columns: 1fr 1fr
    }
}

@media(max-width:767.98px) {
    .payment-head {
        flex-direction: column
    }

    .payment-head .btn {
        width: 100%
    }

    .payment-card-body,
    .payment-card-head {
        padding: 15px
    }

    .stat-grid {
        grid-template-columns: 1fr
    }

    .allocation-row {
        grid-template-columns: 32px 1fr
    }

    .allocation-row>* {
        grid-column: 2
    }

    .allocation-row>.allocation-check {
        grid-column: 1;
        grid-row: 1
    }

    .payment-actions {
        flex-direction: column-reverse
    }

    .payment-actions .btn {
        width: 100%
    }
}
</style>

<div class="payment-page">
    <div class="payment-head">
        <div>
            <span class="badge-soft">PAYMENTS</span>
            <h1><?= $isExistingPayment ? 'Allocate Existing Payment' : 'Record Payment' ?></h1>
            <p class="mb-0 text-muted">
                <?= $isExistingPayment ? 'Use the unallocated amount from an existing receipt against pending invoices.' : 'Record a hospital payment and allocate it against unpaid invoices.' ?>
            </p>
        </div>
        <a class="btn btn-light" href="<?= e(app_url('payments.php')) ?>">
            <i data-lucide="arrow-left"></i> Back to Payment List
        </a>
    </div>

    <form id="paymentForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" id="paymentAction"
            value="<?= $isExistingPayment ? 'allocate_existing_payment' : 'save_payment' ?>">
        <input type="hidden" name="payment_id" id="paymentId" value="<?= $paymentId ?>">
        <input type="hidden" name="allocations_json" id="allocationsJson" value="[]">

        <?php if ($isExistingPayment): ?>
        <section class="payment-card mb-3" id="existingPaymentSummaryCard">
            <div class="payment-card-body">
                <div class="stat-grid">
                    <div class="stat-box"><small>Receipt</small><strong id="existingReceipt">—</strong></div>
                    <div class="stat-box"><small>Total Payment</small><strong id="existingTotalAmount">₹0.00</strong>
                    </div>
                    <div class="stat-box"><small>Available to Allocate</small><strong
                            id="existingAvailableAmount">₹0.00</strong></div>
                </div>
                <div class="mt-3" id="existingAllocationHistory"></div>
            </div>
        </section>
        <?php endif; ?>

        <section class="payment-card mb-3">
            <div class="payment-card-head">
                <div>
                    <h2>Payment Information</h2>
                    <small class="text-muted">Choose the hospital and enter receipt details.</small>
                </div>
            </div>
            <div class="payment-card-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <label class="form-label fw-semibold" for="clientId">Hospital <span
                                class="text-danger">*</span></label>
                        <select class="form-select" name="client_id" id="clientId" required>
                            <option value="">Search hospital...</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?= (int)$client['id'] ?>" data-code="<?= e($client['client_code'] ?? '') ?>"
                                data-mobile="<?= e($client['mobile'] ?? '') ?>">
                                <?= e(($client['client_code'] ?? '') . ' - ' . $client['client_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-2">
                        <label class="form-label fw-semibold" for="paymentDate">Payment Date <span
                                class="text-danger">*</span></label>
                        <input class="form-control" type="date" name="payment_date" id="paymentDate"
                            value="<?= e($today) ?>" required>
                    </div>

                    <div class="col-md-4 col-lg-2">
                        <label class="form-label fw-semibold" for="amount"><span id="amountFieldLabel">Amount1</span>
                            <span class="text-danger">*</span></label>
                        <input class="form-control fw-bold text-end" type="number" name="amount" id="amount" min="0.01"
                            step="0.01" placeholder="0.00" required>
                    </div>

                    <div class="col-md-4 col-lg-3">
                        <label class="form-label fw-semibold" for="paymentModeId">Payment Mode <span
                                class="text-danger">*</span></label>
                        <select class="form-select" name="payment_mode_id" id="paymentModeId" required>
                            <option value="">Select mode...</option>
                            <?php foreach ($paymentModes as $mode): ?>
                            <option value="<?= (int)$mode['id'] ?>"
                                data-requires-reference="<?= (int)$mode['requires_reference'] ?>">
                                <?= e($mode['mode_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="transactionReference">Transaction / Cheque
                            Reference</label>
                        <input class="form-control" name="transaction_reference" id="transactionReference"
                            maxlength="150" placeholder="UPI ID, UTR, cheque no., etc.">
                        <div class="form-text" id="referenceHelp">Required for UPI, card, transfer and cheque.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="payerName">Payer Name</label>
                        <input class="form-control" name="payer_name" id="payerName" maxlength="200"
                            placeholder="Hospital or person name">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="financialYearId">Financial Year</label>
                        <select class="form-select" name="financial_year_id" id="financialYearId">
                            <option value="0">Auto by payment date</option>
                            <?php foreach ($financialYears as $fy): ?>
                            <option value="<?= (int)$fy['id'] ?>"
                                <?= $selectedFinancialYearId === (int)$fy['id'] ? 'selected' : '' ?>>
                                <?= e($fy['year_label']) ?><?= $fy['is_current'] ? ' (Current)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="allocationMethod">Allocation Method</label>
                        <select class="form-select" name="allocation_method" id="allocationMethod">
                            <option value="manual">Manual Allocation</option>
                            <option value="fifo">FIFO — Oldest Invoice First</option>
                            <option value="unallocated">Keep Unallocated</option>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-semibold" for="notes">Notes</label>
                        <input class="form-control" name="notes" id="notes" maxlength="1000"
                            placeholder="Optional payment note">
                    </div>
                </div>

                <div class="client-summary" id="clientSummary">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <strong id="summaryClientName">—</strong>
                            <div class="small text-muted" id="summaryClientCode">—</div>
                        </div>
                        <span class="status-pill">Hospital Account</span>
                    </div>
                    <div class="stat-grid">
                        <div class="stat-box"><small>Total Outstanding</small><strong
                                id="summaryOutstanding">₹0.00</strong></div>
                        <div class="stat-box"><small>Open Invoices</small><strong id="summaryInvoiceCount">0</strong>
                        </div>
                        <div class="stat-box"><small>Mobile</small><strong id="summaryMobile">—</strong></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="payment-card mb-3" id="allocationCard">
            <div class="payment-card-head">
                <div>
                    <h2>Invoice Allocation</h2>
                    <small class="text-muted">Select invoices and enter the amount to allocate.</small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="autoAllocateButton">
                    <i data-lucide="wand-2"></i> Auto Allocate
                </button>
            </div>
            <div class="payment-card-body">
                <div id="allocationList">
                    <div class="empty-box">Select a hospital to load its unpaid invoices.</div>
                </div>
            </div>
        </section>

        <section class="payment-card">
            <div class="payment-card-body">
                <div class="payment-summary">
                    <div class="payment-summary-row"><span>Invoice Balance</span><strong
                            id="invoiceBalanceText">₹0.00</strong></div>
                    <div class="payment-summary-row"><span>Payment Received</span><strong
                            id="paymentAmountText">₹0.00</strong></div>
                    <div class="payment-summary-row"><span>Paid to Invoice</span><strong
                            id="allocatedAmountText">₹0.00</strong></div>
                    <div class="payment-summary-row total"><span>Pending Balance</span><strong
                            id="pendingAmountText">₹0.00</strong></div>
                    <div class="payment-summary-row"><span>Advance / Unused Amount</span><strong
                            id="unallocatedAmountText">₹0.00</strong></div>
                </div>
            </div>
        </section>

        <div class="payment-actions">
            <a class="btn btn-outline-secondary" href="<?= e(app_url('payments.php')) ?>">Cancel</a>
            <button class="btn btn-brand" type="submit" id="savePaymentButton">
                <i data-lucide="badge-check"></i> <?= $isExistingPayment ? 'Allocate Payment' : 'Save Payment' ?>
            </button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const clientSelect = document.getElementById('clientId');
    const amountInput = document.getElementById('amount');
    const modeSelect = document.getElementById('paymentModeId');
    const referenceInput = document.getElementById('transactionReference');
    const allocationMethod = document.getElementById('allocationMethod');
    const allocationList = document.getElementById('allocationList');
    const allocationCard = document.getElementById('allocationCard');
    const form = document.getElementById('paymentForm');
    const existingPaymentId = Number(document.getElementById('paymentId')?.value || 0);
    const isExistingPayment = existingPaymentId > 0;
    let existingAvailableAmount = 0;

    $('#clientId').select2({
        placeholder: 'Search hospital...',
        allowClear: true,
        width: '100%'
    });

    const money = value => '₹' + Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function updateReferenceRequirement() {
        const option = modeSelect.options[modeSelect.selectedIndex];
        const required = option && option.dataset.requiresReference === '1';
        referenceInput.required = required;
        document.getElementById('referenceHelp').textContent = required ?
            'Reference is required for the selected payment mode.' :
            'Optional for the selected payment mode.';
    }

    async function loadInvoices() {
        const clientId = clientSelect.value;
        const summary = document.getElementById('clientSummary');

        if (!clientId) {
            summary.classList.remove('show');
            allocationList.innerHTML =
                '<div class="empty-box">Select a hospital to load its unpaid invoices.</div>';
            calculateAllocation();
            return;
        }

        allocationList.innerHTML =
            '<div class="empty-box"><span class="spinner-border spinner-border-sm me-2"></span>Loading invoices...</div>';

        try {
            const openInvoicesUrl = new URL(
                <?= json_encode(app_url('api/payment.php'), JSON_UNESCAPED_SLASHES) ?>,
                window.location.origin
            );
            openInvoicesUrl.searchParams.set('action', 'open_invoices');
            openInvoicesUrl.searchParams.set('client_id', clientId);

            const response = await fetch(
                openInvoicesUrl.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Unable to load invoices.');

            const option = clientSelect.options[clientSelect.selectedIndex];
            document.getElementById('summaryClientName').textContent = option.text.replace(/^.*?\s-\s/, '');
            document.getElementById('summaryClientCode').textContent = option.dataset.code || '—';
            document.getElementById('summaryMobile').textContent = option.dataset.mobile || '—';
            document.getElementById('summaryOutstanding').textContent = money(result.data
            .total_outstanding);
            document.getElementById('summaryInvoiceCount').textContent = String(result.data.invoices
            .length);
            summary.classList.add('show');

            if (!result.data.invoices.length) {
                allocationList.innerHTML =
                    '<div class="empty-box">No unpaid issued invoices found for this hospital.</div>';
                calculateAllocation();
                return;
            }

            allocationList.innerHTML = result.data.invoices.map(invoice => `
                <div class="allocation-row" data-invoice-id="${invoice.id}" data-balance="${invoice.balance_amount}">
                    <div class="allocation-check"><input class="form-check-input invoice-check" type="checkbox"></div>
                    <div class="invoice-meta">
                        <strong>${escapeHtml(invoice.invoice_number)}</strong>
                        <small>${escapeHtml(invoice.invoice_date_display)} · Due ${escapeHtml(invoice.due_date_display)}</small>
                    </div>
                    <div><small class="text-muted d-block">Invoice Total</small><strong>${money(invoice.grand_total)}</strong></div>
                    <div><small class="text-muted d-block">Balance</small><strong>${money(invoice.balance_amount)}</strong></div>
                    <div><small class="text-muted d-block">Pending</small><strong class="invoice-pending">${money(invoice.balance_amount)}</strong></div>
                    <div>
                        <label class="small text-muted">Pay Now</label>
                        <input class="form-control amount-input allocation-amount" type="number" min="0" max="${invoice.balance_amount}" step="0.01" value="0" disabled>
                    </div>
                </div>
            `).join('');

            allocationList.querySelectorAll('.invoice-check').forEach(check => {
                check.addEventListener('change', event => {
                    const row = event.target.closest('.allocation-row');
                    const input = row.querySelector('.allocation-amount');
                    input.disabled = !event.target.checked;
                    if (!event.target.checked) input.value = '0';
                    calculateAllocation();
                });
            });
            allocationList.querySelectorAll('.allocation-amount').forEach(input => {
                input.addEventListener('input', calculateAllocation);
                input.addEventListener('change', calculateAllocation);
            });

            if (allocationMethod.value === 'fifo') autoAllocate();
        } catch (error) {
            allocationList.innerHTML =
                `<div class="empty-box text-danger">${escapeHtml(error.message)}</div>`;
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function autoAllocate() {
        let remaining = isExistingPayment ? existingAvailableAmount : Math.max(0, Number(amountInput.value ||
            0));
        allocationList.querySelectorAll('.allocation-row').forEach(row => {
            const check = row.querySelector('.invoice-check');
            const input = row.querySelector('.allocation-amount');
            const balance = Number(row.dataset.balance || 0);
            const allocation = Math.min(balance, remaining);
            check.checked = allocation > 0;
            input.disabled = allocation <= 0;
            input.value = allocation.toFixed(2);
            remaining -= allocation;
        });
        calculateAllocation();
    }

    function collectAllocations() {
        return Array.from(allocationList.querySelectorAll('.allocation-row'))
            .map(row => ({
                invoice_id: Number(row.dataset.invoiceId),
                amount: Number(row.querySelector('.allocation-amount').value || 0)
            }))
            .filter(item => item.invoice_id > 0 && item.amount > 0);
    }

    function calculateAllocation() {
        const paymentAmount = isExistingPayment ? existingAvailableAmount : Math.max(0, Number(amountInput
            .value || 0));
        const allocations = collectAllocations();
        const allocated = allocations.reduce((sum, item) => sum + item.amount, 0);

        let invoiceBalance = 0;

        allocationList.querySelectorAll('.allocation-row').forEach(row => {
            const check = row.querySelector('.invoice-check');
            const input = row.querySelector('.allocation-amount');
            const balance = Number(row.dataset.balance || 0);

            if (check && check.checked) {
                invoiceBalance += balance;

                const pending = Math.max(
                    0,
                    balance - Number(input.value || 0)
                );

                const pendingEl = row.querySelector('.invoice-pending');
                if (pendingEl) {
                    pendingEl.textContent = money(pending);
                }
            }
        });

        const pending = Math.max(0, invoiceBalance - allocated);
        const unallocated = Math.max(0, paymentAmount - allocated);

        document.getElementById('invoiceBalanceText').textContent = money(invoiceBalance);
        document.getElementById('paymentAmountText').textContent = money(paymentAmount);
        document.getElementById('allocatedAmountText').textContent = money(allocated);
        document.getElementById('pendingAmountText').textContent = money(pending);
        document.getElementById('unallocatedAmountText').textContent = money(unallocated);

        document.getElementById('allocationsJson').value = JSON.stringify(allocations);
    }

    /*
     * Select2 dispatches its selection changes through jQuery.
     * Bind through Select2/jQuery so the invoice list loads immediately
     * after choosing a hospital.
     */
    $('#clientId')
        .off('change.paymentInvoices')
        .on('change.paymentInvoices', () => {
            loadInvoices();
        });

    amountInput.addEventListener('input', () => {
        if (allocationMethod.value === 'fifo') autoAllocate();
        else calculateAllocation();
    });
    modeSelect.addEventListener('change', updateReferenceRequirement);
    allocationMethod.addEventListener('change', () => {
        allocationCard.style.display = allocationMethod.value === 'unallocated' ? 'none' : '';
        if (allocationMethod.value === 'unallocated') {
            allocationList.querySelectorAll('.invoice-check').forEach(check => {
                check.checked = false;
            });
            allocationList.querySelectorAll('.allocation-amount').forEach(input => {
                input.value = '0';
                input.disabled = true;
            });
            calculateAllocation();
        } else if (allocationMethod.value === 'fifo') {
            autoAllocate();
        }
    });
    document.getElementById('autoAllocateButton').addEventListener('click', autoAllocate);

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const paymentAmount = Number(amountInput.value || 0);

        alert('Payment Amount: ' + paymentAmount); // Debugging line to check the payment amount
        const allocations = collectAllocations();
        const allocated = allocations.reduce((sum, item) => sum + item.amount, 0);

        if (!clientSelect.value) return AppToast.show('warning', 'Select a hospital.');
        if (paymentAmount <= 0) return AppToast.show('warning', isExistingPayment ?
            'This receipt has no unallocated amount.' : 'Enter a valid payment amount.');
        if (!modeSelect.value) return AppToast.show('warning', 'Select a payment mode.');
        if (referenceInput.required && !referenceInput.value.trim()) return AppToast.show('warning',
            'Enter the transaction reference.');
        if (allocated > paymentAmount + 0.001) return AppToast.show('warning',
            'Paid amount cannot exceed payment received.');

        document.getElementById('allocationsJson').value = JSON.stringify(allocations);
        const button = document.getElementById('savePaymentButton');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const response = await fetch('<?= e(app_url('api/payment.php')) ?>', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const raw = await response.text();
            let result;
            try {
                result = JSON.parse(raw);
            } catch {
                throw new Error(raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() ||
                    'Invalid server response.');
            }
            AppToast.show(result.success ? 'success' : 'error', result.message);
            if (result.success) {
                setTimeout(() => window.location.href =
                    '<?= e(app_url('payments.php?updated=')) ?>' + result.data.payment_id, 450);
            }
        } catch (error) {
            AppToast.show('error', error.message || 'Unable to save payment.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
            if (window.lucide) lucide.createIcons();
        }
    });

    async function loadExistingPayment() {
        if (!isExistingPayment) return;

        try {
            const editUrl = new URL(
                <?= json_encode(app_url('api/payment.php'), JSON_UNESCAPED_SLASHES) ?>,
                window.location.origin
            );
            editUrl.searchParams.set('action', 'edit_data');
            editUrl.searchParams.set('id', String(existingPaymentId));

            const response = await fetch(editUrl.toString(), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Unable to load payment.');

            const p = result.data.payment;
            const existingAllocations = result.data.existing_allocations || [];

            existingAvailableAmount = Number(p.unallocated_amount || 0);

            clientSelect.value = String(p.client_id);
            $('#clientId').val(String(p.client_id)).trigger('change.select2');

            document.getElementById('paymentDate').value = p.payment_date || '';
            amountInput.value = Number(existingAvailableAmount || 0).toFixed(2);
            modeSelect.value = String(p.payment_mode_id || '');
            referenceInput.value = p.transaction_reference || '';
            document.getElementById('payerName').value = p.payer_name || '';
            document.getElementById('financialYearId').value = String(p.financial_year_id || 0);
            allocationMethod.value = p.allocation_method === 'unallocated' ? 'manual' : (p
                .allocation_method || 'manual');
            document.getElementById('notes').value = p.notes || '';

            clientSelect.disabled = true;
            amountInput.readOnly = true;
            document.getElementById('paymentDate').readOnly = true;
            modeSelect.disabled = true;
            referenceInput.readOnly = true;
            document.getElementById('payerName').readOnly = true;
            document.getElementById('financialYearId').disabled = true;

            document.getElementById('existingReceipt').textContent = p.receipt_number || '—';
            document.getElementById('existingTotalAmount').textContent = money(p.amount);
            document.getElementById('existingAvailableAmount').textContent = money(existingAvailableAmount);
            const amountLabel = document.getElementById('amountFieldLabel');
            if (amountLabel) amountLabel.textContent = 'Available Amount';

            const history = document.getElementById('existingAllocationHistory');
            if (history) {
                history.innerHTML = existingAllocations.length ?
                    `<h6 class="mb-2">Existing Allocations</h6>
                       <div class="table-responsive">
                           <table class="table align-middle mb-0">
                               <thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Allocated</th></tr></thead>
                               <tbody>${existingAllocations.map(a => `
                                   <tr>
                                       <td>${escapeHtml(a.invoice_number)}</td>
                                       <td>${escapeHtml(a.invoice_date_display)}</td>
                                       <td class="text-end">${money(a.allocated_amount)}</td>
                                   </tr>`).join('')}</tbody>
                           </table>
                       </div>` :
                    '<div class="text-muted">No invoice allocations have been made from this receipt yet.</div>';
            }

            updateReferenceRequirement();
            await loadInvoices();
            calculateAllocation();
        } catch (error) {
            AppToast.show('error', error.message || 'Unable to load existing payment.');
        }
    }

    updateReferenceRequirement();
    calculateAllocation();

    /*
     * Also load invoices when the page opens with a hospital already selected,
     * for example after browser autocomplete or returning to the form.
     */
    if (isExistingPayment) {
        loadExistingPayment();
    } else if (clientSelect.value) {
        loadInvoices();
    }

    if (window.lucide) lucide.createIcons();
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>