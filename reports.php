<?php
declare(strict_types=1);
$pageTitle = 'Business Reports';
require_once __DIR__ . '/includes/bootstrap.php';
include __DIR__ . '/includes/layout-start.php';
?>
<style>
.report-hub-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.report-hub-card{display:block;padding:22px;border:1px solid var(--border-soft);border-radius:18px;background:var(--card-bg);box-shadow:var(--shadow);color:var(--text-main);text-decoration:none;transition:.2s}
.report-hub-card:hover{transform:translateY(-3px);border-color:var(--brand);color:var(--text-main)}
.report-hub-icon{width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:var(--sidebar-active);color:var(--brand);margin-bottom:15px}
.report-hub-card h2{font-size:17px;font-weight:850;margin:0 0 7px}
.report-hub-card p{margin:0;color:var(--text-muted);font-size:13px}
@media(max-width:1100px){.report-hub-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767.98px){.report-hub-grid{grid-template-columns:1fr}}
</style>
<div class="page-head mb-3">
    <div><span class="badge-soft">REPORTS</span><h1 class="mt-2">Business Reports</h1><p class="mb-0 text-muted">Open a focused report with filters, sorting, drill-down and Excel export.</p></div>
</div>
<div class="report-hub-grid">
    <a class="report-hub-card" href="<?= e(app_url('hospital-wise-report.php')) ?>"><div class="report-hub-icon"><i data-lucide="hospital"></i></div><h2>Hospital-Wise Report</h2><p>Billing, collections, outstanding, overdue and collection percentage.</p></a>
    <a class="report-hub-card" href="<?= e(app_url('collection-report.php')) ?>"><div class="report-hub-icon"><i data-lucide="hand-coins"></i></div><h2>Collection Report</h2><p>Receipt-wise collection, allocation, payment mode and unallocated amount.</p></a>
    <a class="report-hub-card" href="<?= e(app_url('outstanding-report.php')) ?>"><div class="report-hub-icon"><i data-lucide="clock-alert"></i></div><h2>Outstanding Report</h2><p>Hospital balances, overdue invoices and ageing buckets.</p></a>
    <a class="report-hub-card" href="<?= e(app_url('profit-summary.php')) ?>"><div class="report-hub-icon"><i data-lucide="chart-no-axes-combined"></i></div><h2>Profit Summary</h2><p>Billed revenue, collections, expenses, net profit and margin.</p></a>
</div>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
