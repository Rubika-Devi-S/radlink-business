<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_owner()) json_response(false,'Only the owner can manage sidebar menus.',[],403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false,'Method not allowed',[],405);
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) json_response(false,'Session expired. Refresh and try again.',[],419);
if ($currentBusinessId <= 0) json_response(false,'Select a business first.',[],422);

$action = (string)($_POST['action'] ?? '');

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $title = trim((string)($_POST['menu_title'] ?? ''));
    $slug = trim((string)($_POST['menu_slug'] ?? ''));
    $url = trim((string)($_POST['menu_url'] ?? '#'));
    $icon = trim((string)($_POST['icon'] ?? 'circle'));
    $sort = (int)($_POST['sort_order'] ?? 0);
    $shown = isset($_POST['show_in_sidebar']) ? 1 : 0;
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($title==='' || $slug==='') json_response(false,'Menu title and slug are required.',[],422);
    if (!preg_match('/^[a-z0-9\-]+$/',$slug)) json_response(false,'Slug may contain lowercase letters, numbers and hyphens only.',[],422);
    $parent = $parentId > 0 ? $parentId : null;

    try {
        if ($id > 0) {
            $stmt=$pdo->prepare("UPDATE business_sidebar_menus SET parent_id=?,menu_title=?,menu_slug=?,menu_url=?,icon=?,sort_order=?,show_in_sidebar=?,is_active=? WHERE id=? AND business_id=?");
            $stmt->execute([$parent,$title,$slug,$url,$icon,$sort,$shown,$active,$id,$currentBusinessId]);
            json_response(true,'Sidebar menu updated.');
        }
        $stmt=$pdo->prepare("INSERT INTO business_sidebar_menus (business_id,parent_id,menu_title,menu_slug,menu_url,icon,sort_order,show_in_sidebar,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$currentBusinessId,$parent,$title,$slug,$url,$icon,$sort,$shown,$active,current_user_id()]);
        json_response(true,'Sidebar menu created.');
    } catch (PDOException $e) {
        json_response(false,$e->getCode()==='23000'?'Menu slug already exists.':'Unable to save menu.',[],422);
    }
}

if ($action === 'toggle') {
    $id=(int)($_POST['id']??0);
    $field=(string)($_POST['field']??'');
    if (!in_array($field,['show_in_sidebar','is_active'],true)) json_response(false,'Invalid field.',[],422);
    $stmt=$pdo->prepare("UPDATE business_sidebar_menus SET {$field}=IF({$field}=1,0,1) WHERE id=? AND business_id=?");
    $stmt->execute([$id,$currentBusinessId]);
    json_response(true,'Menu status changed.');
}

if ($action === 'delete') {
    $id=(int)($_POST['id']??0);
    $stmt=$pdo->prepare("DELETE FROM business_sidebar_menus WHERE id=? AND business_id=?");
    $stmt->execute([$id,$currentBusinessId]);
    json_response(true,'Menu deleted.');
}

json_response(false,'Unknown action.',[],422);
