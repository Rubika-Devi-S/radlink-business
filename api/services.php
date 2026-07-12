<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(false,'Method not allowed.',[],405);
if (!verify_csrf_token($_POST['csrf_token']??null)) json_response(false,'Session expired. Refresh and try again.',[],419);
if ($currentBusinessId<=0) json_response(false,'Select a business first.',[],422);

$action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);

try{
 if($action==='save'){
  $categoryId=(int)($_POST['service_category_id']??0);
  $code=strtoupper(trim((string)($_POST['service_code']??'')));
  $name=trim((string)($_POST['service_name']??''));
  $description=trim((string)($_POST['description']??''));
  $unit=strtoupper(trim((string)($_POST['unit_name']??'QTY')));
  $rate=(float)($_POST['standard_rate']??0);
  $tax=(float)($_POST['tax_percent']??0);
  $hsn=trim((string)($_POST['hsn_sac_code']??''));
  $status=in_array($_POST['status']??'', ['active','inactive'],true)?$_POST['status']:'active';
  if($categoryId<=0||$code===''||$name==='') json_response(false,'Category, service code and service name are required.',[],422);
  if($rate<0||$tax<0||$tax>100) json_response(false,'Enter valid rate and tax values.',[],422);
  $category=$pdo->prepare("SELECT COUNT(*) FROM service_categories WHERE id=? AND business_id=?");$category->execute([$categoryId,$currentBusinessId]);
  if(!(bool)$category->fetchColumn()) json_response(false,'Invalid service category.',[],422);
  if($id>0){
   $stmt=$pdo->prepare("UPDATE services SET service_category_id=?,service_code=?,service_name=?,description=?,unit_name=?,standard_rate=?,tax_percent=?,hsn_sac_code=?,status=? WHERE id=? AND business_id=?");
   $stmt->execute([$categoryId,$code,$name,$description?:null,$unit,$rate,$tax,$hsn?:null,$status,$id,$currentBusinessId]);
   json_response(true,'Service updated.');
  }
  $stmt=$pdo->prepare("INSERT INTO services (business_id,service_category_id,service_code,service_name,description,unit_name,standard_rate,tax_percent,hsn_sac_code,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([$currentBusinessId,$categoryId,$code,$name,$description?:null,$unit,$rate,$tax,$hsn?:null,$status,current_user_id()]);
  json_response(true,'Service created.');
 }
 if($action==='toggle'){
  $stmt=$pdo->prepare("UPDATE services SET status=IF(status='active','inactive','active') WHERE id=? AND business_id=?");$stmt->execute([$id,$currentBusinessId]);json_response(true,'Service status changed.');
 }
 if($action==='delete'){
  $check1=$pdo->prepare("SELECT COUNT(*) FROM client_service_rates WHERE business_id=? AND service_id=?");$check1->execute([$currentBusinessId,$id]);
  $check2=$pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE business_id=? AND service_id=?");$check2->execute([$currentBusinessId,$id]);
  if((int)$check1->fetchColumn()>0||(int)$check2->fetchColumn()>0) json_response(false,'This service is already used and cannot be deleted. Set it inactive instead.',[],422);
  $stmt=$pdo->prepare("DELETE FROM services WHERE id=? AND business_id=?");$stmt->execute([$id,$currentBusinessId]);json_response(true,'Service deleted.');
 }
 json_response(false,'Invalid action.',[],422);
}catch(PDOException $e){
 json_response(false,$e->getCode()==='23000'?'Service code already exists.':'Unable to save service.',[],422);
}
