<?php
declare(strict_types=1);
$pageTitle = 'Settlements';
require_once __DIR__ . '/includes/bootstrap.php';
include __DIR__ . '/includes/layout-start.php';
?>
<div class="page-head">
    <div>
        <span class="badge-soft">RAD LINK HEALTH</span>
        <h1 class="mt-2">Settlements</h1>
        <p>Manage hospital settlements and outstanding balances.</p>
    </div>
    <button class="btn btn-brand"><i data-lucide="plus"></i> Add New</button>
</div>

<section class="card-ui p-4 text-center">
    <i data-lucide="arrow-left-right" style="width:48px;height:48px;color:var(--brand)"></i>
    <h2 class="h5 fw-bold mt-3">Settlements module</h2>
    <p class="text-muted mb-0">
        The common dynamic sidebar, top bar, business switcher, theme and toast system are connected.
        The module-specific CRUD can now be added without changing the layout.
    </p>
</section>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
