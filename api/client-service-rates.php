<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(false,'Method not allowed.',[],405);
if (!verify_csrf_token($_POST['csrf_token']??null)) json_response(false,'Session expired. Refresh and try again.',[],419);
if ($currentBusinessId<=0) json_response(false,'Select a business first.',[],422);

$action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);

try{
 if($action==='save'){
  $clientId=(int)($_POST['client_id']??0);$serviceId=(int)($_POST['service_id']??0);
  $rate=(float)($_POST['agreed_rate']??0);$from=(string)($_POST['effective_from']??'');$to=trim((string)($_POST['effective_to']??''));
  $notes=trim((string)($_POST['notes']??''));$status=in_array($_POST['status']??'', ['active','inactive'],true)?$_POST['status']:'active';
  if($clientId<=0||$serviceId<=0||$rate<0||$from==='') json_response(false,'Client, service, agreed rate and effective date are required.',[],422);
  if($to!==''&&$to<$from) json_response(false,'Effective To cannot be earlier than Effective From.',[],422);
  $client=$pdo->prepare("SELECT COUNT(*) FROM clients WHERE id=? AND business_id=?");$client->execute([$clientId,$currentBusinessId]);
  $service=$pdo->prepare("SELECT COUNT(*) FROM services WHERE id=? AND business_id=?");$service->execute([$serviceId,$currentBusinessId]);
  if(!(bool)$client->fetchColumn()||!(bool)$service->fetchColumn()) json_response(false,'Invalid client or service.',[],422);
  if($id>0){
   $stmt=$pdo->prepare("UPDATE client_service_rates SET client_id=?,service_id=?,agreed_rate=?,effective_from=?,effective_to=?,notes=?,status=? WHERE id=? AND business_id=?");
   $stmt->execute([$clientId,$serviceId,$rate,$from,$to?:null,$notes?:null,$status,$id,$currentBusinessId]);
   json_response(true,'Client service rate updated.');
  }
  $stmt=$pdo->prepare("INSERT INTO client_service_rates (business_id,client_id,service_id,agreed_rate,effective_from,effective_to,notes,status,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
  $stmt->execute([$currentBusinessId,$clientId,$serviceId,$rate,$from,$to?:null,$notes?:null,$status,current_user_id()]);
  json_response(true,'Client service rate created.');
 }
 if($action==='toggle'){
  $stmt=$pdo->prepare("UPDATE client_service_rates SET status=IF(status='active','inactive','active') WHERE id=? AND business_id=?");$stmt->execute([$id,$currentBusinessId]);json_response(true,'Client rate status changed.');
 }
 if($action==='delete'){
  $stmt=$pdo->prepare("DELETE FROM client_service_rates WHERE id=? AND business_id=?");$stmt->execute([$id,$currentBusinessId]);json_response(true,'Client service rate deleted.');
 }
 json_response(false,'Invalid action.',[],422);
}catch(PDOException $e){
 json_response(false,$e->getCode()==='23000'?'A rate already exists for this client, service and effective date.':'Unable to save client rate.',[],422);
}
