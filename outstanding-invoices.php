<?php
declare(strict_types=1);

$pageTitle = 'Outstanding Invoices';

require_once __DIR__ . '/includes/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Filter values
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['q'] ?? ''));
$hospitalId = max(0, (int)($_GET['hospital_id'] ?? 0));
$clientId = max(0, (int)($_GET['client_id'] ?? 0));
$financialYearId = max(0, (int)($_GET['financial_year_id'] ?? 0));
$paymentStatus = trim((string)($_GET['payment_status'] ?? ''));
$ageBucket = trim((string)($_GET['age_bucket'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'due_date_asc'));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));

$allowedPaymentStatuses = [
    'unpaid',
    'partially_paid',
    'overdue',
];

$allowedAgeBuckets = [
    'not_due',
    '1_30',
    '31_60',
    '61_90',
    '90_plus',
];

$allowedSorts = [
    'due_date_asc',
    'due_date_desc',
    'balance_desc',
    'balance_asc',
    'invoice_date_desc',
    'invoice_date_asc',
];

if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
    $paymentStatus = '';
}

if (!in_array($ageBucket, $allowedAgeBuckets, true)) {
    $ageBucket = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'due_date_asc';
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

/*
|--------------------------------------------------------------------------
| Master filter options
|--------------------------------------------------------------------------
*/
$hospitalStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.client_code,
        c.client_name
     FROM clients c
     INNER JOIN client_types ct
        ON ct.id = c.client_type_id
     WHERE c.business_id = ?
       AND (
            ct.type_key = 'hospital'
            OR LOWER(ct.type_name) = 'hospital'
       )
     ORDER BY c.client_name"
);
$hospitalStmt->execute([$currentBusinessId]);
$hospitals = $hospitalStmt->fetchAll();

$clientSql =
    "SELECT
        c.id,
        c.client_code,
        c.client_name,
        c.parent_hospital_id,
        h.client_name AS hospital_name
     FROM clients c
     INNER JOIN client_types ct
        ON ct.id = c.client_type_id
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

$financialYearStmt = $pdo->prepare(
    "SELECT
        id,
        year_label,
        start_date,
        end_date,
        is_current,
        status
     FROM financial_years
     WHERE business_id = ?
     ORDER BY is_current DESC, start_date DESC"
);
$financialYearStmt->execute([$currentBusinessId]);
$financialYears = $financialYearStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Outstanding conditions
|--------------------------------------------------------------------------
*/
$where = [
    'i.business_id = ?',
    "i.invoice_status = 'issued'",
    'i.balance_amount > 0',
];

$params = [$currentBusinessId];

if ($search !== '') {
    $where[] =
        "(
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

    for ($index = 0; $index < 11; $index++) {
        $params[] = $like;
    }
}

if ($hospitalId > 0) {
    $where[] =
        "(
            i.client_id = ?
            OR c.parent_hospital_id = ?
        )";

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

if ($paymentStatus !== '') {
    if ($paymentStatus === 'overdue') {
        $where[] =
            "(
                i.due_date IS NOT NULL
                AND i.due_date < CURRENT_DATE()
            )";
    } else {
        $where[] = 'i.payment_status = ?';
        $params[] = $paymentStatus;
    }
}

if ($dateFrom !== '') {
    $where[] = 'i.invoice_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'i.invoice_date <= ?';
    $params[] = $dateTo;
}

if ($ageBucket === 'not_due') {
    $where[] =
        "(
            i.due_date IS NULL
            OR i.due_date >= CURRENT_DATE()
        )";
} elseif ($ageBucket === '1_30') {
    $where[] =
        "DATEDIFF(CURRENT_DATE(), i.due_date) BETWEEN 1 AND 30";
} elseif ($ageBucket === '31_60') {
    $where[] =
        "DATEDIFF(CURRENT_DATE(), i.due_date) BETWEEN 31 AND 60";
} elseif ($ageBucket === '61_90') {
    $where[] =
        "DATEDIFF(CURRENT_DATE(), i.due_date) BETWEEN 61 AND 90";
} elseif ($ageBucket === '90_plus') {
    $where[] =
        "DATEDIFF(CURRENT_DATE(), i.due_date) > 90";
}

$whereSql = implode(' AND ', $where);

$sortSql = match ($sort) {
    'due_date_desc' =>
        'i.due_date DESC, i.invoice_date DESC, i.id DESC',
    'balance_desc' =>
        'i.balance_amount DESC, i.due_date ASC, i.id DESC',
    'balance_asc' =>
        'i.balance_amount ASC, i.due_date ASC, i.id DESC',
    'invoice_date_desc' =>
        'i.invoice_date DESC, i.id DESC',
    'invoice_date_asc' =>
        'i.invoice_date ASC, i.id ASC',
    default =>
        'CASE WHEN i.due_date IS NULL THEN 1 ELSE 0 END,
         i.due_date ASC,
         i.invoice_date ASC,
         i.id ASC',
};

/*
|--------------------------------------------------------------------------
| Totals and pagination
|--------------------------------------------------------------------------
*/
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
       AND c.business_id = i.business_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     WHERE {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Outstanding invoice rows
|--------------------------------------------------------------------------
*/
$listStmt = $pdo->prepare(
    "SELECT
        i.*,
        c.client_code,
        c.client_name,
        c.parent_hospital_id,
        ct.type_name AS client_type_name,
        h.client_code AS parent_hospital_code,
        h.client_name AS parent_hospital_name,
        fy.year_label AS financial_year_label,
        CASE
            WHEN i.due_date IS NULL THEN 0
            ELSE GREATEST(
                DATEDIFF(CURRENT_DATE(), i.due_date),
                0
            )
        END AS overdue_days,
        CASE
            WHEN i.due_date IS NULL
              OR i.due_date >= CURRENT_DATE()
                THEN 'not_due'
            WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                BETWEEN 1 AND 30
                THEN '1_30'
            WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                BETWEEN 31 AND 60
                THEN '31_60'
            WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                BETWEEN 61 AND 90
                THEN '61_90'
            ELSE '90_plus'
        END AS age_bucket
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
       AND c.business_id = i.business_id
     INNER JOIN client_types ct
        ON ct.id = c.client_type_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     INNER JOIN financial_years fy
        ON fy.id = i.financial_year_id
       AND fy.business_id = i.business_id
     WHERE {$whereSql}
     ORDER BY {$sortSql}
     LIMIT {$perPage}
     OFFSET {$offset}"
);
$listStmt->execute($params);
$invoices = $listStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Summary cards
|--------------------------------------------------------------------------
*/
$summaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(i.grand_total), 0) AS billed_total,
        COALESCE(SUM(i.received_amount), 0) AS received_total,
        COALESCE(SUM(i.balance_amount), 0) AS outstanding_total,
        COALESCE(SUM(
            CASE
                WHEN i.due_date IS NOT NULL
                 AND i.due_date < CURRENT_DATE()
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS overdue_total,
        SUM(
            CASE
                WHEN i.due_date IS NOT NULL
                 AND i.due_date < CURRENT_DATE()
                THEN 1
                ELSE 0
            END
        ) AS overdue_count
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
       AND c.business_id = i.business_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     WHERE {$whereSql}"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [];

/*
|--------------------------------------------------------------------------
| Ageing summary for current filters except selected age bucket
|--------------------------------------------------------------------------
*/
$ageWhere = [
    'i.business_id = ?',
    "i.invoice_status = 'issued'",
    'i.balance_amount > 0',
];

$ageParams = [$currentBusinessId];

if ($search !== '') {
    $ageWhere[] =
        "(
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

    for ($index = 0; $index < 11; $index++) {
        $ageParams[] = $like;
    }
}

if ($hospitalId > 0) {
    $ageWhere[] =
        "(
            i.client_id = ?
            OR c.parent_hospital_id = ?
        )";

    $ageParams[] = $hospitalId;
    $ageParams[] = $hospitalId;
}

if ($clientId > 0) {
    $ageWhere[] = 'i.client_id = ?';
    $ageParams[] = $clientId;
}

if ($financialYearId > 0) {
    $ageWhere[] = 'i.financial_year_id = ?';
    $ageParams[] = $financialYearId;
}

if ($paymentStatus !== '') {
    if ($paymentStatus === 'overdue') {
        $ageWhere[] =
            "(
                i.due_date IS NOT NULL
                AND i.due_date < CURRENT_DATE()
            )";
    } else {
        $ageWhere[] = 'i.payment_status = ?';
        $ageParams[] = $paymentStatus;
    }
}

if ($dateFrom !== '') {
    $ageWhere[] = 'i.invoice_date >= ?';
    $ageParams[] = $dateFrom;
}

if ($dateTo !== '') {
    $ageWhere[] = 'i.invoice_date <= ?';
    $ageParams[] = $dateTo;
}

$ageWhereSql = implode(' AND ', $ageWhere);

$ageingStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(
            CASE
                WHEN i.due_date IS NULL
                  OR i.due_date >= CURRENT_DATE()
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS not_due,
        COALESCE(SUM(
            CASE
                WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                    BETWEEN 1 AND 30
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS age_1_30,
        COALESCE(SUM(
            CASE
                WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                    BETWEEN 31 AND 60
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS age_31_60,
        COALESCE(SUM(
            CASE
                WHEN DATEDIFF(CURRENT_DATE(), i.due_date)
                    BETWEEN 61 AND 90
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS age_61_90,
        COALESCE(SUM(
            CASE
                WHEN DATEDIFF(CURRENT_DATE(), i.due_date) > 90
                THEN i.balance_amount
                ELSE 0
            END
        ), 0) AS age_90_plus
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
       AND c.business_id = i.business_id
     LEFT JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     WHERE {$ageWhereSql}"
);
$ageingStmt->execute($ageParams);
$ageing = $ageingStmt->fetch() ?: [];

$statusLabel = static function (string $value): string {
    return ucwords(str_replace('_', ' ', $value));
};

$ageLabel = static function (string $bucket): string {
    return match ($bucket) {
        '1_30' => '1–30 Days',
        '31_60' => '31–60 Days',
        '61_90' => '61–90 Days',
        '90_plus' => 'Above 90 Days',
        default => 'Not Due',
    };
};

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

$buildPageUrl = static function (
    int $targetPage,
    array $query
): string {
    $query['page'] = $targetPage;

    return '?' . http_build_query($query);
};

$buildAgeUrl = static function (
    string $bucket,
    array $query
): string {
    unset($query['page']);

    if (($query['age_bucket'] ?? '') === $bucket) {
        unset($query['age_bucket']);
    } else {
        $query['age_bucket'] = $bucket;
    }

    return '?' . http_build_query($query);
};

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.outstanding-page {
    --out-radius: 18px;
}

/* Reference-style filters */
.outstanding-filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: 12px;
}

.outstanding-filter-bottom {
    display: grid;
    grid-template-columns: 1fr 1fr .75fr .9fr .7fr auto auto;
    gap: 12px;
    margin-top: 12px;
    align-items: end;
}

.outstanding-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.outstanding-stat {
    min-width: 0;
    padding: 16px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: var(--card-bg);
    box-shadow: var(--shadow);
}

.outstanding-stat small {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted);
    font-weight: 750;
}

.outstanding-stat strong {
    display: block;
    font-size: 20px;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.outstanding-stat.danger strong {
    color: var(--danger, #dc2626);
}

/* Ageing cards */
.ageing-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
}

.ageing-card {
    display: block;
    min-width: 0;
    padding: 13px;
    border: 1px solid var(--border-soft);
    border-radius: 14px;
    color: var(--text-main);
    background: var(--card-bg);
    text-decoration: none;
    transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
}

.ageing-card:hover {
    transform: translateY(-1px);
    border-color: var(--brand);
    box-shadow: var(--shadow);
}

.ageing-card.active {
    border-color: var(--brand);
    background: var(--sidebar-active);
}

.ageing-card small {
    display: block;
    color: var(--text-muted);
    font-weight: 750;
}

.ageing-card strong {
    display: block;
    margin-top: 5px;
    font-size: 16px;
    font-weight: 900;
}

/* Stable desktop table alignment */
.outstanding-desktop-table {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.outstanding-table {
    width: 100%;
    min-width: 1100px;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
}

.outstanding-table th,
.outstanding-table td {
    vertical-align: middle;
    white-space: nowrap;
}

.outstanding-table th {
    padding: 11px 10px;
    font-size: 11px;
    font-weight: 800;
}

.outstanding-table td {
    padding: 12px 10px;
}

.outstanding-table th:nth-child(2),
.outstanding-table td:nth-child(2),
.outstanding-table th:nth-child(3),
.outstanding-table td:nth-child(3) {
    white-space: normal;
}

.outstanding-table .col-invoice {
    width: 12%;
}

.outstanding-table .col-party {
    width: 19%;
}

.outstanding-table .col-patient {
    width: 14%;
}

.outstanding-table .col-date {
    width: 9%;
}

.outstanding-table .col-ageing {
    width: 12%;
}

.outstanding-table .col-money {
    width: 9%;
}

.outstanding-table .col-status {
    width: 9%;
}

.outstanding-table .col-actions {
    width: 10%;
}

.outstanding-number {
    font-weight: 900;
    text-decoration: none;
}

.outstanding-party {
    font-weight: 850;
    line-height: 1.35;
}

.outstanding-meta {
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11px;
}

.outstanding-amount,
.outstanding-balance {
    white-space: nowrap;
    font-weight: 850;
}

.outstanding-balance {
    color: var(--danger, #dc2626);
    font-weight: 950;
}

.outstanding-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    white-space: nowrap;
}

.outstanding-pill.unpaid {
    background: #fee2e2;
    color: #b91c1c;
}

.outstanding-pill.partially_paid {
    background: #fef3c7;
    color: #a16207;
}

.outstanding-pill.overdue {
    background: #ffedd5;
    color: #c2410c;
}

.outstanding-pill.not_due {
    background: #dbeafe;
    color: #1d4ed8;
}

.outstanding-pill.age_1_30 {
    background: #fef3c7;
    color: #a16207;
}

.outstanding-pill.age_31_60 {
    background: #ffedd5;
    color: #c2410c;
}

.outstanding-pill.age_61_90,
.outstanding-pill.age_90_plus {
    background: #fee2e2;
    color: #b91c1c;
}

.outstanding-overdue-row {
    background: color-mix(in srgb, #fff7ed 55%, transparent);
}

.outstanding-actions {
    display: flex;
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

.action-icon-btn svg {
    width: 16px;
    height: 16px;
}

.outstanding-mobile-list {
    display: none;
}

.outstanding-mobile-card {
    padding: 15px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: var(--card-bg);
}

.outstanding-mobile-card+.outstanding-mobile-card {
    margin-top: 12px;
}

.outstanding-mobile-values {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.outstanding-mobile-values small {
    display: block;
    color: var(--text-muted);
    font-weight: 700;
}

.outstanding-mobile-values strong {
    display: block;
    margin-top: 2px;
    overflow-wrap: anywhere;
}

.outstanding-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-top: 16px;
}

.outstanding-pagination .pagination {
    margin-bottom: 0;
}

@media (max-width: 1250px) {
    .outstanding-filter-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .outstanding-filter-bottom {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .outstanding-stats,
    .ageing-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {

    .outstanding-filter-grid,
    .outstanding-filter-bottom,
    .outstanding-stats,
    .ageing-grid {
        grid-template-columns: 1fr;
    }

    .outstanding-filter-bottom .btn {
        width: 100%;
    }

    .outstanding-desktop-table {
        display: none;
    }

    .outstanding-mobile-list {
        display: block;
    }

    .outstanding-pagination {
        align-items: stretch;
        flex-direction: column;
    }

    .outstanding-pagination .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }
}
</style>

<div class="outstanding-page">
    <div class="page-head">
        <div>
            <span class="badge-soft">INVOICES</span>

            <h1 class="mt-2">Outstanding Invoices</h1>

            <p>
                Track unpaid and partially paid hospital and client invoices.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light" href="<?= e(app_url('payments.php')) ?>">
                <i data-lucide="list-checks"></i>
                Payment List
            </a>

            <a class="btn btn-brand" href="<?= e(app_url('payment-form.php')) ?>">
                <i data-lucide="circle-dollar-sign"></i>
                Record Payment
            </a>
        </div>
    </div>

    <section class="card-ui p-3 p-lg-4 mb-3">
        <form method="get" id="outstandingFilterForm">
            <div class="outstanding-filter-grid">
                <div>
                    <label class="form-label fw-semibold">
                        Search
                    </label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i data-lucide="search"></i>
                        </span>

                        <input class="form-control" type="search" name="q" value="<?= e($search) ?>"
                            placeholder="Invoice, hospital, client, patient or reference...">
                    </div>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Hospital
                    </label>

                    <select class="form-select" name="hospital_id" id="hospitalFilter">
                        <option value="0">All Hospitals</option>

                        <?php foreach ($hospitals as $hospital): ?>
                        <option value="<?= (int)$hospital['id'] ?>" <?= $hospitalId === (int)$hospital['id']
                                    ? 'selected'
                                    : '' ?>>
                            <?= e(
                                    $hospital['client_code'] .
                                    ' - ' .
                                    $hospital['client_name']
                                ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Client
                    </label>

                    <select class="form-select" name="client_id" id="clientFilter">
                        <option value="0">All Clients</option>

                        <?php foreach ($clients as $client): ?>
                        <option value="<?= (int)$client['id'] ?>"
                            data-hospital-id="<?= (int)($client['parent_hospital_id'] ?? 0) ?>" <?= $clientId === (int)$client['id']
                                    ? 'selected'
                                    : '' ?>>
                            <?= e(
                                    $client['client_code'] .
                                    ' - ' .
                                    $client['client_name'] .
                                    (
                                        $client['hospital_name']
                                            ? ' / ' .
                                                $client['hospital_name']
                                            : ''
                                    )
                                ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Financial Year
                    </label>

                    <select class="form-select" name="financial_year_id">
                        <option value="0">
                            All Financial Years
                        </option>

                        <?php foreach ($financialYears as $financialYear): ?>
                        <option value="<?= (int)$financialYear['id'] ?>" <?= $financialYearId === (int)$financialYear['id']
                                    ? 'selected'
                                    : '' ?>>
                            <?= e($financialYear['year_label']) ?>
                            <?= $financialYear['is_current']
                                    ? ' (Current)'
                                    : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Payment Status
                    </label>

                    <select class="form-select" name="payment_status">
                        <option value="">All Outstanding</option>
                        <option value="unpaid" <?= $paymentStatus === 'unpaid'
                                ? 'selected'
                                : '' ?>>
                            Unpaid
                        </option>
                        <option value="partially_paid" <?= $paymentStatus === 'partially_paid'
                                ? 'selected'
                                : '' ?>>
                            Partially Paid
                        </option>
                        <option value="overdue" <?= $paymentStatus === 'overdue'
                                ? 'selected'
                                : '' ?>>
                            Overdue
                        </option>
                    </select>
                </div>
            </div>

            <div class="outstanding-filter-bottom">
                <div>
                    <label class="form-label fw-semibold">
                        Invoice From
                    </label>

                    <input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>">
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Invoice To
                    </label>

                    <input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>">
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Ageing
                    </label>

                    <select class="form-select" name="age_bucket">
                        <option value="">All Ageing</option>

                        <?php foreach ($allowedAgeBuckets as $bucket): ?>
                        <option value="<?= e($bucket) ?>" <?= $ageBucket === $bucket
                                    ? 'selected'
                                    : '' ?>>
                            <?= e($ageLabel($bucket)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Sort By
                    </label>

                    <select class="form-select" name="sort">
                        <option value="due_date_asc" <?= $sort === 'due_date_asc'
                                ? 'selected'
                                : '' ?>>
                            Due Date: Earliest
                        </option>
                        <option value="due_date_desc" <?= $sort === 'due_date_desc'
                                ? 'selected'
                                : '' ?>>
                            Due Date: Latest
                        </option>
                        <option value="balance_desc" <?= $sort === 'balance_desc'
                                ? 'selected'
                                : '' ?>>
                            Balance: High to Low
                        </option>
                        <option value="balance_asc" <?= $sort === 'balance_asc'
                                ? 'selected'
                                : '' ?>>
                            Balance: Low to High
                        </option>
                        <option value="invoice_date_desc" <?= $sort === 'invoice_date_desc'
                                ? 'selected'
                                : '' ?>>
                            Invoice Date: Latest
                        </option>
                        <option value="invoice_date_asc" <?= $sort === 'invoice_date_asc'
                                ? 'selected'
                                : '' ?>>
                            Invoice Date: Oldest
                        </option>
                    </select>
                </div>

                <div>
                    <label class="form-label fw-semibold">
                        Rows
                    </label>

                    <select class="form-select" name="per_page">
                        <?php foreach ([10, 20, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $perPage === $size
                                    ? 'selected'
                                    : '' ?>>
                            <?= $size ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="btn btn-brand" type="submit">
                    <i data-lucide="list-filter"></i>
                    Apply
                </button>

                <a class="btn btn-light" href="<?= e(app_url('outstanding-invoices.php')) ?>">
                    <i data-lucide="rotate-ccw"></i>
                    Reset
                </a>
            </div>
        </form>
    </section>

    <div class="outstanding-stats">
        <article class="outstanding-stat">
            <small>Outstanding Invoices</small>
            <strong>
                <?= number_format(
                    (int)($summary['invoice_count'] ?? 0)
                ) ?>
            </strong>
        </article>

        <article class="outstanding-stat">
            <small>Total Billed</small>
            <strong>
                ₹<?= number_format(
                    (float)($summary['billed_total'] ?? 0),
                    2
                ) ?>
            </strong>
        </article>

        <article class="outstanding-stat">
            <small>Total Received</small>
            <strong>
                ₹<?= number_format(
                    (float)($summary['received_total'] ?? 0),
                    2
                ) ?>
            </strong>
        </article>

        <article class="outstanding-stat">
            <small>Total Outstanding</small>
            <strong>
                ₹<?= number_format(
                    (float)($summary['outstanding_total'] ?? 0),
                    2
                ) ?>
            </strong>
        </article>

        <article class="outstanding-stat danger">
            <small>
                Overdue
                (<?= number_format(
                    (int)($summary['overdue_count'] ?? 0)
                ) ?>)
            </small>
            <strong>
                ₹<?= number_format(
                    (float)($summary['overdue_total'] ?? 0),
                    2
                ) ?>
            </strong>
        </article>
    </div>

    <section class="card-ui p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <div class="fw-bold">Outstanding Ageing</div>
                <small class="text-muted">
                    Click a bucket to filter the invoice list.
                </small>
            </div>

            <?php if ($ageBucket !== ''): ?>
            <a class="btn btn-sm btn-light" href="<?= e(
                        $buildAgeUrl(
                            $ageBucket,
                            $_GET
                        )
                    ) ?>">
                Clear Ageing
            </a>
            <?php endif; ?>
        </div>

        <div class="ageing-grid">
            <?php
            $ageCards = [
                'not_due' => [
                    'Not Due',
                    (float)($ageing['not_due'] ?? 0),
                ],
                '1_30' => [
                    '1–30 Days',
                    (float)($ageing['age_1_30'] ?? 0),
                ],
                '31_60' => [
                    '31–60 Days',
                    (float)($ageing['age_31_60'] ?? 0),
                ],
                '61_90' => [
                    '61–90 Days',
                    (float)($ageing['age_61_90'] ?? 0),
                ],
                '90_plus' => [
                    'Above 90 Days',
                    (float)($ageing['age_90_plus'] ?? 0),
                ],
            ];
            ?>

            <?php foreach ($ageCards as $bucket => [$label, $amount]): ?>
            <a class="ageing-card <?= $ageBucket === $bucket
                        ? 'active'
                        : '' ?>" href="<?= e(
                        $buildAgeUrl(
                            $bucket,
                            $_GET
                        )
                    ) ?>">
                <small><?= e($label) ?></small>
                <strong>
                    ₹<?= number_format($amount, 2) ?>
                </strong>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card-ui p-3 outstanding-table-card">
        <div class="outstanding-desktop-table table-responsive">
            <table class="table align-middle mb-0 outstanding-table">
                <colgroup>
                    <col class="col-invoice">
                    <col class="col-party">
                    <col class="col-patient">
                    <col class="col-date">
                    <col class="col-date">
                    <col class="col-ageing">
                    <col class="col-money">
                    <col class="col-money">
                    <col class="col-money">
                    <col class="col-status">
                    <col class="col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Hospital / Client</th>
                        <th>Patient / Reference</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Ageing</th>
                        <th>Total</th>
                        <th>Received</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            <i data-lucide="badge-check"></i>

                            <div class="fw-bold mt-2">
                                No outstanding invoices found
                            </div>

                            <small>
                                All matching issued invoices are fully paid.
                            </small>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($invoices as $invoice): ?>
                    <?php
                        $isOverdue =
                            !empty($invoice['due_date'])
                            && $invoice['due_date'] < date('Y-m-d');

                        $displayHospital =
                            $invoice['parent_hospital_name']
                            ?: $invoice['client_name'];

                        $displayClient =
                            $invoice['parent_hospital_name']
                            ? $invoice['client_name']
                            : '';

                        $displayPaymentStatus = $isOverdue
                            ? 'Overdue'
                            : $statusLabel(
                                (string)$invoice['payment_status']
                            );

                        $paymentClass = $isOverdue
                            ? 'overdue'
                            : (string)$invoice['payment_status'];

                        $ageClass =
                            'age_' . (string)$invoice['age_bucket'];
                        ?>

                    <tr class="<?= $isOverdue
                            ? 'outstanding-overdue-row'
                            : '' ?>">
                        <td>
                            <a class="outstanding-number" href="<?= e(
                                        app_url(
                                            'invoice-view.php?id=' .
                                            (int)$invoice['id']
                                        )
                                    ) ?>">
                                <?= e($invoice['invoice_number']) ?>
                            </a>

                            <small class="d-block text-muted">
                                FY:
                                <?= e(
                                        $invoice['financial_year_label']
                                    ) ?>
                            </small>
                        </td>

                        <td>
                            <div class="outstanding-party">
                                <?= e($displayHospital) ?>
                            </div>

                            <?php if ($displayClient !== ''): ?>
                            <div class="outstanding-meta">
                                Client:
                                <?= e($displayClient) ?>
                            </div>
                            <?php endif; ?>

                            <div class="outstanding-meta">
                                <?= e($invoice['client_code']) ?>
                                ·
                                <?= e($invoice['client_type_name']) ?>
                            </div>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                        $invoice['patient_name']
                                        ?: '—'
                                    ) ?>
                            </strong>

                            <?php if (
                                    $invoice['patient_reference_no']
                                ): ?>
                            <small class="d-block text-muted">
                                <?= e(
                                            $invoice[
                                                'patient_reference_no'
                                            ]
                                        ) ?>
                            </small>
                            <?php endif; ?>

                            <?php if (
                                    $invoice['hospital_reference_no']
                                ): ?>
                            <small class="d-block text-muted">
                                Hospital Ref:
                                <?= e(
                                            $invoice[
                                                'hospital_reference_no'
                                            ]
                                        ) ?>
                            </small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= e(
                                    date(
                                        'd-m-Y',
                                        strtotime(
                                            $invoice['invoice_date']
                                        )
                                    )
                                ) ?>
                        </td>

                        <td>
                            <?php if ($invoice['due_date']): ?>
                            <span class="<?= $isOverdue
                                        ? 'text-danger fw-bold'
                                        : '' ?>">
                                <?= e(
                                            date(
                                                'd-m-Y',
                                                strtotime(
                                                    $invoice['due_date']
                                                )
                                            )
                                        ) ?>
                            </span>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="outstanding-pill <?= e(
                                    $ageClass
                                ) ?>">
                                <?= e(
                                        $ageLabel(
                                            (string)$invoice['age_bucket']
                                        )
                                    ) ?>
                            </span>

                            <?php if (
                                    (int)$invoice['overdue_days'] > 0
                                ): ?>
                            <small class="d-block text-danger mt-1">
                                <?= number_format(
                                            (int)$invoice['overdue_days']
                                        ) ?>
                                days overdue
                            </small>
                            <?php endif; ?>
                        </td>

                        <td class="outstanding-amount">
                            ₹<?= number_format(
                                    (float)$invoice['grand_total'],
                                    2
                                ) ?>
                        </td>

                        <td class="outstanding-amount">
                            ₹<?= number_format(
                                    (float)$invoice['received_amount'],
                                    2
                                ) ?>
                        </td>

                        <td class="outstanding-balance">
                            ₹<?= number_format(
                                    (float)$invoice['balance_amount'],
                                    2
                                ) ?>
                        </td>

                        <td>
                            <span class="outstanding-pill <?= e(
                                    $paymentClass
                                ) ?>">
                                <?= e($displayPaymentStatus) ?>
                            </span>
                        </td>

                        <td>
                            <div class="outstanding-actions">
                                <a class="btn btn-sm btn-light action-icon-btn" title="View" href="<?= e(
                                            app_url(
                                                'invoice-view.php?id=' .
                                                (int)$invoice['id']
                                            )
                                        ) ?>">
                                    <i data-lucide="eye"></i>
                                </a>

                                <a class="btn btn-sm btn-brand action-icon-btn" title="Record Payment" href="<?= e(
                                            app_url(
                                                'payment-form.php?' .
                                                http_build_query([
                                                    'invoice_id' =>
                                                        (int)$invoice['id'],
                                                    'client_id' =>
                                                        (int)$invoice['client_id'],
                                                ])
                                            )
                                        ) ?>">
                                    <i data-lucide="circle-dollar-sign"></i>
                                </a>

                                <a class="btn btn-sm btn-outline-secondary action-icon-btn" title="Print" href="<?= e(
                                            app_url(
                                                'invoice-print-viewer.php?id=' .
                                                (int)$invoice['id']
                                            )
                                        ) ?>" target="_blank">
                                    <i data-lucide="printer"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="outstanding-mobile-list">
            <?php if (!$invoices): ?>
            <div class="text-center text-muted py-5">
                <i data-lucide="badge-check"></i>

                <div class="fw-bold mt-2">
                    No outstanding invoices found
                </div>
            </div>
            <?php endif; ?>

            <?php foreach ($invoices as $invoice): ?>
            <?php
                $isOverdue =
                    !empty($invoice['due_date'])
                    && $invoice['due_date'] < date('Y-m-d');

                $displayHospital =
                    $invoice['parent_hospital_name']
                    ?: $invoice['client_name'];

                $displayClient =
                    $invoice['parent_hospital_name']
                    ? $invoice['client_name']
                    : '';

                $paymentClass = $isOverdue
                    ? 'overdue'
                    : (string)$invoice['payment_status'];

                $displayPaymentStatus = $isOverdue
                    ? 'Overdue'
                    : $statusLabel(
                        (string)$invoice['payment_status']
                    );
                ?>

            <article class="outstanding-mobile-card">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <a class="outstanding-number" href="<?= e(
                                    app_url(
                                        'invoice-view.php?id=' .
                                        (int)$invoice['id']
                                    )
                                ) ?>">
                            <?= e($invoice['invoice_number']) ?>
                        </a>

                        <small class="d-block text-muted">
                            <?= e(
                                    date(
                                        'd-m-Y',
                                        strtotime(
                                            $invoice['invoice_date']
                                        )
                                    )
                                ) ?>
                        </small>
                    </div>

                    <span class="outstanding-pill <?= e(
                            $paymentClass
                        ) ?>">
                        <?= e($displayPaymentStatus) ?>
                    </span>
                </div>

                <div class="mt-3">
                    <strong><?= e($displayHospital) ?></strong>

                    <?php if ($displayClient !== ''): ?>
                    <small class="d-block text-muted">
                        Client: <?= e($displayClient) ?>
                    </small>
                    <?php endif; ?>
                </div>

                <div class="outstanding-mobile-values">
                    <div>
                        <small>Total</small>
                        <strong>
                            ₹<?= number_format(
                                    (float)$invoice['grand_total'],
                                    2
                                ) ?>
                        </strong>
                    </div>

                    <div>
                        <small>Received</small>
                        <strong>
                            ₹<?= number_format(
                                    (float)$invoice['received_amount'],
                                    2
                                ) ?>
                        </strong>
                    </div>

                    <div>
                        <small>Balance</small>
                        <strong class="text-danger">
                            ₹<?= number_format(
                                    (float)$invoice['balance_amount'],
                                    2
                                ) ?>
                        </strong>
                    </div>

                    <div>
                        <small>Due Date</small>
                        <strong class="<?= $isOverdue
                                ? 'text-danger'
                                : '' ?>">
                            <?= $invoice['due_date']
                                    ? e(
                                        date(
                                            'd-m-Y',
                                            strtotime(
                                                $invoice['due_date']
                                            )
                                        )
                                    )
                                    : '—' ?>
                        </strong>
                    </div>

                    <div>
                        <small>Patient</small>
                        <strong>
                            <?= e(
                                    $invoice['patient_name']
                                    ?: '—'
                                ) ?>
                        </strong>
                    </div>

                    <div>
                        <small>Ageing</small>
                        <strong>
                            <?= e(
                                    $ageLabel(
                                        (string)$invoice['age_bucket']
                                    )
                                ) ?>
                        </strong>
                    </div>
                </div>

                <div class="outstanding-actions mt-3">
                    <a class="btn btn-sm btn-light action-icon-btn" title="View" href="<?= e(
                                app_url(
                                    'invoice-view.php?id=' .
                                    (int)$invoice['id']
                                )
                            ) ?>">
                        <i data-lucide="eye"></i>
                    </a>

                    <a class="btn btn-sm btn-brand action-icon-btn" title="Record Payment" href="<?= e(
                                app_url(
                                    'payment-form.php?' .
                                    http_build_query([
                                        'invoice_id' =>
                                            (int)$invoice['id'],
                                        'client_id' =>
                                            (int)$invoice['client_id'],
                                    ])
                                )
                            ) ?>">
                        <i data-lucide="circle-dollar-sign"></i>
                    </a>

                    <a class="btn btn-sm btn-outline-secondary action-icon-btn" title="Print" href="<?= e(
                                app_url(
                                    'invoice-print-viewer.php?id=' .
                                    (int)$invoice['id']
                                )
                            ) ?>" target="_blank">
                        <i data-lucide="printer"></i>
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalRows > 0): ?>
        <div class="outstanding-pagination">
            <small class="text-muted">
                Showing
                <?= number_format($offset + 1) ?>
                to
                <?= number_format(
                        min(
                            $offset + $perPage,
                            $totalRows
                        )
                    ) ?>
                of
                <?= number_format($totalRows) ?>
                outstanding invoices
            </small>

            <?php if ($totalPages > 1): ?>
            <nav aria-label="Outstanding invoice pagination">
                <ul class="pagination pagination-sm">
                    <li class="page-item <?= $page <= 1
                                ? 'disabled'
                                : '' ?>">
                        <a class="page-link" href="<?= e(
                                        $buildPageUrl(
                                            max(1, $page - 1),
                                            $queryWithoutPage
                                        )
                                    ) ?>">
                            Previous
                        </a>
                    </li>

                    <?php
                            $pageStart = max(1, $page - 2);
                            $pageEnd = min(
                                $totalPages,
                                $page + 2
                            );
                            ?>

                    <?php if ($pageStart > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= e(
                                            $buildPageUrl(
                                                1,
                                                $queryWithoutPage
                                            )
                                        ) ?>">
                            1
                        </a>
                    </li>

                    <?php if ($pageStart > 2): ?>
                    <li class="page-item disabled">
                        <span class="page-link">…</span>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php for (
                                $pageNumber = $pageStart;
                                $pageNumber <= $pageEnd;
                                $pageNumber++
                            ): ?>
                    <li class="page-item <?= $pageNumber === $page
                                    ? 'active'
                                    : '' ?>">
                        <a class="page-link" href="<?= e(
                                            $buildPageUrl(
                                                $pageNumber,
                                                $queryWithoutPage
                                            )
                                        ) ?>">
                            <?= $pageNumber ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($pageEnd < $totalPages): ?>
                    <?php if (
                                    $pageEnd < $totalPages - 1
                                ): ?>
                    <li class="page-item disabled">
                        <span class="page-link">…</span>
                    </li>
                    <?php endif; ?>

                    <li class="page-item">
                        <a class="page-link" href="<?= e(
                                            $buildPageUrl(
                                                $totalPages,
                                                $queryWithoutPage
                                            )
                                        ) ?>">
                            <?= $totalPages ?>
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="page-item <?= $page >= $totalPages
                                ? 'disabled'
                                : '' ?>">
                        <a class="page-link" href="<?= e(
                                        $buildPageUrl(
                                            min(
                                                $totalPages,
                                                $page + 1
                                            ),
                                            $queryWithoutPage
                                        )
                                    ) ?>">
                            Next
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const hospitalFilter =
        document.getElementById('hospitalFilter');

    const clientFilter =
        document.getElementById('clientFilter');

    hospitalFilter.addEventListener('change', () => {
        const hospitalId = hospitalFilter.value;

        Array.from(clientFilter.options).forEach(option => {
            if (!option.value || option.value === '0') {
                option.hidden = false;
                return;
            }

            option.hidden =
                hospitalId !== '0' &&
                option.dataset.hospitalId !== hospitalId;
        });

        const selected =
            clientFilter.options[clientFilter.selectedIndex];

        if (selected && selected.hidden) {
            clientFilter.value = '0';
        }
    });

    hospitalFilter.dispatchEvent(
        new Event('change')
    );

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>