<?php
$menuRows = [];
if ($currentBusinessId > 0 && table_exists($pdo, 'business_sidebar_menus')) {
    if (is_owner()) {
        $stmt = $pdo->prepare(
            "SELECT id,parent_id,menu_title,menu_slug,menu_url,icon,sort_order
             FROM business_sidebar_menus
             WHERE business_id=? AND is_active=1 AND show_in_sidebar=1
             ORDER BY CASE WHEN parent_id IS NULL THEN sort_order ELSE 999999 END,
                      COALESCE(parent_id,id), parent_id IS NOT NULL, sort_order,id"
        );
        $stmt->execute([$currentBusinessId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT sm.id,sm.parent_id,sm.menu_title,sm.menu_slug,sm.menu_url,sm.icon,sm.sort_order
             FROM business_sidebar_menus sm
             INNER JOIN business_role_sidebar_access ra
                ON ra.menu_id=sm.id
               AND ra.business_id=sm.business_id
               AND ra.role_key=?
               AND ra.can_view=1
             WHERE sm.business_id=? AND sm.is_active=1 AND sm.show_in_sidebar=1
             ORDER BY CASE WHEN sm.parent_id IS NULL THEN sm.sort_order ELSE 999999 END,
                      COALESCE(sm.parent_id,sm.id), sm.parent_id IS NOT NULL, sm.sort_order,sm.id"
        );
        $stmt->execute([$_SESSION['role_key'] ?? '', $currentBusinessId]);
    }
    $menuRows = $stmt->fetchAll();
}
$mainMenus = [];
foreach ($menuRows as $row) {
    if (empty($row['parent_id'])) {
        $row['children'] = [];
        $mainMenus[(int)$row['id']] = $row;
    }
}
foreach ($menuRows as $row) {
    $pid = (int)($row['parent_id'] ?? 0);
    if ($pid && isset($mainMenus[$pid])) $mainMenus[$pid]['children'][] = $row;
}
$isActive = static fn(string $url): bool => basename(parse_url($url, PHP_URL_PATH) ?: '') === $currentPage;
?>
<aside id="appSidebar" aria-label="Main navigation">
  <div class="sidebar-brand">
    <a href="<?= e(app_url('index.php')) ?>" class="brand-link">
      <span class="brand-logo"><i data-lucide="heart-pulse"></i></span>
      <span class="brand-copy"><strong>RAD LINK HEALTH</strong><small>Business Suite</small></span>
    </a>
    <button type="button" class="sidebar-close d-xl-none" data-sidebar-close aria-label="Close sidebar">
      <i data-lucide="x"></i>
    </button>
  </div>

  <nav class="sidebar-scroll">
    <?php if (!$mainMenus): ?>
      <div class="sidebar-empty">No menu configured</div>
    <?php endif; ?>

    <?php foreach ($mainMenus as $menu):
      $children = $menu['children'];
      $parentActive = $isActive($menu['menu_url']);
      $childActive = false;
      foreach ($children as $child) if ($isActive($child['menu_url'])) $childActive = true;
      $open = $parentActive || $childActive;
    ?>
      <?php if ($children): ?>
        <button class="side-link side-parent <?= $open ? 'active open' : '' ?>"
                type="button"
                data-submenu-toggle="<?= (int)$menu['id'] ?>"
                aria-expanded="<?= $open ? 'true' : 'false' ?>"
                aria-controls="sidebar-submenu-<?= (int)$menu['id'] ?>"
                title="<?= e($menu['menu_title']) ?>">
          <i data-lucide="<?= e($menu['icon']) ?>"></i>
          <span class="side-text"><?= e($menu['menu_title']) ?></span>
          <i class="side-chevron" data-lucide="chevron-down"></i>
        </button>
        <div class="side-submenu <?= $open ? 'open' : '' ?>"
             id="sidebar-submenu-<?= (int)$menu['id'] ?>"
             data-submenu="<?= (int)$menu['id'] ?>"
             data-parent-title="<?= e($menu['menu_title']) ?>">
          <?php foreach ($children as $child): ?>
            <a class="side-link side-child <?= $isActive($child['menu_url']) ? 'active' : '' ?>"
               href="<?= e(app_url($child['menu_url'])) ?>"
               title="<?= e($child['menu_title']) ?>">
              <i data-lucide="<?= e($child['icon']) ?>"></i>
              <span class="side-text"><?= e($child['menu_title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <a class="side-link <?= $parentActive ? 'active' : '' ?>"
           href="<?= e(app_url($menu['menu_url'])) ?>"
           title="<?= e($menu['menu_title']) ?>">
          <i data-lucide="<?= e($menu['icon']) ?>"></i>
          <span class="side-text"><?= e($menu['menu_title']) ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</aside>
<div id="sidebarOverlay" data-sidebar-close></div>
