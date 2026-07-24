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

$sql = "SELECT p.*, c.client_name, c.client_code, c.mobile, pm.mode_name,
               COALESCE(a.allocation_count, 0) AS allocation_count,
               CASE WHEN p.payment_status = 'posted' THEN GREATEST(p.unallocated_amount, 0) ELSE 0 END AS unallocated_balance,
               COALESCE((
                   SELECT SUM(i.balance_amount)
                   FROM invoices i
                   WHERE i.business_id = p.business_id
                     AND i.client_id = p.client_id
                     AND i.invoice_status = 'issued'
                     AND i.balance_amount > 0
               ), 0) AS pending_balance
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
 // Debugging line to print the parameters
$stmt->execute($params);
$payments = $stmt->fetchAll();

foreach ($payments as &$payment) {
    $allocatedAmount = (float)($payment['allocated_amount'] ?? 0);
    $pendingBalance = (float)($payment['pending_balance'] ?? 0);

    if (($payment['payment_status'] ?? '') === 'reversed') {
        $payment['display_status'] = 'refunded';
        $payment['display_status_label'] = 'Refunded';
    } elseif ($pendingBalance > 0.009 && $allocatedAmount > 0.009) {
        $payment['display_status'] = 'partial';
        $payment['display_status_label'] = 'Partial Paid';
    } elseif ($pendingBalance > 0.009) {
        $payment['display_status'] = 'unpaid';
        $payment['display_status_label'] = 'Unpaid';
    } else {
        $payment['display_status'] = 'paid';
        $payment['display_status_label'] = 'Paid';
    }
}
unset($payment);

$summaryStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN amount ELSE 0 END),0) AS total_received,
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN allocated_amount ELSE 0 END),0) AS total_allocated,
        COALESCE(SUM(CASE WHEN payment_status='posted' THEN unallocated_amount ELSE 0 END),0) AS total_unallocated,
        SUM(payment_status='posted') AS posted_count,
        COALESCE((
            SELECT SUM(i.balance_amount)
            FROM invoices i
            WHERE i.business_id = ?
              AND i.invoice_status = 'issued'
              AND i.balance_amount > 0
        ),0) AS total_pending
     FROM payments
     WHERE business_id = ?
       AND payment_date BETWEEN ? AND ?"
);
$summaryStmt->execute([
    $currentBusinessId,
    $currentBusinessId,
    $dateFrom ?: '1900-01-01',
    $dateTo ?: '2999-12-31'
]);
$summary = $summaryStmt->fetch() ?: [];

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.payment-list-page .page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 14px;
}

.payment-list-page .page-head h1 {
    margin: 8px 0 4px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}

.summary-card {
    padding: 10px 12px;
    border: 1px solid var(--border-soft);
    border-radius: 13px;
    background: var(--card-bg);
    box-shadow: var(--shadow);
}

.summary-card small {
    display: block;
    margin-bottom: 3px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
}

.summary-card strong {
    display: block;
    font-size: 17px;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.filter-card {
    padding: 14px;
    margin-bottom: 10px;
    border-radius: 14px;
}

.filter-card .form-label {
    margin-bottom: 4px;
    font-size: 12px;
}

.filter-card .form-control,
.filter-card .form-select,
.filter-card .btn {
    min-height: 38px;
    padding-top: 7px;
    padding-bottom: 7px;
}

.payment-table {
    width: 100%;
    min-width: 1260px;
    table-layout: auto;
    border-collapse: separate;
    border-spacing: 0;
}

.payment-table th,
.payment-table td {
    vertical-align: middle;
    white-space: nowrap;
}

.payment-table th {
    padding: 11px 10px;
    font-size: 11px;
    font-weight: 800;
}

.payment-table td {
    padding: 12px 10px;
    font-weight: 400;
}

.payment-table td *,
.payment-table td strong,
.payment-table td .fw-bold,
.receipt-no {
    font-weight: 400 !important;
}

.payment-table th:nth-child(1),
.payment-table td:nth-child(1) {
    min-width: 125px;
}

.payment-table th:nth-child(2),
.payment-table td:nth-child(2) {
    min-width: 105px;
}

.payment-table th:nth-child(3),
.payment-table td:nth-child(3) {
    min-width: 230px;
    white-space: normal;
}

.payment-table th:nth-child(4),
.payment-table td:nth-child(4) {
    min-width: 90px;
}

.payment-table th:nth-child(5),
.payment-table td:nth-child(5) {
    min-width: 130px;
}

.payment-table th:nth-child(6),
.payment-table td:nth-child(6),
.payment-table th:nth-child(7),
.payment-table td:nth-child(7),
.payment-table th:nth-child(8),
.payment-table td:nth-child(8),
.payment-table th:nth-child(9),
.payment-table td:nth-child(9) {
    min-width: 110px;
}

.payment-table th:nth-child(10),
.payment-table td:nth-child(10) {
    min-width: 105px;
}

.payment-table th:nth-child(11),
.payment-table td:nth-child(11) {
    min-width: 145px;
}

.receipt-no {
    color: var(--text-main);
    text-decoration: none;
}

.payment-status {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    white-space: nowrap;
}

.payment-status.refunded {
    background: #fee2e2;
    color: #b91c1c;
}

.payment-status.unpaid {
    background: #ffedd5;
    color: #c2410c;
}

.payment-status.partial {
    background: #fef3c7;
    color: #a16207;
}

.payment-status.paid {
    background: #dcfce7;
    color: #15803d;
}

.payment-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 6px;
}

.action-icon-btn {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 !important;
    border-radius: 10px;
}

.action-icon-btn i {
    width: 16px;
    height: 16px;
}

.action-whatsapp {
    border-color: #25d366 !important;
    color: #128c4a !important;
}

.action-whatsapp:hover {
    background: #25d366 !important;
    color: #fff !important;
}

.mobile-payment-list {
    display: none;
}

.mobile-payment-card {
    padding: 15px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: var(--card-bg);
}

.mobile-payment-card+.mobile-payment-card {
    margin-top: 12px;
}

.payment-live-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: auto;
    color: var(--text-muted);
    font-size: 11px;
}

.payment-live-status::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #22c55e;
}

#paymentDetailsBody strong,
#paymentDetailsBody .fw-bold,
#paymentDetailsBody td {
    font-weight: 400 !important;
}

#paymentDetailsBody .summary-card strong {
    font-weight: 700 !important;
}

@media(max-width:1250px) {
    .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media(max-width:991.98px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width:767.98px) {
    .payment-list-page .page-head {
        flex-direction: column;
    }

    .payment-list-page .page-head .btn {
        width: 100%;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .desktop-payment-table {
        display: none;
    }

    .mobile-payment-list {
        display: block;
    }

    .filter-card .btn {
        width: 100%;
    }
}
</style>

<div class="payment-list-page">
    <div class="page-head">
        <div>
            <span class="badge-soft">PAYMENTS</span>
            <h1 class="mt-2">Payment List</h1>
            <p>Search, filter and manage hospital receipts, allocations and balances.</p>
        </div>
        <a class="btn btn-brand" href="<?= e(app_url('payment-form.php')) ?>">
            <i data-lucide="circle-dollar-sign"></i> Record Payment
        </a>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><small>Total
                Received</small><strong>₹<?= number_format((float)($summary['total_received'] ?? 0), 2) ?></strong>
        </div>
        <div class="summary-card">
            <small>Paid
                Amount</small><strong>₹<?= number_format((float)($summary['total_allocated'] ?? 0), 2) ?></strong>
        </div>
        <div class="summary-card">
            <small>Unallocated</small><strong>₹<?= number_format((float)($summary['total_unallocated'] ?? 0), 2) ?></strong>
        </div>
        <div class="summary-card"><small>Paid
                Receipts</small><strong><?= number_format((int)($summary['posted_count'] ?? 0)) ?></strong></div>
        <div class="summary-card"><small>Pending
                Balance</small><strong>₹<?= number_format((float)($summary['total_pending'] ?? 0), 2) ?></strong></div>
    </div>

    <section class="card-ui filter-card p-3 p-lg-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-3">
                <label class="form-label fw-semibold">Search</label>
                <input class="form-control" name="search" value="<?= e($search) ?>"
                    placeholder="Receipt, hospital or reference">
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
                    <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Paid</option>
                    <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>Refunded</option>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                <span class="payment-live-status">Live filtering enabled</span>
                <a class="btn btn-light" href="<?= e(app_url('payments.php')) ?>">Reset</a>
                <button class="btn btn-brand" type="submit"><i data-lucide="list-filter"></i> Apply Filters</button>
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
                        <th class="text-end">Paid Amount</th>
                        <th class="text-end">Unallocated</th>
                        <th class="text-end">Balance Pending</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$payments): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            <i data-lucide="receipt-text"></i>
                            <div class="fw-bold mt-2">No payment records found</div>
                            <small>Change the filters or record a new payment.</small>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><span class="receipt-no"><?= e($payment['receipt_number']) ?></span><small
                                class="d-block text-muted"><?= (int)$payment['allocation_count'] ?>
                                allocation(s)</small></td>
                        <td><?= e(date('d M Y', strtotime($payment['payment_date']))) ?></td>
                        <td><?= e($payment['client_name']) ?><small
                                class="d-block text-muted"><?= e($payment['client_code'] ?? '') ?></small></td>
                        <td><?= e($payment['mode_name']) ?></td>
                        <td><?= e($payment['transaction_reference'] ?: '—') ?></td>
                        <td class="text-end">₹<?= number_format((float)$payment['amount'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$payment['allocated_amount'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$payment['unallocated_balance'], 2) ?></td>
                        <td class="text-end">₹<?= number_format((float)$payment['pending_balance'], 2) ?></td>
                        <td><span class="payment-status <?= e($payment['display_status']) ?>">
                                <?= e($payment['display_status_label']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="payment-actions">
                                <button type="button" class="btn btn-sm btn-light action-icon-btn view-payment"
                                    title="View" data-id="<?= (int)$payment['id'] ?>"><i data-lucide="eye"></i></button>
                                <?php if (
                                    $payment['payment_status'] === 'posted'
                                    && in_array($payment['display_status'], ['partial', 'unpaid'], true)
                                    && (float)$payment['pending_balance'] > 0.009
                                ): ?>
                                <a class="btn btn-sm btn-outline-success action-icon-btn" title="Record Pending Payment"
                                    href="<?= e(app_url(
                                        'payment-form.php?client_id=' . (int)$payment['client_id']
                                        . '&source_payment_id=' . (int)$payment['id']
                                    )) ?>">
                                    <i data-lucide="indian-rupee"></i>
                                </a>
                                <?php endif; ?>
                                <?php $waMobile = preg_replace('/\D+/', '', (string)($payment['mobile'] ?? '')); if (strlen($waMobile) === 10) $waMobile = '91' . $waMobile; $waText = rawurlencode('Dear ' . $payment['client_name'] . ",\nPayment receipt " . $payment['receipt_number'] . ' for Rs. ' . number_format((float)$payment['amount'], 2) . ' has been recorded.'); ?>
                                <?php if ($waMobile !== ''): ?><a class="btn btn-sm action-icon-btn action-whatsapp"
                                    title="WhatsApp" target="_blank" rel="noopener"
                                    href="<?= e('https://wa.me/' . $waMobile . '?text=' . $waText) ?>"><i
                                        data-lucide="message-circle"></i></a><?php endif; ?>
                                <button type="button"
                                    class="btn btn-sm btn-outline-danger action-icon-btn delete-payment"
                                    title="Delete Payment"
                                    data-id="<?= (int)$payment['id'] ?>"
                                    data-receipt="<?= e($payment['receipt_number']) ?>">
                                    <i data-lucide="trash-2"></i>
                                </button>
                            </div>
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
                    <div><strong><?= e($payment['receipt_number']) ?></strong>
                        <div class="small text-muted"><?= e($payment['client_name']) ?></div>
                    </div>
                    <span class="payment-status <?= e($payment['display_status']) ?>">
                        <?= e($payment['display_status_label']) ?>
                    </span>
                </div>
                <div class="row g-2 mt-2 small">
                    <div class="col-6">
                        Date<br><strong><?= e(date('d M Y', strtotime($payment['payment_date']))) ?></strong></div>
                    <div class="col-6">Amount<br><strong>₹<?= number_format((float)$payment['amount'], 2) ?></strong>
                    </div>
                    <div class="col-6">
                        Paid Amount<br><strong>₹<?= number_format((float)$payment['allocated_amount'], 2) ?></strong>
                    </div>
                    <div class="col-6">
                        Unallocated<br><strong>₹<?= number_format((float)$payment['unallocated_balance'], 2) ?></strong>
                    </div>
                    <div class="col-6">Balance
                        Pending<br><strong>₹<?= number_format((float)$payment['pending_balance'], 2) ?></strong></div>
                </div>
                <div class="payment-actions mt-3">
                    <button type="button" class="btn btn-sm btn-light action-icon-btn view-payment" title="View Payment"
                        data-id="<?= (int)$payment['id'] ?>"><i data-lucide="eye"></i></button>
                    <?php if (
                        $payment['payment_status'] === 'posted'
                        && in_array($payment['display_status'], ['partial', 'unpaid'], true)
                        && (float)$payment['pending_balance'] > 0.009
                    ): ?>
                    <a class="btn btn-sm btn-outline-success action-icon-btn" title="Record Pending Payment"
                        href="<?= e(app_url(
                            'payment-form.php?client_id=' . (int)$payment['client_id']
                            . '&source_payment_id=' . (int)$payment['id']
                        )) ?>">
                        <i data-lucide="indian-rupee"></i>
                    </a>
                    <?php endif; ?>
                    <?php
                    $mobileWa = preg_replace('/\D+/', '', (string)($payment['mobile'] ?? ''));
                    if (strlen($mobileWa) === 10) $mobileWa = '91' . $mobileWa;
                    $mobileWaText = rawurlencode('Dear ' . $payment['client_name'] . ",\nPayment receipt " . $payment['receipt_number'] . ' for Rs. ' . number_format((float)$payment['amount'], 2) . ' has been recorded.');
                    ?>
                    <?php if ($mobileWa !== ''): ?>
                    <a class="btn btn-sm action-icon-btn action-whatsapp" title="WhatsApp" target="_blank"
                        rel="noopener" href="<?= e('https://wa.me/' . $mobileWa . '?text=' . $mobileWaText) ?>"><i
                            data-lucide="message-circle"></i></a>
                    <?php endif; ?>
                    <button type="button"
                        class="btn btn-sm btn-outline-danger action-icon-btn delete-payment"
                        title="Delete Payment"
                        data-id="<?= (int)$payment['id'] ?>"
                        data-receipt="<?= e($payment['receipt_number']) ?>">
                        <i data-lucide="trash-2"></i>
                    </button>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <div class="modal-header">
                <h5 class="modal-title">Payment Details</h5><button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="paymentDetailsBody">Loading...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const money = value => '₹' + Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const escapeHtml = value => {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };


    const filterForm = document.querySelector('form[method="get"]');
    const searchInput = filterForm?.querySelector('input[name="search"]');
    let filterTimer = 0;
    let filterSubmitting = false;

    function submitLive(delay = 0) {
        if (!filterForm) return;

        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(() => {
            if (filterSubmitting) return;
            filterSubmitting = true;
            filterForm.requestSubmit();
        }, delay);
    }

    filterForm?.querySelectorAll('select[name], input[type="date"][name]').forEach(field => {
        field.addEventListener('change', () => submitLive(0));
    });

    searchInput?.addEventListener('input', () => submitLive(450));

    filterForm?.addEventListener('submit', () => {
        filterSubmitting = true;
    });

    document.querySelectorAll('.view-payment').forEach(button => {
        button.addEventListener('click', async () => {
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(
                'paymentDetailsModal'));
            const body = document.getElementById('paymentDetailsBody');
            body.innerHTML =
                '<div class="text-center py-4"><span class="spinner-border"></span></div>';
            modal.show();
            try {
                const detailsUrl = new URL(
                    <?= json_encode(app_url('api/payment.php'), JSON_UNESCAPED_SLASHES) ?>,
                    window.location.origin
                );
                detailsUrl.searchParams.set('action', 'details');
                detailsUrl.searchParams.set('id', button.dataset.id);

                const response = await fetch(
                    detailsUrl.toString(), {
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
                const statusLabel = p.payment_status === 'posted' ? 'Paid' : 'Refunded';
                const allocationMethod = String(p.allocation_method || 'manual')
                    .replaceAll('_', ' ')
                    .replace(/\b\w/g, c => c.toUpperCase());
                body.innerHTML = `
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="p-3 rounded-3 border bg-body-tertiary d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <small class="text-muted">Receipt Number</small>
                                    <div class="fs-5">${escapeHtml(p.receipt_number)}</div>
                                    <div class="small text-muted">${escapeHtml(p.payment_date || '')}</div>
                                </div>
                                <span class="payment-status ${escapeHtml(p.payment_status)}">${statusLabel}</span>
                            </div>
                        </div>
                        <div class="col-md-6"><small class="text-muted">Hospital</small><div>${escapeHtml(p.client_name)}</div><small class="text-muted">${escapeHtml(p.client_code || '')}</small></div>
                        <div class="col-md-3"><small class="text-muted">Payment Mode</small><div>${escapeHtml(p.mode_name)}</div></div>
                        <div class="col-md-3"><small class="text-muted">Reference</small><div>${escapeHtml(p.transaction_reference || '—')}</div></div>
                        <div class="col-md-4"><small class="text-muted">Payer Name</small><div>${escapeHtml(p.payer_name || p.client_name)}</div></div>
                        <div class="col-md-4"><small class="text-muted">Allocation Method</small><div>${escapeHtml(allocationMethod)}</div></div>
                        <div class="col-md-4"><small class="text-muted">Allocation Count</small><div>${allocations.length}</div></div>
                    </div>

                    <div class="row g-3 mt-1 mb-3">
                        <div class="col-md-4"><div class="summary-card h-100"><small>Payment Received</small><strong>${money(p.amount)}</strong></div></div>
                        <div class="col-md-4"><div class="summary-card h-100"><small>Paid Amount</small><strong>${money(p.allocated_amount)}</strong></div></div>
                        <div class="col-md-4"><div class="summary-card h-100"><small>Unallocated / Advance</small><strong>${money(p.unallocated_amount)}</strong></div></div>
                    </div>

                    <h6 class="mb-2">Invoice Allocation Details</h6>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Paid Amount</th></tr></thead>
                            <tbody>
                            ${allocations.length
                                ? allocations.map(a => `<tr><td>${escapeHtml(a.invoice_number)}</td><td>${escapeHtml(a.invoice_date_display)}</td><td class="text-end">${money(a.allocated_amount)}</td></tr>`).join('')
                                : '<tr><td colspan="3" class="text-center text-muted py-4">This payment is kept as advance/unallocated and is not linked to an invoice.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                    ${p.notes ? `<div class="mt-3"><small class="text-muted">Notes</small><div>${escapeHtml(p.notes)}</div></div>` : ''}
                    ${p.reversal_reason ? `<div class="alert alert-danger mt-3 mb-0"><strong>Refund reason:</strong> ${escapeHtml(p.reversal_reason)}</div>` : ''}
                `;
            } catch (error) {
                body.innerHTML =
                    `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
            }
        });
    });
                const result = await response.json();
                AppToast.show(result.success ? 'success' : 'error', result.message);
                if (result.success) setTimeout(() => location.reload(), 450);
            } catch (error) {
                AppToast.show('error', 'Unable to reverse payment.');
            }
        });
    });

    document.querySelectorAll('.delete-payment').forEach(button => {
        button.addEventListener('click', async () => {
            const receipt = button.dataset.receipt || 'this payment';

            if (!confirm(
                `Delete ${receipt}? The receipt, allocations and advance amount will be removed, and linked invoice balances will be recalculated.`
            )) {
                return;
            }

            const formData = new FormData();
            formData.set('csrf_token', '<?= e(csrf_token()) ?>');
            formData.set('action', 'delete_payment');
            formData.set('payment_id', button.dataset.id);

            button.disabled = true;

            try {
                const response = await fetch('<?= e(app_url('api/payment.php')) ?>', {
                    method: 'POST',
                    body: formData,
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
                    throw new Error(
                        raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
                        || 'Invalid server response.'
                    );
                }

                AppToast.show(result.success ? 'success' : 'error', result.message);

                if (result.success) {
                    setTimeout(() => window.location.reload(), 450);
                    return;
                }
            } catch (error) {
                AppToast.show('error', error.message || 'Unable to delete payment.');
            } finally {
                button.disabled = false;
            }
        });
    });

    if (window.lucide) lucide.createIcons();
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>