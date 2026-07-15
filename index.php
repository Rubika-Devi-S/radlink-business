<?php
declare(strict_types=1);
$pageTitle='Dashboard';

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';

require_page_permission('dashboard','view');

$stats=[
'clients'=>0,'invoices'=>0,'billing'=>0,'received'=>0,
'outstanding'=>0,'month_billing'=>0,'month_received'=>0,
'pending_invoices'=>0
];

$recent=[];

if($currentBusinessId>0){

$q=$pdo->prepare("SELECT COUNT(*) FROM clients WHERE business_id=? AND status='active'");
$q->execute([$currentBusinessId]);
$stats['clients']=(int)$q->fetchColumn();

$q=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM(grand_total),0)
FROM invoices WHERE business_id=? AND invoice_date=CURDATE() AND invoice_status='issued'");
$q->execute([$currentBusinessId]);
[$stats['invoices'],$stats['billing']]=$q->fetch(PDO::FETCH_NUM);

$q=$pdo->prepare("SELECT COALESCE(SUM(amount),0)
FROM payments WHERE business_id=? AND payment_date=CURDATE() AND payment_status='posted'");
$q->execute([$currentBusinessId]);
$stats['received']=(float)$q->fetchColumn();

if(table_exists($pdo,'vw_invoice_balances')){
$q=$pdo->prepare("SELECT COALESCE(SUM(calculated_balance),0)
FROM vw_invoice_balances WHERE business_id=? AND invoice_status='issued'");
$q->execute([$currentBusinessId]);
$stats['outstanding']=(float)$q->fetchColumn();
}

$q=$pdo->prepare("SELECT COUNT(*) FROM invoices
WHERE business_id=? AND payment_status!='paid'");
$q->execute([$currentBusinessId]);
$stats['pending_invoices']=(int)$q->fetchColumn();


$q=$pdo->prepare("SELECT i.invoice_number,i.invoice_date,c.client_name,
i.grand_total,i.payment_status
FROM invoices i JOIN clients c ON c.id=i.client_id
WHERE i.business_id=? ORDER BY i.id DESC LIMIT 5");

$q->execute([$currentBusinessId]);
$recent=$q->fetchAll();

}

$money=fn($v)=>'₹'.number_format((float)$v,2);

$actions=[
['Add Hospital','client-list.php','hospital','clients','create'],
['Create Invoice','invoice-form.php','file-plus-2','invoices','create'],
['Payment Entry','payment-form.php','wallet','payments','create'],
['Add Service','services.php','scan-line','services','create'],
['Reports','reports.php','chart-column','reports','view'],
];

include __DIR__.'/includes/layout-start.php';
?>

<div class="dashboard-head d-flex justify-content-between align-items-start mb-3">
    <div>
        <span class="badge-soft">OVERVIEW</span>
        <h1 class="mt-2" style="font: weight 30%;">Dashboard</h1>
        <p class=" text-muted mb-0">Welcome back <?=e($_SESSION['full_name']??'User')?>.</p>
    </div>

    <?php if(can_access('invoices','create')): ?>
    <a href="<?=e(app_url('invoice-form.php'))?>" class="btn btn-brand">
        <i data-lucide="plus"></i> New Invoice
    </a>
    <?php endif;?>
</div>


<div class="row g-3">
    <?php foreach([
['Hospitals',$stats['clients'],'hospital'],
['Today Invoice',$stats['invoices'],'receipt'],
['Today Billing',$money($stats['billing']),'indian-rupee'],
['Collection',$money($stats['received']),'wallet'],
['Outstanding',$money($stats['outstanding']),'clock'],
['Pending Invoice',$stats['pending_invoices'],'file-warning']
] as $k):?>

    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card-ui p-3 dashboard-kpi">
            <i data-lucide="<?=$k[2]?>"></i>
            <small><?=$k[0]?></small>
            <strong><?=$k[1]?></strong>
        </div>
    </div>

    <?php endforeach;?>
</div>


<section class="card-ui p-3 mt-3">

    <h2 class="h6 fw-bold mb-3">Quick Actions</h2>

    <div class="row g-3">

        <?php foreach($actions as $a): ?>

        <?php if(can_access($a[3],$a[4])): ?>

        <div class="col-6 col-md-4 col-lg-2">

            <a class="dashboard-action" href="<?=e(app_url($a[1]))?>">

                <div class="action-icon">
                    <i data-lucide="<?=$a[2]?>"></i>
                </div>

                <span><?=$a[0]?></span>

            </a>

        </div>

        <?php endif;?>

        <?php endforeach;?>

    </div>

</section>



<section class="card-ui p-3 mt-3">

    <h2 class="h6 fw-bold">Recent Invoice Activity</h2>

    <div class="table-responsive mt-3">

        <table class="table align-middle">

            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Hospital</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach($recent as $r):?>

                <tr>
                    <td><?=$r['invoice_number']?></td>
                    <td><?=$r['client_name']?></td>
                    <td><?=date('d M Y',strtotime($r['invoice_date']))?></td>
                    <td><?=$money($r['grand_total'])?></td>
                    <td><span class="badge-soft"><?=$r['payment_status']?></span></td>
                </tr>

                <?php endforeach;?>

            </tbody>

        </table>

    </div>

</section>


<style>
.dashboard-kpi i {
    padding: 10px;
    border-radius: 12px;
    background: var(--sidebar-active);
}

.dashboard-kpi small {
    display: block;
    margin-top: 12px;
    color: var(--text-muted);
    font-weight: 700;
}

.dashboard-kpi strong {
    font-size: 20px;
    display: block;
    margin-top: 5px;
}


.dashboard-action {
    height: 110px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    text-decoration: none;
    color: var(--text-main);
    background: var(--card-bg);
    font-weight: 700;
    transition: .2s;
}

.dashboard-action:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow);
}

.action-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--brand);
    color: white;
}


@media(max-width:576px) {
    .dashboard-head {
        flex-direction: column;
        gap: 15px;
    }

    .dashboard-head .btn {
        width: 100%;
    }

    .dashboard-action {
        height: 95px;
    }

}

/* Compact KPI Card UI */
.dashboard-kpi i {
    width: 34px;
    height: 34px;
    padding: 7px;
    border-radius: 10px;
}

.dashboard-kpi small {
    margin-top: 8px !important;
    font-size: 13px;
    font-weight: 800 !important;
}

.dashboard-kpi strong {
    margin-top: 3px !important;
    font-size: 21px !important;
    font-weight: 900 !important;
}

@media(max-width:768px) {
    .dashboard-kpi {
        min-height: 90px;
        padding: 12px !important;
    }

    .dashboard-kpi strong {
        font-size: 19px !important;
    }
}

.dashboard-head h1 {
    font-weight: 900 !important;
    letter-spacing: -0.5px;
}

.dashboard-head p {
    font-weight: 500;
}
</style>


<?php include __DIR__.'/includes/layout-end.php';?>