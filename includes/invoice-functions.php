<?php
declare(strict_types=1);

function invoice_setting(PDO $pdo, int $businessId, string $key, string $default=''): string
{
    $stmt=$pdo->prepare(
        "SELECT setting_value FROM business_settings
         WHERE business_id=? AND setting_group='invoice' AND setting_key=?
         LIMIT 1"
    );
    $stmt->execute([$businessId,$key]);
    $value=$stmt->fetchColumn();
    return $value===false ? $default : (string)$value;
}

function current_financial_year(PDO $pdo, int $businessId, string $date): ?array
{
    $stmt=$pdo->prepare(
        "SELECT * FROM financial_years
         WHERE business_id=? AND ? BETWEEN start_date AND end_date
         ORDER BY is_current DESC,id DESC LIMIT 1"
    );
    $stmt->execute([$businessId,$date]);
    $row=$stmt->fetch();
    return $row ?: null;
}

function next_invoice_number(PDO $pdo, int $businessId, int $financialYearId, string $prefix, int $padding=4): string
{
    $stmt=$pdo->prepare(
        "SELECT id,current_number FROM document_sequences
         WHERE business_id=? AND financial_year_id=? AND document_type='invoice'
         FOR UPDATE"
    );
    $stmt->execute([$businessId,$financialYearId]);
    $row=$stmt->fetch();

    if($row){
        $next=(int)$row['current_number']+1;
        $upd=$pdo->prepare("UPDATE document_sequences SET current_number=? WHERE id=?");
        $upd->execute([$next,$row['id']]);
    }else{
        $next=1;
        $ins=$pdo->prepare(
            "INSERT INTO document_sequences
             (business_id,financial_year_id,document_type,prefix,current_number)
             VALUES (?,?,'invoice',?,?)"
        );
        $ins->execute([$businessId,$financialYearId,$prefix,$next]);
    }

    return rtrim($prefix,'-/').' '.str_pad((string)$next,$padding,'0',STR_PAD_LEFT);
}

function amount_in_words(float $amount): string
{
    $whole=(int)floor($amount);
    $paise=(int)round(($amount-$whole)*100);

    if(class_exists('NumberFormatter')){
        $formatter=new NumberFormatter('en_IN',NumberFormatter::SPELLOUT);
        $words=ucwords((string)$formatter->format($whole)).' Rupees';
        if($paise>0){
            $words.=' And '.ucwords((string)$formatter->format($paise)).' Paise';
        }
        return $words.' Only';
    }

    return 'Rupees '.number_format($amount,2).' Only';
}

function safe_storage_path(string $relative): string
{
    $relative=ltrim(str_replace(['..','\\'],['','/'],$relative),'/');
    return dirname(__DIR__) . '/' . $relative;
}

function log_invoice_activity(PDO $pdo,int $businessId,int $userId,string $action,int $invoiceId,string $description): void
{
    try{
        $stmt=$pdo->prepare(
            "INSERT INTO activity_logs
             (business_id,user_id,module_key,action_type,entity_type,entity_id,description,ip_address,user_agent)
             VALUES (?,?, 'invoices', ?, 'invoice', ?, ?, ?, ?)"
        );
        $stmt->execute([
            $businessId,$userId,$action,$invoiceId,$description,
            $_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null
        ]);
    }catch(Throwable $e){
        error_log('[Invoice activity log] '.$e->getMessage());
    }
}


function resolve_invoice_financial_year(
    PDO $pdo,
    int $businessId,
    string $invoiceDate,
    int $requestedFinancialYearId = 0
): array {
    if ($requestedFinancialYearId > 0) {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM financial_years
             WHERE id = ?
               AND business_id = ?
               AND status = 'open'
             LIMIT 1"
        );
        $stmt->execute([$requestedFinancialYearId, $businessId]);
        $row = $stmt->fetch();

        if ($row) {
            return $row;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT *
         FROM financial_years
         WHERE business_id = ?
           AND ? BETWEEN start_date AND end_date
           AND status = 'open'
         ORDER BY is_current DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$businessId, $invoiceDate]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    $businessStmt = $pdo->prepare(
        "SELECT financial_year_start_month
         FROM businesses
         WHERE id = ?
         LIMIT 1"
    );
    $businessStmt->execute([$businessId]);
    $startMonth = (int)($businessStmt->fetchColumn() ?: 4);

    $date = new DateTimeImmutable($invoiceDate);
    $year = (int)$date->format('Y');
    $month = (int)$date->format('n');

    $startYear = $month >= $startMonth ? $year : $year - 1;
    $startDate = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $endDate = (new DateTimeImmutable($startDate))
        ->modify('+1 year')
        ->modify('-1 day')
        ->format('Y-m-d');

    $endYear = (int)substr($endDate, 0, 4);
    $yearLabel = sprintf('%d-%02d', $startYear, $endYear % 100);

    $pdo->prepare(
        "UPDATE financial_years
         SET is_current = 0
         WHERE business_id = ?"
    )->execute([$businessId]);

    $insert = $pdo->prepare(
        "INSERT INTO financial_years
            (business_id, year_label, start_date, end_date, is_current, status)
         VALUES
            (?, ?, ?, ?, 1, 'open')
         ON DUPLICATE KEY UPDATE
            start_date = VALUES(start_date),
            end_date = VALUES(end_date),
            is_current = 1,
            status = 'open'"
    );
    $insert->execute([
        $businessId,
        $yearLabel,
        $startDate,
        $endDate,
    ]);

    $stmt = $pdo->prepare(
        "SELECT *
         FROM financial_years
         WHERE business_id = ?
           AND year_label = ?
         LIMIT 1"
    );
    $stmt->execute([$businessId, $yearLabel]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Unable to create the financial year.');
    }

    return $row;
}

function next_invoice_number_compatible(
    PDO $pdo,
    int $businessId,
    int $financialYearId,
    string $prefix,
    int $padding = 4
): string {
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM document_sequences");
    $columns = array_column($columnsStmt->fetchAll(), 'Field');

    $hasNextNumber = in_array('next_number', $columns, true);
    $hasCurrentNumber = in_array('current_number', $columns, true);
    $hasPaddingLength = in_array('padding_length', $columns, true);

    if ($hasNextNumber) {
        $stmt = $pdo->prepare(
            "SELECT id, next_number
             FROM document_sequences
             WHERE business_id = ?
               AND financial_year_id = ?
               AND document_type = 'invoice'
             FOR UPDATE"
        );
        $stmt->execute([$businessId, $financialYearId]);
        $row = $stmt->fetch();

        if ($row) {
            $number = max(1, (int)$row['next_number']);
            $updateSql = $hasPaddingLength
                ? "UPDATE document_sequences
                   SET prefix = ?, next_number = ?, padding_length = ?
                   WHERE id = ?"
                : "UPDATE document_sequences
                   SET prefix = ?, next_number = ?
                   WHERE id = ?";

            $update = $pdo->prepare($updateSql);

            if ($hasPaddingLength) {
                $update->execute([$prefix, $number + 1, $padding, $row['id']]);
            } else {
                $update->execute([$prefix, $number + 1, $row['id']]);
            }
        } else {
            $number = 1;

            if ($hasPaddingLength) {
                $insert = $pdo->prepare(
                    "INSERT INTO document_sequences
                        (business_id, financial_year_id, document_type, prefix, next_number, padding_length)
                     VALUES
                        (?, ?, 'invoice', ?, 2, ?)"
                );
                $insert->execute([
                    $businessId,
                    $financialYearId,
                    $prefix,
                    $padding,
                ]);
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO document_sequences
                        (business_id, financial_year_id, document_type, prefix, next_number)
                     VALUES
                        (?, ?, 'invoice', ?, 2)"
                );
                $insert->execute([
                    $businessId,
                    $financialYearId,
                    $prefix,
                ]);
            }
        }

        return rtrim($prefix, '-/ ') . ' ' .
            str_pad((string)$number, $padding, '0', STR_PAD_LEFT);
    }

    if ($hasCurrentNumber) {
        return next_invoice_number(
            $pdo,
            $businessId,
            $financialYearId,
            $prefix,
            $padding
        );
    }

    throw new RuntimeException(
        'document_sequences does not contain next_number or current_number.'
    );
}
