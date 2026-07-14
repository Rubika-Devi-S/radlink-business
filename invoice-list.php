<?php
declare(strict_types=1);

$pageTitle = 'Invoice List';
require_once __DIR__ . '/includes/bootstrap.php';

$search = trim((string)($_GET['q'] ?? ''));
$hospitalId = max(0, (int)($_GET['hospital_id'] ?? 0));
$clientId = max(0, (int)($_GET['client_id'] ?? 0));
$financialYearId = max(0, (int)($_GET['financial_year_id'] ?? 0));
$invoiceStatus = trim((string)($_GET['invoice_status'] ?? ''));
$paymentStatus = trim((string)($_GET['payment_status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));

$invoiceStatuses = ['draft', 'issued', 'cancelled'];
$paymentStatuses = ['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'];

if (!in_array($invoiceStatus, $invoiceStatuses, true)) {
    $invoiceStatus = '';
}

if (!in_array($paymentStatus, $paymentStatuses, true)) {
    $paymentStatus = '';
}

$validDate = static function (string $date): bool {
    if ($date === '') {
        return false;
    }

    $value = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $value !== false && $value->format('Y-m-d') === $date;
};

if ($dateFrom !== '' && !$validDate($dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !$validDate($dateTo)) {
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$statusLabel = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));

$hospitalStmt = $pdo->prepare(
    "SELECT c.id, c.client_code, c.client_name
     FROM clients c
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     WHERE c.business_id = ?
       AND (ct.type_key = 'hospital' OR LOWER(ct.type_name) = 'hospital')
     ORDER BY c.client_name"
);
$hospitalStmt->execute([$currentBusinessId]);
$hospitals = $hospitalStmt->fetchAll();

$clientSql =
    "SELECT c.id, c.client_code, c.client_name,
            c.parent_hospital_id,
            h.client_name AS hospital_name
     FROM clients c
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     WHERE c.business_id = ?
       AND ct.type_key <> 'hospital'";
$clientParams = [$currentBusinessId];

if ($hospitalId > 0) {
    $clientSql .= " AND c.parent_hospital_id = ?";
    $clientParams[] = $hospitalId;
}

$clientSql .= " ORDER BY h.client_name, c.client_name";
$clientStmt = $pdo->prepare($clientSql);
$clientStmt->execute($clientParams);
$clients = $clientStmt->fetchAll();

$fyStmt = $pdo->prepare(
    "SELECT id, year_label, is_current
     FROM financial_years
     WHERE business_id = ?
     ORDER BY is_current DESC, start_date DESC"
);
$fyStmt->execute([$currentBusinessId]);
$financialYears = $fyStmt->fetchAll();

$where = ['i.business_id = ?'];
$params = [$currentBusinessId];

if ($search !== '') {
    $where[] = "(
        i.invoice_number LIKE ?
        OR i.bill_to_name LIKE ?
        OR i.patient_name LIKE ?
        OR i.patient_reference_no LIKE ?
        OR i.hospital_reference_no LIKE ?
        OR i.bill_to_mobile LIKE ?
        OR i.bill_to_email LIKE ?
        OR c.client_code LIKE ?
        OR c.client_name LIKE ?
        OR h.client_code LIKE ?
        OR h.client_name LIKE ?
    )";

    $like = '%' . $search . '%';
    for ($i = 0; $i < 11; $i++) {
        $params[] = $like;
    }
}

if ($hospitalId > 0) {
    $where[] = '(i.client_id = ? OR c.parent_hospital_id = ?)';
    $params[] = $hospitalId;
    $params[] = $hospitalId;
}

if ($clientId > 0) {
    $where[] = 'i.client_id = ?';
    $params[] = $clientId;
}

if ($financialYearId > 0) {
    $where[] = 'i.financial_year_id = ?';
    $params[] = $financialYearId;
}

if ($invoiceStatus !== '') {
    $where[] = 'i.invoice_status = ?';
    $params[] = $invoiceStatus;
}

if ($paymentStatus !== '') {
    $where[] = 'i.payment_status = ?';
    $params[] = $paymentStatus;
}

if ($dateFrom !== '') {
    $where[] = 'i.invoice_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'i.invoice_date <= ?';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$commonJoin =
    " FROM invoices i
      INNER JOIN clients c
         ON c.id = i.client_id
        AND c.business_id = i.business_id
      LEFT JOIN clients h
         ON h.id = c.parent_hospital_id
        AND h.business_id = c.business_id ";

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) {$commonJoin} WHERE {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT i.*,
            c.client_code,
            c.client_name,
            c.parent_hospital_id,
            ct.type_name AS client_type_name,
            h.client_code AS parent_hospital_code,
            h.client_name AS parent_hospital_name,
            fy.year_label AS financial_year_label
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
       AND c.business_id = i.business_id
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     INNER JOIN financial_years fy
        ON fy.id = i.financial_year_id
       AND fy.business_id = i.business_id
     WHERE {$whereSql}
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$invoices = $listStmt->fetchAll();

$summaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(CASE WHEN i.invoice_status <> 'cancelled' THEN i.grand_total ELSE 0 END), 0) AS invoice_total,
        COALESCE(SUM(CASE WHEN i.invoice_status <> 'cancelled' THEN i.received_amount ELSE 0 END), 0) AS received_total,
        COALESCE(SUM(CASE WHEN i.invoice_status <> 'cancelled' THEN i.balance_amount ELSE 0 END), 0) AS balance_total,
        SUM(CASE
            WHEN i.invoice_status = 'issued'
             AND i.balance_amount > 0
             AND i.due_date IS NOT NULL
             AND i.due_date < CURRENT_DATE()
            THEN 1 ELSE 0 END
        ) AS overdue_count
     {$commonJoin}
     WHERE {$whereSql}"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [];

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

$paginationUrl = static function (int $targetPage, array $query): string {
    $query['page'] = $targetPage;
    return '?' . http_build_query($query);
};

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.invoice-filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 1fr;gap:12px}
.invoice-filter-row-2{display:grid;grid-template-columns:1fr 1fr .7fr auto auto;gap:12px;align-items:end;margin-top:12px}
.invoice-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}
.invoice-stat{padding:16px;border:1px solid var(--border-soft);border-radius:16px;background:var(--card-bg);box-shadow:var(--shadow)}
.invoice-stat small{display:block;color:var(--text-muted);font-weight:700;margin-bottom:6px}
.invoice-stat strong{font-size:20px;font-weight:900;overflow-wrap:anywhere}
.invoice-table{min-width:1200px}
.invoice-number{font-weight:900;text-decoration:none}
.invoice-client{font-weight:850}
.invoice-meta{font-size:11px;color:var(--text-muted);margin-top:3px}
.invoice-money{font-weight:850;white-space:nowrap}
.invoice-pill{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:850;white-space:nowrap}
.status-draft{background:#f1f5f9;color:#475569}.status-issued,.pay-paid{background:#dcfce7;color:#15803d}.status-cancelled,.pay-cancelled{background:#fee2e2;color:#b91c1c}.pay-unpaid,.pay-overdue{background:#ffedd5;color:#c2410c}.pay-partially_paid{background:#fef3c7;color:#a16207}
.invoice-actions{display:flex;flex-wrap:wrap;gap:6px}.invoice-overdue-row{background:color-mix(in srgb,#fff7ed 58%,transparent)}
.invoice-mobile-list{display:none}.invoice-mobile-card{padding:15px;border:1px solid var(--border-soft);border-radius:16px;background:var(--card-bg)}.invoice-mobile-card+.invoice-mobile-card{margin-top:12px}
.invoice-mobile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.invoice-mobile-grid small{display:block;color:var(--text-muted);font-weight:700}
.invoice-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:16px}
@media(max-width:1250px){.invoice-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.invoice-filter-row-2{grid-template-columns:repeat(3,minmax(0,1fr))}.invoice-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767.98px){.invoice-filter-grid,.invoice-filter-row-2,.invoice-stats{grid-template-columns:1fr}.invoice-filter-row-2 .btn{width:100%}.invoice-desktop{display:none}.invoice-mobile-list{display:block}.invoice-pagination{flex-direction:column;align-items:stretch}.pagination{justify-content:center;flex-wrap:wrap}}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">INVOICES</span>
        <h1 class="mt-2">Invoice List</h1>
        <p>Search, filter and manage hospital and client invoices.</p>
    </div>

    <a class="btn btn-brand" href="<?= e(app_url('invoice-form.php')) ?>">
        <i data-lucide="plus"></i> Create Invoice
    </a>
</div>

<section class="card-ui p-3 p-lg-4 mb-3">
<form method="get">
    <div class="invoice-filter-grid">
        <div>
            <label class="form-label fw-semibold">Search</label>
            <div class="input-group">
                <span class="input-group-text"><i data-lucide="search"></i></span>
                <input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Invoice, hospital, client, patient, reference...">
            </div>
        </div>

        <div>
            <label class="form-label fw-semibold">Hospital</label>
            <select class="form-select" name="hospital_id" id="hospitalFilter">
                <option value="0">All Hospitals</option>
                <?php foreach ($hospitals as $hospital): ?>
                    <option value="<?= (int)$hospital['id'] ?>" <?= $hospitalId === (int)$hospital['id'] ? 'selected' : '' ?>>
                        <?= e($hospital['client_code'] . ' - ' . $hospital['client_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label fw-semibold">Client</label>
            <select class="form-select" name="client_id" id="clientFilter">
                <option value="0">All Clients</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= (int)$client['id'] ?>" data-hospital-id="<?= (int)($client['parent_hospital_id'] ?? 0) ?>" <?= $clientId === (int)$client['id'] ? 'selected' : '' ?>>
                        <?= e($client['client_code'] . ' - ' . $client['client_name'] . ($client['hospital_name'] ? ' / ' . $client['hospital_name'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label fw-semibold">Financial Year</label>
            <select class="form-select" name="financial_year_id">
                <option value="0">All Financial Years</option>
                <?php foreach ($financialYears as $fy): ?>
                    <option value="<?= (int)$fy['id'] ?>" <?= $financialYearId === (int)$fy['id'] ? 'selected' : '' ?>>
                        <?= e($fy['year_label']) ?><?= $fy['is_current'] ? ' (Current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label fw-semibold">Invoice Status</label>
            <select class="form-select" name="invoice_status">
                <option value="">All Invoice Status</option>
                <?php foreach ($invoiceStatuses as $value): ?>
                    <option value="<?= e($value) ?>" <?= $invoiceStatus === $value ? 'selected' : '' ?>><?= e($statusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label fw-semibold">Payment Status</label>
            <select class="form-select" name="payment_status">
                <option value="">All Payment Status</option>
                <?php foreach ($paymentStatuses as $value): ?>
                    <option value="<?= e($value) ?>" <?= $paymentStatus === $value ? 'selected' : '' ?>><?= e($statusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="invoice-filter-row-2">
        <div>
            <label class="form-label fw-semibold">From Date</label>
            <input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>">
        </div>
        <div>
            <label class="form-label fw-semibold">To Date</label>
            <input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>">
        </div>
        <div>
            <label class="form-label fw-semibold">Rows</label>
            <select class="form-select" name="per_page">
                <?php foreach ([10,20,50,100] as $size): ?>
                    <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> Rows</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-brand" type="submit"><i data-lucide="list-filter"></i> Apply Filters</button>
        <a class="btn btn-light" href="<?= e(app_url('invoice-list.php')) ?>"><i data-lucide="rotate-ccw"></i> Reset</a>
    </div>
</form>
</section>

<div class="invoice-stats">
    <article class="invoice-stat"><small>Filtered Invoices</small><strong><?= number_format((int)($summary['invoice_count'] ?? 0)) ?></strong></article>
    <article class="invoice-stat"><small>Invoice Total</small><strong>₹<?= number_format((float)($summary['invoice_total'] ?? 0), 2) ?></strong></article>
    <article class="invoice-stat"><small>Received</small><strong>₹<?= number_format((float)($summary['received_total'] ?? 0), 2) ?></strong></article>
    <article class="invoice-stat"><small>Outstanding</small><strong>₹<?= number_format((float)($summary['balance_total'] ?? 0), 2) ?></strong></article>
    <article class="invoice-stat"><small>Overdue Invoices</small><strong><?= number_format((int)($summary['overdue_count'] ?? 0)) ?></strong></article>
</div>

<section class="card-ui p-3">
<div class="invoice-desktop table-responsive">
<table class="table align-middle mb-0 invoice-table">
<thead>
<tr>
    <th>Invoice</th><th>Hospital / Client</th><th>Patient / Reference</th><th>Patients / Cases</th><th>Invoice Date</th><th>Due Date</th><th>FY</th><th>Total</th><th>Received</th><th>Balance</th><th>Invoice Status</th><th>Payment Status</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (!$invoices): ?>
<tr><td colspan="12" class="text-center text-muted py-5"><i data-lucide="receipt-text"></i><div class="fw-bold mt-2">No invoices found</div><small>Change the filters or create a new invoice.</small></td></tr>
<?php endif; ?>

<?php foreach ($invoices as $invoice): ?>
<?php
$isOverdue = $invoice['invoice_status'] === 'issued'
    && (float)$invoice['balance_amount'] > 0
    && !empty($invoice['due_date'])
    && $invoice['due_date'] < date('Y-m-d');
$hospitalName = $invoice['parent_hospital_name'] ?: $invoice['client_name'];
$clientName = $invoice['parent_hospital_name'] ? $invoice['client_name'] : '';
$paymentLabel = $isOverdue ? 'Overdue' : $statusLabel($invoice['payment_status']);
$paymentClass = $isOverdue ? 'pay-overdue' : 'pay-' . $invoice['payment_status'];
?>
<tr class="<?= $isOverdue ? 'invoice-overdue-row' : '' ?>">
    <td>
        <a class="invoice-number" href="<?= e(app_url('invoice-view.php?id=' . (int)$invoice['id'])) ?>"><?= e($invoice['invoice_number']) ?></a>
        <?php if ($invoice['hospital_reference_no']): ?><small class="d-block text-muted">Ref: <?= e($invoice['hospital_reference_no']) ?></small><?php endif; ?>
    </td>
    <td>
        <div class="invoice-client"><?= e($hospitalName) ?></div>
        <?php if ($clientName !== ''): ?><div class="invoice-meta">Client: <?= e($clientName) ?></div><?php endif; ?>
        <div class="invoice-meta"><?= e($invoice['client_code']) ?> · <?= e($invoice['client_type_name']) ?></div>
    </td>
    <td><strong><?= e($invoice['patient_name'] ?: '—') ?></strong><?php if ($invoice['patient_reference_no']): ?><small class="d-block text-muted"><?= e($invoice['patient_reference_no']) ?></small><?php endif; ?></td>
    <td><?= e(date('d-m-Y', strtotime($invoice['invoice_date']))) ?></td>
    <td><?php if ($invoice['due_date']): ?><span class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= e(date('d-m-Y', strtotime($invoice['due_date']))) ?></span><?php else: ?>—<?php endif; ?></td>
    <td><?= e($invoice['financial_year_label']) ?></td>
    <td class="invoice-money">₹<?= number_format((float)$invoice['grand_total'], 2) ?></td>
    <td class="invoice-money">₹<?= number_format((float)$invoice['received_amount'], 2) ?></td>
    <td class="invoice-money">₹<?= number_format((float)$invoice['balance_amount'], 2) ?></td>
    <td><span class="invoice-pill status-<?= e($invoice['invoice_status']) ?>"><?= e($statusLabel($invoice['invoice_status'])) ?></span></td>
    <td><span class="invoice-pill <?= e($paymentClass) ?>"><?= e($paymentLabel) ?></span></td>
    <td>
        <div class="invoice-actions">
            <a class="btn btn-sm btn-light" href="<?= e(app_url('invoice-view.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="eye"></i> View</a>
            <?php if ($invoice['invoice_status'] === 'draft'): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('invoice-form.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="pencil"></i> Edit</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(app_url('invoice-print-viewer.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="printer"></i> Print</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="invoice-mobile-list">
<?php if (!$invoices): ?><div class="text-center text-muted py-5"><i data-lucide="receipt-text"></i><div class="fw-bold mt-2">No invoices found</div></div><?php endif; ?>
<?php foreach ($invoices as $invoice): ?>
<?php
$isOverdue = $invoice['invoice_status'] === 'issued'
    && (float)$invoice['balance_amount'] > 0
    && !empty($invoice['due_date'])
    && $invoice['due_date'] < date('Y-m-d');
$hospitalName = $invoice['parent_hospital_name'] ?: $invoice['client_name'];
$clientName = $invoice['parent_hospital_name'] ? $invoice['client_name'] : '';
$paymentLabel = $isOverdue ? 'Overdue' : $statusLabel($invoice['payment_status']);
$paymentClass = $isOverdue ? 'pay-overdue' : 'pay-' . $invoice['payment_status'];
?>
<article class="invoice-mobile-card">
    <div class="d-flex justify-content-between gap-2">
        <div><a class="invoice-number" href="<?= e(app_url('invoice-view.php?id=' . (int)$invoice['id'])) ?>"><?= e($invoice['invoice_number']) ?></a><small class="d-block text-muted"><?= e(date('d-m-Y', strtotime($invoice['invoice_date']))) ?></small></div>
        <span class="invoice-pill status-<?= e($invoice['invoice_status']) ?>"><?= e($statusLabel($invoice['invoice_status'])) ?></span>
    </div>
    <div class="mt-3"><strong><?= e($hospitalName) ?></strong><?php if ($clientName !== ''): ?><small class="d-block text-muted">Client: <?= e($clientName) ?></small><?php endif; ?></div>
    <div class="invoice-mobile-grid">
        <div><small>Total</small><strong>₹<?= number_format((float)$invoice['grand_total'], 2) ?></strong></div>
        <div><small>Balance</small><strong>₹<?= number_format((float)$invoice['balance_amount'], 2) ?></strong></div>
        <div><small>Patients / Cases</small><strong><?= number_format((float)($invoice['patient_count'] ?? 0), 0) ?></strong></div>
        <div><small>Payment</small><span class="invoice-pill <?= e($paymentClass) ?>"><?= e($paymentLabel) ?></span></div>
    </div>
    <div class="invoice-actions mt-3">
        <a class="btn btn-sm btn-light" href="<?= e(app_url('invoice-view.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="eye"></i> View</a>
        <?php if ($invoice['invoice_status'] === 'draft'): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('invoice-form.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="pencil"></i> Edit</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(app_url('invoice-print-viewer.php?id=' . (int)$invoice['id'])) ?>"><i data-lucide="printer"></i> Print</a>
    </div>
</article>
<?php endforeach; ?>
</div>

<?php if ($totalRows > 0): ?>
<div class="invoice-pagination">
    <small class="text-muted">Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?> invoices</small>
    <?php if ($totalPages > 1): ?>
    <nav><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($paginationUrl(max(1, $page - 1), $queryWithoutPage)) ?>">Previous</a></li>
        <?php for ($n = max(1, $page - 2); $n <= min($totalPages, $page + 2); $n++): ?>
            <li class="page-item <?= $n === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($paginationUrl($n, $queryWithoutPage)) ?>"><?= $n ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($paginationUrl(min($totalPages, $page + 1), $queryWithoutPage)) ?>">Next</a></li>
    </ul></nav>
    <?php endif; ?>
</div>
<?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const hospital = document.getElementById('hospitalFilter');
    const client = document.getElementById('clientFilter');

    hospital.addEventListener('change', () => {
        const hospitalId = hospital.value;

        Array.from(client.options).forEach(option => {
            if (option.value === '0') {
                option.hidden = false;
                return;
            }

            option.hidden = hospitalId !== '0'
                && option.dataset.hospitalId !== hospitalId;
        });

        const selected = client.options[client.selectedIndex];
        if (selected && selected.hidden) {
            client.value = '0';
        }
    });

    hospital.dispatchEvent(new Event('change'));

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
