<?php
declare(strict_types=1);
$pageTitle='Dashboard';
require_once __DIR__ . '/includes/bootstrap.php';

$stats=['clients'=>0,'invoices'=>0,'billing'=>0,'received'=>0,'outstanding'=>0];
$recent=[];
if ($currentBusinessId>0) {
 $q=$pdo->prepare("SELECT COUNT(*) FROM clients WHERE business_id=? AND status='active'");$q->execute([$currentBusinessId]);$stats['clients']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM(grand_total),0) FROM invoices WHERE business_id=? AND invoice_date=CURDATE() AND invoice_status='issued'");$q->execute([$currentBusinessId]);[$stats['invoices'],$stats['billing']]=$q->fetch(PDO::FETCH_NUM);
 $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id=? AND payment_date=CURDATE() AND payment_status='posted'");$q->execute([$currentBusinessId]);$stats['received']=(float)$q->fetchColumn();
 if(table_exists($pdo,'vw_invoice_balances')){$q=$pdo->prepare("SELECT COALESCE(SUM(calculated_balance),0) FROM vw_invoice_balances WHERE business_id=? AND invoice_status='issued'");$q->execute([$currentBusinessId]);$stats['outstanding']=(float)$q->fetchColumn();}
 $q=$pdo->prepare("SELECT i.invoice_number,i.invoice_date,c.client_name,i.grand_total,i.payment_status FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.business_id=? ORDER BY i.id DESC LIMIT 5");$q->execute([$currentBusinessId]);$recent=$q->fetchAll();
}
$money=fn($v)=>'₹'.number_format((float)$v,2);
include __DIR__.'/includes/layout-start.php';
?>
<div class="page-head">
 <div><span class="badge-soft">OVERVIEW</span><h1 class="mt-2">Dashboard</h1><p>Welcome back, <?= e($_SESSION['full_name']??'User') ?>.</p></div>
 <a href="<?= e(app_url('invoices.php')) ?>" class="btn btn-brand px-3 py-2"><i data-lucide="plus"></i> New Invoice</a>
</div>
<?php if(!$currentBusiness): ?><div class="alert alert-warning">No active business is assigned to this account.</div><?php else: ?>
<div class="row g-3">
 <?php foreach([
  ['Clients',$stats['clients'],'hospital','#eee5ff'],
  ["Today's Invoices",$stats['invoices'],'receipt-text','#e8f1ff'],
  ["Today's Billing",$money($stats['billing']),'indian-rupee','#e8fbff'],
  ["Today's Received",$money($stats['received']),'wallet','#eaf9ee'],
  ['Outstanding',$money($stats['outstanding']),'hourglass','#fff4e7']
 ] as $s): ?>
 <div class="col-6 col-lg"><div class="card-ui kpi-card"><i data-lucide="<?= e($s[2]) ?>"></i><small><?= e($s[0]) ?></small><strong><?= e($s[1]) ?></strong></div></div>
 <?php endforeach; ?>
</div>
<div class="row g-3 mt-1">
 <div class="col-xl-8"><section class="card-ui p-3"><div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="h6 fw-bold mb-1">Recent Invoices</h2><small class="text-muted">Latest billing activity</small></div></div>
 <div class="desktop-table table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Invoice</th><th>Client</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>
 <?php if(!$recent): ?><tr><td colspan="5" class="text-center py-4 text-muted">No invoices yet.</td></tr><?php endif; ?>
 <?php foreach($recent as $r): ?><tr><td class="fw-bold"><?= e($r['invoice_number']) ?></td><td><?= e($r['client_name']) ?></td><td><?= e(date('d M Y',strtotime($r['invoice_date']))) ?></td><td><?= e($money($r['grand_total'])) ?></td><td><span class="badge-soft"><?= e(ucwords(str_replace('_',' ',$r['payment_status']))) ?></span></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <div class="mobile-card-list"><?php foreach($recent as $r): ?><article class="card-ui p-3"><div class="d-flex justify-content-between"><strong><?= e($r['invoice_number']) ?></strong><span class="badge-soft"><?= e(ucwords(str_replace('_',' ',$r['payment_status']))) ?></span></div><div class="mt-2"><?= e($r['client_name']) ?></div><small class="text-muted"><?= e(date('d M Y',strtotime($r['invoice_date']))) ?> · <?= e($money($r['grand_total'])) ?></small></article><?php endforeach; ?></div>
 </section></div>
 <div class="col-xl-4"><section class="card-ui p-3 h-100"><h2 class="h6 fw-bold">Quick Actions</h2><div class="d-grid gap-2 mt-3">
  <a class="btn btn-light text-start" href="<?= e(app_url('clients.php')) ?>">Add Client / Hospital</a>
  <a class="btn btn-light text-start" href="<?= e(app_url('services.php')) ?>">Add Service</a>
  <a class="btn btn-light text-start" href="<?= e(app_url('invoices.php')) ?>">Create Invoice</a>
  <a class="btn btn-light text-start" href="<?= e(app_url('payments.php')) ?>">Record Payment</a>
 </div></section></div>
</div>
<?php endif; ?>
<?php include __DIR__.'/includes/layout-end.php'; ?>
