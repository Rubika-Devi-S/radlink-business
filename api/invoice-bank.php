<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_owner()) {
    json_response(false, 'Only the owner can manage bank accounts.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired.', [], 419);
}

$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);

if ($action === 'save') {
    $accountName = trim((string)($_POST['account_name'] ?? ''));
    $bankName = trim((string)($_POST['bank_name'] ?? ''));
    $branchName = trim((string)($_POST['branch_name'] ?? ''));
    $accountNumber = trim((string)($_POST['account_number'] ?? ''));
    $ifscCode = strtoupper(trim((string)($_POST['ifsc_code'] ?? '')));
    $upiId = trim((string)($_POST['upi_id'] ?? ''));
    $status = (string)($_POST['status'] ?? 'active');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    if (
        $accountName === ''
        || $bankName === ''
        || $accountNumber === ''
        || $ifscCode === ''
    ) {
        json_response(
            false,
            'Account name, bank, account number and IFSC are required.',
            [],
            422
        );
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    try {
        $pdo->beginTransaction();

        if ($isDefault) {
            $clear = $pdo->prepare(
                "UPDATE business_bank_accounts
                 SET is_default = 0
                 WHERE business_id = ?"
            );

            $clear->execute([$currentBusinessId]);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE business_bank_accounts
                 SET account_name = ?,
                     bank_name = ?,
                     branch_name = ?,
                     account_number = ?,
                     ifsc_code = ?,
                     upi_id = ?,
                     is_default = ?,
                     status = ?
                 WHERE id = ?
                   AND business_id = ?"
            );

            $stmt->execute([
                $accountName,
                $bankName,
                $branchName ?: null,
                $accountNumber,
                $ifscCode,
                $upiId ?: null,
                $isDefault,
                $status,
                $id,
                $currentBusinessId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO business_bank_accounts
                    (
                        business_id,
                        account_name,
                        bank_name,
                        branch_name,
                        account_number,
                        ifsc_code,
                        upi_id,
                        is_default,
                        status
                    )
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $currentBusinessId,
                $accountName,
                $bankName,
                $branchName ?: null,
                $accountNumber,
                $ifscCode,
                $upiId ?: null,
                $isDefault,
                $status,
            ]);

            $id = (int)$pdo->lastInsertId();
        }

        $defaultCheck = $pdo->prepare(
            "SELECT COUNT(*)
             FROM business_bank_accounts
             WHERE business_id = ?
               AND is_default = 1"
        );

        $defaultCheck->execute([$currentBusinessId]);

        if ((int)$defaultCheck->fetchColumn() === 0) {
            $makeDefault = $pdo->prepare(
                "UPDATE business_bank_accounts
                 SET is_default = 1
                 WHERE id = ?
                   AND business_id = ?"
            );

            $makeDefault->execute([
                $id,
                $currentBusinessId,
            ]);
        }

        $pdo->commit();

        json_response(
            true,
            'Bank account saved successfully.'
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(
            false,
            'Unable to save bank account.',
            [],
            422
        );
    }
}

if ($action === 'toggle') {
    $stmt = $pdo->prepare(
        "UPDATE business_bank_accounts
         SET status = IF(status = 'active', 'inactive', 'active')
         WHERE id = ?
           AND business_id = ?"
    );

    $stmt->execute([
        $id,
        $currentBusinessId,
    ]);

    json_response(true, 'Bank account status changed.');
}

if ($action === 'default') {
    try {
        $pdo->beginTransaction();

        $clear = $pdo->prepare(
            "UPDATE business_bank_accounts
             SET is_default = 0
             WHERE business_id = ?"
        );

        $clear->execute([$currentBusinessId]);

        $set = $pdo->prepare(
            "UPDATE business_bank_accounts
             SET is_default = 1,
                 status = 'active'
             WHERE id = ?
               AND business_id = ?"
        );

        $set->execute([
            $id,
            $currentBusinessId,
        ]);

        $pdo->commit();

        json_response(true, 'Default invoice account updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(false, 'Unable to update default account.', [], 422);
    }
}

if ($action === 'delete') {
    $check = $pdo->prepare(
        "SELECT is_default
         FROM business_bank_accounts
         WHERE id = ?
           AND business_id = ?
         LIMIT 1"
    );

    $check->execute([
        $id,
        $currentBusinessId,
    ]);

    $account = $check->fetch();

    if (!$account) {
        json_response(false, 'Bank account not found.', [], 404);
    }

    if ((int)$account['is_default'] === 1) {
        json_response(
            false,
            'Set another account as default before deleting this account.',
            [],
            422
        );
    }

    $delete = $pdo->prepare(
        "DELETE FROM business_bank_accounts
         WHERE id = ?
           AND business_id = ?"
    );

    $delete->execute([
        $id,
        $currentBusinessId,
    ]);

    json_response(true, 'Bank account deleted.');
}

json_response(false, 'Invalid bank action.', [], 422);
