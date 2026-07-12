<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_login();

$currentUserId = current_user_id();
$businesses = get_businesses_for_user($pdo, $currentUserId);
$currentBusiness = resolve_current_business($pdo, $currentUserId);
$currentBusinessId = (int)($currentBusiness['id'] ?? 0);
$currentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
$pageTitle = $pageTitle ?? 'RAD LINK HEALTH';
$flashMessage = pull_flash();
