<?php
declare(strict_types=1);
$pageTitle='Sidebar Control';
require_once __DIR__.'/includes/bootstrap.php';
if(!is_owner()){http_response_code(403);exit('Only owner can manage sidebar.');}
$menus=[];$parents=[];
if($currentBusinessId>0){
 $stmt=$pdo->prepare("SELECT * FROM business_sidebar_menus WHERE business_id=? ORDER BY COALESCE(parent_id,id),parent_id IS NOT NULL,sort_order,id");
 $stmt->execute([$currentBusinessId]);$menus=$stmt->fetchAll();
 foreach($menus as $m)if(empty($m['parent_id']))$parents[]=$m;
}
include __DIR__.'/includes/layout-start.php';
?>
<div class="page-head">
 <div><span class="badge-soft">DYNAMIC MODULES</span><h1 class="mt-2">Sidebar Control</h1><p>Create, arrange, show or hide menus for the selected business.</p></div>
 <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#menuModal" data-new-menu><i data-lucide="plus"></i> Add Menu</button>
</div>
<section class="card-ui p-3">
 <div class="desktop-table table-responsive"><table class="table align-middle"><thead><tr><th>Menu</th><th>Parent</th><th>URL</th><th>Sort</th><th>Sidebar</th><th>Status</th><th>Action</th></tr></thead><tbody>
 <?php if(!$menus): ?><tr><td colspan="7" class="text-center py-4">No menus found. Import the migration SQL.</td></tr><?php endif; ?>
 <?php foreach($menus as $m): $parentName='—';foreach($parents as $p)if((int)$p['id']===(int)$m['parent_id'])$parentName=$p['menu_title']; ?>
 <tr><td><div class="d-flex gap-2 align-items-center"><span class="brand-logo" style="width:34px;height:34px"><i data-lucide="<?= e($m['icon']) ?>"></i></span><div><strong><?= e($m['menu_title']) ?></strong><small class="d-block text-muted"><?= e($m['menu_slug']) ?></small></div></div></td><td><?= e($parentName) ?></td><td><code><?= e($m['menu_url']) ?></code></td><td><?= (int)$m['sort_order'] ?></td><td><button class="btn btn-sm <?= $m['show_in_sidebar']?'btn-success':'btn-outline-secondary' ?>" data-toggle-menu data-id="<?= (int)$m['id'] ?>" data-field="show_in_sidebar"><?= $m['show_in_sidebar']?'Shown':'Hidden' ?></button></td><td><button class="btn btn-sm <?= $m['is_active']?'btn-primary':'btn-outline-secondary' ?>" data-toggle-menu data-id="<?= (int)$m['id'] ?>" data-field="is_active"><?= $m['is_active']?'Active':'Inactive' ?></button></td><td><div class="d-flex gap-1"><button class="btn btn-sm btn-light" data-edit-menu data-menu='<?= e(json_encode($m,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button><button class="btn btn-sm btn-outline-danger" data-delete-menu data-id="<?= (int)$m['id'] ?>">Delete</button></div></td></tr>
 <?php endforeach; ?></tbody></table></div>
 <div class="mobile-card-list"><?php foreach($menus as $m): ?><article class="card-ui p-3"><div class="d-flex justify-content-between gap-2"><div><strong><?= e($m['menu_title']) ?></strong><small class="d-block text-muted"><?= e($m['menu_url']) ?></small></div><button class="btn btn-sm btn-light" data-edit-menu data-menu='<?= e(json_encode($m,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button></div><div class="d-flex gap-2 mt-3"><button class="btn btn-sm <?= $m['show_in_sidebar']?'btn-success':'btn-outline-secondary' ?>" data-toggle-menu data-id="<?= (int)$m['id'] ?>" data-field="show_in_sidebar"><?= $m['show_in_sidebar']?'Shown':'Hidden' ?></button><button class="btn btn-sm <?= $m['is_active']?'btn-primary':'btn-outline-secondary' ?>" data-toggle-menu data-id="<?= (int)$m['id'] ?>" data-field="is_active"><?= $m['is_active']?'Active':'Inactive' ?></button><button class="btn btn-sm btn-outline-danger ms-auto" data-delete-menu data-id="<?= (int)$m['id'] ?>">Delete</button></div></article><?php endforeach; ?></div>
</section>
<div class="modal fade" id="menuModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content card-ui"><form id="menuForm"><div class="modal-header"><h5 class="modal-title">Sidebar Menu</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<input type="hidden" name="action" value="save"><input type="hidden" name="id" id="menuId"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<div class="mb-3"><label class="form-label">Parent menu</label><select class="form-select" name="parent_id" id="parentId"><option value="0">Main menu</option><?php foreach($parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['menu_title']) ?></option><?php endforeach; ?></select></div>
<div class="row g-3"><div class="col-md-6"><label class="form-label">Menu title</label><input class="form-control" name="menu_title" id="menuTitle" required></div><div class="col-md-6"><label class="form-label">Slug</label><input class="form-control" name="menu_slug" id="menuSlug" placeholder="client-rates" required></div><div class="col-12"><label class="form-label">URL</label><input class="form-control" name="menu_url" id="menuUrl" value="#"></div><div class="col-md-8"><label class="form-label">Lucide icon</label><input class="form-control" name="icon" id="menuIcon" value="circle"></div><div class="col-md-4"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" id="sortOrder" value="0"></div></div>
<div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="show_in_sidebar" id="showSidebar" checked><label class="form-check-label">Show in sidebar</label></div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked><label class="form-check-label">Active</label></div>
</div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-brand" type="submit">Save Menu</button></div></form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const modalEl=document.getElementById('menuModal'),modal=bootstrap.Modal.getOrCreateInstance(modalEl),form=document.getElementById('menuForm');
 function reset(){form.reset();menuId.value='';parentId.value='0';menuUrl.value='#';menuIcon.value='circle';sortOrder.value='0';showSidebar.checked=true;isActive.checked=true}
 document.querySelector('[data-new-menu]').onclick=reset;
 document.querySelectorAll('[data-edit-menu]').forEach(b=>b.onclick=()=>{const m=JSON.parse(b.dataset.menu);menuId.value=m.id;parentId.value=m.parent_id||0;menuTitle.value=m.menu_title;menuSlug.value=m.menu_slug;menuUrl.value=m.menu_url;menuIcon.value=m.icon;sortOrder.value=m.sort_order;showSidebar.checked=Number(m.show_in_sidebar)===1;isActive.checked=Number(m.is_active)===1;modal.show()});
 async function post(fd){const r=await fetch('<?= e(app_url('api/sidebar-menu.php')) ?>',{method:'POST',body:fd});const j=await r.json();AppToast.show(j.success?'success':'error',j.message);if(j.success)setTimeout(()=>location.reload(),350)}
 form.onsubmit=e=>{e.preventDefault();post(new FormData(form))};
 document.querySelectorAll('[data-toggle-menu]').forEach(b=>b.onclick=()=>{const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','toggle');fd.append('id',b.dataset.id);fd.append('field',b.dataset.field);post(fd)});
 document.querySelectorAll('[data-delete-menu]').forEach(b=>b.onclick=()=>{if(!confirm('Delete this menu and its child menus?'))return;const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','delete');fd.append('id',b.dataset.id);post(fd)});
});
</script>
<?php include __DIR__.'/includes/layout-end.php'; ?>
