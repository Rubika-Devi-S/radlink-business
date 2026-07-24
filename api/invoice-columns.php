<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if (!is_owner()) json_response(false,'Only the owner can manage invoice columns.',[],403);
if ($currentBusinessId<=0) json_response(false,'Select a business first.',[],422);

if ($_SERVER['REQUEST_METHOD']==='GET') {
 $q=$pdo->prepare("SELECT * FROM invoice_item_column_settings WHERE business_id=? AND status='active' ORDER BY sort_order,id");
 $q->execute([$currentBusinessId]);
 json_response(true,'Columns loaded.',['columns'=>$q->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') json_response(false,'Method not allowed.',[],405);
if (!verify_csrf_token($_POST['csrf_token']??null)) json_response(false,'Session expired.',[],419);

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'delete_column') {
    $columnId = (int)($_POST['column_id'] ?? 0);

    if ($columnId <= 0) {
        json_response(false, 'Invalid invoice column.', [], 422);
    }

    $find = $pdo->prepare(
        "SELECT id, column_key, column_type
         FROM invoice_item_column_settings
         WHERE id = ? AND business_id = ?
         LIMIT 1"
    );
    $find->execute([$columnId, $currentBusinessId]);
    $column = $find->fetch();

    if (!$column) {
        json_response(false, 'Invoice column not found.', [], 404);
    }

    if ((string)$column['column_key'] === 'service') {
        json_response(false, 'The Service column is required and cannot be deleted.', [], 422);
    }

    $delete = $pdo->prepare(
        "DELETE FROM invoice_item_column_settings
         WHERE id = ? AND business_id = ?"
    );
    $delete->execute([$columnId, $currentBusinessId]);

    json_response(true, 'Invoice column deleted.');
}
$rows=json_decode((string)($_POST['columns_json']??'[]'),true);
if(!is_array($rows)) json_response(false,'Invalid columns.',[],422);
$allowed=['system','text','number','date','select','checkbox','textarea'];
$systemKeys=['service','quantity','rate','gross_amount','discount_type','discount_value','final_amount'];
try{
 $pdo->beginTransaction();
 $seen=[];
 foreach($rows as $i=>$r){
  $id=(int)($r['id']??0); $key=strtolower(trim((string)($r['column_key']??'')));
  $key=preg_replace('/[^a-z0-9_]/','_',$key); $label=trim((string)($r['column_label']??''));
  $type=(string)($r['column_type']??'text');
  if($key===''||$label==='') throw new RuntimeException('Column label and key are required.');
  if(!in_array($type,$allowed,true))$type='text';
  if(in_array($key,$systemKeys,true))$type='system';
  if(isset($seen[$key]))throw new RuntimeException('Duplicate column key: '.$key);
  $seen[$key]=true;
  $vals=[$label,$key,$type,(int)!empty($r['is_visible']),(int)!empty($r['is_required']),(int)!empty($r['show_in_print']),(int)($r['sort_order']??(($i+1)*10))];
  if($id>0){
   $u=$pdo->prepare("UPDATE invoice_item_column_settings SET column_label=?,column_key=?,column_type=?,is_visible=?,is_required=?,show_in_print=?,sort_order=?,updated_at=NOW() WHERE id=? AND business_id=?");
   $u->execute([...$vals,$id,$currentBusinessId]);
  }else{
   $ins=$pdo->prepare("INSERT INTO invoice_item_column_settings(business_id,column_label,column_key,column_type,is_visible,is_required,show_in_print,sort_order,status) VALUES(?,?,?,?,?,?,?,?,'active')");
   $ins->execute([$currentBusinessId,...$vals]);
  }
 }
 $place=array_keys($seen);
 if($place){
  $marks=implode(',',array_fill(0,count($place),'?'));
  $pdo->prepare("UPDATE invoice_item_column_settings SET status='inactive' WHERE business_id=? AND column_key NOT IN ($marks)")->execute([$currentBusinessId,...$place]);
 }
 $pdo->commit(); json_response(true,'Invoice columns saved.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(false,$e->getMessage(),[],422);}
