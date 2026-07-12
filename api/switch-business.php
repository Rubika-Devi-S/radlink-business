<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false,'Method not allowed',[],405);
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) json_response(false,'Session expired. Refresh and try again.',[],419);

$businessId = (int)($_POST['business_id'] ?? 0);
$allowed = false;
$businessName = '';
foreach ($businesses as $business) {
    if ((int)$business['id'] === $businessId) {
        $allowed = true;
        $businessName = $business['business_name'];
        break;
    }
}
if (!$allowed) json_response(false,'You do not have access to this business.',[],403);

$_SESSION['business_id'] = $businessId;
$_SESSION['business_name'] = $businessName;
json_response(true,'Business switched successfully.',['reload'=>true]);
