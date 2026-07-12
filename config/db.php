<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

/**
 * RAD LINK HEALTH
 * Database connection for:
 * 1. Hostinger live server  -> localhost
 * 2. Local XAMPP / WAMP    -> Hostinger remote MySQL host
 */

$serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
$httpHost   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

/*
|--------------------------------------------------------------------------
| Environment Detection
|--------------------------------------------------------------------------
*/
$isLocalhost =
    in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
    || str_starts_with($httpHost, 'localhost')
    || str_starts_with($httpHost, '127.0.0.1')
    || str_contains($serverName, '.test')
    || str_contains($serverName, '.local')
    || PHP_SAPI === 'cli-server';

/*
|--------------------------------------------------------------------------
| Database Host
|--------------------------------------------------------------------------
| Local development uses Hostinger remote MySQL.
| Live Hostinger server uses localhost.
*/
if ($isLocalhost) {
    $dbHost = 'auth-db1740.hstgr.io';
    $dbPort = 3306;
} else {
    $dbHost = 'localhost';
    $dbPort = 3306;
}

/*
|--------------------------------------------------------------------------
| Database Credentials
|--------------------------------------------------------------------------
*/
$dbName = 'u966043993_radlink';
$dbUser = 'u966043993_radlink';
$dbPass = 'CU/;lz5j^';
$dbCharset = 'utf8mb4';

/*
|--------------------------------------------------------------------------
| PDO Connection
|--------------------------------------------------------------------------
*/
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false,
    PDO::ATTR_TIMEOUT            => 20,
];

try {
    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        $pdoOptions
    );

    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    $safeHost = htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8');
    $safeError = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    error_log(
        sprintf(
            '[RAD LINK PDO ERROR] Host: %s | Database: %s | Message: %s',
            $dbHost,
            $dbName,
            $e->getMessage()
        )
    );

    http_response_code(500);

    if ($isLocalhost) {
        die(
            '<h3>PDO remote database connection failed</h3>' .
            '<p><strong>Host:</strong> ' . $safeHost . '</p>' .
            '<p><strong>Database:</strong> ' . htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p><strong>Error:</strong> ' . $safeError . '</p>' .
            '<p>Please confirm that:</p>' .
            '<ul>' .
            '<li>Your current public IP is added in Hostinger Remote MySQL access.</li>' .
            '<li>Port 3306 is not blocked by your internet provider or firewall.</li>' .
            '<li>The remote host is exactly <strong>auth-db1740.hstgr.io</strong>.</li>' .
            '<li>The PHP extension <strong>pdo_mysql</strong> is enabled.</li>' .
            '</ul>'
        );
    }

    die('Unable to connect to the database. Please try again later.');
}

/*
|--------------------------------------------------------------------------
| MySQLi Connection
|--------------------------------------------------------------------------
| Kept for compatibility with pages that use mysqli.
*/
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect(
    $dbHost,
    $dbUser,
    $dbPass,
    $dbName,
    $dbPort
);

if (!$conn) {
    $mysqliError = mysqli_connect_error();

    error_log(
        sprintf(
            '[RAD LINK MYSQLI ERROR] Host: %s | Database: %s | Message: %s',
            $dbHost,
            $dbName,
            $mysqliError
        )
    );

    http_response_code(500);

    if ($isLocalhost) {
        die(
            '<h3>MySQLi remote database connection failed</h3>' .
            '<p><strong>Host:</strong> ' .
            htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8') .
            '</p><p><strong>Error:</strong> ' .
            htmlspecialchars($mysqliError, ENT_QUOTES, 'UTF-8') .
            '</p>'
        );
    }

    die('Unable to connect to the database. Please try again later.');
}

mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+05:30'");
?>
