<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=UTF-8');

$baseDir = __DIR__ . '/lib/phpqrcode';
$qrlib = $baseDir . '/qrlib.php';
$outputDir = __DIR__ . '/assets/uploads/qr';
$outputFile = $outputDir . '/upi-test.png';

echo "PHP version: " . PHP_VERSION . PHP_EOL;
echo "GD enabled: " . (extension_loaded('gd') ? 'YES' : 'NO') . PHP_EOL;
echo "PNG support: " . (function_exists('imagepng') ? 'YES' : 'NO') . PHP_EOL;
echo "qrlib.php exists: " . (is_file($qrlib) ? 'YES' : 'NO') . PHP_EOL;
echo "QR folder: " . $baseDir . PHP_EOL;

$requiredFiles = [
    'qrconst.php',
    'qrconfig.php',
    'qrtools.php',
    'qrspec.php',
    'qrimage.php',
    'qrinput.php',
    'qrbitstream.php',
    'qrsplit.php',
    'qrrscode.php',
    'qrmask.php',
    'qrencode.php',
];

foreach ($requiredFiles as $file) {
    echo str_pad($file, 20) . ': ' . (is_file($baseDir . '/' . $file) ? 'OK' : 'MISSING') . PHP_EOL;
}

if (!is_dir($outputDir)) {
    echo "Creating output directory..." . PHP_EOL;
    if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        exit("FAILED: Could not create {$outputDir}" . PHP_EOL);
    }
}

echo "Output directory writable: " . (is_writable($outputDir) ? 'YES' : 'NO') . PHP_EOL;

if (!extension_loaded('gd') || !function_exists('imagepng')) {
    exit("FAILED: Enable PHP GD extension in WAMP and restart Apache." . PHP_EOL);
}

if (!is_file($qrlib)) {
    exit("FAILED: qrlib.php not found at {$qrlib}" . PHP_EOL);
}

$upiId = '9787457070-kf4c@ybl';
$amount = '100.00';
$invoiceNumber = 'QR-TEST-001';

$upiUrl = 'upi://pay?pa=' . rawurlencode($upiId)
    . '&pn=' . rawurlencode('RAD LINK SCANS')
    . '&am=' . rawurlencode($amount)
    . '&cu=INR'
    . '&tn=' . rawurlencode($invoiceNumber);

echo "UPI URL: {$upiUrl}" . PHP_EOL;

try {
    ob_start();
    require_once $qrlib;
    $unexpectedOutput = trim((string)ob_get_clean());

    if ($unexpectedOutput !== '') {
        echo "Library output/warning:" . PHP_EOL;
        echo $unexpectedOutput . PHP_EOL;
    }

    if (!class_exists('QRcode')) {
        exit("FAILED: QRcode class was not loaded." . PHP_EOL);
    }

    QRcode::png($upiUrl, $outputFile, QR_ECLEVEL_M, 8, 2);

    clearstatcache(true, $outputFile);

    if (!is_file($outputFile) || filesize($outputFile) < 100) {
        exit("FAILED: QR file was not created correctly." . PHP_EOL);
    }

    echo "SUCCESS: QR generated." . PHP_EOL;
    echo "File: {$outputFile}" . PHP_EOL;
    echo "Size: " . filesize($outputFile) . " bytes" . PHP_EOL;
    echo "Browser URL: assets/uploads/qr/upi-test.png" . PHP_EOL;
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo "FAILED: " . $error->getMessage() . PHP_EOL;
    echo "File: " . $error->getFile() . PHP_EOL;
    echo "Line: " . $error->getLine() . PHP_EOL;
}
