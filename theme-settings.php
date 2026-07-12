<?php
declare(strict_types=1);

$pageTitle = 'Theme Settings';
require_once __DIR__ . '/includes/bootstrap.php';

if (!is_owner()) {
    http_response_code(403);
    exit('Only the owner can manage theme settings.');
}

$defaultSettings = [
    'body_bg' => ['Body Background', '#F5F6FB', 'layout', '--body-bg'],
    'topbar_bg' => ['Topbar Background', '#FFFFFF', 'layout', '--topbar-bg'],
    'card_bg' => ['Card Background', '#FFFFFF', 'layout', '--card-bg'],
    'border_soft' => ['Border Colour', '#E7EAF2', 'layout', '--border-soft'],

    'text_main' => ['Main Text', '#10182B', 'text', '--text-main'],
    'text_muted' => ['Muted Text', '#718096', 'text', '--text-muted'],

    'sidebar_bg' => ['Sidebar Background', '#FFFFFF', 'sidebar', '--sidebar-bg'],
    'sidebar_text' => ['Sidebar Text', '#23314D', 'sidebar', '--sidebar-text'],
    'sidebar_hover_bg' => ['Sidebar Hover', '#F4EFFF', 'sidebar', '--sidebar-hover'],
    'sidebar_active_bg' => ['Sidebar Active', '#EEE5FF', 'sidebar', '--sidebar-active'],

    'brand_1' => ['Primary Brand', '#7541D8', 'brand', '--brand'],
    'brand_2' => ['Secondary Brand', '#9A65F0', 'brand', '--brand-2'],

    'success_color' => ['Success', '#16A34A', 'status', '--success'],
    'warning_color' => ['Warning', '#D97706', 'status', '--warning'],
    'danger_color' => ['Danger', '#DC2626', 'status', '--danger'],
    'info_color' => ['Information', '#2563EB', 'status', '--info'],
];

$values = [];

foreach ($defaultSettings as $key => $row) {
    $values[$key] = $row[1];
}

if ($currentBusinessId > 0 && table_exists($pdo, 'website_color_settings')) {
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value
         FROM website_color_settings
         WHERE business_id = ?
           AND is_active = 1"
    );

    $stmt->execute([$currentBusinessId]);

    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $values)) {
            $values[$row['setting_key']] = $row['setting_value'];
        }
    }
}

$cssVariableMap = [];

foreach ($defaultSettings as $key => $row) {
    $cssVariableMap[$key] = $row[3];
}

include __DIR__ . '/includes/layout-start.php';
?>

<style>
.theme-preview-toolbar {
    position: sticky;
    top: calc(var(--topbar-h, 68px) + 12px);
    z-index: 1020;
}

.theme-preview-note {
    background: var(--card-bg);
    color: var(--text-main);
    border: 1px solid var(--border-soft);
    border-radius: 16px;
    box-shadow: var(--shadow);
}

.theme-live-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--success);
    font-size: 12px;
    font-weight: 800;
}

.theme-live-status::before {
    content: '';
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 0 5px rgba(22, 163, 74, .12);
}

.theme-color-picker {
    width: 58px;
    min-width: 58px;
    padding: 4px;
    cursor: pointer;
}

.theme-setting-card {
    transition: transform .18s ease, box-shadow .18s ease;
}

.theme-setting-card:hover {
    transform: translateY(-1px);
}

.live-preview-shell {
    background: var(--body-bg);
    border: 1px solid var(--border-soft);
    border-radius: 20px;
    padding: 14px;
}

.live-preview-topbar {
    min-height: 56px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    background: var(--topbar-bg);
    color: var(--text-main);
    border: 1px solid var(--border-soft);
    border-radius: 15px;
}

.live-preview-layout {
    display: grid;
    grid-template-columns: 180px minmax(0, 1fr);
    gap: 12px;
    margin-top: 12px;
}

.live-preview-sidebar {
    min-height: 280px;
    padding: 12px;
    background: var(--sidebar-bg);
    color: var(--sidebar-text);
    border: 1px solid var(--border-soft);
    border-radius: 15px;
}

.preview-menu-link {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 42px;
    padding: 9px 10px;
    margin-bottom: 6px;
    border-radius: 11px;
    color: var(--sidebar-text);
    font-weight: 700;
    transition: .18s ease;
}

.preview-menu-link:hover {
    background: var(--sidebar-hover);
}

.preview-menu-link.active {
    background: var(--sidebar-active);
    color: var(--brand);
}

.preview-menu-link svg {
    width: 17px;
    height: 17px;
}

.live-preview-content {
    min-width: 0;
}

.preview-card {
    background: var(--card-bg);
    color: var(--text-main);
    border: 1px solid var(--border-soft);
    border-radius: 15px;
    padding: 15px;
    margin-bottom: 12px;
}

.preview-muted {
    color: var(--text-muted);
}

.preview-brand-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 13px;
    color: #fff;
    background: linear-gradient(135deg, var(--brand), var(--brand-2));
}

.preview-status-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.preview-status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
}

.preview-success {
    background: var(--success);
}

.preview-warning {
    background: var(--warning);
}

.preview-danger {
    background: var(--danger);
}

.preview-info {
    background: var(--info);
}

@media (max-width: 767.98px) {
    .theme-preview-toolbar {
        position: static;
    }

    .live-preview-layout {
        grid-template-columns: 1fr;
    }

    .live-preview-sidebar {
        min-height: auto;
    }
}
</style>

<div class="page-head">
    <div>
        <span class="badge-soft">MASTER</span>
        <h1 class="mt-2">Theme Settings</h1>
        <p>
            Update the appearance for
            <?= e($currentBusiness['business_name'] ?? 'the selected business') ?>.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <button
            type="button"
            class="btn btn-outline-secondary"
            id="resetThemePreview"
        >
            <i data-lucide="rotate-ccw"></i>
            Reset Preview
        </button>

        <button
            form="themeForm"
            class="btn btn-brand"
            type="submit"
            id="saveThemeButton"
        >
            <i data-lucide="save"></i>
            Save Theme
        </button>
    </div>
</div>

<div class="theme-preview-toolbar mb-3">
    <div class="theme-preview-note p-3">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
            <div>
                <div class="theme-live-status">
                    Live preview enabled
                </div>

                <div class="small preview-muted mt-1">
                    The page, sidebar, top bar, cards and buttons update immediately.
                    Changes are stored only after clicking Save Theme.
                </div>
            </div>

            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                id="viewPreviewButton"
            >
                <i data-lucide="eye"></i>
                View Preview
            </button>
        </div>
    </div>
</div>

<form id="themeForm">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(csrf_token()) ?>"
    >

    <?php foreach (
        [
            'brand' => 'Brand',
            'sidebar' => 'Sidebar',
            'layout' => 'Layout',
            'text' => 'Text',
            'status' => 'Status',
        ] as $groupKey => $groupTitle
    ): ?>
        <section class="card-ui theme-setting-card p-3 p-lg-4 mb-3">
            <h2 class="h6 fw-bold mb-3">
                <?= e($groupTitle) ?> Colours
            </h2>

            <div class="row g-3">
                <?php foreach (
                    $defaultSettings as
                    $key => [$label, $default, $group, $cssVariable]
                ): ?>
                    <?php if ($group !== $groupKey) continue; ?>

                    <div class="col-sm-6 col-xl-4">
                        <label
                            class="form-label fw-semibold"
                            for="<?= e($key) ?>"
                        >
                            <?= e($label) ?>
                        </label>

                        <div class="input-group">
                            <input
                                type="color"
                                class="form-control form-control-color theme-color-picker"
                                id="<?= e($key) ?>"
                                data-color-picker="<?= e($key) ?>"
                                value="<?= e($values[$key]) ?>"
                                title="<?= e($label) ?>"
                            >

                            <input
                                type="text"
                                class="form-control"
                                name="<?= e($key) ?>"
                                data-color-text="<?= e($key) ?>"
                                value="<?= e($values[$key]) ?>"
                                maxlength="7"
                                pattern="^#[0-9A-Fa-f]{6}$"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</form>

<section
    class="card-ui p-3 p-lg-4 mb-3"
    id="themePreviewSection"
>
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h6 fw-bold mb-1">Live Interface Preview</h2>
            <p class="preview-muted small mb-0">
                This preview changes while selecting colours.
            </p>
        </div>

        <span class="badge-soft">PREVIEW</span>
    </div>

    <div class="live-preview-shell">
        <div class="live-preview-topbar">
            <i data-lucide="menu"></i>
            <strong>RAD LINK SCANS</strong>

            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="preview-muted small">Owner</span>
                <span class="preview-brand-icon">R</span>
            </div>
        </div>

        <div class="live-preview-layout">
            <aside class="live-preview-sidebar">
                <div class="preview-menu-link active">
                    <i data-lucide="layout-dashboard"></i>
                    Dashboard
                </div>

                <div class="preview-menu-link">
                    <i data-lucide="hospital"></i>
                    Clients
                </div>

                <div class="preview-menu-link">
                    <i data-lucide="receipt-text"></i>
                    Invoices
                </div>

                <div class="preview-menu-link">
                    <i data-lucide="credit-card"></i>
                    Payments
                </div>

                <div class="preview-menu-link">
                    <i data-lucide="bar-chart-3"></i>
                    Reports
                </div>
            </aside>

            <div class="live-preview-content">
                <div class="preview-card">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div>
                            <div class="preview-muted small">
                                Today's Billing
                            </div>
                            <strong class="fs-4">₹24,500.00</strong>
                        </div>

                        <span class="preview-brand-icon">
                            <i data-lucide="indian-rupee"></i>
                        </span>
                    </div>
                </div>

                <div class="preview-card">
                    <h3 class="h6 fw-bold">Recent Invoice</h3>
                    <p class="preview-muted mb-3">
                        Apollo Scan Centre · RLS-INV-00021
                    </p>

                    <button
                        type="button"
                        class="btn btn-brand btn-sm"
                    >
                        View Invoice
                    </button>
                </div>

                <div class="preview-status-badges">
                    <span class="preview-status-badge preview-success">
                        Success
                    </span>

                    <span class="preview-status-badge preview-warning">
                        Warning
                    </span>

                    <span class="preview-status-badge preview-danger">
                        Error
                    </span>

                    <span class="preview-status-badge preview-info">
                        Information
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('themeForm');
    const rootStyle = document.documentElement.style;
    const saveButton = document.getElementById('saveThemeButton');

    const savedValues = <?= json_encode(
        $values,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>;

    const cssVariableMap = <?= json_encode(
        $cssVariableMap,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>;

    function isValidHex(value) {
        return /^#[0-9A-Fa-f]{6}$/.test(value);
    }

    function applyPreview(key, value) {
        if (!isValidHex(value)) {
            return;
        }

        const cssVariable = cssVariableMap[key];

        if (!cssVariable) {
            return;
        }

        rootStyle.setProperty(
            cssVariable,
            value.toUpperCase()
        );
    }

    function syncInputs(key, value, source = '') {
        const picker = document.querySelector(
            '[data-color-picker="' + key + '"]'
        );

        const text = document.querySelector(
            '[data-color-text="' + key + '"]'
        );

        if (!picker || !text || !isValidHex(value)) {
            return;
        }

        const normalized = value.toUpperCase();

        if (source !== 'picker') {
            picker.value = normalized;
        }

        if (source !== 'text') {
            text.value = normalized;
        }

        applyPreview(key, normalized);
    }

    document
        .querySelectorAll('[data-color-picker]')
        .forEach(picker => {
            picker.addEventListener('input', () => {
                syncInputs(
                    picker.dataset.colorPicker,
                    picker.value,
                    'picker'
                );
            });
        });

    document
        .querySelectorAll('[data-color-text]')
        .forEach(text => {
            text.addEventListener('input', () => {
                const value = text.value.trim();

                if (isValidHex(value)) {
                    syncInputs(
                        text.dataset.colorText,
                        value,
                        'text'
                    );
                }
            });

            text.addEventListener('blur', () => {
                const key = text.dataset.colorText;
                const value = text.value.trim();

                if (!isValidHex(value)) {
                    syncInputs(key, savedValues[key]);

                    AppToast.show(
                        'warning',
                        'Invalid colour code was reset.'
                    );
                }
            });
        });

    document
        .getElementById('resetThemePreview')
        .addEventListener('click', () => {
            Object.entries(savedValues).forEach(
                ([key, value]) => {
                    syncInputs(key, value);
                }
            );

            AppToast.show(
                'info',
                'Preview reset to the saved theme.'
            );
        });

    document
        .getElementById('viewPreviewButton')
        .addEventListener('click', () => {
            document
                .getElementById('themePreviewSection')
                .scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
        });

    form.addEventListener('submit', async event => {
        event.preventDefault();

        const originalButtonHtml = saveButton.innerHTML;

        saveButton.disabled = true;
        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>' +
            'Saving...';

        try {
            const response = await fetch(
                '<?= e(app_url('api/theme-settings.php')) ?>',
                {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const result = await response.json();

            AppToast.show(
                result.success ? 'success' : 'error',
                result.message
            );

            if (result.success) {
                window.setTimeout(() => {
                    window.location.reload();
                }, 500);
            }
        } catch (error) {
            AppToast.show(
                'error',
                'Unable to save theme settings. Please try again.'
            );
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = originalButtonHtml;

            if (window.lucide) {
                lucide.createIcons();
            }
        }
    });

    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-end.php'; ?>
