<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/invoice-functions.php';

$action = trim((string)($_REQUEST['action'] ?? ''));

if ($currentBusinessId <= 0) {
    json_response(false, 'Select a business first.', [], 422);
}

function payment_current_financial_year(PDO $pdo, int $businessId, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM financial_years
         WHERE business_id = ?
           AND ? BETWEEN start_date AND end_date
           AND status = 'open'
         ORDER BY is_current DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$businessId, $date]);
    $fy = $stmt->fetch();

    if ($fy) {
        return $fy;
    }

    if (function_exists('current_financial_year')) {
        $fy = current_financial_year($pdo, $businessId, $date);
        if ($fy) return $fy;
    }

    throw new RuntimeException('No open financial year exists for the payment date.');
}

function payment_next_receipt_number(PDO $pdo, int $businessId, int $financialYearId): string
{
    $businessStmt = $pdo->prepare("SELECT receipt_prefix FROM businesses WHERE id = ? LIMIT 1");
    $businessStmt->execute([$businessId]);
    $prefix = trim((string)($businessStmt->fetchColumn() ?: 'RLS-REC'));
    if ($prefix === '') $prefix = 'RLS-REC';

    $columnsStmt = $pdo->query("SHOW COLUMNS FROM document_sequences");
    $columns = array_column($columnsStmt->fetchAll(), 'Field');

    if (in_array('next_number', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT id, next_number, padding_length
             FROM document_sequences
             WHERE business_id = ?
               AND financial_year_id = ?
               AND document_type = 'receipt'
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$businessId, $financialYearId]);
        $row = $stmt->fetch();

        if ($row) {
            $number = max(1, (int)$row['next_number']);
            $padding = max(2, (int)($row['padding_length'] ?? 4));
            $update = $pdo->prepare("UPDATE document_sequences SET prefix = ?, next_number = ? WHERE id = ?");
            $update->execute([$prefix, $number + 1, $row['id']]);
        } else {
            $number = 1;
            $padding = 4;
            $insert = $pdo->prepare(
                "INSERT INTO document_sequences
                    (business_id, financial_year_id, document_type, prefix, next_number, padding_length)
                 VALUES (?, ?, 'receipt', ?, 2, ?)"
            );
            $insert->execute([$businessId, $financialYearId, $prefix, $padding]);
        }

        return rtrim($prefix, '-/ ') . '-' . str_pad((string)$number, $padding, '0', STR_PAD_LEFT);
    }

    throw new RuntimeException('document_sequences.next_number column is required.');
}

function recalculate_invoice_payment(PDO $pdo, int $businessId, int $invoiceId): void
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pa.allocated_amount), 0)
         FROM payment_allocations pa
         INNER JOIN payments p ON p.id = pa.payment_id
         WHERE pa.business_id = ?
           AND pa.invoice_id = ?
           AND p.payment_status = 'posted'"
    );
    $stmt->execute([$businessId, $invoiceId]);
    $received = (float)$stmt->fetchColumn();

    $update = $pdo->prepare(
        "UPDATE invoices
         SET received_amount = ?,
             balance_amount = GREATEST(0, grand_total - ?),
             payment_status = CASE
                WHEN ? <= 0 THEN 'unpaid'
                WHEN ? >= grand_total THEN 'paid'
                ELSE 'partially_paid'
             END
         WHERE id = ?
           AND business_id = ?"
    );
    $update->execute([$received, $received, $received, $received, $invoiceId, $businessId]);
}

function payment_activity(PDO $pdo, int $businessId, string $type, int $paymentId, string $description): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs
                (business_id, user_id, module_key, action_type, entity_type, entity_id, description, ip_address, user_agent)
             VALUES (?, ?, 'payments', ?, 'payment', ?, ?, ?, ?)"
        );
        $stmt->execute([
            $businessId,
            current_user_id(),
            $type,
            $paymentId,
            substr($description, 0, 500),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000),
        ]);
    } catch (Throwable $e) {
        error_log('[PAYMENT ACTIVITY] ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'open_invoices') {
    $clientId = (int)($_GET['client_id'] ?? 0);
    if ($clientId <= 0) json_response(false, 'Invalid hospital.', [], 422);

    $clientStmt = $pdo->prepare(
        "SELECT id FROM clients WHERE id = ? AND business_id = ? AND status = 'active' LIMIT 1"
    );
    $clientStmt->execute([$clientId, $currentBusinessId]);
    if (!$clientStmt->fetch()) json_response(false, 'Hospital not found.', [], 404);

    $stmt = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, due_date, grand_total, received_amount, balance_amount
         FROM invoices
         WHERE business_id = ?
           AND client_id = ?
           AND invoice_status = 'issued'
           AND balance_amount > 0
         ORDER BY COALESCE(due_date, invoice_date), invoice_date, id"
    );
    $stmt->execute([$currentBusinessId, $clientId]);
    $invoices = $stmt->fetchAll();

    foreach ($invoices as &$invoice) {
        $invoice['id'] = (int)$invoice['id'];
        $invoice['grand_total'] = (float)$invoice['grand_total'];
        $invoice['received_amount'] = (float)$invoice['received_amount'];
        $invoice['balance_amount'] = (float)$invoice['balance_amount'];
        $invoice['invoice_date_display'] = date('d M Y', strtotime($invoice['invoice_date']));
        $invoice['due_date_display'] = $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '—';
    }
    unset($invoice);

    json_response(true, 'Invoices loaded.', [
        'invoices' => $invoices,
        'total_outstanding' => array_sum(array_column($invoices, 'balance_amount')),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'details') {
    $paymentId = (int)($_GET['id'] ?? 0);

    if ($paymentId <= 0) {
        json_response(false, 'Invalid payment ID.', [], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT p.*, c.client_name, c.client_code, pm.mode_name
         FROM payments p
         INNER JOIN clients c ON c.id = p.client_id
         INNER JOIN payment_modes pm ON pm.id = p.payment_mode_id
         WHERE p.id = ? AND p.business_id = ?
         LIMIT 1"
    );
    $stmt->execute([$paymentId, $currentBusinessId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        json_response(false, 'Payment not found for the selected business.', [], 404);
    }

    $allocStmt = $pdo->prepare(
        "SELECT pa.allocated_amount, i.invoice_number, i.invoice_date
         FROM payment_allocations pa
         INNER JOIN invoices i ON i.id = pa.invoice_id
         WHERE pa.payment_id = ? AND pa.business_id = ?
         ORDER BY pa.id"
    );
    $allocStmt->execute([$paymentId, $currentBusinessId]);
    $allocations = $allocStmt->fetchAll();
    foreach ($allocations as &$allocation) {
        $allocation['allocated_amount'] = (float)$allocation['allocated_amount'];
        $allocation['invoice_date_display'] = date('d M Y', strtotime($allocation['invoice_date']));
    }
    unset($allocation);

    json_response(true, 'Payment loaded.', ['payment' => $payment, 'allocations' => $allocations]);
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'edit_data') {
    $paymentId = (int)($_GET['id'] ?? 0);
    if ($paymentId <= 0) {
        json_response(false, 'Invalid payment ID.', [], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT p.*, c.client_name, c.client_code, c.mobile, pm.mode_name, pm.requires_reference
         FROM payments p
         INNER JOIN clients c
            ON c.id = p.client_id
           AND c.business_id = p.business_id
         INNER JOIN payment_modes pm
            ON pm.id = p.payment_mode_id
         WHERE p.id = ?
           AND p.business_id = ?
         LIMIT 1"
    );
    $stmt->execute([$paymentId, $currentBusinessId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        json_response(false, 'Payment not found for the selected business.', [], 404);
    }
    if ($payment['payment_status'] !== 'posted') {
        json_response(false, 'Refunded payments cannot be allocated.', [], 422);
    }

    $payment['id'] = (int)$payment['id'];
    $payment['client_id'] = (int)$payment['client_id'];
    $payment['payment_mode_id'] = (int)$payment['payment_mode_id'];
    $payment['financial_year_id'] = (int)$payment['financial_year_id'];
    $payment['amount'] = (float)$payment['amount'];
    $payment['allocated_amount'] = (float)$payment['allocated_amount'];
    $payment['unallocated_amount'] = (float)$payment['unallocated_amount'];

    $allocStmt = $pdo->prepare(
        "SELECT pa.invoice_id, pa.allocated_amount, i.invoice_number, i.invoice_date
         FROM payment_allocations pa
         INNER JOIN invoices i
            ON i.id = pa.invoice_id
           AND i.business_id = pa.business_id
         WHERE pa.payment_id = ?
           AND pa.business_id = ?
         ORDER BY pa.id"
    );
    $allocStmt->execute([$paymentId, $currentBusinessId]);
    $existingAllocations = $allocStmt->fetchAll();

    foreach ($existingAllocations as &$allocation) {
        $allocation['invoice_id'] = (int)$allocation['invoice_id'];
        $allocation['allocated_amount'] = (float)$allocation['allocated_amount'];
        $allocation['invoice_date_display'] = date('d M Y', strtotime($allocation['invoice_date']));
    }
    unset($allocation);

    json_response(true, 'Payment data loaded.', [
        'payment' => $payment,
        'existing_allocations' => $existingAllocations,
    ]);
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'receive_payment_data') {
    $clientId = (int)($_GET['client_id'] ?? 0);

    if ($clientId <= 0) {
        json_response(false, 'Invalid hospital.', [], 422);
    }

    $clientStmt = $pdo->prepare(
        "SELECT id, client_code, client_name, mobile
         FROM clients
         WHERE id = ? AND business_id = ? AND status='active'
         LIMIT 1"
    );
    $clientStmt->execute([$clientId, $currentBusinessId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        json_response(false, 'Hospital not found.', [], 404);
    }

    $invoiceStmt = $pdo->prepare(
        "SELECT id, invoice_number, invoice_date, due_date,
                grand_total, received_amount, balance_amount
         FROM invoices
         WHERE business_id = ?
           AND client_id = ?
           AND invoice_status='issued'
           AND balance_amount > 0
         ORDER BY invoice_date, id"
    );
    $invoiceStmt->execute([$currentBusinessId, $clientId]);

    $invoices = $invoiceStmt->fetchAll();

    foreach ($invoices as &$invoice) {
        $invoice['id'] = (int)$invoice['id'];
        $invoice['grand_total'] = (float)$invoice['grand_total'];
        $invoice['received_amount'] = (float)$invoice['received_amount'];
        $invoice['balance_amount'] = (float)$invoice['balance_amount'];
    }

    json_response(true, 'Payment data loaded.', [
        'client' => $client,
        'invoices' => $invoices,
        'total_outstanding' => array_sum(array_column($invoices, 'balance_amount'))
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response(false, 'Session expired. Refresh and try again.', [], 419);
}

if ($action === 'save_payment') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $paymentModeId = (int)($_POST['payment_mode_id'] ?? 0);
    $financialYearId = (int)($_POST['financial_year_id'] ?? 0);
    $reference = trim((string)($_POST['transaction_reference'] ?? ''));
    $payerName = trim((string)($_POST['payer_name'] ?? ''));
    $allocationMethod = trim((string)($_POST['allocation_method'] ?? 'manual'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $allocations = json_decode((string)($_POST['allocations_json'] ?? '[]'), true);

    if ($clientId <= 0 || $paymentDate === '' || $amount <= 0 || $paymentModeId <= 0) {
        json_response(false, 'Hospital, payment date, amount and payment mode are required.', [], 422);
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDate);
    if (!$date || $date->format('Y-m-d') !== $paymentDate) {
        json_response(false, 'Enter a valid payment date.', [], 422);
    }
    if (!in_array($allocationMethod, ['manual', 'fifo', 'single_invoice', 'unallocated'], true)) {
        $allocationMethod = 'manual';
    }
    if (!is_array($allocations)) $allocations = [];

    $clientStmt = $pdo->prepare(
        "SELECT * FROM clients WHERE id = ? AND business_id = ? AND status = 'active' LIMIT 1"
    );
    $clientStmt->execute([$clientId, $currentBusinessId]);
    $client = $clientStmt->fetch();
    if (!$client) json_response(false, 'Invalid hospital.', [], 422);

    $modeStmt = $pdo->prepare(
        "SELECT * FROM payment_modes
         WHERE id = ? AND status = 'active'
           AND (business_id IS NULL OR business_id = ?)
         LIMIT 1"
    );
    $modeStmt->execute([$paymentModeId, $currentBusinessId]);
    $mode = $modeStmt->fetch();
    if (!$mode) json_response(false, 'Invalid payment mode.', [], 422);
    if ((int)$mode['requires_reference'] === 1 && $reference === '') {
        json_response(false, 'Transaction reference is required for the selected payment mode.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        if ($financialYearId > 0) {
            $fyStmt = $pdo->prepare(
                "SELECT * FROM financial_years
                 WHERE id = ? AND business_id = ? AND status = 'open'
                   AND ? BETWEEN start_date AND end_date
                 LIMIT 1"
            );
            $fyStmt->execute([$financialYearId, $currentBusinessId, $paymentDate]);
            $financialYear = $fyStmt->fetch();
            if (!$financialYear) throw new RuntimeException('Selected financial year does not match the payment date.');
        } else {
            $financialYear = payment_current_financial_year($pdo, $currentBusinessId, $paymentDate);
        }

        $receiptNumber = payment_next_receipt_number(
            $pdo,
            $currentBusinessId,
            (int)$financialYear['id']
        );

        $cleanAllocations = [];
        $allocatedTotal = 0.0;
        $seen = [];

        if ($allocationMethod !== 'unallocated') {
            foreach ($allocations as $index => $allocation) {
                $invoiceId = (int)($allocation['invoice_id'] ?? 0);
                $allocationAmount = round((float)($allocation['amount'] ?? 0), 2);
                if ($invoiceId <= 0 || $allocationAmount <= 0) continue;
                if (isset($seen[$invoiceId])) throw new RuntimeException('Duplicate invoice allocation found.');
                $seen[$invoiceId] = true;

                $invoiceStmt = $pdo->prepare(
                    "SELECT id, balance_amount, invoice_status
                     FROM invoices
                     WHERE id = ? AND business_id = ? AND client_id = ?
                     FOR UPDATE"
                );
                $invoiceStmt->execute([$invoiceId, $currentBusinessId, $clientId]);
                $invoice = $invoiceStmt->fetch();
                if (!$invoice || $invoice['invoice_status'] !== 'issued') {
                    throw new RuntimeException('Invalid invoice allocation on row ' . ($index + 1) . '.');
                }

                $balance = round((float)$invoice['balance_amount'], 2);
                if ($allocationAmount > $balance + 0.001) {
                    throw new RuntimeException('Allocation exceeds invoice balance.');
                }

                $allocatedTotal += $allocationAmount;
                $cleanAllocations[] = ['invoice_id' => $invoiceId, 'amount' => $allocationAmount];
            }
        }

        if ($allocatedTotal > $amount + 0.001) {
            throw new RuntimeException('Allocated amount cannot exceed payment amount.');
        }

        $unallocatedAmount = round($amount - $allocatedTotal, 2);

        $insert = $pdo->prepare(
            "INSERT INTO payments
                (business_id, financial_year_id, client_id, receipt_number, payment_date,
                 amount, payment_mode_id, transaction_reference, payer_type, payer_name,
                 allocation_method, allocated_amount, unallocated_amount, notes,
                 payment_status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'client', ?, ?, ?, ?, ?, 'posted', ?)"
        );
        $insert->execute([
            $currentBusinessId,
            $financialYear['id'],
            $clientId,
            $receiptNumber,
            $paymentDate,
            $amount,
            $paymentModeId,
            $reference ?: null,
            $payerName ?: $client['client_name'],
            $allocationMethod,
            $allocatedTotal,
            $unallocatedAmount,
            $notes ?: null,
            current_user_id(),
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        $allocationInsert = $pdo->prepare(
            "INSERT INTO payment_allocations
                (business_id, payment_id, invoice_id, allocated_amount, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );

        foreach ($cleanAllocations as $allocation) {
            $allocationInsert->execute([
                $currentBusinessId,
                $paymentId,
                $allocation['invoice_id'],
                $allocation['amount'],
                current_user_id(),
            ]);
            recalculate_invoice_payment($pdo, $currentBusinessId, $allocation['invoice_id']);
        }

        payment_activity(
            $pdo,
            $currentBusinessId,
            'create',
            $paymentId,
            'Recorded payment ' . $receiptNumber . ' for ' . $client['client_name']
        );

        $pdo->commit();
        json_response(true, 'Payment recorded successfully.', [
            'payment_id' => $paymentId,
            'receipt_number' => $receiptNumber,
            'allocated_amount' => $allocatedTotal,
            'unallocated_amount' => $unallocatedAmount,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PAYMENT SAVE] ' . $e->getMessage());
        json_response(false, $e->getMessage(), [], 422);
    }
}


if ($action === 'allocate_existing_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $allocationMethod = trim((string)($_POST['allocation_method'] ?? 'manual'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $allocations = json_decode((string)($_POST['allocations_json'] ?? '[]'), true);

    if ($paymentId <= 0) {
        json_response(false, 'Invalid payment ID.', [], 422);
    }
    if (!in_array($allocationMethod, ['manual', 'fifo', 'single_invoice'], true)) {
        $allocationMethod = 'manual';
    }
    if (!is_array($allocations)) {
        $allocations = [];
    }

    try {
        $pdo->beginTransaction();

        $paymentStmt = $pdo->prepare(
            "SELECT *
             FROM payments
             WHERE id = ?
               AND business_id = ?
             FOR UPDATE"
        );
        $paymentStmt->execute([$paymentId, $currentBusinessId]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }
        if ($payment['payment_status'] !== 'posted') {
            throw new RuntimeException('Refunded payments cannot be allocated.');
        }

        $availableAmount = round((float)$payment['unallocated_amount'], 2);
        if ($availableAmount <= 0) {
            throw new RuntimeException('This payment has no unallocated balance.');
        }

        $cleanAllocations = [];
        $newAllocatedTotal = 0.0;
        $seen = [];

        foreach ($allocations as $index => $allocation) {
            $invoiceId = (int)($allocation['invoice_id'] ?? 0);
            $allocationAmount = round((float)($allocation['amount'] ?? 0), 2);

            if ($invoiceId <= 0 || $allocationAmount <= 0) {
                continue;
            }
            if (isset($seen[$invoiceId])) {
                throw new RuntimeException('Duplicate invoice allocation found.');
            }
            $seen[$invoiceId] = true;

            $invoiceStmt = $pdo->prepare(
                "SELECT id, balance_amount, invoice_status
                 FROM invoices
                 WHERE id = ?
                   AND business_id = ?
                   AND client_id = ?
                 FOR UPDATE"
            );
            $invoiceStmt->execute([
                $invoiceId,
                $currentBusinessId,
                (int)$payment['client_id'],
            ]);
            $invoice = $invoiceStmt->fetch();

            if (!$invoice || $invoice['invoice_status'] !== 'issued') {
                throw new RuntimeException('Invalid invoice allocation on row ' . ($index + 1) . '.');
            }

            $invoiceBalance = round((float)$invoice['balance_amount'], 2);
            if ($allocationAmount > $invoiceBalance + 0.001) {
                throw new RuntimeException('Allocation exceeds invoice balance.');
            }

            $newAllocatedTotal += $allocationAmount;
            $cleanAllocations[] = [
                'invoice_id' => $invoiceId,
                'amount' => $allocationAmount,
            ];
        }

        if (!$cleanAllocations || $newAllocatedTotal <= 0) {
            throw new RuntimeException('Select at least one invoice and enter an allocation amount.');
        }
        if ($newAllocatedTotal > $availableAmount + 0.001) {
            throw new RuntimeException('Allocation cannot exceed the available unallocated amount.');
        }

        /*
         * uq_payment_invoice_allocation allows only one row for the same
         * payment and invoice. When the receipt is allocated to the same
         * invoice again, increase the existing allocation instead of
         * attempting to insert a duplicate row.
         */
        $allocationUpsert = $pdo->prepare(
            "INSERT INTO payment_allocations
                (business_id, payment_id, invoice_id, allocated_amount, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                allocated_amount = allocated_amount + VALUES(allocated_amount)"
        );

        foreach ($cleanAllocations as $allocation) {
            $allocationUpsert->execute([
                $currentBusinessId,
                $paymentId,
                $allocation['invoice_id'],
                $allocation['amount'],
                current_user_id(),
            ]);

            recalculate_invoice_payment(
                $pdo,
                $currentBusinessId,
                $allocation['invoice_id']
            );
        }

        $updatedAllocated = round((float)$payment['allocated_amount'] + $newAllocatedTotal, 2);
        $updatedUnallocated = round(max(0, $availableAmount - $newAllocatedTotal), 2);

        $updatePayment = $pdo->prepare(
            "UPDATE payments
             SET allocation_method = ?,
                 allocated_amount = ?,
                 unallocated_amount = ?,
                 notes = CASE
                    WHEN ? <> '' THEN ?
                    ELSE notes
                 END
             WHERE id = ?
               AND business_id = ?"
        );
        $updatePayment->execute([
            $allocationMethod,
            $updatedAllocated,
            $updatedUnallocated,
            $notes,
            $notes,
            $paymentId,
            $currentBusinessId,
        ]);

        payment_activity(
            $pdo,
            $currentBusinessId,
            'allocate',
            $paymentId,
            'Allocated ' . number_format($newAllocatedTotal, 2, '.', '') .
            ' from receipt ' . $payment['receipt_number'] . ' to pending invoices'
        );

        $pdo->commit();

        json_response(true, 'Existing payment allocated successfully.', [
            'payment_id' => $paymentId,
            'newly_allocated_amount' => $newAllocatedTotal,
            'allocated_amount' => $updatedAllocated,
            'unallocated_amount' => $updatedUnallocated,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PAYMENT ALLOCATE EXISTING] ' . $e->getMessage());
        json_response(false, $e->getMessage(), [], 422);
    }
}

if ($action === 'reverse_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($paymentId <= 0 || $reason === '') {
        json_response(false, 'Payment and refund reason are required.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT * FROM payments
             WHERE id = ? AND business_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$paymentId, $currentBusinessId]);
        $payment = $stmt->fetch();
        if (!$payment) throw new RuntimeException('Payment not found.');
        if ($payment['payment_status'] === 'reversed') throw new RuntimeException('Payment is already refunded.');

        $invoiceStmt = $pdo->prepare(
            "SELECT invoice_id FROM payment_allocations
             WHERE payment_id = ? AND business_id = ?"
        );
        $invoiceStmt->execute([$paymentId, $currentBusinessId]);
        $invoiceIds = array_map('intval', $invoiceStmt->fetchAll(PDO::FETCH_COLUMN));

        $update = $pdo->prepare(
            "UPDATE payments
             SET payment_status = 'reversed',
                 reversal_reason = ?,
                 reversed_by = ?,
                 reversed_at = NOW()
             WHERE id = ? AND business_id = ?"
        );
        $update->execute([$reason, current_user_id(), $paymentId, $currentBusinessId]);

        foreach ($invoiceIds as $invoiceId) {
            recalculate_invoice_payment($pdo, $currentBusinessId, $invoiceId);
        }

        payment_activity(
            $pdo,
            $currentBusinessId,
            'reverse',
            $paymentId,
            'Refunded payment ' . $payment['receipt_number'] . ': ' . $reason
        );

        $pdo->commit();
        json_response(true, 'Payment refunded successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PAYMENT REFUND] ' . $e->getMessage());
        json_response(false, $e->getMessage(), [], 422);
    }
}


if ($action === 'delete_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    if ($paymentId <= 0) {
        json_response(false, 'Invalid payment ID.', [], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT * FROM payments
             WHERE id = ?
               AND business_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$paymentId, $currentBusinessId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }

        $invoiceStmt = $pdo->prepare(
            "SELECT invoice_id
             FROM payment_allocations
             WHERE payment_id = ?
               AND business_id = ?"
        );
        $invoiceStmt->execute([$paymentId, $currentBusinessId]);
        $invoiceIds = array_map('intval', $invoiceStmt->fetchAll(PDO::FETCH_COLUMN));

        $pdo->prepare(
            "DELETE FROM payment_allocations
             WHERE payment_id = ?
               AND business_id = ?"
        )->execute([$paymentId, $currentBusinessId]);

        $pdo->prepare(
            "DELETE FROM payments
             WHERE id = ?
               AND business_id = ?"
        )->execute([$paymentId, $currentBusinessId]);

        foreach ($invoiceIds as $invoiceId) {
            recalculate_invoice_payment($pdo, $currentBusinessId, $invoiceId);
        }

        payment_activity(
            $pdo,
            $currentBusinessId,
            'delete',
            $paymentId,
            'Deleted payment ' . $payment['receipt_number']
        );

        $pdo->commit();

        json_response(true, 'Payment deleted successfully. Invoice balances and payment totals were recalculated.', [
            'payment_id' => $paymentId,
            'recalculated_invoice_count' => count($invoiceIds),
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PAYMENT DELETE] ' . $e->getMessage());
        json_response(false, $e->getMessage(), [], 422);
    }
}

json_response(false, 'Invalid payment action.', [], 422);