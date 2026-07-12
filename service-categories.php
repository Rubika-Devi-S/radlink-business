<?php
declare(strict_types=1);

$pageTitle = 'Service Categories';
require_once __DIR__ . '/includes/bootstrap.php';

$categories = [];
if ($currentBusinessId > 0) {
    $stmt = $pdo->prepare(
        "SELECT sc.*,
                (SELECT COUNT(*) FROM services s
                 WHERE s.business_id=sc.business_id
                   AND s.service_category_id=sc.id) AS service_count
         FROM service_categories sc
         WHERE sc.business_id=?
         ORDER BY sc.sort_order, sc.category_name"
    );
    $stmt->execute([$currentBusinessId]);
    $categories = $stmt->fetchAll();
}

$total = count($categories);
$active = count(array_filter($categories, fn($row) => $row['status'] === 'active'));

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.module-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.module-search{max-width:340px}
.module-stat{padding:16px;height:100%}
.module-stat small{color:var(--text-muted);font-weight:700}
.module-stat strong{display:block;font-size:24px;margin-top:5px}
.action-group{display:flex;flex-wrap:wrap;gap:6px}
.status-badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}
.status-active{background:#dcfce7;color:#15803d}
.status-inactive{background:#f1f5f9;color:#64748b}
.code-pill{display:inline-flex;padding:5px 8px;border-radius:9px;background:var(--sidebar-active);color:var(--brand);font-family:monospace;font-size:12px;font-weight:800}
.mobile-record{padding:14px}
.mobile-record+.mobile-record{margin-top:10px}
.form-help{font-size:11px;color:var(--text-muted)}
@media(max-width:767.98px){.module-search{max-width:none;width:100%}.module-toolbar{align-items:stretch}}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">SERVICES</span>
        <h1 class="mt-2">Service Categories</h1>
        <p>Create categories such as CT, MRI, X-Ray and Ultrasound.</p>
    </div>
    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#categoryModal" id="newCategoryBtn">
        <i data-lucide="plus"></i> Add Category
    </button>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card-ui module-stat"><small>Total Categories</small><strong><?= $total ?></strong></div></div>
    <div class="col-6 col-md-3"><div class="card-ui module-stat"><small>Active</small><strong><?= $active ?></strong></div></div>
</div>

<section class="card-ui p-3">
    <div class="module-toolbar mb-3">
        <div>
            <h2 class="h6 fw-bold mb-1">Category List</h2>
            <small class="text-muted">Business-wise service grouping</small>
        </div>
        <div class="input-group module-search">
            <span class="input-group-text"><i data-lucide="search"></i></span>
            <input type="search" class="form-control" id="categorySearch" placeholder="Search category...">
        </div>
    </div>

    <div class="desktop-table table-responsive">
        <table class="table align-middle mb-0" id="categoryTable">
            <thead><tr><th>Category</th><th>Description</th><th>Services</th><th>Sort</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$categories): ?><tr><td colspan="6" class="text-center py-4 text-muted">No service categories found.</td></tr><?php endif; ?>
            <?php foreach ($categories as $category): ?>
                <tr data-search-row="<?= e(strtolower($category['category_code'].' '.$category['category_name'].' '.$category['description'])) ?>">
                    <td><span class="code-pill"><?= e($category['category_code']) ?></span><div class="fw-bold mt-1"><?= e($category['category_name']) ?></div></td>
                    <td><?= e($category['description'] ?: '—') ?></td>
                    <td><?= (int)$category['service_count'] ?></td>
                    <td><?= (int)$category['sort_order'] ?></td>
                    <td><span class="status-badge <?= $category['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($category['status'])) ?></span></td>
                    <td><div class="action-group">
                        <button class="btn btn-sm btn-outline-primary" data-view-category data-record='<?= e(json_encode($category, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>View</button><button class="btn btn-sm btn-light" data-edit-category data-record='<?= e(json_encode($category, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button>
                        <button class="btn btn-sm btn-outline-secondary" data-toggle-category data-id="<?= (int)$category['id'] ?>">Status</button>
                        <button class="btn btn-sm btn-outline-danger" data-delete-category data-id="<?= (int)$category['id'] ?>">Delete</button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-card-list" id="categoryMobileList">
        <?php foreach ($categories as $category): ?>
            <article class="card-ui mobile-record" data-search-row="<?= e(strtolower($category['category_code'].' '.$category['category_name'].' '.$category['description'])) ?>">
                <div class="d-flex justify-content-between gap-2">
                    <div><span class="code-pill"><?= e($category['category_code']) ?></span><strong class="d-block mt-2"><?= e($category['category_name']) ?></strong></div>
                    <span class="status-badge <?= $category['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($category['status'])) ?></span>
                </div>
                <small class="text-muted d-block mt-2"><?= e($category['description'] ?: 'No description') ?></small>
                <div class="action-group mt-3">
                    <button class="btn btn-sm btn-outline-primary" data-view-category data-record='<?= e(json_encode($category, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>View</button><button class="btn btn-sm btn-light" data-edit-category data-record='<?= e(json_encode($category, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button>
                    <button class="btn btn-sm btn-outline-secondary" data-toggle-category data-id="<?= (int)$category['id'] ?>">Status</button>
                    <button class="btn btn-sm btn-outline-danger ms-auto" data-delete-category data-id="<?= (int)$category['id'] ?>">Delete</button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>


<div class="modal fade" id="categoryViewModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content card-ui">
<div class="modal-header"><h5 class="modal-title">Category Details</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><dl class="row mb-0">
<dt class="col-5">Code</dt><dd class="col-7" id="viewCategoryCode"></dd>
<dt class="col-5">Name</dt><dd class="col-7" id="viewCategoryName"></dd>
<dt class="col-5">Description</dt><dd class="col-7" id="viewCategoryDescription"></dd>
<dt class="col-5">Services</dt><dd class="col-7" id="viewCategoryCount"></dd>
<dt class="col-5">Sort Order</dt><dd class="col-7" id="viewCategorySort"></dd>
<dt class="col-5">Status</dt><dd class="col-7" id="viewCategoryStatus"></dd>
</dl></div></div></div></div>

<div class="modal fade" id="categoryModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content card-ui">
<form id="categoryForm">
    <div class="modal-header"><h5 class="modal-title">Service Category</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="categoryId">
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label">Category Code</label><input class="form-control text-uppercase" name="category_code" id="categoryCode" maxlength="40" required></div>
            <div class="col-md-7"><label class="form-label">Category Name</label><input class="form-control" name="category_name" id="categoryName" maxlength="150" required></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" id="categoryDescription" rows="3"></textarea></div>
            <div class="col-md-6"><label class="form-label">Sort Order</label><input type="number" class="form-control" name="sort_order" id="categorySort" value="0" min="0"></div>
            <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="categoryStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save Category</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('categoryForm');
 const categoryViewModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryViewModal'));
 document.querySelectorAll('[data-view-category]').forEach(btn=>btn.addEventListener('click',()=>{
  const r=JSON.parse(btn.dataset.record);
  viewCategoryCode.textContent=r.category_code;
  viewCategoryName.textContent=r.category_name;
  viewCategoryDescription.textContent=r.description||'—';
  viewCategoryCount.textContent=r.service_count||0;
  viewCategorySort.textContent=r.sort_order;
  viewCategoryStatus.textContent=r.status.charAt(0).toUpperCase()+r.status.slice(1);
  categoryViewModal.show();
 }));
 const modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal'));
 const reset=()=>{form.reset();categoryId.value='';categorySort.value='0';categoryStatus.value='active'};
 newCategoryBtn.addEventListener('click',reset);
 document.querySelectorAll('[data-edit-category]').forEach(btn=>btn.addEventListener('click',()=>{
   const r=JSON.parse(btn.dataset.record);categoryId.value=r.id;categoryCode.value=r.category_code;categoryName.value=r.category_name;categoryDescription.value=r.description||'';categorySort.value=r.sort_order;categoryStatus.value=r.status;modal.show();
 }));
 async function send(fd){try{const res=await fetch('<?= e(app_url('api/service-categories.php')) ?>',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();AppToast.show(data.success?'success':'error',data.message);if(data.success)setTimeout(()=>location.reload(),350)}catch(e){AppToast.show('error','Unable to process category request.')}} 
 form.addEventListener('submit',e=>{e.preventDefault();send(new FormData(form))});
 document.querySelectorAll('[data-toggle-category]').forEach(btn=>btn.addEventListener('click',()=>{const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','toggle');fd.append('id',btn.dataset.id);send(fd)}));
 document.querySelectorAll('[data-delete-category]').forEach(btn=>btn.addEventListener('click',()=>{if(!confirm('Delete this category? Categories used by services cannot be deleted.'))return;const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','delete');fd.append('id',btn.dataset.id);send(fd)}));
 categorySearch.addEventListener('input',()=>{const q=categorySearch.value.trim().toLowerCase();document.querySelectorAll('[data-search-row]').forEach(row=>row.hidden=!row.dataset.searchRow.includes(q))});
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
