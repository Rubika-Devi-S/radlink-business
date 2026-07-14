<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

$action = trim((string)($_GET['action'] ?? 'data'));
$report = trim((string)($_GET['report'] ?? 'hospital_wise'));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$clientId = (int)($_GET['client_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? ''));

$allowedReports = ['hospital_wise','collection','outstanding','profit'];
if (!in_array($report, $allowedReports, true)) {
    json_response(false, 'Invalid report.', [], 422);
}

function report_valid_date(string $date): bool {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
if (!report_valid_date($dateFrom) || !report_valid_date($dateTo) || $dateFrom > $dateTo) {
    json_response(false, 'Enter a valid report date range.', [], 422);
}

function report_money(float $value): string {
    return number_format($value, 2, '.', '');
}
function report_excel(string $filename, array $columns, array $rows, array $summary = []): never {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#d9baf5;font-weight:bold}.money{text-align:right}</style></head><body>';
    if ($summary) {
        echo '<table><tr>';
        foreach ($summary as $item) echo '<th>' . htmlspecialchars($item['label']) . '</th>';
        echo '</tr><tr>';
        foreach ($summary as $item) echo '<td>' . htmlspecialchars((string)($item['display'] ?? $item['value'])) . '</td>';
        echo '</tr></table><br>';
    }
    echo '<table><thead><tr>';
    foreach ($columns as $column) echo '<th>' . htmlspecialchars($column['label']) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? '';
            echo '<td' . (($column['type'] ?? '') === 'money' ? ' class="money"' : '') . '>' . htmlspecialchars((string)$value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}
function report_details_table(string $title, array $columns, array $rows): string {
    $html = '<h6 class="mb-3">' . htmlspecialchars($title) . '</h6><div class="table-responsive"><table class="table align-middle"><thead><tr>';
    foreach ($columns as $label) $html .= '<th>' . htmlspecialchars($label) . '</th>';
    $html .= '</tr></thead><tbody>';
    if (!$rows) return $html . '<tr><td colspan="' . count($columns) . '" class="text-center text-muted">No records</td></tr></tbody></table></div>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $value) $html .= '<td>' . htmlspecialchars((string)$value) . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

if ($action === 'details') {
    $detailClientId = (int)($_GET['client_id'] ?? 0);
    $detailId = (int)($_GET['id'] ?? 0);

    if ($report === 'collection' && $detailId > 0) {
        $stmt = $pdo->prepare(
            "SELECT p.receipt_number, p.payment_date, c.client_name, pm.mode_name,
                    p.transaction_reference, p.amount, p.allocated_amount, p.unallocated_amount, p.payment_status
             FROM payments p JOIN clients c ON c.id=p.client_id JOIN payment_modes pm ON pm.id=p.payment_mode_id
             WHERE p.id=? AND p.business_id=? LIMIT 1"
        );
        $stmt->execute([$detailId,$currentBusinessId]);
        $p=$stmt->fetch();
        if(!$p) json_response(false,'Payment not found.',[],404);
        $a=$pdo->prepare("SELECT i.invoice_number,i.invoice_date,pa.allocated_amount FROM payment_allocations pa JOIN invoices i ON i.id=pa.invoice_id WHERE pa.payment_id=? AND pa.business_id=? ORDER BY pa.id");
        $a->execute([$detailId,$currentBusinessId]);
        $rows=array_map(static fn($r)=>[$r['invoice_number'],date('d M Y',strtotime($r['invoice_date'])),'₹'.number_format((float)$r['allocated_amount'],2)],$a->fetchAll());
        $top='<div class="row g-3 mb-4"><div class="col-md-4"><small class="text-muted">Receipt</small><strong class="d-block">'.htmlspecialchars($p['receipt_number']).'</strong></div><div class="col-md-4"><small class="text-muted">Hospital</small><strong class="d-block">'.htmlspecialchars($p['client_name']).'</strong></div><div class="col-md-4"><small class="text-muted">Amount</small><strong class="d-block">₹'.number_format((float)$p['amount'],2).'</strong></div></div>';
        json_response(true,'Details loaded.',['title'=>'Payment Details','html'=>$top.report_details_table('Invoice Allocations',['Invoice','Date','Allocated'],$rows)]);
    }

    if ($detailClientId <= 0) json_response(false,'Invalid hospital.',[],422);
    $clientStmt=$pdo->prepare("SELECT client_name,client_code FROM clients WHERE id=? AND business_id=? LIMIT 1");
    $clientStmt->execute([$detailClientId,$currentBusinessId]);$client=$clientStmt->fetch();
    if(!$client) json_response(false,'Hospital not found.',[],404);

    $inv=$pdo->prepare(
        "SELECT invoice_number,invoice_date,due_date,grand_total,received_amount,balance_amount,payment_status
         FROM invoices WHERE business_id=? AND client_id=? AND invoice_status='issued' AND invoice_date BETWEEN ? AND ?
         ORDER BY invoice_date DESC,id DESC"
    );
    $inv->execute([$currentBusinessId,$detailClientId,$dateFrom,$dateTo]);
    $invoiceRows=array_map(static fn($r)=>[
        $r['invoice_number'],date('d M Y',strtotime($r['invoice_date'])),
        $r['due_date']?date('d M Y',strtotime($r['due_date'])):'—',
        '₹'.number_format((float)$r['grand_total'],2),'₹'.number_format((float)$r['received_amount'],2),
        '₹'.number_format((float)$r['balance_amount'],2),ucwords(str_replace('_',' ',$r['payment_status']))
    ],$inv->fetchAll());

    $pay=$pdo->prepare(
        "SELECT receipt_number,payment_date,amount,allocated_amount,unallocated_amount,payment_status
         FROM payments WHERE business_id=? AND client_id=? AND payment_date BETWEEN ? AND ?
         ORDER BY payment_date DESC,id DESC"
    );
    $pay->execute([$currentBusinessId,$detailClientId,$dateFrom,$dateTo]);
    $paymentRows=array_map(static fn($r)=>[
        $r['receipt_number'],date('d M Y',strtotime($r['payment_date'])),'₹'.number_format((float)$r['amount'],2),
        '₹'.number_format((float)$r['allocated_amount'],2),'₹'.number_format((float)$r['unallocated_amount'],2),ucfirst($r['payment_status'])
    ],$pay->fetchAll());

    $html=report_details_table('Invoices',['Invoice','Date','Due Date','Total','Collected','Balance','Status'],$invoiceRows);
    $html.=report_details_table('Payments',['Receipt','Date','Amount','Allocated','Unallocated','Status'],$paymentRows);
    json_response(true,'Details loaded.',['title'=>$client['client_code'].' - '.$client['client_name'],'html'=>$html]);
}

if ($report === 'hospital_wise') {
    $params = [$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$currentBusinessId];
    $clientFilter = $clientId > 0 ? ' AND c.id = ?' : '';
    if ($clientId > 0) $params[]=$clientId;
    $activityFilter = $status === 'with_activity' ? ' HAVING (invoice_count > 0 OR payment_count > 0)' : ($status === 'no_activity' ? ' HAVING (invoice_count = 0 AND payment_count = 0)' : '');
    $order = match($sort){
        'invoiced_desc'=>'total_invoiced DESC,c.client_name',
        'collected_desc'=>'allocated_collected DESC,c.client_name',
        'hospital_asc'=>'c.client_name ASC',
        default=>'outstanding DESC,c.client_name'
    };
    $sql="SELECT c.id AS client_id,c.client_code,c.client_name,c.mobile,c.district,c.credit_period_days,
        COUNT(DISTINCT i.id) invoice_count,COALESCE(SUM(DISTINCT 0),0) dummy,
        COALESCE(inv.service_total,0) service_total,COALESCE(inv.discount_total,0) discount_total,
        COALESCE(inv.tax_total,0) tax_total,COALESCE(inv.total_invoiced,0) total_invoiced,
        COALESCE(inv.received,0) allocated_collected,COALESCE(inv.outstanding,0) outstanding,
        COALESCE(inv.overdue,0) overdue,inv.last_invoice_date,
        COALESCE(pay.payment_count,0) payment_count,COALESCE(pay.total_received,0) total_received,
        COALESCE(pay.unallocated,0) unallocated,pay.last_payment_date
      FROM clients c
      LEFT JOIN invoices i ON i.client_id=c.id AND i.business_id=c.business_id AND i.invoice_status='issued' AND i.invoice_date BETWEEN ? AND ?
      LEFT JOIN (
        SELECT client_id,SUM(subtotal) service_total,SUM(discount_amount) discount_total,SUM(tax_amount) tax_total,
               SUM(grand_total) total_invoiced,SUM(received_amount) received,SUM(balance_amount) outstanding,
               SUM(CASE WHEN due_date<CURDATE() THEN balance_amount ELSE 0 END) overdue,MAX(invoice_date) last_invoice_date
        FROM invoices WHERE invoice_status='issued' AND invoice_date BETWEEN ? AND ? GROUP BY client_id
      ) inv ON inv.client_id=c.id
      LEFT JOIN (
        SELECT client_id,COUNT(*) payment_count,SUM(amount) total_received,SUM(unallocated_amount) unallocated,MAX(payment_date) last_payment_date
        FROM payments WHERE payment_status='posted' AND payment_date BETWEEN ? AND ? GROUP BY client_id
      ) pay ON pay.client_id=c.id
      WHERE c.business_id=? AND c.status='active' $clientFilter
      GROUP BY c.id $activityFilter ORDER BY $order";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
    foreach($rows as &$r){
        $r['collection_percent']=(float)$r['total_invoiced']>0?round((float)$r['allocated_collected']/(float)$r['total_invoiced']*100,2):0;
        $r['status_label']=(float)$r['outstanding']<=0?'Clear':((float)$r['overdue']>0?'Overdue':'Outstanding');
        $r['last_invoice_date']=$r['last_invoice_date']?date('d M Y',strtotime($r['last_invoice_date'])):'—';
        $r['last_payment_date']=$r['last_payment_date']?date('d M Y',strtotime($r['last_payment_date'])):'—';
    } unset($r);
    $kpis=[
        ['label'=>'Hospitals','value'=>count($rows),'type'=>'number'],
        ['label'=>'Total Invoiced','value'=>array_sum(array_column($rows,'total_invoiced')),'type'=>'money'],
        ['label'=>'Collected','value'=>array_sum(array_column($rows,'allocated_collected')),'type'=>'money'],
        ['label'=>'Outstanding','value'=>array_sum(array_column($rows,'outstanding')),'type'=>'money'],
        ['label'=>'Overdue','value'=>array_sum(array_column($rows,'overdue')),'type'=>'money'],
        ['label'=>'Unallocated Payments','value'=>array_sum(array_column($rows,'unallocated')),'type'=>'money'],
    ];
    $columns=[
        ['key'=>'client_code','label'=>'Hospital Code'],['key'=>'client_name','label'=>'Hospital Name'],
        ['key'=>'invoice_count','label'=>'Invoices','align'=>'center'],['key'=>'total_invoiced','label'=>'Invoiced','type'=>'money','align'=>'right'],
        ['key'=>'allocated_collected','label'=>'Collected','type'=>'money','align'=>'right'],['key'=>'unallocated','label'=>'Unallocated','type'=>'money','align'=>'right'],
        ['key'=>'outstanding','label'=>'Outstanding','type'=>'money','align'=>'right'],['key'=>'overdue','label'=>'Overdue','type'=>'money','align'=>'right'],
        ['key'=>'collection_percent','label'=>'Collection %','type'=>'percent','align'=>'right'],['key'=>'last_invoice_date','label'=>'Last Invoice'],
        ['key'=>'last_payment_date','label'=>'Last Payment'],['key'=>'status_label','label'=>'Status','type'=>'status'],['key'=>'client_id','label'=>'Action','type'=>'action','align'=>'center']
    ];
} elseif ($report === 'collection') {
    $params=[$currentBusinessId,$dateFrom,$dateTo];
    $where="p.business_id=? AND p.payment_date BETWEEN ? AND ?";
    if($clientId>0){$where.=" AND p.client_id=?";$params[]=$clientId;}
    if(in_array($status,['posted','reversed'],true)){$where.=" AND p.payment_status=?";$params[]=$status;}
    $order=match($sort){'amount_desc'=>'p.amount DESC','hospital_asc'=>'c.client_name ASC,p.payment_date DESC',default=>'p.payment_date DESC,p.id DESC'};
    $stmt=$pdo->prepare("SELECT p.id,p.client_id,p.receipt_number,p.payment_date,c.client_code,c.client_name,pm.mode_name,p.transaction_reference,p.amount,p.allocated_amount,p.unallocated_amount,p.payment_status FROM payments p JOIN clients c ON c.id=p.client_id JOIN payment_modes pm ON pm.id=p.payment_mode_id WHERE $where ORDER BY $order");
    $stmt->execute($params);$rows=$stmt->fetchAll();
    foreach($rows as &$r){$r['payment_date']=date('d M Y',strtotime($r['payment_date']));$r['status_label']=ucfirst($r['payment_status']);}unset($r);
    $posted=array_filter($rows,fn($r)=>$r['payment_status']==='posted');
    $kpis=[
        ['label'=>'Receipts','value'=>count($rows),'type'=>'number'],['label'=>'Total Received','value'=>array_sum(array_column($posted,'amount')),'type'=>'money'],
        ['label'=>'Allocated','value'=>array_sum(array_column($posted,'allocated_amount')),'type'=>'money'],['label'=>'Unallocated','value'=>array_sum(array_column($posted,'unallocated_amount')),'type'=>'money'],
        ['label'=>'Reversed Receipts','value'=>count(array_filter($rows,fn($r)=>$r['payment_status']==='reversed')),'type'=>'number'],['label'=>'Average Receipt','value'=>count($posted)?array_sum(array_column($posted,'amount'))/count($posted):0,'type'=>'money']
    ];
    $columns=[
        ['key'=>'receipt_number','label'=>'Receipt'],['key'=>'payment_date','label'=>'Date'],['key'=>'client_name','label'=>'Hospital'],
        ['key'=>'mode_name','label'=>'Mode'],['key'=>'transaction_reference','label'=>'Reference'],['key'=>'amount','label'=>'Amount','type'=>'money','align'=>'right'],
        ['key'=>'allocated_amount','label'=>'Allocated','type'=>'money','align'=>'right'],['key'=>'unallocated_amount','label'=>'Unallocated','type'=>'money','align'=>'right'],
        ['key'=>'status_label','label'=>'Status','type'=>'status'],['key'=>'id','label'=>'Action','type'=>'action','align'=>'center']
    ];
} elseif ($report === 'outstanding') {
    $params=[$currentBusinessId,$dateFrom,$dateTo];
    $where="i.business_id=? AND i.invoice_status='issued' AND i.balance_amount>0 AND i.invoice_date BETWEEN ? AND ?";
    if($clientId>0){$where.=" AND i.client_id=?";$params[]=$clientId;}
    if($status==='overdue')$where.=" AND i.due_date<CURDATE()";
    elseif($status==='not_due')$where.=" AND (i.due_date IS NULL OR i.due_date>=CURDATE())";
    $order=match($sort){'overdue_desc'=>'ageing_days DESC','due_asc'=>'i.due_date ASC','hospital_asc'=>'c.client_name ASC,i.due_date',default=>'i.balance_amount DESC'};
    $stmt=$pdo->prepare("SELECT i.id,i.client_id,i.invoice_number,i.invoice_date,i.due_date,c.client_code,c.client_name,i.grand_total,i.received_amount,i.balance_amount,GREATEST(0,DATEDIFF(CURDATE(),COALESCE(i.due_date,i.invoice_date))) ageing_days FROM invoices i JOIN clients c ON c.id=i.client_id WHERE $where ORDER BY $order");
    $stmt->execute($params);$rows=$stmt->fetchAll();
    foreach($rows as &$r){
        $days=(int)$r['ageing_days'];$r['invoice_date']=date('d M Y',strtotime($r['invoice_date']));$r['due_date']=$r['due_date']?date('d M Y',strtotime($r['due_date'])):'—';
        $r['age_bucket']=$days<=0?'Current':($days<=30?'1–30 Days':($days<=60?'31–60 Days':($days<=90?'61–90 Days':'Above 90 Days')));
        $r['status_label']=$days>0?'Overdue':'Outstanding';
    }unset($r);
    $kpis=[
        ['label'=>'Open Invoices','value'=>count($rows),'type'=>'number'],['label'=>'Total Outstanding','value'=>array_sum(array_column($rows,'balance_amount')),'type'=>'money'],
        ['label'=>'Current','value'=>array_sum(array_map(fn($r)=>(int)$r['ageing_days']<=0?(float)$r['balance_amount']:0,$rows)),'type'=>'money'],
        ['label'=>'1–30 Days','value'=>array_sum(array_map(fn($r)=>(int)$r['ageing_days']>0&&(int)$r['ageing_days']<=30?(float)$r['balance_amount']:0,$rows)),'type'=>'money'],
        ['label'=>'31–90 Days','value'=>array_sum(array_map(fn($r)=>(int)$r['ageing_days']>30&&(int)$r['ageing_days']<=90?(float)$r['balance_amount']:0,$rows)),'type'=>'money'],
        ['label'=>'Above 90 Days','value'=>array_sum(array_map(fn($r)=>(int)$r['ageing_days']>90?(float)$r['balance_amount']:0,$rows)),'type'=>'money']
    ];
    $columns=[
        ['key'=>'invoice_number','label'=>'Invoice'],['key'=>'client_name','label'=>'Hospital'],['key'=>'invoice_date','label'=>'Invoice Date'],['key'=>'due_date','label'=>'Due Date'],
        ['key'=>'grand_total','label'=>'Invoice Total','type'=>'money','align'=>'right'],['key'=>'received_amount','label'=>'Collected','type'=>'money','align'=>'right'],
        ['key'=>'balance_amount','label'=>'Outstanding','type'=>'money','align'=>'right'],['key'=>'ageing_days','label'=>'Ageing Days','align'=>'center'],
        ['key'=>'age_bucket','label'=>'Age Bucket'],['key'=>'status_label','label'=>'Status','type'=>'status'],['key'=>'client_id','label'=>'Action','type'=>'action','align'=>'center']
    ];
} else {
    $params=[$dateFrom,$dateTo,$currentBusinessId,$dateFrom,$dateTo,$currentBusinessId,$dateFrom,$dateTo,$currentBusinessId];
    $stmt=$pdo->prepare(
        "SELECT m.month_key,m.month_label,COALESCE(inv.billed_revenue,0) billed_revenue,COALESCE(pay.collected,0) collected,
                COALESCE(exp.expenses,0) expenses,(COALESCE(inv.billed_revenue,0)-COALESCE(exp.expenses,0)) net_profit
         FROM (
            SELECT DATE_FORMAT(d,'%Y-%m') month_key,DATE_FORMAT(d,'%b %Y') month_label
            FROM (
                SELECT DATE_ADD(?,INTERVAL n MONTH) d FROM (
                    SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
                    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
                    UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17
                    UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
                ) nums
            ) dates WHERE d<=?
         ) m
         LEFT JOIN (SELECT DATE_FORMAT(invoice_date,'%Y-%m') month_key,SUM(grand_total) billed_revenue FROM invoices WHERE business_id=? AND invoice_status='issued' AND invoice_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(invoice_date,'%Y-%m')) inv ON inv.month_key=m.month_key
         LEFT JOIN (SELECT DATE_FORMAT(payment_date,'%Y-%m') month_key,SUM(amount) collected FROM payments WHERE business_id=? AND payment_status='posted' AND payment_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(payment_date,'%Y-%m')) pay ON pay.month_key=m.month_key
         LEFT JOIN (SELECT DATE_FORMAT(expense_date,'%Y-%m') month_key,SUM(amount) expenses FROM expenses WHERE business_id=? AND expense_status='posted' AND expense_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(expense_date,'%Y-%m')) exp ON exp.month_key=m.month_key
         ORDER BY m.month_key"
    );
    # Fix parameters explicitly due repeated ranges
    $stmt->execute([$dateFrom,$dateTo,$currentBusinessId,$dateFrom,$dateTo,$currentBusinessId,$dateFrom,$dateTo,$currentBusinessId,$dateFrom,$dateTo]);
    $rows=$stmt->fetchAll();
    foreach($rows as &$r){$r['margin_percent']=(float)$r['billed_revenue']>0?round((float)$r['net_profit']/(float)$r['billed_revenue']*100,2):0;$r['status_label']=(float)$r['net_profit']>=0?'Profit':'Loss';}unset($r);
    if($sort==='profit_desc')usort($rows,fn($a,$b)=>(float)$b['net_profit']<=>(float)$a['net_profit']);
    elseif($sort==='revenue_desc')usort($rows,fn($a,$b)=>(float)$b['billed_revenue']<=>(float)$a['billed_revenue']);
    $revenue=array_sum(array_column($rows,'billed_revenue'));$expenses=array_sum(array_column($rows,'expenses'));$profit=$revenue-$expenses;
    $kpis=[
        ['label'=>'Billed Revenue','value'=>$revenue,'type'=>'money'],['label'=>'Cash Collected','value'=>array_sum(array_column($rows,'collected')),'type'=>'money'],
        ['label'=>'Expenses','value'=>$expenses,'type'=>'money'],['label'=>'Net Profit','value'=>$profit,'type'=>'money'],
        ['label'=>'Profit Margin','value'=>$revenue>0?round($profit/$revenue*100,2):0,'display'=>($revenue>0?number_format($profit/$revenue*100,2):'0.00').'%','type'=>'number'],
        ['label'=>'Average Monthly Profit','value'=>count($rows)?$profit/count($rows):0,'type'=>'money']
    ];
    $columns=[
        ['key'=>'month_label','label'=>'Month'],['key'=>'billed_revenue','label'=>'Billed Revenue','type'=>'money','align'=>'right'],
        ['key'=>'collected','label'=>'Cash Collected','type'=>'money','align'=>'right'],['key'=>'expenses','label'=>'Expenses','type'=>'money','align'=>'right'],
        ['key'=>'net_profit','label'=>'Net Profit','type'=>'money','align'=>'right'],['key'=>'margin_percent','label'=>'Margin %','type'=>'percent','align'=>'right'],
        ['key'=>'status_label','label'=>'Result','type'=>'status']
    ];
}

if ($action === 'export') {
    $exportColumns=array_values(array_filter($columns,fn($c)=>($c['type']??'')!=='action'));
    $exportRows=$rows;
    foreach($exportRows as &$row){
        foreach($exportColumns as $column){
            if(($column['type']??'')==='money')$row[$column['key']]=report_money((float)($row[$column['key']]??0));
            elseif(($column['type']??'')==='percent')$row[$column['key']]=number_format((float)($row[$column['key']]??0),2).'%';
        }
    }unset($row);
    report_excel($report.'_'.$dateFrom.'_to_'.$dateTo,$exportColumns,$exportRows,$kpis);
}

json_response(true,'Report loaded.',['columns'=>$columns,'rows'=>$rows,'kpis'=>$kpis]);
