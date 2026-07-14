<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

$action = trim((string)($_POST['action'] ?? ''));

function normalize_role_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
    return trim(substr($value, 0, 50), '_');
}

function role_activity(PDO $pdo, int $businessId, string $action, string $roleKey, string $description): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs
                (business_id, user_id, module_key, action_type, entity_type,
                 entity_id, description, ip_address, user_agent)
             VALUES (?, ?, 'role_permissions', ?, 'role', NULL, ?, ?, ?)"
        );
        $stmt->execute([
            $businessId,
            current_user_id(),
            $action,
            substr($description . ' [' . $roleKey . ']', 0, 500),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000),
        ]);
    } catch (Throwable $e) {
        error_log('[ROLE ACTIVITY] ' . $e->getMessage());
    }
}

if ($action === 'save_role') {
    $originalKey = normalize_role_key((string)($_POST['original_role_key'] ?? ''));
    require_page_permission('role-permissions', $originalKey !== '' ? 'edit' : 'create');

    $roleName = trim((string)($_POST['role_name'] ?? ''));
    $roleKey = normalize_role_key((string)($_POST['role_key'] ?? $roleName));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'active'));

    if ($roleName === '' || $roleKey === '') {
        json_response(false, 'Role name and role key are required.', [], 422);
    }
    if (in_array($roleKey, ['owner','super_admin'], true) && $originalKey !== $roleKey) {
        json_response(false, 'The owner and super-admin keys are reserved.', [], 422);
    }
    if (!in_array($status, ['active','inactive'], true)) $status = 'active';

    try {
        $pdo->beginTransaction();

        if ($originalKey !== '') {
            $roleStmt = $pdo->prepare(
                "SELECT * FROM business_roles
                 WHERE business_id = ? AND role_key = ?
                 FOR UPDATE"
            );
            $roleStmt->execute([$currentBusinessId, $originalKey]);
            $existing = $roleStmt->fetch();
            if (!$existing) throw new RuntimeException('Role not found.');
            if ((int)$existing['is_system'] === 1 && $roleKey !== $originalKey) {
                throw new RuntimeException('A system role key cannot be changed.');
            }

            if ($roleKey !== $originalKey) {
                $dup = $pdo->prepare(
                    "SELECT id FROM business_roles
                     WHERE business_id = ? AND role_key = ? LIMIT 1"
                );
                $dup->execute([$currentBusinessId, $roleKey]);
                if ($dup->fetch()) throw new RuntimeException('Role key already exists.');
            }

            $pdo->prepare(
                "UPDATE business_roles
                 SET role_key = ?, role_name = ?, description = ?, status = ?
                 WHERE business_id = ? AND role_key = ?"
            )->execute([$roleKey,$roleName,$description ?: null,$status,$currentBusinessId,$originalKey]);

            if ($roleKey !== $originalKey) {
                $pdo->prepare(
                    "UPDATE business_role_sidebar_access
                     SET role_key = ?
                     WHERE business_id = ? AND role_key = ?"
                )->execute([$roleKey,$currentBusinessId,$originalKey]);

                $pdo->prepare(
                    "UPDATE user_business_access
                     SET access_role = ?
                     WHERE business_id = ? AND access_role = ?"
                )->execute([$roleKey,$currentBusinessId,$originalKey]);
            }

            role_activity($pdo,$currentBusinessId,'update',$roleKey,'Updated role ' . $roleName);
        } else {
            $insert=$pdo->prepare(
                "INSERT INTO business_roles
                    (business_id, role_key, role_name, description, is_system, status, created_by)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            );
            $insert->execute([$currentBusinessId,$roleKey,$roleName,$description ?: null,$status,current_user_id()]);
            role_activity($pdo,$currentBusinessId,'create',$roleKey,'Created role ' . $roleName);
        }

        $pdo->commit();
        json_response(true,'Role saved successfully.',['role_key'=>$roleKey]);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        json_response(false,$e->getMessage(),[],422);
    }
}

if ($action === 'save_access') {
    require_page_permission('role-permissions', 'edit');

    $roleKey = normalize_role_key((string)($_POST['role_key'] ?? ''));
    $permissions = $_POST['permissions'] ?? [];

    $roleStmt=$pdo->prepare(
        "SELECT role_key FROM business_roles
         WHERE business_id=? AND role_key=? AND status='active' LIMIT 1"
    );
    $roleStmt->execute([$currentBusinessId,$roleKey]);
    if(!$roleStmt->fetch())json_response(false,'Role is invalid or inactive.',[],422);
    if(!is_array($permissions))$permissions=[];

    try {
        $pdo->beginTransaction();

        $menusStmt=$pdo->prepare(
            "SELECT id FROM business_sidebar_menus
             WHERE business_id=? AND is_active=1"
        );
        $menusStmt->execute([$currentBusinessId]);
        $validMenuIds=array_map('intval',$menusStmt->fetchAll(PDO::FETCH_COLUMN));

        $pdo->prepare(
            "DELETE FROM business_role_sidebar_access
             WHERE business_id=? AND role_key=?"
        )->execute([$currentBusinessId,$roleKey]);

        $insert=$pdo->prepare(
            "INSERT INTO business_role_sidebar_access
                (business_id,role_key,menu_id,can_view,can_create,can_edit,can_delete,can_approve)
             VALUES (?,?,?,?,?,?,?,?)"
        );

        foreach($validMenuIds as $menuId){
            $row=is_array($permissions[$menuId]??null)?$permissions[$menuId]:[];
            $view=isset($row['view'])?1:0;
            $create=isset($row['create'])?1:0;
            $edit=isset($row['edit'])?1:0;
            $delete=isset($row['delete'])?1:0;
            $approve=isset($row['approve'])?1:0;
            if($create||$edit||$delete||$approve)$view=1;
            if(!$view&&!$create&&!$edit&&!$delete&&!$approve)continue;
            $insert->execute([$currentBusinessId,$roleKey,$menuId,$view,$create,$edit,$delete,$approve]);
        }

        role_activity($pdo,$currentBusinessId,'permission',$roleKey,'Updated role permissions');
        $pdo->commit();
        json_response(true,'Role permissions saved successfully.');
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        json_response(false,$e->getMessage(),[],422);
    }
}

if ($action === 'toggle_role') {
    require_page_permission('role-permissions', 'approve');

    $roleKey=normalize_role_key((string)($_POST['role_key']??''));
    $stmt=$pdo->prepare(
        "SELECT status,is_system FROM business_roles
         WHERE business_id=? AND role_key=? LIMIT 1"
    );
    $stmt->execute([$currentBusinessId,$roleKey]);$role=$stmt->fetch();
    if(!$role)json_response(false,'Role not found.',[],404);
    if((int)$role['is_system']===1)json_response(false,'System role status cannot be changed.',[],422);
    $newStatus=$role['status']==='active'?'inactive':'active';
    $pdo->prepare(
        "UPDATE business_roles SET status=?
         WHERE business_id=? AND role_key=?"
    )->execute([$newStatus,$currentBusinessId,$roleKey]);
    role_activity($pdo,$currentBusinessId,'status',$roleKey,'Changed role status to '.$newStatus);
    json_response(true,'Role status changed successfully.');
}

if ($action === 'delete_role') {
    require_page_permission('role-permissions', 'delete');

    $roleKey=normalize_role_key((string)($_POST['role_key']??''));
    try{
        $pdo->beginTransaction();
        $stmt=$pdo->prepare(
            "SELECT is_system FROM business_roles
             WHERE business_id=? AND role_key=? FOR UPDATE"
        );
        $stmt->execute([$currentBusinessId,$roleKey]);$role=$stmt->fetch();
        if(!$role)throw new RuntimeException('Role not found.');
        if((int)$role['is_system']===1)throw new RuntimeException('System roles cannot be deleted.');
        $users=$pdo->prepare(
            "SELECT COUNT(*) FROM user_business_access
             WHERE business_id=? AND access_role=?"
        );
        $users->execute([$currentBusinessId,$roleKey]);
        if((int)$users->fetchColumn()>0)throw new RuntimeException('Remove or reassign users before deleting this role.');
        $pdo->prepare(
            "DELETE FROM business_role_sidebar_access
             WHERE business_id=? AND role_key=?"
        )->execute([$currentBusinessId,$roleKey]);
        $pdo->prepare(
            "DELETE FROM business_roles
             WHERE business_id=? AND role_key=?"
        )->execute([$currentBusinessId,$roleKey]);
        role_activity($pdo,$currentBusinessId,'delete',$roleKey,'Deleted role');
        $pdo->commit();json_response(true,'Role deleted successfully.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(false,$e->getMessage(),[],422);}
}

json_response(false,'Invalid role action.',[],422);
