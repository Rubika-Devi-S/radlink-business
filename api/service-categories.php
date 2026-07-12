<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(false,'Method not allowed.',[],405);
if (!verify_csrf_token($_POST['csrf_token']??null)) json_response(false,'Session expired. Refresh and try again.',[],419);
if ($currentBusinessId<=0) json_response(false,'Select a business first.',[],422);

$action=(string)($_POST['action']??'');
$id=(int)($_POST['id']??0);

try {
 if($action==='save'){
   $code=strtoupper(trim((string)($_POST['category_code']??'')));
   $name=trim((string)($_POST['category_name']??''));
   $description=trim((string)($_POST['description']??''));
   $sort=max(0,(int)($_POST['sort_order']??0));
   $status=in_array($_POST['status']??'', ['active','inactive'],true)?$_POST['status']:'active';
   if($code===''||$name==='') json_response(false,'Category code and name are required.',[],422);
   if(!preg_match('/^[A-Z0-9_-]+$/',$code)) json_response(false,'Category code may contain letters, numbers, underscore and hyphen only.',[],422);
   if($id>0){
     $stmt=$pdo->prepare("UPDATE service_categories SET category_code=?,category_name=?,description=?,sort_order=?,status=? WHERE id=? AND business_id=?");
     $stmt->execute([$code,$name,$description?:null,$sort,$status,$id,$currentBusinessId]);
     json_response(true,'Service category updated.');
   }
   $stmt=$pdo->prepare("INSERT INTO service_categories (business_id,category_code,category_name,description,sort_order,status,created_by) VALUES (?,?,?,?,?,?,?)");
   $stmt->execute([$currentBusinessId,$code,$name,$description?:null,$sort,$status,current_user_id()]);
   json_response(true,'Service category created.');
 }
 if($action==='toggle'){
   $stmt=$pdo->prepare("UPDATE service_categories SET status=IF(status='active','inactive','active') WHERE id=? AND business_id=?");
   $stmt->execute([$id,$currentBusinessId]);json_response(true,'Category status changed.');
 }
 if($action==='delete'){
   $check=$pdo->prepare("SELECT COUNT(*) FROM services WHERE business_id=? AND service_category_id=?");$check->execute([$currentBusinessId,$id]);
   if((int)$check->fetchColumn()>0) json_response(false,'This category has services and cannot be deleted.',[],422);
   $stmt=$pdo->prepare("DELETE FROM service_categories WHERE id=? AND business_id=?");$stmt->execute([$id,$currentBusinessId]);json_response(true,'Category deleted.');
 }
 json_response(false,'Invalid action.',[],422);
} catch(PDOException $e){
 json_response(false,$e->getCode()==='23000'?'Category code already exists.':'Unable to save category.',[],422);
}
