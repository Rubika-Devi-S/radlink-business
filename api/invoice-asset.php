<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/invoice-settings-functions.php';

if (!is_owner()) {
    json_response(false, 'Only the owner can manage invoice images.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired.', [], 419);
}

$assetType = (string)($_POST['asset_type'] ?? '');
$action = (string)($_POST['action'] ?? 'upload');

if (!in_array($assetType, ['logo', 'signature', 'qr'], true)) {
    json_response(false, 'Invalid asset type.', [], 422);
}

$businessStmt = $pdo->prepare(
    "SELECT logo_path
     FROM businesses
     WHERE id = ?
     LIMIT 1"
);

$businessStmt->execute([$currentBusinessId]);
$business = $businessStmt->fetch();

if (!$business) {
    json_response(false, 'Business not found.', [], 404);
}

function delete_local_asset(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $absolute = dirname(__DIR__) . '/' . ltrim($relativePath, '/');

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

if ($action === 'delete') {
    if ($assetType === 'logo') {
        delete_local_asset($business['logo_path'] ?? null);

        $stmt = $pdo->prepare(
            "UPDATE businesses
             SET logo_path = NULL
             WHERE id = ?"
        );

        $stmt->execute([$currentBusinessId]);

        json_response(true, 'Business logo removed.');
    }

    $settingKey = $assetType === 'signature'
        ? 'invoice_signature_path'
        : 'invoice_uploaded_qr_path';

    $currentPath = get_invoice_setting(
        $pdo,
        $currentBusinessId,
        $settingKey,
        ''
    );

    delete_local_asset($currentPath);

    save_invoice_setting(
        $pdo,
        $currentBusinessId,
        current_user_id(),
        $settingKey,
        ''
    );

    json_response(
        true,
        ucfirst($assetType) . ' image removed.'
    );
}

if (
    !isset($_FILES['asset_file'])
    || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK
) {
    json_response(false, 'Select a valid image file.', [], 422);
}

$file = $_FILES['asset_file'];

if ((int)$file['size'] > 2 * 1024 * 1024) {
    json_response(false, 'Image size must not exceed 2 MB.', [], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
];

if (!isset($allowed[$mime])) {
    json_response(
        false,
        'Only PNG, JPG and JPEG images are allowed.',
        [],
        422
    );
}

$extension = $allowed[$mime];
$folder = match ($assetType) {
    'logo' => 'assets/uploads/logos',
    'signature' => 'assets/uploads/signatures',
    'qr' => 'assets/uploads/qr',
};

$absoluteFolder = dirname(__DIR__) . '/' . $folder;

if (!is_dir($absoluteFolder)) {
    mkdir($absoluteFolder, 0755, true);
}

$fileName = sprintf(
    '%s_%d_%s.%s',
    $assetType,
    $currentBusinessId,
    bin2hex(random_bytes(6)),
    $extension
);

$relativePath = $folder . '/' . $fileName;
$absolutePath = dirname(__DIR__) . '/' . $relativePath;

if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    json_response(false, 'Unable to store the uploaded image.', [], 500);
}

if ($assetType === 'logo') {
    delete_local_asset($business['logo_path'] ?? null);

    $stmt = $pdo->prepare(
        "UPDATE businesses
         SET logo_path = ?
         WHERE id = ?"
    );

    $stmt->execute([
        $relativePath,
        $currentBusinessId,
    ]);

    json_response(
        true,
        'Business logo uploaded successfully.',
        ['path' => $relativePath]
    );
}

$settingKey = $assetType === 'signature'
    ? 'invoice_signature_path'
    : 'invoice_uploaded_qr_path';

$oldPath = get_invoice_setting(
    $pdo,
    $currentBusinessId,
    $settingKey,
    ''
);

delete_local_asset($oldPath);

save_invoice_setting(
    $pdo,
    $currentBusinessId,
    current_user_id(),
    $settingKey,
    $relativePath
);

json_response(
    true,
    ucfirst($assetType) . ' image uploaded successfully.',
    ['path' => $relativePath]
);
