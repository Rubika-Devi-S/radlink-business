<?php
declare(strict_types=1);

/**
 * RAD LINK HEALTH
 * PDO database connection
 */

$host = 'localhost';
$dbname = 'u966043993_radlink';
$username = 'u966043993_radlink';
$password = 'CU/;lz5j^';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to connect to the database. Please try again later.');
}
