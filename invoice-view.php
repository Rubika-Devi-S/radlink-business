<?php
declare(strict_types=1);
$pageTitle='Invoice Details';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/invoice-functions.php';

$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT i.*,c.client_code,c.client_name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=? AND i.business_id=? LIMIT 1");$stmt->execute([$id,$currentBusinessId]);$invoice=$stmt->fetch();
if(!$invoice){http_response_code(404);exit('Invoice not found.');}
$stmt=$pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? AND business_id=? ORDER BY sort_order,id");$stmt->execute([$id,$currentBusinessId]);$items=$stmt->fetchAll();

include __DIR__.'/includes/layout-start.php';
?>
<style>
.invoice-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: space-between
}

.invoice-filter-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 10px
}

.invoice-item-row {
    display: grid;
    grid-template-columns: 2.2fr .7fr 1fr .8fr .8fr 1fr auto;
    gap: 8px;
    align-items: end;
    padding: 12px;
    border: 1px solid var(--border-soft);
    border-radius: 14px;
    margin-bottom: 10px;
    background: var(--card-bg)
}

.invoice-summary {
    max-width: 420px;
    margin-left: auto
}

.invoice-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-soft)
}

.invoice-summary-row.total {
    font-size: 18px;
    font-weight: 900;
    border-top: 2px solid var(--text-main)
}

.status-pill {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    background: var(--sidebar-active);
    color: var(--brand)
}

.mobile-invoice-card {
    padding: 14px
}

.mobile-invoice-card+.mobile-invoice-card {
    margin-top: 10px
}

.invoice-services-table {
    width: 100%;
    table-layout: fixed;
}

.invoice-services-table th,
.invoice-services-table td {
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
}

.invoice-services-table th:nth-child(2),
.invoice-services-table td:nth-child(2) {
    text-align: left;
    white-space: normal;
}

.invoice-services-table th:nth-child(1) { width: 6%; }
.invoice-services-table th:nth-child(2) { width: 36%; }
.invoice-services-table th:nth-child(3) { width: 15%; }
.invoice-services-table th:nth-child(4) { width: 14%; }
.invoice-services-table th:nth-child(5) { width: 14%; }
.invoice-services-table th:nth-child(6) { width: 15%; }

@media(max-width:1100px) {
    .invoice-filter-grid {
        grid-template-columns: repeat(2, 1fr)
    }

    .invoice-item-row {
        grid-template-columns: 1fr 1fr
    }

    .invoice-item-row .service-col {
        grid-column: 1/-1
    }
}

@media(max-width:767.98px) {
    .invoice-filter-grid {
        grid-template-columns: 1fr
    }

    .invoice-item-row {
        grid-template-columns: 1fr
    }

    .invoice-item-row .service-col {
        grid-column: auto
    }

    .sticky-mobile-save {
        position: sticky;
        bottom: 8px;
        z-index: 20;
        background: var(--card-bg);
        padding: 10px;
        border: 1px solid var(--border-soft);
        border-radius: 15px;
        box-shadow: var(--shadow)
    }
}
</style>
<div class="page-head">
    <div><span class="badge-soft">INVOICE</span>
        <h1 class="mt-2"><?= e($invoice['invoice_number']) ?></h1>
        <p><?= e($invoice['client_name']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(app_url('invoice-form.php?id='.$id)) ?>">Edit</a>
        <a class="btn btn-brand" href="<?= e(app_url('invoice-print-viewer.php?id='.$id)) ?>">
            <i data-lucide="printer"></i> Print Invoice
        </a>
    </div>
</div>
<section class="card-ui p-3 p-lg-4 mb-3">
    <div class="row g-3">
        <div class="col-md-4"><small class="text-muted">Invoice Date</small><strong
                class="d-block"><?= e(date('d M Y',strtotime($invoice['invoice_date']))) ?></strong></div>
        <div class="col-md-4"><small class="text-muted">Due Date</small><strong
                class="d-block"><?= $invoice['due_date']?e(date('d M Y',strtotime($invoice['due_date']))):'—' ?></strong>
        </div>
        <div class="col-md-4"><small class="text-muted">Status</small><span
                class="status-pill"><?= e(ucwords(str_replace('_',' ',$invoice['payment_status']))) ?></span></div>
        <div class="col-md-6"><small class="text-muted">Bill To</small><strong
                class="d-block"><?= e($invoice['bill_to_name']) ?></strong>
            <div><?= nl2br(e($invoice['bill_to_address'])) ?></div>
        </div>
        <div class="col-md-6"><small class="text-muted">Patient / References</small>
            <div><?= e($invoice['patient_name']?:'—') ?></div>
            <div><?= e($invoice['hospital_reference_no']?:$invoice['patient_reference_no']?:'') ?></div>
        </div>
    </div>
</section>
<section class="card-ui p-3">
    <div class="desktop-table table-responsive">
        <table class="table align-middle invoice-services-table">
            <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Services</th>
                    <th>Service Rate</th>
                    <th>Discount</th>
                    <th>Value</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $n => $item): ?>
                    <?php
                    $discountType = (string)($item['discount_type'] ?? 'none');
                    $discountValue = (float)($item['discount_value'] ?? 0);
                    $discountAmount = (float)($item['discount_amount'] ?? 0);

                    if ($discountType === 'percentage' && $discountValue > 0) {
                        $discountDisplay = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.') . '%';
                    } elseif ($discountType === 'amount' && $discountAmount > 0) {
                        $discountDisplay = '₹' . number_format($discountAmount, 2);
                    } else {
                        $discountDisplay = '—';
                    }
                    ?>
                    <tr>
                        <td><?= $n + 1 ?></td>
                        <td>
                            <strong><?= e($item['service_name_snapshot']) ?></strong>
                            <small class="d-block text-muted"><?= e($item['service_code_snapshot'] ?: '') ?></small>
                        </td>
                        <td>₹<?= number_format((float)$item['applied_rate'], 2) ?></td>
                        <td><?= e($discountDisplay) ?></td>
                        <td>₹<?= number_format($discountAmount, 2) ?></td>
                        <td><strong>₹<?= number_format((float)$item['line_total'], 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-card-list">
        <?php foreach ($items as $item): ?>
            <?php
            $discountType = (string)($item['discount_type'] ?? 'none');
            $discountValue = (float)($item['discount_value'] ?? 0);
            $discountAmount = (float)($item['discount_amount'] ?? 0);

            if ($discountType === 'percentage' && $discountValue > 0) {
                $discountDisplay = rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.') . '%';
            } elseif ($discountType === 'amount' && $discountAmount > 0) {
                $discountDisplay = '₹' . number_format($discountAmount, 2);
            } else {
                $discountDisplay = '—';
            }
            ?>
            <article class="card-ui p-3 mobile-invoice-card">
                <strong><?= e($item['service_name_snapshot']) ?></strong>
                <small class="d-block text-muted"><?= e($item['service_code_snapshot'] ?: '') ?></small>
                <div class="row g-2 mt-2 small">
                    <div class="col-6">Service Rate<br><strong>₹<?= number_format((float)$item['applied_rate'], 2) ?></strong></div>
                    <div class="col-6">Discount<br><strong><?= e($discountDisplay) ?></strong></div>
                    <div class="col-6">Value<br><strong>₹<?= number_format($discountAmount, 2) ?></strong></div>
                    <div class="col-6">Amount<br><strong>₹<?= number_format((float)$item['line_total'], 2) ?></strong></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="invoice-summary mt-3">
        <div class="invoice-summary-row">
            <span>Service Total</span><strong>₹<?= number_format((float)$invoice['subtotal'], 2) ?></strong>
        </div>
        <div class="invoice-summary-row">
            <span>Discount</span><strong>₹<?= number_format((float)$invoice['discount_amount'], 2) ?></strong>
        </div>
        <div class="invoice-summary-row">
            <span>Tax</span><strong>₹<?= number_format((float)$invoice['tax_amount'], 2) ?></strong>
        </div>
        <div class="invoice-summary-row total">
            <span>Grand Total</span><strong>₹<?= number_format((float)$invoice['grand_total'], 2) ?></strong>
        </div>
        <div class="invoice-summary-row">
            <span>Received</span><strong>₹<?= number_format((float)$invoice['received_amount'], 2) ?></strong>
        </div>
        <div class="invoice-summary-row">
            <span>Balance</span><strong>₹<?= number_format((float)$invoice['balance_amount'], 2) ?></strong>
        </div>
    </div>
</section>
<?php include __DIR__.'/includes/layout-end.php'; ?>