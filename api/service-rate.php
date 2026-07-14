<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, 'Method not allowed.', [], 405);
}

$clientId = (int)($_GET['client_id'] ?? 0);
$serviceId = (int)($_GET['service_id'] ?? 0);
$rateDate = trim((string)($_GET['date'] ?? date('Y-m-d')));

if ($clientId <= 0 || $serviceId <= 0) {
    json_response(
        false,
        'Hospital and service are required.',
        [],
        422
    );
}

$dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $rateDate);

if (
    !$dateObject
    || $dateObject->format('Y-m-d') !== $rateDate
) {
    $rateDate = date('Y-m-d');
}

$serviceStmt = $pdo->prepare(
    "SELECT
        id,
        standard_rate,
        tax_percent
     FROM services
     WHERE id = ?
       AND business_id = ?
       AND status = 'active'
     LIMIT 1"
);

$serviceStmt->execute([
    $serviceId,
    $currentBusinessId,
]);

$service = $serviceStmt->fetch();

if (!$service) {
    json_response(false, 'Service not found.', [], 404);
}

$rateStmt = $pdo->prepare(
    "SELECT
        agreed_rate,
        effective_from,
        effective_to
     FROM client_service_rates
     WHERE business_id = ?
       AND client_id = ?
       AND service_id = ?
       AND status = 'active'
       AND effective_from <= ?
       AND (
            effective_to IS NULL
            OR effective_to >= ?
       )
     ORDER BY effective_from DESC, id DESC
     LIMIT 1"
);

$rateStmt->execute([
    $currentBusinessId,
    $clientId,
    $serviceId,
    $rateDate,
    $rateDate,
]);

$hospitalRate = $rateStmt->fetch();

$standardRate = (float)$service['standard_rate'];
$appliedRate = $hospitalRate
    ? (float)$hospitalRate['agreed_rate']
    : $standardRate;

json_response(
    true,
    $hospitalRate
        ? 'Hospital agreed rate loaded.'
        : 'Standard service rate loaded.',
    [
        'standard_rate' => $standardRate,
        'applied_rate' => $appliedRate,
        'tax_percent' => (float)$service['tax_percent'],
        'is_hospital_rate' => (bool)$hospitalRate,
        'effective_from' =>
            $hospitalRate['effective_from'] ?? null,
        'effective_to' =>
            $hospitalRate['effective_to'] ?? null,
    ]
);
