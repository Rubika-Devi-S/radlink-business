<?php
declare(strict_types=1);

$pageTitle = 'Hospital List';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';

require_page_permission('clients','view');

$canCreateHospital = can_access('clients','create');
$canEditHospital = can_access('clients','edit');
$canDeleteHospital = can_access('clients','delete');
$canApproveHospital = can_access('clients','approve');


$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');

$where = [
    'c.business_id = ?',
    "(ct.type_key = 'hospital' OR LOWER(ct.type_name) = 'hospital')"
];
$params = [$currentBusinessId];

if ($search !== '') {
    $where[] = "(c.client_code LIKE ? OR c.client_name LIKE ? OR c.mobile LIKE ? OR c.email LIKE ? OR c.city LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}

$stmt = $pdo->prepare(
    "SELECT c.*,
            COUNT(DISTINCT child.id) AS client_count,
            COALESCE((
                SELECT COUNT(*)
                FROM invoices i
                WHERE i.business_id = c.business_id
                  AND i.client_id = c.id
            ), 0) AS invoice_count
     FROM clients c
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     LEFT JOIN clients child
        ON child.parent_hospital_id = c.id
       AND child.business_id = c.business_id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY c.id
     ORDER BY c.client_name"
);
$stmt->execute($params);
$hospitals = $stmt->fetchAll();

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.master-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: space-between;
    align-items: center
}

.master-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px
}

.master-card {
    padding: 15px
}

.master-card+.master-card {
    margin-top: 10px
}

.status-pill {
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800
}

.status-active {
    background: #dcfce7;
    color: #15803d
}

.status-inactive {
    background: #f1f5f9;
    color: #64748b
}

@media(max-width:767.98px) {
    .master-toolbar>* {
        width: 100%
    }
}

#hospitalModal .modal-dialog {
    height: calc(100vh - 32px);
}

#hospitalModal .modal-content {
    max-height: 100%;
    overflow: hidden;
}

#hospitalModal #hospitalForm {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

#hospitalModal .modal-header {
    flex: 0 0 auto;
}

#hospitalModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding-bottom: 28px;
}

#hospitalModal .modal-footer {
    flex: 0 0 auto;
    position: sticky;
    bottom: 0;
    z-index: 20;
    background: var(--card-bg, #fff);
    border-top: 1px solid var(--border-soft, #e5e7eb);
    box-shadow: 0 -8px 20px rgba(15, 23, 42, .08);
}

#hospitalModalSaveButton {
    min-width: 165px;
}

@media (max-width: 767.98px) {
    #hospitalModal .modal-dialog {
        height: calc(100vh - 16px);
        margin: 8px;
    }

    #hospitalModal .modal-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    #hospitalModal .modal-footer .btn {
        width: 100%;
        margin: 0;
    }
}

.action-icon-btn {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    padding: 0 !important;
}

.action-icon-btn i {
    width: 16px;
    height: 16px;
}

/* Compact invoice-list reference UI */
.live-list-filter-card{padding:12px!important;border-radius:14px!important}
.live-list-filter-card .form-control,.live-list-filter-card .form-select,.live-list-filter-card .input-group-text{min-height:38px;padding-top:7px;padding-bottom:7px}
.live-list-status{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted)}
.live-list-status:before{content:"";width:7px;height:7px;border-radius:50%;background:#22c55e}
.compact-stat{padding:10px 12px!important;border-radius:13px!important}
.compact-stat small{font-size:11px;margin-bottom:3px!important}
.compact-stat strong{font-size:17px!important}

.hospital-filter-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 2fr) minmax(160px, .8fr) auto;
    gap: 10px;
    align-items: center;
}

.hospital-filter-toolbar .input-group {
    max-width: none !important;
}

.hospital-list-table td,
.hospital-list-table td *,
.hospital-list-table strong,
.hospital-list-table .fw-bold {
    font-weight: 400 !important;
}

.hospital-list-table th {
    font-weight: 700;
}

.hospital-list-table td small {
    font-weight: 400 !important;
}

.hospital-row-hidden {
    display: none !important;
}

.hospital-empty-filter {
    display: none;
    padding: 28px 15px;
    text-align: center;
    color: var(--text-muted);
}

@media(max-width:767.98px) {
    .hospital-filter-toolbar {
        grid-template-columns: 1fr;
    }

    .hospital-filter-toolbar > * {
        width: 100%;
    }
}

</style>

<div class="page-head">
    <div>
        <span class="badge-soft">CLIENTS / HOSPITALS</span>
        <h1 class="mt-2">Hospital List</h1>
        <p>Manage hospitals and view linked clients.</p>
    </div>
    <?php if ($canCreateHospital): ?>
    <button class="btn btn-brand" type="button" id="addHospitalButton">
        <i data-lucide="plus"></i> Add New Hospital
    </button>
    <?php endif; ?>
</div>

<section class="card-ui live-list-filter-card mb-3">
    <form method="get" class="master-toolbar hospital-filter-toolbar" id="hospitalLiveFilter">
        <div class="input-group" style="max-width:460px">
            <span class="input-group-text"><i data-lucide="search"></i></span>
            <input class="form-control" type="search" name="q" id="hospitalLiveSearch" value="<?= e($search) ?>"
                placeholder="Search hospital code, name, mobile, email or city">
        </div>
        <div class="d-flex gap-2">
            <select class="form-select" name="status" id="hospitalStatusFilter">
                <option value="">All Status</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button class="btn btn-light" type="button" id="hospitalFilterReset">
                <i data-lucide="rotate-ccw"></i> Reset
            </button>
        </div>
    </form>
</section>

<section class="card-ui p-3">
    <div class="desktop-table table-responsive">
        <table class="table align-middle mb-0 hospital-list-table">
            <thead>
                <tr>
                    <th>Hospital</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Credit</th>
                    <th>Clients</th>
                    <th>Invoices</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$hospitals): ?><tr>
                    <td colspan="8" class="text-center py-4 text-muted">No hospitals found.</td>
                </tr><?php endif; ?>
                <?php foreach ($hospitals as $hospital): ?>
                <tr data-hospital-row
                    data-status="<?= e($hospital['status']) ?>"
                    data-search="<?= e(strtolower(implode(' ', array_filter([
                        $hospital['client_code'],
                        $hospital['client_name'],
                        $hospital['contact_person'],
                        $hospital['mobile'],
                        $hospital['alternate_mobile'],
                        $hospital['email'],
                        $hospital['city'],
                        $hospital['district'],
                        $hospital['state'],
                        $hospital['gst_number']
                    ])))) ?>">
                    <td><strong><?= e($hospital['client_name']) ?></strong><small
                            class="d-block text-muted"><?= e($hospital['client_code']) ?></small></td>
                    <td><?= e($hospital['mobile'] ?: '—') ?><small
                            class="d-block text-muted"><?= e($hospital['email'] ?: '') ?></small></td>
                    <td><?= e(implode(', ', array_filter([$hospital['city'], $hospital['district'], $hospital['state']]))) ?: '—' ?>
                    </td>
                    <td><?= (int)$hospital['credit_period_days'] ?> Days</td>
                    <td><a
                            href="<?= e(app_url('client-list.php?hospital_id=' . (int)$hospital['id'])) ?>"><?= (int)$hospital['client_count'] ?></a>
                    </td>
                    <td><?= (int)$hospital['invoice_count'] ?></td>
                    <td><span
                            class="status-pill <?= $hospital['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= e(ucfirst($hospital['status'])) ?></span>
                    </td>
                    <td>
                        <div class="master-actions">
                            <?php if (can_access('clients','view')): ?><button
                                class="btn btn-sm btn-light action-icon-btn" title="View" type="button"
                                data-view-hospital
                                data-record='<?= e(json_encode($hospital, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="eye"></i>

                            </button>
                            <?php if ($canEditHospital): ?>
                            <button class="btn btn-sm btn-outline-primary action-icon-btn" title="Edit" type="button"
                                data-edit-hospital
                                data-record='<?= e(json_encode($hospital, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="pencil"></i>

                            </button>
                            <button class="btn btn-sm btn-outline-secondary action-icon-btn" title="Change Status"
                                type="button" data-action="toggle" data-entity="hospital"
                                data-id="<?= (int)$hospital['id'] ?>">
                                <i data-lucide="power"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger action-icon-btn" title="Delete" type="button"
                                data-action="delete" data-entity="hospital" data-id="<?= (int)$hospital['id'] ?>">
                                <i data-lucide="trash-2"></i>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="hospital-empty-filter" id="hospitalDesktopEmpty">
            <i data-lucide="search-x"></i>
            <div class="mt-2">No matching hospitals</div>
            <small>Change the search text or status filter.</small>
        </div>
    </div>

    <div class="mobile-card-list">
        <?php foreach ($hospitals as $hospital): ?>
        <article class="card-ui master-card"
            data-hospital-row
            data-status="<?= e($hospital['status']) ?>"
            data-search="<?= e(strtolower(implode(' ', array_filter([
                $hospital['client_code'],
                $hospital['client_name'],
                $hospital['contact_person'],
                $hospital['mobile'],
                $hospital['alternate_mobile'],
                $hospital['email'],
                $hospital['city'],
                $hospital['district'],
                $hospital['state'],
                $hospital['gst_number']
            ])))) ?>">
            <div class="d-flex justify-content-between gap-2">
                <div><strong><?= e($hospital['client_name']) ?></strong><small
                        class="d-block text-muted"><?= e($hospital['client_code']) ?></small></div>
                <span
                    class="status-pill <?= $hospital['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= e(ucfirst($hospital['status'])) ?></span>
            </div>
            <div class="row g-2 mt-2 small">
                <div class="col-6">Mobile<br><strong><?= e($hospital['mobile'] ?: '—') ?></strong></div>
                <div class="col-6">Credit<br><strong><?= (int)$hospital['credit_period_days'] ?> Days</strong></div>
                <div class="col-6">Clients<br><strong><?= (int)$hospital['client_count'] ?></strong></div>
                <div class="col-6">Invoices<br><strong><?= (int)$hospital['invoice_count'] ?></strong></div>
            </div>
            <div class="master-actions mt-3">
                <?php if (can_access('clients','view')): ?><button class="btn btn-sm btn-light action-icon-btn"
                    title="View" type="button" data-view-hospital
                    data-record='<?= e(json_encode($hospital, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="eye"></i>
                </button>
                <?php if ($canEditHospital): ?>
                <button class="btn btn-sm btn-outline-primary action-icon-btn" title="Edit" type="button"
                    data-edit-hospital data-record='<?= e(json_encode($hospital, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="pencil"></i>
                </button>
                <a class="btn btn-sm btn-outline-primary"
                    href="<?= e(app_url('client-list.php?hospital_id=' . (int)$hospital['id'])) ?>">Clients</a>
                <button class="btn btn-sm btn-outline-secondary action-icon-btn" title="Change Status" type="button"
                    data-action="toggle" data-entity="hospital" data-id="<?= (int)$hospital['id'] ?>">
                    <i data-lucide="power"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger action-icon-btn ms-auto" title="Delete" type="button"
                    data-action="delete" data-entity="hospital" data-id="<?= (int)$hospital['id'] ?>">
                    <i data-lucide="trash-2"></i>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
        <div class="hospital-empty-filter" id="hospitalMobileEmpty">
            <i data-lucide="search-x"></i>
            <div class="mt-2">No matching hospitals</div>
            <small>Change the search text or status filter.</small>
        </div>
    </div>
</section>

<div class="modal fade" id="hospitalModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content card-ui">
            <form id="hospitalForm">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1" id="hospitalModalTitle">Quick Add Hospital</h5><small
                            class="text-muted" id="hospitalModalSubtitle">Enter hospital details and save.</small>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id">
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Hospital Name</label><input class="form-control"
                                name="client_name" required></div>
                        <div class="col-md-4"><label class="form-label">Hospital Code</label><input
                                class="form-control text-uppercase" name="client_code" placeholder="Auto-generated">
                        </div>
                        <div class="col-md-6"><label class="form-label">Contact Person</label><input
                                class="form-control" name="contact_person"></div>
                        <div class="col-md-3"><label class="form-label">Mobile</label><input class="form-control"
                                name="mobile"></div>
                        <div class="col-md-3"><label class="form-label">Alternate Mobile</label><input
                                class="form-control" name="alternate_mobile"></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control"
                                type="email" name="email"></div>
                        <div class="col-md-6"><label class="form-label">GST Number</label><input
                                class="form-control text-uppercase" name="gst_number"></div>
                        <div class="col-md-6"><label class="form-label">Address Line 1</label><input
                                class="form-control" name="address_line_1"></div>
                        <div class="col-md-6"><label class="form-label">Address Line 2</label><input
                                class="form-control" name="address_line_2"></div>
                        <div class="col-md-3"><label class="form-label">City</label><input class="form-control"
                                name="city"></div>
                        <div class="col-md-3"><label class="form-label">District</label><input class="form-control"
                                name="district"></div>
                        <div class="col-md-3"><label class="form-label">State</label><input class="form-control"
                                name="state"></div>
                        <div class="col-md-3"><label class="form-label">Postal Code</label><input class="form-control"
                                name="postal_code"></div>
                        <div class="col-md-4"><label class="form-label">Credit Period</label><input class="form-control"
                                type="number" name="credit_period_days" min="0" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Billing Mode</label><select class="form-select"
                                name="default_billing_mode">
                                <option value="credit">Hospital Credit</option>
                                <option value="direct">Direct</option>
                                <option value="mixed">Mixed</option>
                            </select></div>
                        <div class="col-md-4"><label class="form-label">Status</label><select class="form-select"
                                name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select></div>
                        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control"
                                name="notes" rows="3"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-brand px-4" type="submit" id="hospitalModalSaveButton">
                        <i data-lucide="save"></i>
                        <span id="hospitalModalSaveText">Save Hospital</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('hospitalLiveFilter');
    const searchInput = document.getElementById('hospitalLiveSearch');
    const statusFilter = document.getElementById('hospitalStatusFilter');
    const resetButton = document.getElementById('hospitalFilterReset');
    const desktopEmpty = document.getElementById('hospitalDesktopEmpty');
    const mobileEmpty = document.getElementById('hospitalMobileEmpty');
    const hospitalRows = Array.from(
        document.querySelectorAll('[data-hospital-row]')
    );

    function applyHospitalFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = statusFilter?.value || '';

        let desktopVisible = 0;
        let mobileVisible = 0;

        hospitalRows.forEach(record => {
            const matchesSearch =
                query === '' ||
                (record.dataset.search || '').includes(query);

            const matchesStatus =
                status === '' ||
                record.dataset.status === status;

            const visible = matchesSearch && matchesStatus;

            record.classList.toggle('hospital-row-hidden', !visible);

            if (visible) {
                if (record.matches('tr')) {
                    desktopVisible++;
                } else {
                    mobileVisible++;
                }
            }
        });

        if (desktopEmpty) {
            desktopEmpty.style.display =
                desktopVisible === 0 ? 'block' : 'none';
        }

        if (mobileEmpty) {
            mobileEmpty.style.display =
                mobileVisible === 0 ? 'block' : 'none';
        }
    }

    searchInput?.addEventListener('input', applyHospitalFilters);
    statusFilter?.addEventListener('change', applyHospitalFilters);

    filterForm?.addEventListener('submit', event => {
        event.preventDefault();
        applyHospitalFilters();
    });

    resetButton?.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        applyHospitalFilters();
        searchInput?.focus();
    });

    applyHospitalFilters();

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>