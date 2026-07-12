<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired.', [], 419);
}

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

if ($id <= 0) {
    json_response(false, 'Invalid hospital.', [], 422);
}

if ($action === 'toggle') {
    $stmt = $pdo->prepare(
        "UPDATE clients
         SET status = IF(status = 'active', 'inactive', 'active')
         WHERE id = ? AND business_id = ?"
    );
    $stmt->execute([$id, $currentBusinessId]);
    json_response(true, 'Hospital status changed.');
}

if ($action === 'delete') {
    $checks = [
        "SELECT COUNT(*) FROM invoices WHERE business_id = ? AND client_id = ?",
        "SELECT COUNT(*) FROM client_service_rates WHERE business_id = ? AND client_id = ?",
        "SELECT COUNT(*) FROM payments WHERE business_id = ? AND client_id = ?",
        "SELECT COUNT(*) FROM hospital_settlements WHERE business_id = ? AND client_id = ?",
    ];

    foreach ($checks as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$currentBusinessId, $id]);

            if ((int)$stmt->fetchColumn() > 0) {
                json_response(
                    false,
                    'This hospital is already used. Set it inactive instead.',
                    [],
                    422
                );
            }
        } catch (PDOException $exception) {
            // Ignore optional tables that are not yet present.
        }
    }

    $stmt = $pdo->prepare(
        "DELETE FROM clients
         WHERE id = ? AND business_id = ?"
    );
    $stmt->execute([$id, $currentBusinessId]);

    json_response(true, 'Hospital deleted.');
}

json_response(false, 'Invalid action.', [], 422);
