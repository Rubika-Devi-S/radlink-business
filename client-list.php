<?php
declare(strict_types=1);

$pageTitle = 'Client List';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';

$canClientView = can_access('clients','view');
$canClientEdit = can_access('clients','edit');
$canClientDelete = can_access('clients','delete');
$canClientStatus = can_access('clients','approve');

$hospitalId = (int)($_GET['hospital_id'] ?? 0);
$clientTypeId = (int)($_GET['client_type_id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));

$hospitalStmt = $pdo->prepare(
    "SELECT c.id, c.client_code, c.client_name
     FROM clients c
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     WHERE c.business_id = ?
       AND (ct.type_key = 'hospital' OR LOWER(ct.type_name) = 'hospital')
     ORDER BY c.client_name"
);
$hospitalStmt->execute([$currentBusinessId]);
$hospitals = $hospitalStmt->fetchAll();

$typeStmt = $pdo->query(
    "SELECT id, type_key, type_name
     FROM client_types
     WHERE type_key <> 'hospital'
     ORDER BY type_name"
);
$clientTypes = $typeStmt->fetchAll();

$where = [
    'c.business_id = ?',
    'c.parent_hospital_id IS NOT NULL',
    "ct.type_key <> 'hospital'"
];
$params = [$currentBusinessId];

if ($hospitalId > 0) {
    $where[] = 'c.parent_hospital_id = ?';
    $params[] = $hospitalId;
}

if ($clientTypeId > 0) {
    $where[] = 'c.client_type_id = ?';
    $params[] = $clientTypeId;
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = "(c.client_code LIKE ? OR c.client_name LIKE ? OR h.client_name LIKE ? OR c.mobile LIKE ? OR c.email LIKE ? OR c.contact_person LIKE ? OR c.city LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$stmt = $pdo->prepare(
    "SELECT c.*,
            ct.type_name AS client_type_name,
            h.client_name AS hospital_name,
            h.client_code AS hospital_code
     FROM clients c
     INNER JOIN client_types ct ON ct.id = c.client_type_id
     INNER JOIN clients h
        ON h.id = c.parent_hospital_id
       AND h.business_id = c.business_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY h.client_name, c.client_name"
);
$stmt->execute($params);
$clients = $stmt->fetchAll();

include __DIR__ . '/includes/layout-start.php';
?>
<style>
.client-toolbar {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 10px
}

.client-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px
}

.client-card {
    padding: 15px
}

.client-card+.client-card {
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

@media(max-width:991.98px) {
    .client-toolbar {
        grid-template-columns: 1fr 1fr
    }
}

@media(max-width:767.98px) {
    .client-toolbar {
        grid-template-columns: 1fr
    }
}

#clientModal .modal-dialog {
    height: calc(100vh - 32px);
}

#clientModal .modal-content {
    max-height: 100%;
    overflow: hidden;
}

#clientModal #clientForm {
    display: flex;
    flex-direction: column;
    min-height: 0;
    height: 100%;
}

#clientModal .modal-header {
    flex: 0 0 auto;
}

#clientModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding-bottom: 28px;
}

#clientModal .modal-footer {
    flex: 0 0 auto;
    position: sticky;
    bottom: 0;
    z-index: 20;
    background: var(--card-bg, #fff);
    border-top: 1px solid var(--border-soft, #e5e7eb);
    box-shadow: 0 -8px 20px rgba(15, 23, 42, .08);
}

#clientModalSaveButton {
    min-width: 150px;
}

@media (max-width: 767.98px) {
    #clientModal .modal-dialog {
        height: calc(100vh - 16px);
        margin: 8px;
    }

    #clientModal .modal-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    #clientModal .modal-footer .btn {
        width: 100%;
        margin: 0;
    }
}

<style>.client-actions .icon-action-btn {
    width: 38px;
    height: 38px;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
}

.client-actions .icon-action-btn svg {
    width: 18px;
    height: 18px;
}
</style>

</style>

<div class="page-head">
    <div>
        <span class="badge-soft">CLIENTS / HOSPITALS</span>
        <h1 class="mt-2">Client List</h1>
        <p>See which client belongs to which hospital.</p>
    </div>
    <button class="btn btn-brand" type="button" id="addClientButton">
        <i data-lucide="plus"></i> Add Client
    </button>
</div>

<section class="card-ui p-3 mb-3">
    <form method="get" class="client-toolbar">
        <input class="form-control" name="q" value="<?= e($search) ?>"
            placeholder="Search client, hospital, mobile, email, contact or city">
        <select class="form-select" name="hospital_id">
            <option value="">All Hospitals</option>
            <?php foreach ($hospitals as $hospital): ?>
            <option value="<?= (int)$hospital['id'] ?>" <?= $hospitalId === (int)$hospital['id'] ? 'selected' : '' ?>>
                <?= e($hospital['client_code'] . ' - ' . $hospital['client_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select" name="client_type_id">
            <option value="">All Client Types</option>
            <?php foreach ($clientTypes as $type): ?>
            <option value="<?= (int)$type['id'] ?>" <?= $clientTypeId === (int)$type['id'] ? 'selected' : '' ?>>
                <?= e($type['type_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select" name="status">
            <option value="">All Status</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button class="btn btn-brand">Filter</button>
    </form>
</section>

<section class="card-ui p-3">
    <div class="desktop-table table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Hospital</th>
                    <th>Type</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$clients): ?><tr>
                    <td colspan="7" class="text-center py-4 text-muted">No clients found.</td>
                </tr><?php endif; ?>
                <?php foreach ($clients as $client): ?>
                <tr>
                    <td><strong><?= e($client['client_name']) ?></strong><small
                            class="d-block text-muted"><?= e($client['client_code']) ?></small></td>
                    <td><strong><?= e($client['hospital_name']) ?></strong><small
                            class="d-block text-muted"><?= e($client['hospital_code']) ?></small></td>
                    <td><?= e($client['client_type_name']) ?></td>
                    <td><?= e($client['mobile'] ?: '—') ?><small
                            class="d-block text-muted"><?= e($client['email'] ?: '') ?></small></td>
                    <td><?= e(implode(', ', array_filter([$client['city'], $client['district'], $client['state']]))) ?: '—' ?>
                    </td>
                    <td><span
                            class="status-pill <?= $client['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= e(ucfirst($client['status'])) ?></span>
                    </td>
                    <td>
                        <div class="client-actions">
                            <button class="btn btn-sm btn-light icon-action-btn" title="View" type="button"
                                data-view-client
                                data-record='<?= e(json_encode($client, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-primary icon-action-btn" title="Edit" type="button"
                                data-edit-client
                                data-record='<?= e(json_encode($client, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary icon-action-btn" title="Status"
                                data-action="toggle" data-entity="client" data-id="<?= (int)$client['id'] ?>"><i
                                    data-lucide="power"></i></button>
                            <button class="btn btn-sm btn-outline-danger icon-action-btn" title="Delete"
                                data-action="delete" data-entity="client" data-id="<?= (int)$client['id'] ?>"><i
                                    data-lucide="trash-2"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-card-list">
        <?php foreach ($clients as $client): ?>
        <article class="card-ui client-card">
            <div class="d-flex justify-content-between gap-2">
                <div><strong><?= e($client['client_name']) ?></strong><small
                        class="d-block text-muted"><?= e($client['client_code']) ?></small></div>
                <span
                    class="status-pill <?= $client['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= e(ucfirst($client['status'])) ?></span>
            </div>
            <div class="row g-2 mt-2 small">
                <div class="col-12">Hospital<br><strong><?= e($client['hospital_name']) ?></strong></div>
                <div class="col-6">Type<br><strong><?= e($client['client_type_name']) ?></strong></div>
                <div class="col-6">Mobile<br><strong><?= e($client['mobile'] ?: '—') ?></strong></div>
            </div>
            <div class="client-actions mt-3">
                <button class="btn btn-sm btn-light icon-action-btn" title="View" type="button" data-view-client
                    data-record='<?= e(json_encode($client, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-primary icon-action-btn" title="Edit" type="button"
                    data-edit-client data-record='<?= e(json_encode($client, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary icon-action-btn" title="Status" data-action="toggle"
                    data-entity="client" data-id="<?= (int)$client['id'] ?>"><i data-lucide="power"></i></button>
                <button class="btn btn-sm btn-outline-danger ms-auto icon-action-btn" title="Delete"
                    data-action="delete" data-entity="client" data-id="<?= (int)$client['id'] ?>"><i
                        data-lucide="trash-2"></i></button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content card-ui">
            <form id="clientForm">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1" id="clientModalTitle">Quick Add Client</h5><small
                            class="text-muted" id="clientModalSubtitle">Select the hospital, enter client details and
                            save.</small>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id">
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Hospital</label><select class="form-select"
                                name="parent_hospital_id" required>
                                <option value="">Select Hospital</option><?php foreach ($hospitals as $hospital): ?>
                                <option value="<?= (int)$hospital['id'] ?>">
                                    <?= e($hospital['client_code'] . ' - ' . $hospital['client_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-6"><label class="form-label">Client Type</label><select class="form-select"
                                name="client_type_id" required>
                                <option value="">Select Type</option><?php foreach ($clientTypes as $type): ?><option
                                    value="<?= (int)$type['id'] ?>"><?= e($type['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-8"><label class="form-label">Client Name</label><input class="form-control"
                                name="client_name" required></div>
                        <div class="col-md-4"><label class="form-label">Client Code</label><input
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
                                <option value="credit">Credit</option>
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
                    <button class="btn btn-brand px-4" type="submit" id="clientModalSaveButton">
                        <i data-lucide="save"></i>
                        <span id="clientModalSaveText">Save Client</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('clientModal'));
    const form = document.getElementById('clientForm');

    const modalTitle = document.getElementById('clientModalTitle');
    const modalSubtitle = document.getElementById('clientModalSubtitle');
    const saveButton = document.getElementById('clientModalSaveButton');
    const saveText = document.getElementById('clientModalSaveText');

    function resetForm() {
        form.reset();
        form.querySelector('[name="id"]').value = '';
        form.querySelector('[name="state"]').value = 'Tamil Nadu';
        form.querySelector('[name="status"]').value = 'active';
        form.querySelector('[name="default_billing_mode"]').value = 'credit';
        <?php if ($hospitalId > 0): ?>
        form.querySelector('[name="parent_hospital_id"]').value = '<?= $hospitalId ?>';
        <?php endif; ?>
    }

    function fillForm(row) {
        Object.entries(row).forEach(([key, value]) => {
            const input = form.querySelector('[name="' + key + '"]');

            if (input) {
                input.value = value ?? '';
            }
        });
    }

    function setClientMode(mode) {
        const isView = mode === 'view';
        const isEdit = mode === 'edit';

        form.querySelectorAll('input, select, textarea').forEach(field => {
            if (
                field.name === 'csrf_token' ||
                field.name === 'id'
            ) {
                return;
            }

            field.disabled = isView;
        });

        if (mode === 'add') {
            modalTitle.textContent = 'Quick Add Client';
            modalSubtitle.textContent =
                'Select the hospital, enter client details and click Save Client.';
            saveText.textContent = 'Save Client';
            saveButton.hidden = false;
        } else if (isEdit) {
            modalTitle.textContent = 'Edit Client';
            modalSubtitle.textContent =
                'Update the client details and click Save Changes.';
            saveText.textContent = 'Save Changes';
            saveButton.hidden = false;
        } else {
            modalTitle.textContent = 'View Client';
            modalSubtitle.textContent =
                'Client details are shown in read-only mode.';
            saveButton.hidden = true;
        }
    }

    document.getElementById('addClientButton').addEventListener('click', () => {
        resetForm();
        setClientMode('add');
        modal.show();
    });

    document.querySelectorAll('[data-view-client]').forEach(button => {
        button.addEventListener('click', () => {
            resetForm();
            fillForm(JSON.parse(button.dataset.record));
            setClientMode('view');
            modal.show();
        });
    });

    document.querySelectorAll('[data-edit-client]').forEach(button => {
        button.addEventListener('click', () => {
            resetForm();
            fillForm(JSON.parse(button.dataset.record));
            setClientMode('edit');
            modal.show();
        });
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();

        const originalHtml = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

        try {
            const response = await fetch(
                '<?= e(app_url('api/client.php')) ?>', {
                    method: 'POST',
                    body: (() => {
                        const formData = new FormData(form);
                        formData.set('action', 'save');
                        return formData;
                    })(),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            const raw = await response.text();
            let result;

            try {
                result = JSON.parse(raw);
            } catch (error) {
                throw new Error(
                    raw.replace(/<[^>]*>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim() ||
                    'Invalid server response.'
                );
            }

            AppToast.show(
                result.success ? 'success' : 'error',
                result.message
            );

            if (result.success) {
                setTimeout(() => location.reload(), 350);
            }
        } catch (error) {
            AppToast.show(
                'error',
                error.message || 'Unable to save client.'
            );
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = originalHtml;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    });

    document.querySelectorAll('[data-action]').forEach(button => {
        button.addEventListener('click', async () => {
            if (button.dataset.action === 'delete' && !confirm('Delete this client?'))
                return;

            const fd = new FormData();
            fd.append('csrf_token', window.APP_CSRF);
            fd.append('action', button.dataset.action);
            fd.append('id', button.dataset.id);

            const response = await fetch('<?= e(app_url('api/client.php')) ?>', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });

            const result = await response.json();
            AppToast.show(result.success ? 'success' : 'error', result.message);
            if (result.success) setTimeout(() => location.reload(), 350);
        });
    });
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>