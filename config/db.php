<?php
declare(strict_types=1);


$databaseName = 'u966043993_radlink';
$databaseUser = 'u966043993_radlink';
$databasePassword = 'CU/;lz5j^';
$databasePort = 3306;
$charset = 'utf8mb4';

$remoteDatabaseHost = 'REPLACE_WITH_HOSTINGER_REMOTE_MYSQL_HOST';

$serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

$isLocalEnvironment =
    PHP_SAPI === 'cli-server'
    || $serverName === 'localhost'
    || $serverName === '127.0.0.1'
    || str_starts_with($httpHost, 'localhost')
    || str_starts_with($httpHost, '127.0.0.1');

$databaseHost = $isLocalEnvironment ? $remoteDatabaseHost : 'localhost';

if (
    $isLocalEnvironment
    && (
        $databaseHost === ''
        || $databaseHost === 'REPLACE_WITH_HOSTINGER_REMOTE_MYSQL_HOST'
        || $databaseHost === 'localhost'
    )
) {
    http_response_code(500);

    exit(
        '<h3>Remote database hostname is not configured.</h3>' .
        '<p>Open <strong>config/db.php</strong> and replace:</p>' .
        '<pre>$remoteDatabaseHost = \'REPLACE_WITH_HOSTINGER_REMOTE_MYSQL_HOST\';</pre>' .
        '<p>with the exact Remote MySQL hostname or IP from your Hostinger account.</p>'
    );
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $databaseHost,
    $databasePort,
    $databaseName,
    $charset
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false,
    PDO::ATTR_TIMEOUT            => 15,
];

try {
    $pdo = new PDO(
        $dsn,
        $databaseUser,
        $databasePassword,
        $options
    );

    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $exception) {
    error_log(
        sprintf(
            '[RAD LINK DB ERROR] Host: %s | Database: %s | Message: %s',
            $databaseHost,
            $databaseName,
            $exception->getMessage()
        )
    );

    http_response_code(500);

    if ($isLocalEnvironment) {
        exit(
            '<h3>Unable to connect to the remote database.</h3>' .
            '<p><strong>Host:</strong> ' .
            htmlspecialchars($databaseHost, ENT_QUOTES, 'UTF-8') .
            '</p><p><strong>Error:</strong> ' .
            htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') .
            '</p><p>Check the remote hostname, database credentials, port 3306, and whether your current public IP is permitted for remote database access.</p>'
        );
    }

    exit('Unable to connect to the database. Please try again later.');
}