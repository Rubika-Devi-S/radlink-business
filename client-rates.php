<?php
declare(strict_types=1);

$pageTitle = 'Client Service Rates';
require_once __DIR__ . '/includes/bootstrap.php';

$clientsStmt = $pdo->prepare(
    "SELECT id, client_code, client_name
     FROM clients
     WHERE business_id = ? AND status = 'active'
     ORDER BY client_name"
);
$clientsStmt->execute([$currentBusinessId]);
$clients = $clientsStmt->fetchAll();

$servicesStmt = $pdo->prepare(
    "SELECT s.id, s.service_code, s.service_name, s.standard_rate, sc.category_name
     FROM services s
     JOIN service_categories sc ON sc.id = s.service_category_id
     WHERE s.business_id = ? AND s.status = 'active'
     ORDER BY sc.sort_order, s.service_name"
);
$servicesStmt->execute([$currentBusinessId]);
$services = $servicesStmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT csr.*, c.client_code, c.client_name,
            s.service_code, s.service_name, s.standard_rate,
            sc.category_name
     FROM client_service_rates csr
     JOIN clients c
       ON c.id = csr.client_id
      AND c.business_id = csr.business_id
     JOIN services s
       ON s.id = csr.service_id
      AND s.business_id = csr.business_id
     JOIN service_categories sc ON sc.id = s.service_category_id
     WHERE csr.business_id = ?
     ORDER BY c.client_name, s.service_name, csr.effective_from DESC"
);
$stmt->execute([$currentBusinessId]);
$rates = $stmt->fetchAll();

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.rate-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.rate-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    width: min(100%, 520px);
}

.rate-filters .form-select {
    min-width: 210px;
}

.rate-filters .form-control {
    min-width: 230px;
}

/* Desktop table */
.rate-table-wrap {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.rate-table {
    width: 100%;
    min-width: 1180px;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
}

.rate-table th {
    white-space: nowrap;
    font-size: 11px;
    font-weight: 800;
    padding: 12px 10px;
    vertical-align: middle;
}

.rate-table td {
    padding: 14px 10px;
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rate-table td:first-child,
.rate-table td:nth-child(2),
.rate-table td:nth-child(5) {
    white-space: normal;
}

.rate-table .rate-client,
.rate-table .rate-service,
.rate-table .rate-effective {
    line-height: 1.25;
}

.rate-table .rate-client small,
.rate-table .rate-service small {
    display: block;
    margin-top: 3px;
}

.rate-table .rate-code {
    color: var(--brand);
    font-size: 12px;
    font-weight: 800;
}

.rate-table .rate-amount {
    font-weight: 800;
    white-space: nowrap;
}

.rate-table .rate-agreed {
    color: #15803d;
}

.rate-table .rate-effective small {
    color: var(--text-muted);
}

/*
 * IMPORTANT:
 * The actions column has a fixed width that is large enough
 * for all 4 icon buttons. This prevents the buttons from
 * overflowing/clipping at 100% browser zoom.
 */
.rate-table th.rate-actions-col,
.rate-table td.rate-actions-cell {
    width: 190px;
    min-width: 190px;
    max-width: 190px;
}

.rate-table td.rate-actions-cell {
    overflow: visible;
    text-overflow: clip;
}

.rate-actions {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 6px;
    width: 100%;
    min-width: 170px;
    white-space: nowrap;
}

.action-icon-btn {
    width: 38px;
    height: 38px;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    flex: 0 0 38px;
}

.action-icon-btn svg,
.action-icon-btn i {
    width: 17px;
    height: 17px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.status-active {
    background: #dcfce7;
    color: #15803d;
}

.status-inactive {
    background: #f1f5f9;
    color: #64748b;
}

.code-pill {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 9px;
    background: var(--sidebar-active);
    color: var(--brand);
    font-family: monospace;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.mobile-card-list {
    display: none;
}

.mobile-record {
    padding: 16px;
}

.mobile-record+.mobile-record {
    margin-top: 10px;
}

.mobile-record .mobile-title {
    font-weight: 800;
    line-height: 1.25;
}

.mobile-record .mobile-subtitle {
    color: var(--text-muted);
    font-size: 12px;
    margin-top: 3px;
}

.mobile-record .mobile-rate {
    font-size: 12px;
    color: var(--text-muted);
}

.mobile-record .mobile-rate strong {
    display: block;
    color: var(--text);
    font-size: 15px;
    margin-top: 3px;
}

.mobile-record .mobile-rate .text-success {
    color: #15803d !important;
}

.form-help {
    font-size: 11px;
    color: var(--text-muted);
}

/* Tablet */
@media (max-width: 1100px) and (min-width: 768px) {
    .rate-table-wrap {
        overflow-x: auto;
    }
}

/* Mobile */
@media (max-width: 767.98px) {
    .page-head {
        align-items: stretch;
    }

    .page-head .btn {
        width: 100%;
    }

    .rate-toolbar {
        align-items: stretch;
    }

    .rate-filters {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }

    .rate-filters .form-select,
    .rate-filters .form-control {
        min-width: 0;
        width: 100%;
    }

    .desktop-table {
        display: none;
    }

    .mobile-card-list {
        display: block;
    }

    .mobile-record .rate-actions {
        min-width: 0;
        width: 100%;
    }
}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">SERVICES</span>
        <h1 class="mt-2">Client Service Rates</h1>
        <p>Override the standard service rate for a particular hospital or client.</p>
    </div>

    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#rateModal" id="newRateBtn">
        <i data-lucide="plus"></i>
        Add Client Rate
    </button>
</div>

<section class="card-ui p-3">
    <div class="rate-toolbar mb-3">
        <div>
            <h2 class="h6 fw-bold mb-1">Agreed Rates</h2>
            <small class="text-muted">
                The active rate effective on the invoice date should override the standard rate
            </small>
        </div>

        <div class="rate-filters">
            <select class="form-select" id="clientFilter">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>">
                    <?= e($c['client_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <input class="form-control" type="search" id="rateSearch" placeholder="Search rates...">
        </div>
    </div>

    <div class="desktop-table rate-table-wrap">
        <table class="table align-middle mb-0 rate-table">
            <colgroup>
                <col style="width: 220px">
                <col style="width: 220px">
                <col style="width: 120px">
                <col style="width: 120px">
                <col style="width: 170px">
                <col style="width: 110px">
                <col style="width: 190px">
            </colgroup>

            <thead>
                <tr>
                    <th>CLIENT</th>
                    <th>SERVICE</th>
                    <th>STANDARD</th>
                    <th>AGREED</th>
                    <th>EFFECTIVE</th>
                    <th>STATUS</th>
                    <th class="rate-actions-col">ACTIONS</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$rates): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        No client-specific service rates found.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($rates as $rate): ?>
                <tr data-rate-row data-client="<?= (int)$rate['client_id'] ?>" data-search="<?= e(strtolower(
                            $rate['client_code'] . ' ' .
                            $rate['client_name'] . ' ' .
                            $rate['service_code'] . ' ' .
                            $rate['service_name'] . ' ' .
                            $rate['category_name']
                        )) ?>">
                    <td class="rate-client">
                        <span class="code-pill"><?= e($rate['client_code']) ?></span>
                        <div class="fw-bold mt-1"><?= e($rate['client_name']) ?></div>
                    </td>

                    <td class="rate-service">
                        <span class="code-pill"><?= e($rate['service_code']) ?></span>
                        <div class="mt-1"><?= e($rate['service_name']) ?></div>
                        <small class="text-muted"><?= e($rate['category_name']) ?></small>
                    </td>

                    <td class="rate-amount">
                        ₹<?= number_format((float)$rate['standard_rate'], 2) ?>
                    </td>

                    <td class="rate-amount rate-agreed">
                        ₹<?= number_format((float)$rate['agreed_rate'], 2) ?>
                    </td>

                    <td class="rate-effective">
                        <?= e(date('d M Y', strtotime($rate['effective_from']))) ?>
                        <small>
                            <?= $rate['effective_to']
                                    ? 'to ' . e(date('d M Y', strtotime($rate['effective_to'])))
                                    : 'No end date' ?>
                        </small>
                    </td>

                    <td>
                        <span class="status-badge <?= $rate['status'] === 'active'
                                ? 'status-active'
                                : 'status-inactive' ?>">
                            <?= e(ucfirst($rate['status'])) ?>
                        </span>
                    </td>

                    <td class="rate-actions-cell">
                        <div class="rate-actions">
                            <button class="btn btn-sm btn-light action-icon-btn" title="View" type="button"
                                data-view-rate
                                data-record='<?= e(json_encode($rate, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="eye"></i>
                            </button>

                            <button class="btn btn-sm btn-outline-primary action-icon-btn" title="Edit" type="button"
                                data-edit-rate
                                data-record='<?= e(json_encode($rate, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                                <i data-lucide="pencil"></i>
                            </button>

                            <button class="btn btn-sm btn-outline-secondary action-icon-btn" title="Change Status"
                                type="button" data-toggle-rate data-id="<?= (int)$rate['id'] ?>">
                                <i data-lucide="power"></i>
                            </button>

                            <button class="btn btn-sm btn-outline-danger action-icon-btn" title="Delete" type="button"
                                data-delete-rate data-id="<?= (int)$rate['id'] ?>">
                                <i data-lucide="trash-2"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-card-list">
        <?php foreach ($rates as $rate): ?>
        <article class="card-ui mobile-record" data-rate-row data-client="<?= (int)$rate['client_id'] ?>" data-search="<?= e(strtolower(
                    $rate['client_code'] . ' ' .
                    $rate['client_name'] . ' ' .
                    $rate['service_code'] . ' ' .
                    $rate['service_name'] . ' ' .
                    $rate['category_name']
                )) ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="mobile-title"><?= e($rate['client_name']) ?></div>
                    <div class="mobile-subtitle">
                        <?= e($rate['service_name']) ?> · <?= e($rate['service_code']) ?>
                    </div>
                </div>

                <span class="status-badge <?= $rate['status'] === 'active'
                        ? 'status-active'
                        : 'status-inactive' ?>">
                    <?= e(ucfirst($rate['status'])) ?>
                </span>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-6 mobile-rate">
                    Standard
                    <strong>₹<?= number_format((float)$rate['standard_rate'], 2) ?></strong>
                </div>

                <div class="col-6 mobile-rate">
                    Agreed
                    <strong class="text-success">
                        ₹<?= number_format((float)$rate['agreed_rate'], 2) ?>
                    </strong>
                </div>

                <div class="col-12 mobile-rate">
                    Effective
                    <strong>
                        <?= e(date('d M Y', strtotime($rate['effective_from']))) ?>
                        -
                        <?= $rate['effective_to']
                                ? e(date('d M Y', strtotime($rate['effective_to'])))
                                : 'No end date' ?>
                    </strong>
                </div>
            </div>

            <div class="rate-actions mt-3">
                <button class="btn btn-sm btn-light action-icon-btn" title="View" type="button" data-view-rate
                    data-record='<?= e(json_encode($rate, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="eye"></i>
                </button>

                <button class="btn btn-sm btn-outline-primary action-icon-btn" title="Edit" type="button" data-edit-rate
                    data-record='<?= e(json_encode($rate, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                    <i data-lucide="pencil"></i>
                </button>

                <button class="btn btn-sm btn-outline-secondary action-icon-btn" title="Change Status" type="button"
                    data-toggle-rate data-id="<?= (int)$rate['id'] ?>">
                    <i data-lucide="power"></i>
                </button>

                <button class="btn btn-sm btn-outline-danger action-icon-btn ms-auto" title="Delete" type="button"
                    data-delete-rate data-id="<?= (int)$rate['id'] ?>">
                    <i data-lucide="trash-2"></i>
                </button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="modal fade" id="rateViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content card-ui">
            <div class="modal-header">
                <h5 class="modal-title">Client Service Rate Details</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted">Client / Hospital</small>
                        <strong class="d-block" id="viewRateClient"></strong>
                    </div>

                    <div class="col-md-6">
                        <small class="text-muted">Service</small>
                        <strong class="d-block" id="viewRateService"></strong>
                    </div>

                    <div class="col-md-4">
                        <small class="text-muted">Standard Rate</small>
                        <div id="viewRateStandard"></div>
                    </div>

                    <div class="col-md-4">
                        <small class="text-muted">Agreed Rate</small>
                        <div class="fw-bold text-success" id="viewRateAgreed"></div>
                    </div>

                    <div class="col-md-4">
                        <small class="text-muted">Status</small>
                        <div id="viewRateStatus"></div>
                    </div>

                    <div class="col-md-6">
                        <small class="text-muted">Effective From</small>
                        <div id="viewRateFrom"></div>
                    </div>

                    <div class="col-md-6">
                        <small class="text-muted">Effective To</small>
                        <div id="viewRateTo"></div>
                    </div>

                    <div class="col-12">
                        <small class="text-muted">Notes</small>
                        <div id="viewRateNotes"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content card-ui">
            <form id="rateForm">
                <div class="modal-header">
                    <h5 class="modal-title">Client Service Rate</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="rateId">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client / Hospital</label>
                            <select class="form-select" name="client_id" id="rateClientId" required>
                                <option value="">Select client</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">
                                    <?= e($c['client_code'] . ' - ' . $c['client_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Service</label>
                            <select class="form-select" name="service_id" id="rateServiceId" required>
                                <option value="">Select service</option>
                                <?php foreach ($services as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" data-standard-rate="<?= e($s['standard_rate']) ?>">
                                    <?= e($s['service_code'] . ' - ' . $s['service_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Standard Rate</label>
                            <input class="form-control" id="displayStandardRate" readonly>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Agreed Rate</label>
                            <input type="number" class="form-control" name="agreed_rate" id="agreedRate" min="0"
                                step="0.01" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="rateStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Effective From</label>
                            <input type="date" class="form-control" name="effective_from" id="effectiveFrom" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Effective To</label>
                            <input type="date" class="form-control" name="effective_to" id="effectiveTo">
                            <div class="form-help">
                                Leave empty when the rate has no fixed end date.
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="rateNotes" rows="2"
                                maxlength="500"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button class="btn btn-brand" type="submit">
                        Save Rate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('rateForm');
    const rateModalEl = document.getElementById('rateModal');
    const rateViewModalEl = document.getElementById('rateViewModal');

    const modal = bootstrap.Modal.getOrCreateInstance(rateModalEl);
    const rateViewModal = bootstrap.Modal.getOrCreateInstance(rateViewModalEl);

    const newRateBtn = document.getElementById('newRateBtn');
    const rateId = document.getElementById('rateId');
    const rateClientId = document.getElementById('rateClientId');
    const rateServiceId = document.getElementById('rateServiceId');
    const displayStandardRate = document.getElementById('displayStandardRate');
    const agreedRate = document.getElementById('agreedRate');
    const effectiveFrom = document.getElementById('effectiveFrom');
    const effectiveTo = document.getElementById('effectiveTo');
    const rateNotes = document.getElementById('rateNotes');
    const rateStatus = document.getElementById('rateStatus');

    const clientFilter = document.getElementById('clientFilter');
    const rateSearch = document.getElementById('rateSearch');

    const viewRateClient = document.getElementById('viewRateClient');
    const viewRateService = document.getElementById('viewRateService');
    const viewRateStandard = document.getElementById('viewRateStandard');
    const viewRateAgreed = document.getElementById('viewRateAgreed');
    const viewRateStatus = document.getElementById('viewRateStatus');
    const viewRateFrom = document.getElementById('viewRateFrom');
    const viewRateTo = document.getElementById('viewRateTo');
    const viewRateNotes = document.getElementById('viewRateNotes');

    document.querySelectorAll('[data-view-rate]').forEach(btn => {
        btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.record);

            viewRateClient.textContent = r.client_code + ' - ' + r.client_name;
            viewRateService.textContent = r.service_code + ' - ' + r.service_name;
            viewRateStandard.textContent = '₹' + Number(r.standard_rate).toFixed(2);
            viewRateAgreed.textContent = '₹' + Number(r.agreed_rate).toFixed(2);
            viewRateStatus.textContent =
                r.status.charAt(0).toUpperCase() + r.status.slice(1);
            viewRateFrom.textContent = r.effective_from;
            viewRateTo.textContent = r.effective_to || 'No end date';
            viewRateNotes.textContent = r.notes || '—';

            rateViewModal.show();
        });
    });

    function standard() {
        const option = rateServiceId.options[rateServiceId.selectedIndex];

        displayStandardRate.value =
            option && option.dataset.standardRate ?
            '₹' + Number(option.dataset.standardRate).toFixed(2) :
            '';
    }

    rateServiceId.addEventListener('change', standard);

    function resetForm() {
        form.reset();
        rateId.value = '';
        effectiveFrom.value = new Date().toISOString().slice(0, 10);
        rateStatus.value = 'active';
        displayStandardRate.value = '';
    }

    newRateBtn.addEventListener('click', resetForm);

    document.querySelectorAll('[data-edit-rate]').forEach(btn => {
        btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.record);

            rateId.value = r.id;
            rateClientId.value = r.client_id;
            rateServiceId.value = r.service_id;
            agreedRate.value = r.agreed_rate;
            effectiveFrom.value = r.effective_from;
            effectiveTo.value = r.effective_to || '';
            rateNotes.value = r.notes || '';
            rateStatus.value = r.status;

            standard();
            modal.show();
        });
    });

    async function send(fd) {
        try {
            const res = await fetch(
                '<?= e(app_url('api/client-service-rates.php')) ?>', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }
            );

            const data = await res.json();

            AppToast.show(
                data.success ? 'success' : 'error',
                data.message
            );

            if (data.success) {
                setTimeout(() => location.reload(), 350);
            }
        } catch (e) {
            AppToast.show(
                'error',
                'Unable to process client rate request.'
            );
        }
    }

    form.addEventListener('submit', e => {
        e.preventDefault();

        if (
            effectiveTo.value &&
            effectiveTo.value < effectiveFrom.value
        ) {
            AppToast.show(
                'warning',
                'Effective To cannot be earlier than Effective From.'
            );
            return;
        }

        send(new FormData(form));
    });

    document.querySelectorAll('[data-toggle-rate]').forEach(btn => {
        btn.addEventListener('click', () => {
            const fd = new FormData();

            fd.append('csrf_token', window.APP_CSRF);
            fd.append('action', 'toggle');
            fd.append('id', btn.dataset.id);

            send(fd);
        });
    });

    document.querySelectorAll('[data-delete-rate]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Delete this client service rate?')) {
                return;
            }

            const fd = new FormData();

            fd.append('csrf_token', window.APP_CSRF);
            fd.append('action', 'delete');
            fd.append('id', btn.dataset.id);

            send(fd);
        });
    });

    function filter() {
        const q = rateSearch.value.trim().toLowerCase();
        const client = clientFilter.value;

        document.querySelectorAll('[data-rate-row]').forEach(row => {
            row.hidden = !(
                row.dataset.search.includes(q) &&
                (!client || row.dataset.client === client)
            );
        });
    }

    rateSearch.addEventListener('input', filter);
    clientFilter.addEventListener('change', filter);
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>