<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$allowedReports = [
    'hospital_wise',
    'collection',
    'outstanding',
    'profit',
];

if (!isset($reportKey) || !in_array($reportKey, $allowedReports, true)) {
    http_response_code(404);
    exit('Invalid report.');
}

$clientsStmt = $pdo->prepare(
    "SELECT id, client_code, client_name, district
     FROM clients
     WHERE business_id = ?
       AND status = 'active'
     ORDER BY client_name"
);
$clientsStmt->execute([$currentBusinessId]);
$reportClients = $clientsStmt->fetchAll();

$fyStmt = $pdo->prepare(
    "SELECT id, year_label, start_date, end_date, is_current
     FROM financial_years
     WHERE business_id = ?
     ORDER BY is_current DESC, start_date DESC"
);
$fyStmt->execute([$currentBusinessId]);
$reportFinancialYears = $fyStmt->fetchAll();

$currentFy = null;
foreach ($reportFinancialYears as $fy) {
    if ((int)$fy['is_current'] === 1) {
        $currentFy = $fy;
        break;
    }
}
$defaultFrom = $currentFy['start_date'] ?? date('Y-m-01');
$defaultTo = date('Y-m-d');

include __DIR__ . '/layout-start.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.2.0/css/buttons.bootstrap5.min.css">
<style>
.report-page .report-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.report-page .report-head h1{margin:8px 0 4px;font-weight:850}
.report-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
.report-tabs a{padding:9px 13px;border:1px solid var(--border-soft);border-radius:12px;background:var(--card-bg);color:var(--text-main);text-decoration:none;font-weight:750}
.report-tabs a.active{background:var(--brand);border-color:var(--brand);color:#fff}
.filter-card{padding:17px;margin-bottom:16px}
.kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:16px}
.kpi-card{padding:16px;border:1px solid var(--border-soft);border-radius:16px;background:var(--card-bg);box-shadow:var(--shadow)}
.kpi-card small{display:block;color:var(--text-muted);font-weight:700}
.kpi-card strong{display:block;margin-top:5px;font-size:20px;overflow-wrap:anywhere}
.report-table-card{padding:15px}
.report-table th{white-space:nowrap}
.report-table td{vertical-align:middle;font-weight:400}
.report-table td *,
.report-table td strong,
.report-table td .fw-bold{font-weight:400!important}
.report-table th{font-weight:700}
.report-table .money{font-weight:400}
.report-table-card .dt-container .dt-search input,
.report-table-card .dt-container .dt-length select{
    min-height:38px;
    border:1px solid var(--border-soft);
    border-radius:10px;
    padding:6px 10px;
}
.report-table-card .dt-container .dt-search{
    display:flex;
    align-items:center;
    gap:8px;
}
.report-table-card .dt-container .dt-length{
    display:flex;
    align-items:center;
    gap:8px;
}
.report-loading{padding:45px;text-align:center;color:var(--text-muted)}
.money{text-align:right;white-space:nowrap;font-weight:700}
.report-status{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:11px;font-weight:800}
.report-status.good{background:rgba(25,135,84,.12);color:#198754}
.report-status.warn{background:rgba(255,193,7,.16);color:#8a6500}
.report-status.danger{background:rgba(220,53,69,.12);color:#dc3545}
.report-status.neutral{background:var(--body-bg);color:var(--text-muted)}
.dt-buttons .btn{margin-right:6px}
@media(max-width:1250px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767.98px){.report-page .report-head{flex-direction:column}.report-page .report-head .btn{width:100%}.kpi-grid{grid-template-columns:1fr 1fr}.report-tabs{display:grid;grid-template-columns:1fr 1fr}.report-tabs a{text-align:center}}
@media(max-width:480px){.kpi-grid,.report-tabs{grid-template-columns:1fr}}
</style>

<div class="report-page">
    <div class="report-head">
        <div>
            <span class="badge-soft">REPORTS</span>
            <h1><?= e($reportTitle) ?></h1>
            <p class="mb-0 text-muted"><?= e($reportDescription) ?></p>
        </div>
        <a class="btn btn-light" href="<?= e(app_url('reports.php')) ?>"><i data-lucide="layout-grid"></i> All Reports</a>
    </div>

    <nav class="report-tabs">
        <a class="<?= $reportKey === 'hospital_wise' ? 'active' : '' ?>" href="<?= e(app_url('hospital-wise-report.php')) ?>">Hospital-Wise</a>
        <a class="<?= $reportKey === 'collection' ? 'active' : '' ?>" href="<?= e(app_url('collection-report.php')) ?>">Collections</a>
        <a class="<?= $reportKey === 'outstanding' ? 'active' : '' ?>" href="<?= e(app_url('outstanding-report.php')) ?>">Outstanding</a>
        <a class="<?= $reportKey === 'profit' ? 'active' : '' ?>" href="<?= e(app_url('profit-summary.php')) ?>">Profit Summary</a>
    </nav>

    <section class="card-ui filter-card">
        <form id="reportFilterForm" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="<?= e($reportKey) ?>">
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">From Date</label>
                <input class="form-control" type="date" name="date_from" value="<?= e($defaultFrom) ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">To Date</label>
                <input class="form-control" type="date" name="date_to" value="<?= e($defaultTo) ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label fw-semibold">Financial Year</label>
                <select class="form-select" name="financial_year_id" id="financialYearFilter">
                    <option value="0">Custom dates</option>
                    <?php foreach ($reportFinancialYears as $fy): ?>
                        <option value="<?= (int)$fy['id'] ?>" data-start="<?= e($fy['start_date']) ?>" data-end="<?= e($fy['end_date']) ?>">
                            <?= e($fy['year_label']) ?><?= $fy['is_current'] ? ' (Current)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label fw-semibold">Hospital</label>
                <select class="form-select" name="client_id">
                    <option value="0">All hospitals</option>
                    <?php foreach ($reportClients as $client): ?>
                        <option value="<?= (int)$client['id'] ?>"><?= e(($client['client_code'] ?? '') . ' - ' . $client['client_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-1">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <?php if ($reportKey === 'collection'): ?>
                        <option value="posted">Posted</option><option value="reversed">Reversed</option>
                    <?php elseif ($reportKey === 'outstanding'): ?>
                        <option value="all_outstanding">Outstanding</option><option value="overdue">Overdue</option><option value="not_due">Not Due</option>
                    <?php else: ?>
                        <option value="active">Active</option><option value="with_activity">With Activity</option><option value="no_activity">No Activity</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label fw-semibold">Sort</label>
                <select class="form-select" name="sort">
                    <?php if ($reportKey === 'hospital_wise'): ?>
                        <option value="outstanding_desc">Outstanding: High to Low</option>
                        <option value="invoiced_desc">Invoiced: High to Low</option>
                        <option value="collected_desc">Collected: High to Low</option>
                        <option value="hospital_asc">Hospital: A–Z</option>
                    <?php elseif ($reportKey === 'collection'): ?>
                        <option value="date_desc">Latest Payment</option>
                        <option value="amount_desc">Amount: High to Low</option>
                        <option value="hospital_asc">Hospital: A–Z</option>
                    <?php elseif ($reportKey === 'outstanding'): ?>
                        <option value="outstanding_desc">Outstanding: High to Low</option>
                        <option value="overdue_desc">Oldest Overdue</option>
                        <option value="due_asc">Due Date: Earliest</option>
                        <option value="hospital_asc">Hospital: A–Z</option>
                    <?php else: ?>
                        <option value="month_asc">Month: Old to New</option>
                        <option value="profit_desc">Profit: High to Low</option>
                        <option value="revenue_desc">Revenue: High to Low</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                <button class="btn btn-light" type="button" id="resetReport"><i data-lucide="rotate-ccw"></i> Reset</button>
                <button class="btn btn-outline-success" type="button" id="excelExport"><i data-lucide="file-spreadsheet"></i> Excel Export</button>
                <button class="btn btn-brand" type="submit"><i data-lucide="filter"></i> Apply Filters</button>
            </div>
        </form>
    </section>

    <div class="kpi-grid" id="reportKpis"></div>

    <section class="card-ui report-table-card">
        <div id="reportTableWrap"><div class="report-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading report...</div></div>
    </section>
</div>

<div class="modal fade" id="reportDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <div class="modal-header"><h5 class="modal-title" id="reportDetailsTitle">Details</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="reportDetailsBody"></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('reportFilterForm');
    const tableWrap = document.getElementById('reportTableWrap');
    const kpis = document.getElementById('reportKpis');
    let dataTable = null;

    const money = value => '₹' + Number(value || 0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    const escapeHtml = value => { const d=document.createElement('div');d.textContent=String(value??'');return d.innerHTML; };

    function params() {
        return new URLSearchParams(new FormData(form));
    }

    let liveFilterTimer = 0;
    let activeLoadController = null;

    function scheduleLiveLoad(delay = 0) {
        window.clearTimeout(liveFilterTimer);

        liveFilterTimer = window.setTimeout(() => {
            loadReport();
        }, delay);
    }

    async function loadReport() {
        if (dataTable) { dataTable.destroy(); dataTable = null; }
        tableWrap.innerHTML = '<div class="report-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading report...</div>';
        try {
            if (activeLoadController) {
                activeLoadController.abort();
            }

            activeLoadController = new AbortController();

            const url = new URL(<?= json_encode(app_url('api/reports.php'), JSON_UNESCAPED_SLASHES) ?>, window.location.origin);
            params().forEach((v,k)=>url.searchParams.set(k,v));
            url.searchParams.set('action','data');
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: activeLoadController.signal
            });
            const raw = await response.text();
            let result; try{result=JSON.parse(raw)}catch{throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}
            if(!result.success) throw new Error(result.message);

            kpis.innerHTML = result.data.kpis.map(k=>`<div class="kpi-card"><small>${escapeHtml(k.label)}</small><strong>${k.type==='money'?money(k.value):escapeHtml(k.display ?? k.value)}</strong></div>`).join('');
            const headers = result.data.columns.map(c=>`<th class="${c.align==='right'?'text-end':c.align==='center'?'text-center':''}">${escapeHtml(c.label)}</th>`).join('');
            const rows = result.data.rows.map(row=>`<tr>${result.data.columns.map(c=>`<td class="${c.align==='right'?'money':c.align==='center'?'text-center':''}">${formatCell(row[c.key],c,row)}</td>`).join('')}</tr>`).join('');
            tableWrap.innerHTML = `<div class="table-responsive"><table class="table report-table align-middle w-100" id="reportTable"><thead><tr>${headers}</tr></thead><tbody>${rows || `<tr><td colspan="${result.data.columns.length}" class="text-center text-muted py-5">No records found.</td></tr>`}</tbody></table></div>`;
            if(result.data.rows.length){
                dataTable = new DataTable('#reportTable', {
                    pageLength: 25,
                    lengthMenu: [10, 25, 50, 100],
                    order: [],
                    scrollX: true,
                    autoWidth: false,
                    layout: {
                        topStart: 'pageLength',
                        topEnd: 'search',
                        bottomStart: 'info',
                        bottomEnd: 'paging'
                    },
                    language: {
                        search: 'Search:',
                        lengthMenu: '_MENU_ entries per page',
                        emptyTable: 'No records found.'
                    }
                });

                /*
                 * DataTables already filters locally. This explicit binding
                 * keeps the search functional even when a theme or another
                 * script interferes with DataTables' delegated input event.
                 */
                const dataTableSearch = document.querySelector(
                    '#reportTable_wrapper .dt-search input'
                );

                dataTableSearch?.addEventListener('input', event => {
                    dataTable.search(event.target.value).draw();
                });
            }
            bindDetails();
        } catch(error) {
            if (error.name === 'AbortError') {
                return;
            }

            kpis.innerHTML = '';
            tableWrap.innerHTML =
                `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}</div>`;
        }
    }

    function formatCell(value,column,row){
        if(column.type==='money') return money(value);
        if(column.type==='percent') return Number(value||0).toFixed(2)+'%';
        if(column.type==='status'){
            const status=String(value||'');
            const cls=['Paid','Clear','Profit','Posted'].includes(status)?'good':['Overdue','Loss','Reversed'].includes(status)?'danger':['Partial','Outstanding'].includes(status)?'warn':'neutral';
            return `<span class="report-status ${cls}">${escapeHtml(status)}</span>`;
        }
        if(column.type==='action') return `<button class="btn btn-sm btn-outline-primary report-detail" data-client-id="${row.client_id||0}" data-id="${row.id||0}">View</button>`;
        return escapeHtml(value ?? '—');
    }

    function bindDetails(){
        document.querySelectorAll('.report-detail').forEach(button=>button.addEventListener('click',async()=>{
            const modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('reportDetailsModal'));
            const body=document.getElementById('reportDetailsBody');
            body.innerHTML='<div class="text-center py-5"><span class="spinner-border"></span></div>';modal.show();
            try{
                const url=new URL(<?= json_encode(app_url('api/reports.php'), JSON_UNESCAPED_SLASHES) ?>,window.location.origin);
                params().forEach((v,k)=>url.searchParams.set(k,v));
                url.searchParams.set('action','details');
                url.searchParams.set('client_id',button.dataset.clientId||'0');
                url.searchParams.set('id',button.dataset.id||'0');
                const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
                const result=await response.json();if(!result.success)throw new Error(result.message);
                document.getElementById('reportDetailsTitle').textContent=result.data.title;
                body.innerHTML=result.data.html;
            }catch(error){body.innerHTML=`<div class="alert alert-danger">${escapeHtml(error.message)}</div>`}
        }));
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        scheduleLiveLoad(0);
    });

    form.querySelectorAll(
        'select[name]:not(#financialYearFilter), input[type="date"][name]'
    ).forEach(control => {
        control.addEventListener('change', () => scheduleLiveLoad(0));
    });

    document.getElementById('excelExport').addEventListener('click', () => {
        const url = new URL(
            <?= json_encode(app_url('api/reports.php'), JSON_UNESCAPED_SLASHES) ?>,
            window.location.origin
        );

        params().forEach((value, key) => url.searchParams.set(key, value));
        url.searchParams.set('action', 'export');
        window.location.href = url.toString();
    });

    document.getElementById('resetReport').addEventListener('click', () => {
        form.reset();
        scheduleLiveLoad(0);
    });

    document.getElementById('financialYearFilter').addEventListener(
        'change',
        event => {
            const option =
                event.target.options[event.target.selectedIndex];

            if (option?.dataset.start) {
                form.elements.date_from.value = option.dataset.start;
                form.elements.date_to.value = option.dataset.end;
            }

            scheduleLiveLoad(0);
        }
    );

    loadReport();
    if(window.lucide)lucide.createIcons();
});
</script>
<?php include __DIR__ . '/layout-end.php'; ?>
