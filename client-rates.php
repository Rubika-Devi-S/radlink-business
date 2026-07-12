<?php
declare(strict_types=1);

$pageTitle = 'Client Service Rates';
require_once __DIR__ . '/includes/bootstrap.php';

$clientsStmt=$pdo->prepare("SELECT id,client_code,client_name FROM clients WHERE business_id=? AND status='active' ORDER BY client_name");
$clientsStmt->execute([$currentBusinessId]);$clients=$clientsStmt->fetchAll();

$servicesStmt=$pdo->prepare("SELECT s.id,s.service_code,s.service_name,s.standard_rate,sc.category_name FROM services s JOIN service_categories sc ON sc.id=s.service_category_id WHERE s.business_id=? AND s.status='active' ORDER BY sc.sort_order,s.service_name");
$servicesStmt->execute([$currentBusinessId]);$services=$servicesStmt->fetchAll();

$stmt=$pdo->prepare(
 "SELECT csr.*,c.client_code,c.client_name,s.service_code,s.service_name,s.standard_rate,sc.category_name
  FROM client_service_rates csr
  JOIN clients c ON c.id=csr.client_id AND c.business_id=csr.business_id
  JOIN services s ON s.id=csr.service_id AND s.business_id=csr.business_id
  JOIN service_categories sc ON sc.id=s.service_category_id
  WHERE csr.business_id=?
  ORDER BY c.client_name,s.service_name,csr.effective_from DESC"
);
$stmt->execute([$currentBusinessId]);$rates=$stmt->fetchAll();

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
 <div><span class="badge-soft">SERVICES</span><h1 class="mt-2">Client Service Rates</h1><p>Override the standard service rate for a particular hospital or client.</p></div>
 <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#rateModal" id="newRateBtn"><i data-lucide="plus"></i> Add Client Rate</button>
</div>

<section class="card-ui p-3">
 <div class="module-toolbar mb-3">
  <div><h2 class="h6 fw-bold mb-1">Agreed Rates</h2><small class="text-muted">The active rate effective on the invoice date should override the standard rate</small></div>
  <div class="d-flex flex-wrap gap-2 module-search">
   <select class="form-select" id="clientFilter"><option value="">All clients</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['client_name']) ?></option><?php endforeach; ?></select>
   <input class="form-control" type="search" id="rateSearch" placeholder="Search rate...">
  </div>
 </div>

 <div class="desktop-table table-responsive">
  <table class="table align-middle mb-0"><thead><tr><th>Client</th><th>Service</th><th>Standard</th><th>Agreed</th><th>Effective</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php if(!$rates): ?><tr><td colspan="7" class="text-center py-4 text-muted">No client-specific service rates found.</td></tr><?php endif; ?>
  <?php foreach($rates as $rate): ?>
   <tr data-rate-row data-client="<?= (int)$rate['client_id'] ?>" data-search="<?= e(strtolower($rate['client_code'].' '.$rate['client_name'].' '.$rate['service_code'].' '.$rate['service_name'].' '.$rate['category_name'])) ?>">
    <td><span class="code-pill"><?= e($rate['client_code']) ?></span><div class="fw-bold mt-1"><?= e($rate['client_name']) ?></div></td>
    <td><span class="code-pill"><?= e($rate['service_code']) ?></span><div class="mt-1"><?= e($rate['service_name']) ?></div><small class="text-muted"><?= e($rate['category_name']) ?></small></td>
    <td>₹<?= number_format((float)$rate['standard_rate'],2) ?></td><td class="fw-bold text-success">₹<?= number_format((float)$rate['agreed_rate'],2) ?></td>
    <td><?= e(date('d M Y',strtotime($rate['effective_from']))) ?><br><small class="text-muted"><?= $rate['effective_to']?'to '.e(date('d M Y',strtotime($rate['effective_to']))):'No end date' ?></small></td>
    <td><span class="status-badge <?= $rate['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($rate['status'])) ?></span></td>
    <td><div class="action-group"><button class="btn btn-sm btn-outline-primary" data-view-rate data-record='<?= e(json_encode($rate,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>View</button><button class="btn btn-sm btn-light" data-edit-rate data-record='<?= e(json_encode($rate,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button><button class="btn btn-sm btn-outline-secondary" data-toggle-rate data-id="<?= (int)$rate['id'] ?>">Status</button><button class="btn btn-sm btn-outline-danger" data-delete-rate data-id="<?= (int)$rate['id'] ?>">Delete</button></div></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table>
 </div>

 <div class="mobile-card-list">
 <?php foreach($rates as $rate): ?>
  <article class="card-ui mobile-record" data-rate-row data-client="<?= (int)$rate['client_id'] ?>" data-search="<?= e(strtolower($rate['client_code'].' '.$rate['client_name'].' '.$rate['service_code'].' '.$rate['service_name'].' '.$rate['category_name'])) ?>">
   <div class="d-flex justify-content-between"><div><strong><?= e($rate['client_name']) ?></strong><small class="d-block text-muted"><?= e($rate['service_name']) ?></small></div><span class="status-badge <?= $rate['status']==='active'?'status-active':'status-inactive' ?>"><?= e(ucfirst($rate['status'])) ?></span></div>
   <div class="row g-2 mt-2"><div class="col-6 small">Standard<br><strong>₹<?= number_format((float)$rate['standard_rate'],2) ?></strong></div><div class="col-6 small">Agreed<br><strong class="text-success">₹<?= number_format((float)$rate['agreed_rate'],2) ?></strong></div></div>
   <div class="action-group mt-3"><button class="btn btn-sm btn-outline-primary" data-view-rate data-record='<?= e(json_encode($rate,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>View</button><button class="btn btn-sm btn-light" data-edit-rate data-record='<?= e(json_encode($rate,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>Edit</button><button class="btn btn-sm btn-outline-secondary" data-toggle-rate data-id="<?= (int)$rate['id'] ?>">Status</button><button class="btn btn-sm btn-outline-danger ms-auto" data-delete-rate data-id="<?= (int)$rate['id'] ?>">Delete</button></div>
  </article>
 <?php endforeach; ?>
 </div>
</section>


<div class="modal fade" id="rateViewModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content card-ui">
<div class="modal-header"><h5 class="modal-title">Client Service Rate Details</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
<div class="col-md-6"><small class="text-muted">Client / Hospital</small><strong class="d-block" id="viewRateClient"></strong></div>
<div class="col-md-6"><small class="text-muted">Service</small><strong class="d-block" id="viewRateService"></strong></div>
<div class="col-md-4"><small class="text-muted">Standard Rate</small><div id="viewRateStandard"></div></div>
<div class="col-md-4"><small class="text-muted">Agreed Rate</small><div class="fw-bold text-success" id="viewRateAgreed"></div></div>
<div class="col-md-4"><small class="text-muted">Status</small><div id="viewRateStatus"></div></div>
<div class="col-md-6"><small class="text-muted">Effective From</small><div id="viewRateFrom"></div></div>
<div class="col-md-6"><small class="text-muted">Effective To</small><div id="viewRateTo"></div></div>
<div class="col-12"><small class="text-muted">Notes</small><div id="viewRateNotes"></div></div>
</div></div></div></div></div>

<div class="modal fade" id="rateModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content card-ui">
<form id="rateForm">
<div class="modal-header"><h5 class="modal-title">Client Service Rate</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="rateId">
<div class="row g-3">
 <div class="col-md-6"><label class="form-label">Client / Hospital</label><select class="form-select" name="client_id" id="rateClientId" required><option value="">Select client</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['client_code'].' - '.$c['client_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label">Service</label><select class="form-select" name="service_id" id="rateServiceId" required><option value="">Select service</option><?php foreach($services as $s): ?><option value="<?= (int)$s['id'] ?>" data-standard-rate="<?= e($s['standard_rate']) ?>"><?= e($s['service_code'].' - '.$s['service_name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-4"><label class="form-label">Standard Rate</label><input class="form-control" id="displayStandardRate" readonly></div>
 <div class="col-md-4"><label class="form-label">Agreed Rate</label><input type="number" class="form-control" name="agreed_rate" id="agreedRate" min="0" step="0.01" required></div>
 <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status" id="rateStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
 <div class="col-md-6"><label class="form-label">Effective From</label><input type="date" class="form-control" name="effective_from" id="effectiveFrom" required></div>
 <div class="col-md-6"><label class="form-label">Effective To</label><input type="date" class="form-control" name="effective_to" id="effectiveTo"><div class="form-help">Leave empty when the rate has no fixed end date.</div></div>
 <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" id="rateNotes" rows="2" maxlength="500"></textarea></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save Rate</button></div>
</form></div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('rateForm'),modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal'));
 const rateViewModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('rateViewModal'));
 document.querySelectorAll('[data-view-rate]').forEach(btn=>btn.addEventListener('click',()=>{
  const r=JSON.parse(btn.dataset.record);
  viewRateClient.textContent=r.client_code+' - '+r.client_name;
  viewRateService.textContent=r.service_code+' - '+r.service_name;
  viewRateStandard.textContent='₹'+Number(r.standard_rate).toFixed(2);
  viewRateAgreed.textContent='₹'+Number(r.agreed_rate).toFixed(2);
  viewRateStatus.textContent=r.status.charAt(0).toUpperCase()+r.status.slice(1);
  viewRateFrom.textContent=r.effective_from;
  viewRateTo.textContent=r.effective_to||'No end date';
  viewRateNotes.textContent=r.notes||'—';
  rateViewModal.show();
 }));
 function standard(){const o=rateServiceId.options[rateServiceId.selectedIndex];displayStandardRate.value=o&&o.dataset.standardRate?'₹'+Number(o.dataset.standardRate).toFixed(2):''}
 rateServiceId.addEventListener('change',standard);
 const reset=()=>{form.reset();rateId.value='';effectiveFrom.value=new Date().toISOString().slice(0,10);rateStatus.value='active';displayStandardRate.value=''};
 newRateBtn.addEventListener('click',reset);
 document.querySelectorAll('[data-edit-rate]').forEach(btn=>btn.addEventListener('click',()=>{const r=JSON.parse(btn.dataset.record);rateId.value=r.id;rateClientId.value=r.client_id;rateServiceId.value=r.service_id;agreedRate.value=r.agreed_rate;effectiveFrom.value=r.effective_from;effectiveTo.value=r.effective_to||'';rateNotes.value=r.notes||'';rateStatus.value=r.status;standard();modal.show()}));
 async function send(fd){try{const res=await fetch('<?= e(app_url('api/client-service-rates.php')) ?>',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();AppToast.show(data.success?'success':'error',data.message);if(data.success)setTimeout(()=>location.reload(),350)}catch(e){AppToast.show('error','Unable to process client rate request.')}}
 form.addEventListener('submit',e=>{e.preventDefault();if(effectiveTo.value&&effectiveTo.value<effectiveFrom.value){AppToast.show('warning','Effective To cannot be earlier than Effective From.');return}send(new FormData(form))});
 document.querySelectorAll('[data-toggle-rate]').forEach(btn=>btn.addEventListener('click',()=>{const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','toggle');fd.append('id',btn.dataset.id);send(fd)}));
 document.querySelectorAll('[data-delete-rate]').forEach(btn=>btn.addEventListener('click',()=>{if(!confirm('Delete this client service rate?'))return;const fd=new FormData();fd.append('csrf_token',window.APP_CSRF);fd.append('action','delete');fd.append('id',btn.dataset.id);send(fd)}));
 function filter(){const q=rateSearch.value.trim().toLowerCase(),client=clientFilter.value;document.querySelectorAll('[data-rate-row]').forEach(row=>row.hidden=!(row.dataset.search.includes(q)&&(!client||row.dataset.client===client)))}
 rateSearch.addEventListener('input',filter);clientFilter.addEventListener('change',filter);
});
</script>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
