<?php
declare(strict_types=1);
$pageTitle = 'Master';
require_once __DIR__ . '/includes/bootstrap.php';
include __DIR__ . '/includes/layout-start.php';
?>
<div class="page-head">
    <div>
        <span class="badge-soft">ADMINISTRATION</span>
        <h1 class="mt-2">Master</h1>
        <p>Manage reusable configuration and interface settings.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6 col-xl-4">
        <a href="<?= e(app_url('theme-settings.php')) ?>" class="card-ui p-4 d-block h-100 text-reset">
            <i data-lucide="palette" style="width:34px;height:34px;color:var(--brand)"></i>
            <h2 class="h6 fw-bold mt-3">Theme Settings</h2>
            <p class="text-muted mb-0">Configure sidebar, top bar, background, card, text and status colours.</p>
        </a>
    </div>

    <div class="col-md-6 col-xl-4">
        <a href="<?= e(app_url('manage-sidebar.php')) ?>" class="card-ui p-4 d-block h-100 text-reset">
            <i data-lucide="panel-left" style="width:34px;height:34px;color:var(--brand)"></i>
            <h2 class="h6 fw-bold mt-3">Sidebar Control</h2>
            <p class="text-muted mb-0">Add main menus, create submenus, arrange order and control visibility.</p>
        </a>
    </div>

    <div class="col-md-6 col-xl-4">
        <a href="<?= e(app_url('settings.php')) ?>" class="card-ui p-4 d-block h-100 text-reset">
            <i data-lucide="settings" style="width:34px;height:34px;color:var(--brand)"></i>
            <h2 class="h6 fw-bold mt-3">Business Settings</h2>
            <p class="text-muted mb-0">Manage business profile, invoice configuration, bank and UPI information.</p>
        </a>
    </div>
</div>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
