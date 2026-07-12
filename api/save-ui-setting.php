<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false,'Method not allowed',[],405);
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) json_response(false,'Session expired. Refresh and try again.',[],419);

$key = (string)($_POST['key'] ?? '');
$value = (string)($_POST['value'] ?? '');
$allowed = ['theme_mode','sidebar_default_collapsed'];
if (!in_array($key,$allowed,true)) json_response(false,'Invalid setting.',[],422);

save_ui_setting($pdo,$key,$value);
json_response(true,'Preference saved.');
