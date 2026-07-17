<?php
declare(strict_types=1);

$pageTitle = 'Business Settings';

require_once __DIR__ . '/includes/bootstrap.php';

$permissionsFile = __DIR__ . '/includes/permissions.php';
if (is_file($permissionsFile)) {
    require_once $permissionsFile;
}

/*
|--------------------------------------------------------------------------
| Permission handling
|--------------------------------------------------------------------------
| Supports either `business-settings` or `settings` menu slugs.
| Owner / super-admin access remains available through the existing helper.
*/
$canViewBusinessSettings = true;
$canEditBusinessSettings = true;

if (function_exists('can_access')) {
    $canViewBusinessSettings =
        can_access('business-settings', 'view')
        || can_access('settings', 'view')
        || can_access('master', 'view');

    $canEditBusinessSettings =
        can_access('business-settings', 'edit')
        || can_access('settings', 'edit')
        || can_access('master', 'edit');
}

if (!$canViewBusinessSettings) {
    http_response_code(403);
    exit('Access Denied. You do not have permission to view Business Settings.');
}

if ($currentBusinessId <= 0) {
    include __DIR__ . '/includes/layout-start.php';
    ?>
    <div class="alert alert-warning">
        Select an active business before opening Business Settings.
    </div>
    <?php
    include __DIR__ . '/includes/layout-end.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Detect current businesses table columns
|--------------------------------------------------------------------------
*/
$columnStmt = $pdo->query("SHOW COLUMNS FROM businesses");
$businessColumns = [];

foreach ($columnStmt->fetchAll() as $column) {
    $businessColumns[(string)$column['Field']] = true;
}

$businessStmt = $pdo->prepare(
    "SELECT *
     FROM businesses
     WHERE id = ?
     LIMIT 1"
);
$businessStmt->execute([$currentBusinessId]);
$business = $businessStmt->fetch();

if (!$business) {
    http_response_code(404);
    exit('Business not found.');
}

function business_field(
    array $business,
    array $columns,
    array $candidates,
    mixed $default = ''
): mixed {
    foreach ($candidates as $candidate) {
        if (
            isset($columns[$candidate])
            && array_key_exists($candidate, $business)
        ) {
            return $business[$candidate] ?? $default;
        }
    }

    return $default;
}

$settings = [
    'business_name' => business_field(
        $business,
        $businessColumns,
        ['business_name', 'name', 'company_name']
    ),
    'business_code' => business_field(
        $business,
        $businessColumns,
        ['business_code', 'code', 'company_code']
    ),
    'legal_name' => business_field(
        $business,
        $businessColumns,
        ['legal_name', 'registered_name', 'company_legal_name']
    ),
    'email' => business_field(
        $business,
        $businessColumns,
        ['email', 'business_email', 'company_email']
    ),
    'mobile' => business_field(
        $business,
        $businessColumns,
        ['mobile', 'phone', 'contact_number', 'business_phone']
    ),
    'alternate_mobile' => business_field(
        $business,
        $businessColumns,
        ['alternate_mobile', 'alternate_phone']
    ),
    'gst_number' => business_field(
        $business,
        $businessColumns,
        ['gst_number', 'gstin', 'tax_number']
    ),
    'pan_number' => business_field(
        $business,
        $businessColumns,
        ['pan_number', 'pan']
    ),
    'address_line_1' => business_field(
        $business,
        $businessColumns,
        ['address_line_1', 'address1', 'address']
    ),
    'address_line_2' => business_field(
        $business,
        $businessColumns,
        ['address_line_2', 'address2']
    ),
    'city' => business_field(
        $business,
        $businessColumns,
        ['city']
    ),
    'district' => business_field(
        $business,
        $businessColumns,
        ['district']
    ),
    'state' => business_field(
        $business,
        $businessColumns,
        ['state'],
        'Tamil Nadu'
    ),
    'postal_code' => business_field(
        $business,
        $businessColumns,
        ['postal_code', 'pincode', 'zip_code']
    ),
    'website' => business_field(
        $business,
        $businessColumns,
        ['website', 'website_url']
    ),
    'currency_code' => business_field(
        $business,
        $businessColumns,
        ['currency_code', 'currency'],
        'INR'
    ),
    'timezone' => business_field(
        $business,
        $businessColumns,
        ['timezone'],
        'Asia/Kolkata'
    ),
    'invoice_prefix' => business_field(
        $business,
        $businessColumns,
        ['invoice_prefix'],
        'INV'
    ),
    'receipt_prefix' => business_field(
        $business,
        $businessColumns,
        ['receipt_prefix', 'payment_prefix'],
        'REC'
    ),
    'financial_year_start_month' => (int)business_field(
        $business,
        $businessColumns,
        ['financial_year_start_month', 'fy_start_month'],
        4
    ),
    'status' => business_field(
        $business,
        $businessColumns,
        ['status'],
        'active'
    ),
    'notes' => business_field(
        $business,
        $businessColumns,
        ['notes', 'description']
    ),
];

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.business-settings-page {
    --settings-radius: 18px;
}

.settings-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

.settings-page-head h1 {
    margin: 8px 0 4px;
    font-weight: 900;
}

.settings-card {
    background: var(--card-bg);
    border: 1px solid var(--border-soft);
    border-radius: var(--settings-radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.settings-card + .settings-card {
    margin-top: 16px;
}

.settings-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-soft);
}

.settings-card-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 900;
}

.settings-card-body {
    padding: 20px;
}

.settings-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 13px;
    color: var(--brand);
    background: var(--sidebar-active);
}

.settings-icon svg {
    width: 20px;
    height: 20px;
}

.settings-section-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.settings-preview {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--border-soft);
    border-radius: 15px;
    background: var(--body-bg);
}

.business-avatar {
    width: 56px;
    height: 56px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 17px;
    color: #fff;
    background: var(--brand);
    font-size: 21px;
    font-weight: 900;
}

.settings-actions {
    position: sticky;
    bottom: 10px;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px;
    margin-top: 16px;
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    background: color-mix(in srgb, var(--card-bg) 95%, transparent);
    backdrop-filter: blur(12px);
    box-shadow: var(--shadow);
}

.readonly-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 193, 7, .35);
    border-radius: 14px;
    background: rgba(255, 193, 7, .08);
}

.readonly-notice svg {
    flex: 0 0 auto;
}

@media (max-width: 767.98px) {
    .settings-page-head {
        flex-direction: column;
        align-items: stretch;
    }

    .settings-page-head .btn {
        width: 100%;
    }

    .settings-card-head,
    .settings-card-body {
        padding: 15px;
    }

    .settings-actions {
        flex-direction: column-reverse;
    }

    .settings-actions .btn {
        width: 100%;
    }

    .settings-preview {
        align-items: flex-start;
    }
}
</style>

<div class="business-settings-page">
    <div class="settings-page-head">
        <div>
            <span class="badge-soft">RAD LINK HEALTH</span>
            <h1>Business Settings</h1>
            <p class="mb-0 text-muted">
                Manage the selected business profile and operational defaults.
            </p>
        </div>

        <a
            class="btn btn-light"
            href="<?= e(app_url('index.php')) ?>"
        >
            <i data-lucide="arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <form id="businessSettingsForm" novalidate>
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save"
        >

        <?php if (!$canEditBusinessSettings): ?>
            <div class="readonly-notice">
                <i data-lucide="shield-alert"></i>
                <div>
                    <strong>Read-only access</strong>
                    <div class="small text-muted">
                        Your role can view these settings but cannot update them.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section class="settings-card">
            <div class="settings-card-head">
                <div class="settings-section-title">
                    <div class="settings-icon">
                        <i data-lucide="building-2"></i>
                    </div>
                    <div>
                        <h2>Business Profile</h2>
                        <small class="text-muted">
                            Basic business identity and contact details.
                        </small>
                    </div>
                </div>
            </div>

            <div class="settings-card-body">
                <div class="settings-preview mb-4">
                    <div class="business-avatar" id="businessAvatar">
                        <?= e(strtoupper(substr((string)$settings['business_name'], 0, 1)) ?: 'B') ?>
                    </div>

                    <div>
                        <strong id="previewBusinessName">
                            <?= e((string)$settings['business_name']) ?>
                        </strong>
                        <small class="d-block text-muted" id="previewBusinessCode">
                            <?= e((string)$settings['business_code']) ?>
                        </small>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">
                            Business Name
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="business_name"
                            id="businessName"
                            value="<?= e((string)$settings['business_name']) ?>"
                            maxlength="200"
                            required
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Business Code
                        </label>

                        <input
                            class="form-control text-uppercase"
                            name="business_code"
                            id="businessCode"
                            value="<?= e((string)$settings['business_code']) ?>"
                            maxlength="50"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Legal / Registered Name
                        </label>

                        <input
                            class="form-control"
                            name="legal_name"
                            value="<?= e((string)$settings['legal_name']) ?>"
                            maxlength="250"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Website
                        </label>

                        <input
                            class="form-control"
                            type="url"
                            name="website"
                            value="<?= e((string)$settings['website']) ?>"
                            maxlength="255"
                            placeholder="https://example.com"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Email</label>
                        <input
                            class="form-control"
                            type="email"
                            name="email"
                            value="<?= e((string)$settings['email']) ?>"
                            maxlength="190"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Mobile</label>
                        <input
                            class="form-control"
                            name="mobile"
                            value="<?= e((string)$settings['mobile']) ?>"
                            maxlength="20"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Alternate Mobile
                        </label>
                        <input
                            class="form-control"
                            name="alternate_mobile"
                            value="<?= e((string)$settings['alternate_mobile']) ?>"
                            maxlength="20"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="settings-card">
            <div class="settings-card-head">
                <div class="settings-section-title">
                    <div class="settings-icon">
                        <i data-lucide="map-pinned"></i>
                    </div>
                    <div>
                        <h2>Address and Tax Details</h2>
                        <small class="text-muted">
                            Used in invoices, receipts and reports.
                        </small>
                    </div>
                </div>
            </div>

            <div class="settings-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Address Line 1
                        </label>
                        <input
                            class="form-control"
                            name="address_line_1"
                            value="<?= e((string)$settings['address_line_1']) ?>"
                            maxlength="255"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Address Line 2
                        </label>
                        <input
                            class="form-control"
                            name="address_line_2"
                            value="<?= e((string)$settings['address_line_2']) ?>"
                            maxlength="255"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">City</label>
                        <input
                            class="form-control"
                            name="city"
                            value="<?= e((string)$settings['city']) ?>"
                            maxlength="100"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">District</label>
                        <input
                            class="form-control"
                            name="district"
                            value="<?= e((string)$settings['district']) ?>"
                            maxlength="100"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">State</label>
                        <input
                            class="form-control"
                            name="state"
                            value="<?= e((string)$settings['state']) ?>"
                            maxlength="100"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Postal Code
                        </label>
                        <input
                            class="form-control"
                            name="postal_code"
                            value="<?= e((string)$settings['postal_code']) ?>"
                            maxlength="15"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            GST Number
                        </label>
                        <input
                            class="form-control text-uppercase"
                            name="gst_number"
                            value="<?= e((string)$settings['gst_number']) ?>"
                            maxlength="30"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            PAN Number
                        </label>
                        <input
                            class="form-control text-uppercase"
                            name="pan_number"
                            value="<?= e((string)$settings['pan_number']) ?>"
                            maxlength="20"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>
                </div>
            </div>
        </section>

        <section class="settings-card">
            <div class="settings-card-head">
                <div class="settings-section-title">
                    <div class="settings-icon">
                        <i data-lucide="sliders-horizontal"></i>
                    </div>
                    <div>
                        <h2>Operational Defaults</h2>
                        <small class="text-muted">
                            Default numbering, currency and financial-year preferences.
                        </small>
                    </div>
                </div>
            </div>

            <div class="settings-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Currency
                        </label>

                        <select
                            class="form-select"
                            name="currency_code"
                            <?= !$canEditBusinessSettings ? 'disabled' : '' ?>
                        >
                            <?php foreach (['INR', 'USD', 'AED', 'SAR', 'SGD'] as $currency): ?>
                                <option
                                    value="<?= e($currency) ?>"
                                    <?= $settings['currency_code'] === $currency
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($currency) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Timezone
                        </label>

                        <select
                            class="form-select"
                            name="timezone"
                            <?= !$canEditBusinessSettings ? 'disabled' : '' ?>
                        >
                            <?php foreach ([
                                'Asia/Kolkata',
                                'Asia/Dubai',
                                'Asia/Riyadh',
                                'Asia/Singapore',
                                'UTC',
                            ] as $timezone): ?>
                                <option
                                    value="<?= e($timezone) ?>"
                                    <?= $settings['timezone'] === $timezone
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($timezone) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Financial Year Starts
                        </label>

                        <select
                            class="form-select"
                            name="financial_year_start_month"
                            <?= !$canEditBusinessSettings ? 'disabled' : '' ?>
                        >
                            <?php
                            $months = [
                                1 => 'January',
                                2 => 'February',
                                3 => 'March',
                                4 => 'April',
                                5 => 'May',
                                6 => 'June',
                                7 => 'July',
                                8 => 'August',
                                9 => 'September',
                                10 => 'October',
                                11 => 'November',
                                12 => 'December',
                            ];
                            ?>

                            <?php foreach ($months as $monthNo => $monthName): ?>
                                <option
                                    value="<?= $monthNo ?>"
                                    <?= (int)$settings['financial_year_start_month'] === $monthNo
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($monthName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Invoice Prefix
                        </label>

                        <input
                            class="form-control text-uppercase"
                            name="invoice_prefix"
                            value="<?= e((string)$settings['invoice_prefix']) ?>"
                            maxlength="20"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Receipt Prefix
                        </label>

                        <input
                            class="form-control text-uppercase"
                            name="receipt_prefix"
                            value="<?= e((string)$settings['receipt_prefix']) ?>"
                            maxlength="20"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status</label>

                        <select
                            class="form-select"
                            name="status"
                            <?= !$canEditBusinessSettings ? 'disabled' : '' ?>
                        >
                            <option
                                value="active"
                                <?= $settings['status'] === 'active'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Active
                            </option>
                            <option
                                value="inactive"
                                <?= $settings['status'] === 'inactive'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea
                            class="form-control"
                            name="notes"
                            rows="3"
                            maxlength="1000"
                            <?= !$canEditBusinessSettings ? 'readonly' : '' ?>
                        ><?= e((string)$settings['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($canEditBusinessSettings): ?>
            <div class="settings-actions">
                <button
                    class="btn btn-light"
                    type="reset"
                    id="resetSettingsButton"
                >
                    <i data-lucide="rotate-ccw"></i>
                    Reset
                </button>

                <button
                    class="btn btn-brand px-4"
                    type="submit"
                    id="saveSettingsButton"
                >
                    <i data-lucide="save"></i>
                    Save Settings
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('businessSettingsForm');
    const saveButton = document.getElementById('saveSettingsButton');
    const businessName = document.getElementById('businessName');
    const businessCode = document.getElementById('businessCode');
    const previewName = document.getElementById('previewBusinessName');
    const previewCode = document.getElementById('previewBusinessCode');
    const avatar = document.getElementById('businessAvatar');

    function updatePreview() {
        const name = String(businessName?.value || '').trim();

        if (previewName) {
            previewName.textContent = name || 'Business';
        }

        if (previewCode) {
            previewCode.textContent =
                String(businessCode?.value || '').trim();
        }

        if (avatar) {
            avatar.textContent =
                (name.charAt(0) || 'B').toUpperCase();
        }
    }

    businessName?.addEventListener('input', updatePreview);
    businessCode?.addEventListener('input', updatePreview);

    form?.addEventListener('submit', async event => {
        event.preventDefault();

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const originalHtml = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Saving...';

        try {
            const response = await fetch(
                '<?= e(app_url('api/business-settings.php')) ?>',
                {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
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
                        .trim()
                    || 'Invalid server response.'
                );
            }

            AppToast.show(
                result.success ? 'success' : 'error',
                result.message
            );

            if (result.success) {
                window.setTimeout(() => {
                    window.location.reload();
                }, 400);
            }
        } catch (error) {
            AppToast.show(
                'error',
                error.message || 'Unable to save business settings.'
            );
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = originalHtml;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    });

    updatePreview();

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
