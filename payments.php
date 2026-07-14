<?php
declare(strict_types=1);

$pageTitle = 'Payment List';
require_once __DIR__ . '/includes/bootstrap.php';

$clientId = (int)($_GET['client_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$search = trim((string)($_GET['search'] ?? ''));

$clientsStmt = $pdo->prepare(
    "SELECT id, client_code, client_name
     FROM clients
     WHERE business_id = ?
       AND status = 'active'
     ORDER BY client_name"
);
$clientsStmt->execute([$currentBusinessId]);
$clients = $clientsStmt->fetchAll();

$where = ["p.business_id = ?"];
$params = [$currentBusinessId];

if ($clientId > 0) {
    $where[] = "p.client_id = ?";
    $params[] = $clientId;
}
if (in_array($status, ['posted', 'reversed'], true)) {
    $where[] = "p.payment_status = ?";
    $params[] = $status;
}
if ($dateFrom !== '') {
    $where[] = "p.payment_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "p.payment_date <= ?";
    $params[] = $dateTo;
}
if ($search !== '') {
    $where[] = "(p.receipt_number LIKE ? OR p.transaction_reference LIKE ? OR c.client_name LIKE ? OR c.client_code LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

$sql = "SELECT p.*, c.client_name, c.client_code, pm.mode_name,
               COALESCE(a.allocation_count, 0) AS allocation_count
        FROM payments p
        INNER JOIN clients c ON c.id = p.client_id
        INNER JOIN payment_modes pm ON pm.id = p.payment_mode_id
        LEFT JOIN (
            SELECT payment_id, COUNT(*) AS allocation_count
            FROM payment_allocations
            GROUP BY payment_id
        ) a ON a.payment_id = p.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$summaryStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN amount ELSE 0 END),0) AS total_received,
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN allocated_amount ELSE 0 END),0) AS total_allocated,
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN unallocated_amount ELSE 0 END),0) AS total_unallocated,
        SUM(payment_status='posted') AS posted_count
     FROM payments
     WHERE business_id = ?
       AND payment_date BETWEEN ? AND ?"
);
$summaryStmt->execute([$currentBusinessId, $dateFrom ?: '1900-01-01', $dateTo ?: '2999-12-31']);
$summary = $summaryStmt->fetch() ?: [];

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.payment-list-page .page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.payment-list-page .page-head h1{margin:8px 0 4px}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.summary-card{padding:17px;border:1px solid var(--border-soft);border-radius:16px;background:var(--card-bg);box-shadow:var(--shadow)}
.summary-card small{display:block;color:var(--text-muted);font-weight:700}
.summary-card strong{display:block;margin-top:5px;font-size:21px}
.filter-card{padding:16px;margin-bottom:16px}
.payment-table th{white-space:nowrap}
.payment-table td{vertical-align:middle}
.receipt-no{font-weight:850;color:var(--text-main)}
.payment-status{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}
.payment-status.posted{background:rgba(25,135,84,.12);color:#198754}
.payment-status.reversed{background:rgba(220,53,69,.12);color:#dc3545}
.mobile-payment-list{display:none}
@media(max-width:991.98px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767.98px){.payment-list-page .page-head{flex-direction:column}.payment-list-page .page-head .btn{width:100%}.summary-grid{grid-template-columns:1fr}.desktop-payment-table{display:none}.mobile-payment-list{display:block}.mobile-payment-card{padding:15px;margin-bottom:10px}}
</style>

<div class="payment-list-page">
    <div class="page-head">
        <div>
            <span class="badge-soft">PAYMENTS</span>
            <h1>Payment List</h1>
            <p class="mb-0 text-muted">View hospital receipts, allocations and unallocated balances.</p>
        </div>
        <a class="btn btn-brand" href="<?= e(app_url('payment-form.php')) ?>">
            <i data-lucide="circle-dollar-sign"></i> Record Payment
        </a>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><small>Total Received</small><strong>₹<?= number_format((float)($summary['total_received'] ?? 0), 2) ?></strong></div>
        <div class="summary-card"><small>Allocated</small><strong>₹<?= number_format((float)($summary['total_allocated'] ?? 0), 2) ?></strong></div>
        <div class="summary-card"><small>Unallocated</small><strong>₹<?= number_format((float)($summary['total_unallocated'] ?? 0), 2) ?></strong></div>
        <div class="summary-card"><small>Posted Receipts</small><strong><?= number_format((int)($summary['posted_count'] ?? 0)) ?></strong></div>
    </div>

    <section class="card-ui filter-card">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-3">
                <label class="form-label fw-semibold">Search</label>
                <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Receipt, hospital or reference">
            </div>
            <div class="col-md-4 col-lg-3">
                <label class="form-label fw-semibold">Hospital</label>
                <select class="form-select" name="client_id">
                    <option value="0">All hospitals</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int)$client['id'] ?>" <?= $clientId === (int)$client['id'] ? 'selected' : '' ?>>
                            <?= e(($client['client_code'] ?? '') . ' - ' . $client['client_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">From</label>
                <input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">To</label>
                <input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <option value="">All statuses</option>
                    <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Posted</option>
                    <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                <a class="btn btn-light" href="<?= e(app_url('payments.php')) ?>">Reset</a>
                <button class="btn btn-primary" type="submit"><i data-lucide="search"></i> Apply Filters</button>
            </div>
        </form>
    </section>

    <section class="card-ui p-3">
        <div class="desktop-payment-table table-responsive">
            <table class="table payment-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th>Hospital</th>
                        <th>Mode</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Allocated</th>
                        <th class="text-end">Unallocated</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">No payment records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><span class="receipt-no"><?= e($payment['receipt_number']) ?></span><small class="d-block text-muted"><?= (int)$payment['allocation_count'] ?> allocation(s)</small></td>
                            <td><?= e(date('d M Y', strtotime($payment['payment_date']))) ?></td>
                            <td><strong><?= e($payment['client_name']) ?></strong><small class="d-block text-muted"><?= e($payment['client_code'] ?? '') ?></small></td>
                            <td><?= e($payment['mode_name']) ?></td>
                            <td><?= e($payment['transaction_reference'] ?: '—') ?></td>
                            <td class="text-end fw-bold">₹<?= number_format((float)$payment['amount'], 2) ?></td>
                            <td class="text-end">₹<?= number_format((float)$payment['allocated_amount'], 2) ?></td>
                            <td class="text-end">₹<?= number_format((float)$payment['unallocated_amount'], 2) ?></td>
                            <td><span class="payment-status <?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary view-payment" data-id="<?= (int)$payment['id'] ?>">View</button>
                                <?php if ($payment['payment_status'] === 'posted'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger reverse-payment" data-id="<?= (int)$payment['id'] ?>">Reverse</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-payment-list">
            <?php foreach ($payments as $payment): ?>
                <article class="card-ui mobile-payment-card">
                    <div class="d-flex justify-content-between gap-3">
                        <div><strong><?= e($payment['receipt_number']) ?></strong><div class="small text-muted"><?= e($payment['client_name']) ?></div></div>
                        <span class="payment-status <?= e($payment['payment_status']) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span>
                    </div>
                    <div class="row g-2 mt-2 small">
                        <div class="col-6">Date<br><strong><?= e(date('d M Y', strtotime($payment['payment_date']))) ?></strong></div>
                        <div class="col-6">Amount<br><strong>₹<?= number_format((float)$payment['amount'], 2) ?></strong></div>
                        <div class="col-6">Allocated<br><strong>₹<?= number_format((float)$payment['allocated_amount'], 2) ?></strong></div>
                        <div class="col-6">Unallocated<br><strong>₹<?= number_format((float)$payment['unallocated_amount'], 2) ?></strong></div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-primary flex-fill view-payment" data-id="<?= (int)$payment['id'] ?>">View</button>
                        <?php if ($payment['payment_status'] === 'posted'): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger flex-fill reverse-payment" data-id="<?= (int)$payment['id'] ?>">Reverse</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <div class="modal-header"><h5 class="modal-title">Payment Details</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="paymentDetailsBody">Loading...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const money = value => '₹' + Number(value || 0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    const escapeHtml = value => { const div=document.createElement('div'); div.textContent=String(value??''); return div.innerHTML; };

    document.querySelectorAll('.view-payment').forEach(button => {
        button.addEventListener('click', async () => {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentDetailsModal'));
            const body = document.getElementById('paymentDetailsBody');
            body.innerHTML = '<div class="text-center py-4"><span class="spinner-border"></span></div>';
            modal.show();
            try {
                const detailsUrl = new URL(
                    <?= json_encode(app_url('api/payment.php'), JSON_UNESCAPED_SLASHES) ?>,
                    window.location.origin
                );
                detailsUrl.searchParams.set('action', 'details');
                detailsUrl.searchParams.set('id', button.dataset.id);

                const response = await fetch(
                    detailsUrl.toString(),
                    {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );
                const result = await response.json();
                if (!result.success) throw new Error(result.message);
                const p = result.data.payment;
                const allocations = result.data.allocations;
                body.innerHTML = `
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><small class="text-muted">Receipt</small><strong class="d-block">${escapeHtml(p.receipt_number)}</strong></div>
                        <div class="col-md-4"><small class="text-muted">Hospital</small><strong class="d-block">${escapeHtml(p.client_name)}</strong></div>
                        <div class="col-md-4"><small class="text-muted">Amount</small><strong class="d-block">${money(p.amount)}</strong></div>
                        <div class="col-md-4"><small class="text-muted">Mode</small><strong class="d-block">${escapeHtml(p.mode_name)}</strong></div>
                        <div class="col-md-4"><small class="text-muted">Reference</small><strong class="d-block">${escapeHtml(p.transaction_reference || '—')}</strong></div>
                        <div class="col-md-4"><small class="text-muted">Status</small><strong class="d-block">${escapeHtml(p.payment_status)}</strong></div>
                    </div>
                    <h6>Allocations</h6>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Allocated</th></tr></thead><tbody>
                    ${allocations.length ? allocations.map(a=>`<tr><td>${escapeHtml(a.invoice_number)}</td><td>${escapeHtml(a.invoice_date_display)}</td><td class="text-end">${money(a.allocated_amount)}</td></tr>`).join('') : '<tr><td colspan="3" class="text-center text-muted">No allocations</td></tr>'}
                    </tbody></table></div>`;
            } catch(error) { body.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`; }
        });
    });

    document.querySelectorAll('.reverse-payment').forEach(button => {
        button.addEventListener('click', async () => {
            const reason = prompt('Enter reversal reason:');
            if (reason === null) return;
            if (!reason.trim()) return AppToast.show('warning','Reversal reason is required.');

            const formData = new FormData();
            formData.set('csrf_token','<?= e(csrf_token()) ?>');
            formData.set('action','reverse_payment');
            formData.set('payment_id',button.dataset.id);
            formData.set('reason',reason.trim());

            try {
                const response = await fetch('<?= e(app_url('api/payment.php')) ?>',{method:'POST',body:formData,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                const result = await response.json();
                AppToast.show(result.success?'success':'error',result.message);
                if(result.success) setTimeout(()=>location.reload(),450);
            } catch(error) { AppToast.show('error','Unable to reverse payment.'); }
        });
    });

    if(window.lucide) lucide.createIcons();
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
