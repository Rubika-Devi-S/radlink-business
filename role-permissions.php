<?php
declare(strict_types=1);

$pageTitle = 'Role Permissions';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';

require_page_permission('role-permissions', 'view');

$canCreate = can_access('role-permissions', 'create');
$canEdit = can_access('role-permissions', 'edit');
$canDelete = can_access('role-permissions', 'delete');
$canApprove = can_access('role-permissions', 'approve');

$rolesStmt = $pdo->prepare(
    "SELECT br.*,
            (SELECT COUNT(*) FROM user_business_access uba
             WHERE uba.business_id = br.business_id
               AND uba.access_role = br.role_key
               AND uba.status = 'active') AS user_count
     FROM business_roles br
     WHERE br.business_id = ?
     ORDER BY br.is_system DESC, br.role_name"
);
$rolesStmt->execute([$currentBusinessId]);
$roles = $rolesStmt->fetchAll();

$menusStmt = $pdo->prepare(
    "SELECT id, parent_id, menu_title, menu_slug, menu_url, icon, sort_order
     FROM business_sidebar_menus
     WHERE business_id = ?
       AND is_active = 1
     ORDER BY
        CASE WHEN parent_id IS NULL THEN id ELSE parent_id END,
        parent_id IS NOT NULL,
        sort_order,
        id"
);
$menusStmt->execute([$currentBusinessId]);
$menus = $menusStmt->fetchAll();

$accessStmt = $pdo->prepare(
    "SELECT role_key, menu_id, can_view, can_create, can_edit, can_delete, can_approve
     FROM business_role_sidebar_access
     WHERE business_id = ?"
);
$accessStmt->execute([$currentBusinessId]);
$accessMap = [];
foreach ($accessStmt->fetchAll() as $access) {
    $accessMap[$access['role_key']][(int)$access['menu_id']] = $access;
}

$stats = ['total'=>count($roles),'active'=>0,'system'=>0,'custom'=>0];
foreach ($roles as $role) {
    if ($role['status']==='active') $stats['active']++;
    if ((int)$role['is_system']===1) $stats['system']++; else $stats['custom']++;
}

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.role-page .page-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.role-page .page-heading h1{margin:8px 0 4px;font-weight:850}
.role-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.role-kpi{padding:18px;border:1px solid var(--border-soft);border-radius:18px;background:var(--card-bg);box-shadow:var(--shadow)}
.role-kpi small{display:block;color:var(--text-muted);font-weight:700}.role-kpi strong{display:block;font-size:22px;margin-top:4px}
.role-card{background:var(--card-bg);border:1px solid var(--border-soft);border-radius:18px;box-shadow:var(--shadow)}
.role-card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid var(--border-soft)}
.role-card-body{padding:18px}
.role-status{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}
.role-status.active{background:rgba(25,135,84,.12);color:#198754}.role-status.inactive{background:rgba(255,193,7,.15);color:#8a6500}
.permission-wrap{max-height:62vh;overflow:auto;border:1px solid var(--border-soft);border-radius:14px}
.permission-table{margin:0}.permission-table th,.permission-table td{padding:10px 9px;border-right:1px solid var(--border-soft);border-bottom:1px solid var(--border-soft);vertical-align:middle}
.permission-table thead th{position:sticky;top:0;z-index:2;background:var(--card-bg);font-size:11px;text-transform:uppercase}
.permission-table .section-row td{background:var(--body-bg);font-weight:850}
.permission-check,.select-all-check,.column-all-check,.section-all-check{width:18px;height:18px;cursor:pointer}
.role-mobile{display:none}
@media(max-width:991.98px){.role-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767.98px){.role-page .page-heading{flex-direction:column}.role-page .page-heading .btn{width:100%}.role-kpis{grid-template-columns:1fr 1fr}.role-desktop{display:none}.role-mobile{display:block}.role-mobile-card{padding:15px;margin-bottom:10px}}
@media(max-width:480px){.role-kpis{grid-template-columns:1fr}}
</style>

<div class="role-page">
    <div class="page-heading">
        <div><span class="badge-soft">ACCESS CONTROL</span><h1>Role Permissions</h1><p class="mb-0 text-muted">Create roles and control page-level View, Create, Edit, Delete and Approve actions.</p></div>
        <?php if ($canCreate): ?><button class="btn btn-brand" type="button" id="addRoleButton"><i data-lucide="shield-plus"></i> Add Role</button><?php endif; ?>
    </div>

    <div class="role-kpis">
        <div class="role-kpi"><small>Total Roles</small><strong><?= (int)$stats['total'] ?></strong></div>
        <div class="role-kpi"><small>Active Roles</small><strong><?= (int)$stats['active'] ?></strong></div>
        <div class="role-kpi"><small>System Roles</small><strong><?= (int)$stats['system'] ?></strong></div>
        <div class="role-kpi"><small>Custom Roles</small><strong><?= (int)$stats['custom'] ?></strong></div>
    </div>

    <section class="role-card">
        <div class="role-card-head">
            <div><h2 class="h6 fw-bold mb-1">Business Roles</h2><small class="text-muted">Access applies only to the selected business.</small></div>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('users.php')) ?>"><i data-lucide="users"></i> Users</a>
        </div>
        <div class="role-card-body">
            <div class="role-desktop table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Role</th><th>Role Key</th><th>Users</th><th>Type</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><strong><?= e($role['role_name']) ?></strong><small class="d-block text-muted"><?= e($role['description'] ?: '') ?></small></td>
                            <td><code><?= e($role['role_key']) ?></code></td>
                            <td><?= (int)$role['user_count'] ?></td>
                            <td><?= (int)$role['is_system']===1 ? 'System' : 'Custom' ?></td>
                            <td><span class="role-status <?= e($role['status']) ?>"><?= e(ucfirst($role['status'])) ?></span></td>
                            <td class="text-end">
                                <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-primary edit-role" type="button" data-role='<?= e(json_encode($role,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i data-lucide="pencil"></i></button><button class="btn btn-sm btn-outline-secondary edit-access" type="button" data-role-key="<?= e($role['role_key']) ?>" data-role-name="<?= e($role['role_name']) ?>"><i data-lucide="key-round"></i> Access</button><?php endif; ?>
                                <?php if ($canApprove && (int)$role['is_system']===0): ?><button class="btn btn-sm btn-outline-warning toggle-role" type="button" data-role-key="<?= e($role['role_key']) ?>"><i data-lucide="power"></i></button><?php endif; ?>
                                <?php if ($canDelete && (int)$role['is_system']===0 && (int)$role['user_count']===0): ?><button class="btn btn-sm btn-outline-danger delete-role" type="button" data-role-key="<?= e($role['role_key']) ?>"><i data-lucide="trash-2"></i></button><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="role-mobile">
                <?php foreach ($roles as $role): ?>
                    <article class="role-card role-mobile-card">
                        <div class="d-flex justify-content-between"><div><strong><?= e($role['role_name']) ?></strong><small class="d-block text-muted"><?= e($role['role_key']) ?></small></div><span class="role-status <?= e($role['status']) ?>"><?= e(ucfirst($role['status'])) ?></span></div>
                        <div class="small mt-2">Users: <strong><?= (int)$role['user_count'] ?></strong> · <?= (int)$role['is_system']===1?'System':'Custom' ?></div>
                        <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-secondary w-100 mt-3 edit-access" type="button" data-role-key="<?= e($role['role_key']) ?>" data-role-name="<?= e($role['role_name']) ?>">Manage Access</button><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-ui">
            <form id="roleForm">
                <div class="modal-header"><div><h5 class="modal-title" id="roleModalTitle">Add Role</h5><small class="text-muted">Create a reusable business access role.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_role"><input type="hidden" name="original_role_key" id="originalRoleKey">
                    <div class="mb-3"><label class="form-label fw-semibold">Role Name <span class="text-danger">*</span></label><input class="form-control" name="role_name" id="roleName" maxlength="100" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Role Key</label><input class="form-control" name="role_key" id="roleKey" maxlength="50" placeholder="Auto-generated"><div class="form-text">Lowercase letters, numbers and underscore only.</div></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Description</label><textarea class="form-control" name="description" id="roleDescription" rows="3" maxlength="500"></textarea></div>
                    <div><label class="form-label fw-semibold">Status</label><select class="form-select" name="status" id="roleStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit" id="saveRoleButton">Save Role</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="accessModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content card-ui">
            <form id="accessForm">
                <div class="modal-header"><div><h5 class="modal-title">Role Access</h5><small class="text-muted" id="accessRoleText">Configure page and action permissions.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_access"><input type="hidden" name="role_key" id="accessRoleKey">
                    <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                        <label class="fw-semibold"><input class="form-check-input select-all-check me-2" type="checkbox" id="selectAllPermissions">Select All Permissions</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach (['view'=>'View','create'=>'Create','edit'=>'Edit','delete'=>'Delete','approve'=>'Approve'] as $permission=>$label): ?>
                                <label class="small fw-semibold"><input class="form-check-input column-all-check me-1" type="checkbox" data-column="<?= e($permission) ?>">All <?= e($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="permission-wrap">
                        <table class="table permission-table">
                            <thead><tr><th>Page / Menu</th><th class="text-center">View</th><th class="text-center">Create</th><th class="text-center">Edit</th><th class="text-center">Delete</th><th class="text-center">Approve</th></tr></thead>
                            <tbody>
                            <?php foreach ($menus as $menu): ?>
                                <?php $sectionId=(int)($menu['parent_id'] ?: $menu['id']);$isParent=$menu['parent_id']===null; ?>
                                <tr class="<?= $isParent?'section-row':'' ?>">
                                    <td>
                                        <?php if($isParent): ?><label><input class="form-check-input section-all-check me-2" type="checkbox" data-section="<?= $sectionId ?>"><?= e($menu['menu_title']) ?></label>
                                        <?php else: ?><div class="ps-3">— <?= e($menu['menu_title']) ?></div><?php endif; ?>
                                        <small class="d-block text-muted <?= $isParent?'':'ps-3' ?>"><?= e($menu['menu_slug']) ?> · <?= e($menu['menu_url']) ?></small>
                                    </td>
                                    <?php foreach (['view','create','edit','delete','approve'] as $permission): ?>
                                        <td class="text-center"><input class="form-check-input permission-check" type="checkbox" data-section="<?= $sectionId ?>" data-menu-id="<?= (int)$menu['id'] ?>" data-permission="<?= e($permission) ?>" name="permissions[<?= (int)$menu['id'] ?>][<?= e($permission) ?>]" value="1"></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit" id="saveAccessButton">Save Permissions</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const roleModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('roleModal'));
    const accessModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('accessModal'));
    const accessData=<?= json_encode($accessMap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

    function slug(value){return String(value||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,50)}
    document.getElementById('addRoleButton')?.addEventListener('click',()=>{document.getElementById('roleForm').reset();document.getElementById('originalRoleKey').value='';document.getElementById('roleModalTitle').textContent='Add Role';roleModal.show()});
    document.getElementById('roleName').addEventListener('input',()=>{if(!document.getElementById('originalRoleKey').value)document.getElementById('roleKey').value=slug(document.getElementById('roleName').value)});

    document.querySelectorAll('.edit-role').forEach(button=>button.addEventListener('click',()=>{const role=JSON.parse(button.dataset.role);document.getElementById('roleForm').reset();document.getElementById('roleModalTitle').textContent='Edit Role';document.getElementById('originalRoleKey').value=role.role_key;document.getElementById('roleName').value=role.role_name||'';document.getElementById('roleKey').value=role.role_key||'';document.getElementById('roleDescription').value=role.description||'';document.getElementById('roleStatus').value=role.status||'active';roleModal.show()}));

    function refreshPermissionSelectors(){
        document.querySelectorAll('.section-all-check').forEach(section=>{const checks=[...document.querySelectorAll(`.permission-check[data-section="${section.dataset.section}"]`)];const count=checks.filter(c=>c.checked).length;section.checked=checks.length>0&&count===checks.length;section.indeterminate=count>0&&count<checks.length});
        document.querySelectorAll('.column-all-check').forEach(column=>{const checks=[...document.querySelectorAll(`.permission-check[data-permission="${column.dataset.column}"]`)];const count=checks.filter(c=>c.checked).length;column.checked=checks.length>0&&count===checks.length;column.indeterminate=count>0&&count<checks.length});
        const all=[...document.querySelectorAll('.permission-check')],count=all.filter(c=>c.checked).length,box=document.getElementById('selectAllPermissions');box.checked=all.length>0&&count===all.length;box.indeterminate=count>0&&count<all.length;
    }

    document.querySelectorAll('.edit-access').forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.roleKey;document.getElementById('accessRoleKey').value=key;document.getElementById('accessRoleText').textContent=`Configure access for ${button.dataset.roleName}`;document.querySelectorAll('.permission-check').forEach(c=>c.checked=false);const roleAccess=accessData[key]||{};Object.keys(roleAccess).forEach(menuId=>['view','create','edit','delete','approve'].forEach(permission=>{const input=document.querySelector(`.permission-check[data-menu-id="${menuId}"][data-permission="${permission}"]`);if(input)input.checked=Number(roleAccess[menuId][`can_${permission}`]||0)===1}));refreshPermissionSelectors();accessModal.show()}));

    document.querySelectorAll('.permission-check').forEach(c=>c.addEventListener('change',()=>{if(c.dataset.permission!=='view'&&c.checked){document.querySelector(`.permission-check[data-menu-id="${c.dataset.menuId}"][data-permission="view"]`).checked=true}if(c.dataset.permission==='view'&&!c.checked){document.querySelectorAll(`.permission-check[data-menu-id="${c.dataset.menuId}"]`).forEach(x=>x.checked=false)}refreshPermissionSelectors()}));
    document.querySelectorAll('.section-all-check').forEach(c=>c.addEventListener('change',()=>{document.querySelectorAll(`.permission-check[data-section="${c.dataset.section}"]`).forEach(x=>x.checked=c.checked);refreshPermissionSelectors()}));
    document.querySelectorAll('.column-all-check').forEach(c=>c.addEventListener('change',()=>{document.querySelectorAll(`.permission-check[data-permission="${c.dataset.column}"]`).forEach(x=>x.checked=c.checked);if(c.dataset.column!=='view'&&c.checked)document.querySelectorAll('.permission-check[data-permission="view"]').forEach(x=>x.checked=true);refreshPermissionSelectors()}));
    document.getElementById('selectAllPermissions').addEventListener('change',e=>{document.querySelectorAll('.permission-check').forEach(x=>x.checked=e.target.checked);refreshPermissionSelectors()});

    async function submitForm(form,buttonId){
        const button=document.getElementById(buttonId),original=button.innerHTML;button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
        try{const response=await fetch('<?= e(app_url('api/role-permissions.php')) ?>',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const raw=await response.text();let result;try{result=JSON.parse(raw)}catch{throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}AppToast.show(result.success?'success':'error',result.message);if(result.success)setTimeout(()=>location.reload(),450)}
        catch(error){AppToast.show('error',error.message||'Unable to save permissions.')}finally{button.disabled=false;button.innerHTML=original}
    }
    document.getElementById('roleForm').addEventListener('submit',e=>{e.preventDefault();submitForm(e.target,'saveRoleButton')});
    document.getElementById('accessForm').addEventListener('submit',e=>{e.preventDefault();submitForm(e.target,'saveAccessButton')});

    async function roleAction(action,key,message){if(!confirm(message))return;const fd=new FormData();fd.set('csrf_token','<?= e(csrf_token()) ?>');fd.set('action',action);fd.set('role_key',key);const response=await fetch('<?= e(app_url('api/role-permissions.php')) ?>',{method:'POST',body:fd,credentials:'same-origin',headers:{Accept:'application/json'}});const result=await response.json();AppToast.show(result.success?'success':'error',result.message);if(result.success)setTimeout(()=>location.reload(),450)}
    document.querySelectorAll('.toggle-role').forEach(b=>b.addEventListener('click',()=>roleAction('toggle_role',b.dataset.roleKey,'Change this role status?')));
    document.querySelectorAll('.delete-role').forEach(b=>b.addEventListener('click',()=>roleAction('delete_role',b.dataset.roleKey,'Delete this role and its permissions?')));

    if(window.lucide)lucide.createIcons();
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
