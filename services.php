<?php
declare(strict_types=1);

$pageTitle = 'Service List';
require_once __DIR__ . '/includes/bootstrap.php';

$categoriesStmt = $pdo->prepare(
    "SELECT id, category_code, category_name
     FROM service_categories
     WHERE business_id=? AND status='active'
     ORDER BY sort_order, category_name"
);
$categoriesStmt->execute([$currentBusinessId]);
$categories = $categoriesStmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.*, sc.category_code, sc.category_name
     FROM services s
     INNER JOIN service_categories sc
        ON sc.id=s.service_category_id
       AND sc.business_id=s.business_id
     WHERE s.business_id=?
     ORDER BY sc.sort_order, sc.category_name, s.service_name"
);
$stmt->execute([$currentBusinessId]);
$services = $stmt->fetchAll();

$total=count($services);
$active=count(array_filter($services,fn($r)=>$r['status']==='active'));
$avg=$total?array_sum(array_map(fn($r)=>(float)$r['standard_rate'],$services))/$total:0;

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.module-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.module-search{max-width:340px}
.module-stat{padding:10px 12px;height:100%;border-radius:13px}
.module-stat small{color:var(--text-muted);font-weight:700}
.module-stat strong{display:block;font-size:17px;margin-top:3px}
.action-group{display:flex;flex-wrap:wrap;gap:6px}
.status-badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;font-size:11px;font-weight:800}
.status-active{background:#dcfce7;color:#15803d}
.status-inactive{background:#f1f5f9;color:#64748b}
.code-pill{display:inline-flex;padding:5px 8px;border-radius:9px;background:var(--sidebar-active);color:var(--brand);font-family:monospace;font-size:12px;font-weight:800}
.mobile-record{padding:14px}
.mobile-record+.mobile-record{margin-top:10px}
.form-help{font-size:11px;color:var(--text-muted)}
@media(max-width:767.98px){.module-search{max-width:none;width:100%}.module-toolbar{align-items:stretch}}

/* Compact invoice-list reference UI */
.live-list-filter-card{padding:12px!important;border-radius:14px!important}
.live-list-filter-card .form-control,.live-list-filter-card .form-select,.live-list-filter-card .input-group-text{min-height:38px;padding-top:7px;padding-bottom:7px}
.live-list-status{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted)}
.live-list-status:before{content:"";width:7px;height:7px;border-radius:50%;background:#22c55e}
.compact-stat{padding:10px 12px!important;border-radius:13px!important}
.compact-stat small{font-size:11px;margin-bottom:3px!important}
.compact-stat strong{font-size:17px!important}
</style>
<style>
.action-icon-btn{
    width:36px;
    height:36px;
    padding:0 !important;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
}
.action-icon-btn svg,
.action-icon-btn i{
    width:17px;
    height:17px;
}
.action-group{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
</style>


<div class="page-head">
    <div><span class="badge-soft">SERVICES</span><h1 class="mt-2">Service List</h1><p>Manage scan and radiology reporting services.</p></div>
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#serviceModal" id="newServiceBtn"><i data-lucide="plus"></i> Add Service</button>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card-ui module-stat"><small>Total Services</small><strong><?= $total ?></strong></div></div>
    <div class="col-6 col-md-3"><div class="card-ui module-stat"><small>Active</small><strong><?= $active ?></strong></div></div>
    <div class="col-12 col-md-3"><div class="card-ui module-stat"><small>Average Rate</small><strong>₹<?= number_format($avg,2) ?></strong></div></div>
</div>

<section class="card-ui live-list-filter-card">
 <div class="module-toolbar mb-3">
   <div><h2 class="h6 fw-bold mb-1">Services</h2><small class="text-muted">Standard rates can be overridden by client-specific rates</small></div>
   <div class="d-flex flex-wrap gap-2 module-search">
      <select class="form-select" id="serviceCategoryFilter"><option value="">All categories</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['category_name']) ?></option><?php endforeach; ?></select>
      <input class="form-control" type="search" id="serviceSearch" placeholder="Search service...">
   </div>
 </div>

 <div class="desktop-table table-responsive">
  <table class="table align-middle mb-0"><thead><tr><th>Service</th><th>Category</th><th>Rate</th><th>Tax</th><th>HSN/SAC</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php if(!$services): ?><tr><td colspan="7" class="text-center py-4 text-muted">No services found.</td></tr><?php endif; ?>
  <?php foreach($services as $service): ?>
   <tr data-service-row data-category="<?= (int)$service['service_category_id'] ?>" data-search="<?= e(strtolower($service['service_code'].' '.$service['service_name'].' '.$service['category_name'].' '.$service['hsn_sac_code'])) ?>">
    <td><span class="code-pill"><?= e($service['service_code']) ?></span><div class="fw-bold mt-1"><?= e($service['service_name']) ?></div></td>
    <td><?= e($service['category_name']) ?></td><td>₹<?= number_format((float)$service['standard_rate'],2) ?></td><td><?= number_format((float)$service['tax_percent'],2) ?>%</td><td><?= e($service['hsn_sac_code'] ?: '—') ?></td>
    <td><span class="status-badge <?= $service['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($service['status'])) ?></span></td>
    <td><div class="action-group"><button type="button" class="btn btn-sm btn-outline-primary action-icon-btn" title="View" data-view-service data-record='<?= e(json_encode($service,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i data-lucide="eye"></i></button><button type="button" class="btn btn-sm btn-light action-icon-btn" title="Edit" data-edit-service data-record='<?= e(json_encode($service,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i data-lucide="pencil"></i></button><button type="button" class="btn btn-sm btn-outline-secondary action-icon-btn" data-toggle-service data-id="<?= (int)$service['id'] ?>" title="Status"><i data-lucide="refresh-cw"></i></button><button type="button" class="btn btn-sm btn-outline-danger action-icon-btn" data-delete-service data-id="<?= (int)$service['id'] ?>" title="Delete"><i data-lucide="trash-2"></i></button></div></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table>
 </div>

 <div class="mobile-card-list">
 <?php foreach($services as $service): ?>
  <article class="card-ui mobile-record" data-service-row data-category="<?= (int)$service['service_category_id'] ?>" data-search="<?= e(strtolower($service['service_code'].' '.$service['service_name'].' '.$service['category_name'].' '.$service['hsn_sac_code'])) ?>">
   <div class="d-flex justify-content-between gap-2"><div><span class="code-pill"><?= e($service['service_code']) ?></span><strong class="d-block mt-2"><?= e($service['service_name']) ?></strong><small class="text-muted"><?= e($service['category_name']) ?></small></div><span class="status-badge <?= $service['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($service['status'])) ?></span></div>
   <div class="row g-2 mt-2 small"><div class="col-6">Rate: <strong>₹<?= number_format((float)$service['standard_rate'],2) ?></strong></div><div class="col-6">Tax: <strong><?= number_format((float)$service['tax_percent'],2) ?>%</strong></div></div>
   <div class="action-group mt-3"><button type="button" class="btn btn-sm btn-outline-primary action-icon-btn" title="View" data-view-service data-record='<?= e(json_encode($service,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i data-lucide="eye"></i></button><button type="button" class="btn btn-sm btn-light action-icon-btn" title="Edit" data-edit-service data-record='<?= e(json_encode($service,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i data-lucide="pencil"></i></button><button type="button" class="btn btn-sm btn-outline-secondary action-icon-btn" data-toggle-service data-id="<?= (int)$service['id'] ?>" title="Status"><i data-lucide="refresh-cw"></i></button><button type="button" class="btn btn-sm btn-outline-danger action-icon-btn ms-auto" data-delete-service data-id="<?= (int)$service['id'] ?>" title="Delete"><i data-lucide="trash-2"></i></button></div>
  </article>
 <?php endforeach; ?>
 </div>
</section>


<div class="modal fade" id="serviceViewModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content card-ui">
<div class="modal-header"><h5 class="modal-title">Service Details</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
<div class="col-md-4"><small class="text-muted">Code</small><strong class="d-block" id="viewServiceCode"></strong></div>
<div class="col-md-8"><small class="text-muted">Name</small><strong class="d-block" id="viewServiceName"></strong></div>
<div class="col-md-4"><small class="text-muted">Category</small><div id="viewServiceCategory"></div></div>
<div class="col-md-4"><small class="text-muted">Rate</small><div id="viewServiceRate"></div></div>
<div class="col-md-4"><small class="text-muted">Tax</small><div id="viewServiceTax"></div></div>

<div class="col-md-4"><small class="text-muted">HSN/SAC</small><div id="viewServiceHsn"></div></div>
<div class="col-md-4"><small class="text-muted">Status</small><div id="viewServiceStatus"></div></div>
<div class="col-12"><small class="text-muted">Description</small><div id="viewServiceDescription"></div></div>
</div></div></div></div></div>

<div class="modal fade" id="serviceModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content card-ui">
<form id="serviceForm">
<div class="modal-header"><h5 class="modal-title">Service</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="serviceId">
<div class="row g-3">
 <div class="col-md-8"><label class="form-label">Category</label><select class="form-select" name="service_category_id" id="serviceCategoryId" required><option value="">Select category</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['category_code'].' - '.$c['category_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-4"><label class="form-label">Service Code</label><input class="form-control text-uppercase" name="service_code" id="serviceCode" maxlength="50" required></div>
 <input type="hidden" name="unit_name" id="unitName" value="SERVICE">
 <div class="col-12"><label class="form-label">Service Name</label><input class="form-control" name="service_name" id="serviceName" maxlength="200" required></div>
 <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" id="serviceDescription" rows="2"></textarea></div>
 <div class="col-md-4"><label class="form-label">Standard Rate</label><input type="number" class="form-control" name="standard_rate" id="standardRate" min="0" step="0.01" value="0.00" required></div>
 <div class="col-md-4"><label class="form-label">Tax %</label><input type="number" class="form-control" name="tax_percent" id="taxPercent" min="0" max="100" step="0.01" value="0.00" required></div>
 <div class="col-md-4"><label class="form-label">HSN / SAC</label><input class="form-control" name="hsn_sac_code" id="hsnSacCode" maxlength="30"></div>
 <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status" id="serviceStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save Service</button></div>
</form></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
 const form = document.getElementById('serviceForm');
 const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('serviceModal'));
 const serviceViewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('serviceViewModal'));

 const viewServiceCode = document.getElementById('viewServiceCode');
 const viewServiceName = document.getElementById('viewServiceName');
 const viewServiceCategory = document.getElementById('viewServiceCategory');
 const viewServiceRate = document.getElementById('viewServiceRate');
 const viewServiceTax = document.getElementById('viewServiceTax');
 const viewServiceHsn = document.getElementById('viewServiceHsn');
 const viewServiceStatus = document.getElementById('viewServiceStatus');
 const viewServiceDescription = document.getElementById('viewServiceDescription');

 const serviceId = document.getElementById('serviceId');
 const serviceCategoryId = document.getElementById('serviceCategoryId');
 const serviceCode = document.getElementById('serviceCode');
 const unitName = document.getElementById('unitName');
 const serviceName = document.getElementById('serviceName');
 const serviceDescription = document.getElementById('serviceDescription');
 const standardRate = document.getElementById('standardRate');
 const taxPercent = document.getElementById('taxPercent');
 const hsnSacCode = document.getElementById('hsnSacCode');
 const serviceStatus = document.getElementById('serviceStatus');
 const newServiceBtn = document.getElementById('newServiceBtn');
 const serviceSearch = document.getElementById('serviceSearch');
 const serviceCategoryFilter = document.getElementById('serviceCategoryFilter');

 document.querySelectorAll('[data-view-service]').forEach(button => {
  button.addEventListener('click', () => {
   try {
    const service = JSON.parse(button.dataset.record || '{}');
    viewServiceCode.textContent = service.service_code || '—';
    viewServiceName.textContent = service.service_name || '—';
    viewServiceCategory.textContent = service.category_name || '—';
    viewServiceRate.textContent = '₹' + Number(service.standard_rate || 0).toFixed(2);
    viewServiceTax.textContent = Number(service.tax_percent || 0).toFixed(2) + '%';
    viewServiceHsn.textContent = service.hsn_sac_code || '—';
    viewServiceStatus.textContent = service.status ? service.status.charAt(0).toUpperCase() + service.status.slice(1) : '—';
    viewServiceDescription.textContent = service.description || '—';
    serviceViewModal.show();
   } catch (error) {
    console.error('Unable to open service details:', error);
    AppToast.show('error', 'Unable to load service details.');
   }
  });
 });
 const reset=()=>{form.reset();serviceId.value='';unitName.value='SERVICE';standardRate.value='0.00';taxPercent.value='0.00';serviceStatus.value='active'};
 if (newServiceBtn) { newServiceBtn.addEventListener('click', reset); }
 document.querySelectorAll('[data-edit-service]').forEach(btn=>btn.addEventListener('click',()=>{const r=JSON.parse(btn.dataset.record);serviceId.value=r.id;serviceCategoryId.value=r.service_category_id;serviceCode.value=r.service_code;unitName.value='SERVICE';serviceName.value=r.service_name;serviceDescription.value=r.description||'';standardRate.value=r.standard_rate;taxPercent.value=r.tax_percent;hsnSacCode.value=r.hsn_sac_code||'';serviceStatus.value=r.status;modal.show()}));
 async function send(fd){try{const res=await fetch('<?= e(app_url('api/services.php')) ?>',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();AppToast.show(data.success?'success':'error',data.message);if(data.success)setTimeout(()=>location.reload(),350)}catch(e){AppToast.show('error','Unable to process service request.')}}
 form.addEventListener('submit',e=>{e.preventDefault();send(new FormData(form))});
 document.querySelectorAll('[data-toggle-service]').forEach(btn=>btn.addEventListener('click',()=>{const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','toggle');fd.append('id',btn.dataset.id);send(fd)}));
 document.querySelectorAll('[data-delete-service]').forEach(btn=>btn.addEventListener('click',()=>{if(!confirm('Delete this service? Used services cannot be deleted.'))return;const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','delete');fd.append('id',btn.dataset.id);send(fd)}));
 function filter(){const q=serviceSearch.value.trim().toLowerCase(),cat=serviceCategoryFilter.value;document.querySelectorAll('[data-service-row]').forEach(row=>row.hidden=!(row.dataset.search.includes(q)&&(!cat||row.dataset.category===cat)))}
 serviceSearch.addEventListener('input',filter);serviceCategoryFilter.addEventListener('change',filter);
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
