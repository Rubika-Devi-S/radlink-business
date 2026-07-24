<?php
declare(strict_types=1);

$pageTitle = 'Payment View';
require_once __DIR__ . '/includes/bootstrap.php';

$paymentId = max(0, (int)($_GET['id'] ?? 0));

$payment = null;
$allocations = [];
$hospitalPending = 0.0;

if ($paymentId > 0) {
    $paymentStmt = $pdo->prepare(
        "SELECT
            p.*,
            c.client_name,
            c.client_code,
            c.mobile AS client_mobile,
            c.email AS client_email,
            pm.mode_name,
            fy.year_label
         FROM payments p
         INNER JOIN clients c
            ON c.id = p.client_id
           AND c.business_id = p.business_id
         INNER JOIN payment_modes pm
            ON pm.id = p.payment_mode_id
         LEFT JOIN financial_years fy
            ON fy.id = p.financial_year_id
           AND fy.business_id = p.business_id
         WHERE p.id = ?
           AND p.business_id = ?
         LIMIT 1"
    );
    $paymentStmt->execute([$paymentId, $currentBusinessId]);
    $payment = $paymentStmt->fetch();

    if ($payment) {
        $allocationStmt = $pdo->prepare(
            "SELECT
                pa.id,
                pa.invoice_id,
                pa.allocated_amount,
                pa.allocated_at,
                i.invoice_number,
                i.invoice_date,
                i.due_date,
                i.grand_total,
                i.received_amount,
                i.balance_amount,
                i.payment_status AS invoice_payment_status
             FROM payment_allocations pa
             INNER JOIN invoices i
                ON i.id = pa.invoice_id
               AND i.business_id = pa.business_id
             WHERE pa.payment_id = ?
               AND pa.business_id = ?
             ORDER BY i.invoice_date, i.id"
        );
        $allocationStmt->execute([$paymentId, $currentBusinessId]);
        $allocations = $allocationStmt->fetchAll();

        $pendingStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(balance_amount), 0)
             FROM invoices
             WHERE business_id = ?
               AND client_id = ?
               AND invoice_status = 'issued'
               AND balance_amount > 0"
        );
        $pendingStmt->execute([$currentBusinessId, (int)$payment['client_id']]);
        $hospitalPending = (float)$pendingStmt->fetchColumn();
    }
}

$statusLabel = 'Unknown';
$statusClass = 'secondary';
if ($payment) {
    if (($payment['payment_status'] ?? '') === 'reversed') {
        $statusLabel = 'Refunded';
        $statusClass = 'danger';
    } elseif ((float)$payment['allocated_amount'] <= 0.009) {
        $statusLabel = 'Unallocated';
        $statusClass = 'warning';
    } elseif ($hospitalPending > 0.009) {
        $statusLabel = 'Partial Paid';
        $statusClass = 'warning';
    } else {
        $statusLabel = 'Paid';
        $statusClass = 'success';
    }
}

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.payment-view-page {
    max-width: 1180px;
    margin: 0 auto
}

.payment-view-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px
}

.payment-view-head h1 {
    margin: 8px 0 4px
}

.payment-view-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap
}

.payment-view-card {
    background: var(--card-bg);
    border: 1px solid var(--border-soft);
    border-radius: 18px;
    box-shadow: var(--shadow)
}

.payment-view-card+.payment-view-card {
    margin-top: 14px
}

.payment-view-card-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border-soft)
}

.payment-view-card-body {
    padding: 18px
}

.payment-view-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px
}

.payment-view-field {
    padding: 12px;
    border: 1px solid var(--border-soft);
    border-radius: 13px;
    background: var(--body-bg)
}

.payment-view-field small {
    display: block;
    color: var(--text-muted);
    font-size: 11px;
    margin-bottom: 4px
}

.payment-view-field div {
    font-weight: 400;
    overflow-wrap: anywhere
}

.payment-view-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px
}

.payment-view-summary .summary-box {
    padding: 14px;
    border: 1px solid var(--border-soft);
    border-radius: 14px;
    background: var(--card-bg)
}

.payment-view-summary small {
    display: block;
    color: var(--text-muted);
    font-size: 11px;
    margin-bottom: 5px
}

.payment-view-summary strong {
    font-size: 19px
}

.payment-view-table td,
.payment-view-table td * {
    font-weight: 400 !important
}

.payment-view-status {
    display: inline-flex;
    padding: 7px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700
}

.payment-view-status.success {
    background: #dcfce7;
    color: #15803d
}

.payment-view-status.warning {
    background: #fef3c7;
    color: #a16207
}

.payment-view-status.danger {
    background: #fee2e2;
    color: #b91c1c
}

.payment-view-status.secondary {
    background: #e5e7eb;
    color: #374151
}

@media(max-width:991.98px) {

    .payment-view-grid,
    .payment-view-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr))
    }
}

@media(max-width:767.98px) {
    .payment-view-head {
        flex-direction: column
    }

    .payment-view-actions {
        width: 100%
    }

    .payment-view-actions .btn {
        flex: 1
    }

    .payment-view-grid,
    .payment-view-summary {
        grid-template-columns: 1fr
    }

    .payment-view-card-body,
    .payment-view-card-head {
        padding: 14px
    }
}

@media print {

    .sidebar,
    .topbar,
    .payment-view-actions,
    .no-print {
        display: none !important
    }

    .main-content {
        margin: 0 !important;
        padding: 0 !important
    }

    .payment-view-card {
        box-shadow: none
    }
}
</style>

<div class="payment-view-page">
    <?php if (!$payment): ?>
    <section class="payment-view-card">
        <div class="payment-view-card-body text-center py-5">
            <i data-lucide="circle-alert"></i>
            <h2 class="h5 mt-3">Payment not found</h2>
            <p class="text-muted">The selected payment does not exist or does not belong to the active business.</p>
            <a class="btn btn-brand" href="<?= e(app_url('payments.php')) ?>">Back to Payment List</a>
        </div>
    </section>
    <?php else: ?>
    <div class="payment-view-head">
        <div>
            <span class="badge-soft">PAYMENT RECEIPT</span>
            <h1><?= e($payment['receipt_number']) ?></h1>
            <p class="mb-0 text-muted">Payment receipt and invoice allocation details.</p>
        </div>
        <div class="payment-view-actions no-print">
            <a class="btn btn-light" href="<?= e(app_url('payments.php')) ?>">
                <i data-lucide="arrow-left"></i> Back
            </a>
        </div>
    </div>

    <section class="payment-view-card">
        <div class="payment-view-card-head">
            <div>
                <h2 class="h6 mb-1">Receipt Information</h2>
                <small class="text-muted">Recorded payment information.</small>
            </div>
            <span class="payment-view-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
        </div>
        <div class="payment-view-card-body">
            <div class="payment-view-grid">
                <div class="payment-view-field"><small>Hospital</small>
                    <div><?= e($payment['client_name']) ?></div>
                </div>
                <div class="payment-view-field"><small>Hospital Code</small>
                    <div><?= e($payment['client_code'] ?: '—') ?></div>
                </div>
                <div class="payment-view-field"><small>Payment Date</small>
                    <div><?= e(date('d M Y', strtotime($payment['payment_date']))) ?></div>
                </div>
                <div class="payment-view-field"><small>Financial Year</small>
                    <div><?= e($payment['year_label'] ?: '—') ?></div>
                </div>
                <div class="payment-view-field"><small>Payment Mode</small>
                    <div><?= e($payment['mode_name']) ?></div>
                </div>
                <div class="payment-view-field"><small>Transaction Reference</small>
                    <div><?= e($payment['transaction_reference'] ?: '—') ?></div>
                </div>
                <div class="payment-view-field"><small>Payer Name</small>
                    <div><?= e($payment['payer_name'] ?: $payment['client_name']) ?></div>
                </div>
                <div class="payment-view-field"><small>Allocation Method</small>
                    <div><?= e(ucwords(str_replace('_', ' ', (string)$payment['allocation_method']))) ?></div>
                </div>
            </div>

            <div class="payment-view-summary mt-3">
                <div class="summary-box"><small>Payment
                        Amount</small><strong>₹<?= number_format((float)$payment['amount'], 2) ?></strong></div>
                <div class="summary-box"><small>Allocated
                        Amount</small><strong>₹<?= number_format((float)$payment['allocated_amount'], 2) ?></strong>
                </div>
                <div class="summary-box"><small>Unallocated /
                        Advance</small><strong>₹<?= number_format((float)$payment['unallocated_amount'], 2) ?></strong>
                </div>
                <div class="summary-box"><small>Hospital Pending
                        Balance</small><strong>₹<?= number_format($hospitalPending, 2) ?></strong></div>
            </div>

            <?php if (!empty($payment['notes'])): ?>
            <div class="payment-view-field mt-3"><small>Notes</small>
                <div><?= nl2br(e($payment['notes'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($payment['reversal_reason'])): ?>
            <div class="alert alert-danger mt-3 mb-0"><strong>Refund reason:</strong>
                <?= e($payment['reversal_reason']) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="payment-view-card">
        <div class="payment-view-card-head">
            <div>
                <h2 class="h6 mb-1">Invoice Allocations</h2>
                <small class="text-muted"><?= count($allocations) ?> invoice allocation(s).</small>
            </div>
        </div>
        <div class="payment-view-card-body">
            <div class="table-responsive">
                <table class="table payment-view-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Invoice Date</th>
                            <th>Due Date</th>
                            <th class="text-end">Invoice Total</th>
                            <th class="text-end">Allocated by Receipt</th>
                            <th class="text-end">Current Received</th>
                            <th class="text-end">Current Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$allocations): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">This payment has no invoice
                                allocation and is currently kept as unallocated/advance.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($allocations as $allocation): ?>
                        <tr>
                            <td><?= e($allocation['invoice_number']) ?></td>
                            <td><?= e(date('d M Y', strtotime($allocation['invoice_date']))) ?></td>
                            <td><?= $allocation['due_date'] ? e(date('d M Y', strtotime($allocation['due_date']))) : '—' ?>
                            </td>
                            <td class="text-end">₹<?= number_format((float)$allocation['grand_total'], 2) ?></td>
                            <td class="text-end">₹<?= number_format((float)$allocation['allocated_amount'], 2) ?>
                            </td>
                            <td class="text-end">₹<?= number_format((float)$allocation['received_amount'], 2) ?>
                            </td>
                            <td class="text-end">₹<?= number_format((float)$allocation['balance_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>