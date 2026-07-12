<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/invoice-functions.php';

if($_SERVER['REQUEST_METHOD']!=='POST')json_response(false,'Method not allowed.',[],405);
if(!verify_csrf_token($_POST['csrf_token']??null))json_response(false,'Session expired.',[],419);

$id=(int)($_POST['id']??0);
$reason=trim((string)($_POST['reason']??''));
if($id<=0||$reason==='')json_response(false,'Cancellation reason is required.',[],422);

$stmt=$pdo->prepare(
 "UPDATE invoices SET invoice_status='cancelled',payment_status='cancelled',
  cancel_reason=?,cancelled_by=?,cancelled_at=NOW()
  WHERE id=? AND business_id=? AND invoice_status<>'cancelled'"
);
$stmt->execute([$reason,current_user_id(),$id,$currentBusinessId]);
if(!$stmt->rowCount())json_response(false,'Invoice not found or already cancelled.',[],422);
log_invoice_activity($pdo,$currentBusinessId,current_user_id(),'cancel',$id,'Invoice cancelled: '.$reason);
json_response(true,'Invoice cancelled.');
