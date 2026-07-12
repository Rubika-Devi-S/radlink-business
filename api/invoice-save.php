<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/invoice-functions.php';

if($_SERVER['REQUEST_METHOD']!=='POST') json_response(false,'Method not allowed.',[],405);
if(!verify_csrf_token($_POST['csrf_token']??null)) json_response(false,'Session expired.',[],419);

$invoiceId=(int)($_POST['invoice_id']??0);
$requestedFinancialYearId=(int)($_POST['financial_year_id']??0);
$clientId=(int)($_POST['client_id']??0);
$invoiceDate=(string)($_POST['invoice_date']??'');
$dueDate=trim((string)($_POST['due_date']??''));
$billing=(string)($_POST['billing_responsibility']??'client_credit');
$patientName=trim((string)($_POST['patient_name']??''));
$patientRef=trim((string)($_POST['patient_reference_no']??''));
$hospitalRef=trim((string)($_POST['hospital_reference_no']??''));
$notes=trim((string)($_POST['notes']??''));
$status=(string)($_POST['invoice_status']??'issued');
$items=json_decode((string)($_POST['items_json']??'[]'),true);

$allowedBilling=['client_credit','patient_direct','patient_paid_to_client','split','complimentary'];
if(!in_array($billing,$allowedBilling,true)) $billing='client_credit';
if(!in_array($status,['draft','issued'],true)) $status='issued';

if($clientId<=0||$invoiceDate===''||!is_array($items)||count($items)===0){
 json_response(false,'Client, invoice date and at least one service are required.',[],422);
}

$clientStmt=$pdo->prepare("SELECT * FROM clients WHERE id=? AND business_id=? AND status='active' LIMIT 1");
$clientStmt->execute([$clientId,$currentBusinessId]);
$client=$clientStmt->fetch();
if(!$client) json_response(false,'Invalid client.',[],422);

$fy=resolve_invoice_financial_year(
    $pdo,
    $currentBusinessId,
    $invoiceDate,
    $requestedFinancialYearId
);

$subtotal=0;$discountTotal=0;$taxTotal=0;$cleanItems=[];
foreach($items as $index=>$item){
 $serviceId=(int)($item['service_id']??0);
 $qty=max(0.001,(float)($item['quantity']??1));
 $rate=max(0,(float)($item['applied_rate']??0));
 $discountType=in_array($item['discount_type']??'none',['none','amount','percentage'],true)?$item['discount_type']:'none';
 $discountValue=max(0,(float)($item['discount_value']??0));
 $taxPercent=max(0,min(100,(float)($item['tax_percent']??0)));

 $serviceStmt=$pdo->prepare("SELECT * FROM services WHERE id=? AND business_id=? LIMIT 1");
 $serviceStmt->execute([$serviceId,$currentBusinessId]);
 $service=$serviceStmt->fetch();
 if(!$service) json_response(false,'Invalid service on row '.($index+1).'.',[],422);

 $gross=$qty*$rate;
 $discount=$discountType==='percentage'?($gross*$discountValue/100):($discountType==='amount'?min($discountValue,$gross):0);
 $taxable=$gross-$discount;
 $tax=$taxable*$taxPercent/100;
 $line=$taxable+$tax;

 $subtotal+=$gross;$discountTotal+=$discount;$taxTotal+=$tax;
 $cleanItems[]=[
  'service'=>$service,'qty'=>$qty,'rate'=>$rate,'discount_type'=>$discountType,
  'discount_value'=>$discountValue,'discount_amount'=>$discount,'tax_percent'=>$taxPercent,
  'tax_amount'=>$tax,'line_total'=>$line,'sort_order'=>$index+1,
  'description'=>trim((string)($item['description']??''))
 ];
}
$roundOff=round(round($subtotal-$discountTotal+$taxTotal)-($subtotal-$discountTotal+$taxTotal),2);
$grand=round($subtotal-$discountTotal+$taxTotal+$roundOff,2);
$patientPayable=in_array($billing,['patient_direct','patient_paid_to_client'],true)?$grand:($billing==='split'?(float)($_POST['patient_payable_amount']??0):0);
$patientPayable=max(0,min($grand,$patientPayable));
$clientPayable=$billing==='complimentary'?0:$grand-$patientPayable;

try{
 $pdo->beginTransaction();

 if($invoiceId>0){
  $check=$pdo->prepare("SELECT * FROM invoices WHERE id=? AND business_id=? FOR UPDATE");
  $check->execute([$invoiceId,$currentBusinessId]);
  $existing=$check->fetch();
  if(!$existing) throw new RuntimeException('Invoice not found.');
  if($existing['invoice_status']==='cancelled') throw new RuntimeException('Cancelled invoice cannot be edited.');
  $invoiceNumber=$existing['invoice_number'];
 }else{
  $businessStmt=$pdo->prepare("SELECT * FROM businesses WHERE id=? LIMIT 1");
  $businessStmt->execute([$currentBusinessId]);$business=$businessStmt->fetch();
  $padding=(int)invoice_setting($pdo,$currentBusinessId,'invoice_number_padding','4');
  $prefix=trim((string)($business['invoice_prefix']??''));
  if($prefix==='')$prefix='RLS-INV';
  $invoiceNumber=next_invoice_number_compatible(
      $pdo,
      $currentBusinessId,
      (int)$fy['id'],
      $prefix,
      $padding
  );
 }

 $terms=invoice_setting($pdo,$currentBusinessId,'invoice_terms','');

 if($invoiceId>0){
  $stmt=$pdo->prepare(
   "UPDATE invoices SET financial_year_id=?,client_id=?,invoice_date=?,due_date=?,
    bill_to_name=?,bill_to_address=?,bill_to_mobile=?,bill_to_email=?,
    patient_name=?,patient_reference_no=?,hospital_reference_no=?,billing_responsibility=?,
    subtotal=?,discount_amount=?,tax_amount=?,round_off=?,grand_total=?,
    patient_payable_amount=?,client_payable_amount=?,balance_amount=grand_total-received_amount,
    payment_status=CASE WHEN received_amount<=0 THEN 'unpaid' WHEN received_amount>=? THEN 'paid' ELSE 'partially_paid' END,
    invoice_status=?,notes=?,terms_snapshot=?
    WHERE id=? AND business_id=?"
  );
  $stmt->execute([
   $fy['id'],$clientId,$invoiceDate,$dueDate?:null,$client['client_name'],
   trim(implode(', ',array_filter([$client['address_line_1'],$client['address_line_2'],$client['city'],$client['district'],$client['state'],$client['postal_code']]))),
   $client['mobile'],$client['email'],$patientName?:null,$patientRef?:null,$hospitalRef?:null,$billing,
   $subtotal,$discountTotal,$taxTotal,$roundOff,$grand,$patientPayable,$clientPayable,$grand,
   $status,$notes?:null,$terms,$invoiceId,$currentBusinessId
  ]);
  $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=? AND business_id=?")->execute([$invoiceId,$currentBusinessId]);
 }else{
  $stmt=$pdo->prepare(
   "INSERT INTO invoices
    (business_id,financial_year_id,client_id,invoice_number,invoice_date,due_date,
     bill_to_name,bill_to_address,bill_to_mobile,bill_to_email,patient_name,patient_reference_no,
     hospital_reference_no,billing_responsibility,subtotal,discount_amount,tax_amount,round_off,
     grand_total,patient_payable_amount,client_payable_amount,received_amount,balance_amount,
     payment_status,invoice_status,notes,terms_snapshot,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,'unpaid',?,?,?,?)"
  );
  $stmt->execute([
   $currentBusinessId,$fy['id'],$clientId,$invoiceNumber,$invoiceDate,$dueDate?:null,
   $client['client_name'],
   trim(implode(', ',array_filter([$client['address_line_1'],$client['address_line_2'],$client['city'],$client['district'],$client['state'],$client['postal_code']]))),
   $client['mobile'],$client['email'],$patientName?:null,$patientRef?:null,$hospitalRef?:null,$billing,
   $subtotal,$discountTotal,$taxTotal,$roundOff,$grand,$patientPayable,$clientPayable,$grand,
   $status,$notes?:null,$terms,current_user_id()
  ]);
  $invoiceId=(int)$pdo->lastInsertId();
 }

 $itemStmt=$pdo->prepare(
  "INSERT INTO invoice_items
   (business_id,invoice_id,service_id,service_code_snapshot,service_name_snapshot,description,
    quantity,unit_name,standard_rate,applied_rate,discount_type,discount_value,discount_amount,
    tax_percent,tax_amount,line_total,sort_order)
   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
 );
 foreach($cleanItems as $item){
  $s=$item['service'];
  $itemStmt->execute([
   $currentBusinessId,$invoiceId,$s['id'],$s['service_code'],$s['service_name'],
   $item['description']?:$s['description'],$item['qty'],$s['unit_name'],$s['standard_rate'],
   $item['rate'],$item['discount_type'],$item['discount_value'],$item['discount_amount'],
   $item['tax_percent'],$item['tax_amount'],$item['line_total'],$item['sort_order']
  ]);
 }

 log_invoice_activity($pdo,$currentBusinessId,current_user_id(),$invoiceId>0?'save':'create',$invoiceId,'Invoice '.$invoiceNumber.' saved.');
 $pdo->commit();
 json_response(true,'Invoice saved successfully.',['invoice_id'=>$invoiceId,'invoice_number'=>$invoiceNumber]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 json_response(false,$e->getMessage(),[],422);
}
