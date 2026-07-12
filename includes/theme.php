<?php
$themeDefaults = [
    'body_bg' => '#F5F6FB',
    'topbar_bg' => '#FFFFFF',
    'card_bg' => '#FFFFFF',
    'border_soft' => '#E7EAF2',
    'text_main' => '#10182B',
    'text_muted' => '#718096',
    'sidebar_bg' => '#FFFFFF',
    'sidebar_text' => '#23314D',
    'sidebar_hover_bg' => '#F4EFFF',
    'sidebar_active_bg' => '#EEE5FF',
    'brand_1' => '#7541D8',
    'brand_2' => '#9A65F0',
    'success_color' => '#16A34A',
    'warning_color' => '#D97706',
    'danger_color' => '#DC2626',
    'info_color' => '#2563EB',
];

$themeValues = $themeDefaults;

if (isset($pdo) && current_business_id() > 0 && table_exists($pdo, 'website_color_settings')) {
    $stmt = $pdo->prepare(
        "SELECT setting_key, setting_value
         FROM website_color_settings
         WHERE business_id = ? AND is_active = 1"
    );
    $stmt->execute([current_business_id()]);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $themeValues)) {
            $themeValues[$row['setting_key']] = $row['setting_value'];
        }
    }
}

$themeMode = isset($pdo) ? ui_setting($pdo, 'theme_mode', 'light') : 'light';
?>
<style>
:root {
    --body-bg: <?=e($themeValues['body_bg']) ?>;
    --topbar-bg: <?=e($themeValues['topbar_bg']) ?>;
    --card-bg: <?=e($themeValues['card_bg']) ?>;
    --border-soft: <?=e($themeValues['border_soft']) ?>;
    --text-main: <?=e($themeValues['text_main']) ?>;
    --text-muted: <?=e($themeValues['text_muted']) ?>;
    --sidebar-bg: <?=e($themeValues['sidebar_bg']) ?>;
    --sidebar-text: <?=e($themeValues['sidebar_text']) ?>;
    --sidebar-hover: <?=e($themeValues['sidebar_hover_bg']) ?>;
    --sidebar-active: <?=e($themeValues['sidebar_active_bg']) ?>;
    --brand: <?=e($themeValues['brand_1']) ?>;
    --brand-2: <?=e($themeValues['brand_2']) ?>;
    --success: <?=e($themeValues['success_color']) ?>;
    --warning: <?=e($themeValues['warning_color']) ?>;
    --danger: <?=e($themeValues['danger_color']) ?>;
    --info: <?=e($themeValues['info_color']) ?>;
    --shadow: 0 14px 38px rgba(30, 25, 50, .08);
    --sidebar-wide: 264px;
    --sidebar-mini: 82px;
    --topbar-h: 68px;
}

html[data-theme="dark"] {
    --body-bg: #111827;
    --card-bg: #182235;
    --topbar-bg: rgba(24, 34, 53, .96);
    --text-main: #F8FAFC;
    --text-muted: #A8B3C7;
    --border-soft: #2A3851;
    --sidebar-bg: #131D2E;
    --sidebar-text: #DCE5F5;
    --sidebar-hover: #26334B;
    --sidebar-active: #392C61;
}
</style>
<script>
document.documentElement.dataset.theme = <?= json_encode($themeMode) ?>;
</script>