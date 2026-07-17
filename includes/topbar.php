<?php
$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role_key'] ?? 'User';
$userInitial = strtoupper(substr(trim($userName),0,1)) ?: 'U';
?>
<header id="appTopbar">
    <div class="topbar-left">
        <button id="sidebarToggle" class="icon-button" type="button" aria-label="Toggle sidebar">
            <i data-lucide="menu"></i>
        </button>
        <div class="topbar-title d-none d-sm-block">
            <strong><?= e($currentBusiness['business_name'] ?? 'No Business') ?></strong>
            <small><?= e($pageTitle) ?></small>
        </div>
    </div>

    <div class="topbar-actions">
        <div class="dropdown">
            <button class="business-select dropdown-toggle" data-bs-toggle="dropdown" type="button">
                <i data-lucide="building-2"></i>
                <span><?= e($currentBusiness['business_name'] ?? 'No Business') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if (!$businesses): ?><li><span class="dropdown-item-text">No business assigned</span></li>
                <?php endif; ?>
                <?php foreach ($businesses as $business): ?>
                <li><button
                        class="dropdown-item business-switch-item <?= (int)$business['id']===$currentBusinessId?'active':'' ?>"
                        data-business-id="<?= (int)$business['id'] ?>"
                        type="button"><?= e($business['business_name']) ?></button></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <button id="themeToggle" class="icon-button" type="button" aria-label="Toggle theme">
            <i data-lucide="moon"></i>
        </button>

        <div class="dropdown">
            <button class="user-button dropdown-toggle" data-bs-toggle="dropdown" type="button">
                <span class="user-avatar"><?= e($userInitial) ?></span>
                <span
                    class="user-copy d-none d-md-block"><strong><?= e($userName) ?></strong><small><?= e($userRole) ?></small></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2"><strong><?= e($userName) ?></strong><small
                        class="d-block text-muted"><?= e($userRole) ?></small></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <?php if (is_owner()): ?><li><a class="dropdown-item" href="<?= e(app_url('manage-sidebar.php')) ?>"><i
                            data-lucide="panel-left"></i> Sidebar Control</a></li><?php endif; ?>
                <li><a class="dropdown-item" href="<?= e(app_url('business-settings.php')) ?>"><i
                            data-lucide="settings"></i> Settings</a></li>
                <li><a class="dropdown-item text-danger" href="<?= e(app_url('logout.php')) ?>"><i
                            data-lucide="log-out"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</header>