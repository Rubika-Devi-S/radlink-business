<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/invoice-functions.php';
require_once __DIR__ . '/includes/invoice-settings-functions.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT
        i.*,
        c.client_code,
        c.client_name,
        b.*
     FROM invoices i
     INNER JOIN clients c
        ON c.id = i.client_id
     INNER JOIN businesses b
        ON b.id = i.business_id
     WHERE i.id = ?
       AND i.business_id = ?
     LIMIT 1"
);
$stmt->execute([$id, $currentBusinessId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM invoice_items
     WHERE invoice_id = ?
       AND business_id = ?
     ORDER BY sort_order, id"
);
$stmt->execute([$id, $currentBusinessId]);
$items = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT *
     FROM business_bank_accounts
     WHERE business_id = ?
       AND status = 'active'
     ORDER BY is_default DESC, id
     LIMIT 1"
);
$stmt->execute([$currentBusinessId]);
$bank = $stmt->fetch() ?: [];

$fpdfPath = __DIR__ . '/lib/fpdf/fpdf.php';

if (!is_file($fpdfPath)) {
    exit('FPDF library missing. Copy fpdf.php into lib/fpdf/.');
}

require_once $fpdfPath;

function invoice_hex_rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');

    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
        $hex = '111111';
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function invoice_pdf_text(string $text): string
{
    return iconv(
        'UTF-8',
        'windows-1252//TRANSLIT',
        $text
    ) ?: $text;
}

function invoice_compact_quantity(float $quantity): string
{
    return rtrim(
        rtrim(
            number_format($quantity, 3, '.', ''),
            '0'
        ),
        '.'
    );
}

final class RadLinkInvoicePdf extends FPDF
{
    private string $footerText = '';

    public function setInvoiceFooter(string $text): void
    {
        $this->footerText = $text;
    }

    public function Footer(): void
    {
        if ($this->footerText === '') {
            return;
        }

        $this->SetY(-8.5);
        $this->SetTextColor(92, 92, 92);
        $this->SetFont('Helvetica', 'I', 6.4);
        $this->Cell(
            0,
            4,
            invoice_pdf_text($this->footerText),
            0,
            0,
            'C'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Dynamic invoice settings
|--------------------------------------------------------------------------
*/
$title = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_title',
    'BILL OF SUPPLY'
);

$copyLabel = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_copy_label',
    'ORIGINAL'
);

$brandHeading = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_brand_heading',
    'TELE RADIOLOGY REPORTING SOFTWARE'
);

$subHeading = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_sub_heading',
    ''
);

$themeColour = invoice_hex_rgb(
    invoice_setting(
        $pdo,
        $currentBusinessId,
        'invoice_theme_hex',
        '#E6C8F2'
    )
);

$headingColour = invoice_hex_rgb(
    invoice_setting(
        $pdo,
        $currentBusinessId,
        'invoice_heading_hex',
        '#7B169E'
    )
);

$textColour = invoice_hex_rgb(
    invoice_setting(
        $pdo,
        $currentBusinessId,
        'invoice_text_hex',
        '#111111'
    )
);

$showLogo = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_logo',
    '1'
) === '1';

$showAddress = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_address',
    '1'
) === '1';

$showPatient = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_patient',
    '1'
) === '1';

$showBank = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_bank',
    '1'
) === '1';

$showQr = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_qr',
    '1'
) === '1';

$showSignature = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_show_signature',
    '1'
) === '1';

$qrMode = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_qr_mode',
    'dynamic_upi'
);

$qrAmountSource = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_qr_amount_source',
    'grand_total'
);

$uploadedQrPath = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_uploaded_qr_path',
    ''
);

$signaturePath = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_signature_path',
    ''
);

$invoiceMobile = trim(invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_contact_mobile',
    (string)($invoice['mobile'] ?? '')
));

$invoiceEmail = trim(invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_contact_email',
    (string)($invoice['email'] ?? '')
));

$businessAddressFallback = implode(', ', array_filter([
    $invoice['address_line_1'] ?? '',
    $invoice['address_line_2'] ?? '',
    $invoice['city'] ?? '',
    $invoice['district'] ?? '',
    $invoice['state'] ?? '',
    $invoice['postal_code'] ?? '',
], static fn ($value): bool => trim((string)$value) !== ''));

$invoiceAddress = trim(invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_address',
    $businessAddressFallback
));

if ($invoiceAddress === '') {
    $invoiceAddress = $businessAddressFallback;
}

$terms = (string)(
    $invoice['terms_snapshot']
    ?: invoice_setting(
        $pdo,
        $currentBusinessId,
        'invoice_terms',
        ''
    )
);

$footerText = invoice_setting(
    $pdo,
    $currentBusinessId,
    'invoice_footer_text',
    ''
);

$logoWidth = max(
    10.0,
    min(
        80.0,
        (float)invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_logo_width_mm',
            '27'
        )
    )
);

$logoHeight = max(
    10.0,
    min(
        50.0,
        (float)invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_logo_height_mm',
            '27'
        )
    )
);

$qrSize = max(
    18.0,
    min(
        34.0,
        (float)invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_qr_size_mm',
            '25'
        )
    )
);

$signatureWidth = max(
    10.0,
    min(
        55.0,
        (float)invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_signature_width_mm',
            '30'
        )
    )
);

$signatureHeight = max(
    8.0,
    min(
        28.0,
        (float)invoice_setting(
            $pdo,
            $currentBusinessId,
            'invoice_signature_height_mm',
            '18'
        )
    )
);

$qrAmount = $qrAmountSource === 'balance_amount'
    ? (float)$invoice['balance_amount']
    : (float)$invoice['grand_total'];

$upiId = trim((string)($bank['upi_id'] ?? ''));

/*
|--------------------------------------------------------------------------
| Prepare dynamic QR before sending the PDF
|--------------------------------------------------------------------------
*/
$resolvedQrPath = '';
$qrError = '';

if ($showQr) {
    if ($qrMode === 'uploaded_qr') {
        if ($uploadedQrPath !== '') {
            $candidate = safe_storage_path($uploadedQrPath);

            if (is_file($candidate)) {
                $resolvedQrPath = $candidate;
            } else {
                $qrError = 'Uploaded QR image was not found.';
            }
        } else {
            $qrError = 'No uploaded QR image is configured.';
        }
    } elseif ($qrMode === 'dynamic_upi') {
        $qrLibrary = __DIR__ . '/lib/phpqrcode/qrlib.php';

        if ($upiId === '') {
            $qrError = 'Default bank account UPI ID is empty.';
        } elseif (!is_file($qrLibrary)) {
            $qrError = 'PHP QR Code library was not found.';
        } else {
            require_once $qrLibrary;

            $qrDirectory = __DIR__ . '/assets/uploads/qr';

            if (
                !is_dir($qrDirectory)
                && !mkdir($qrDirectory, 0755, true)
            ) {
                $qrError = 'Unable to create QR directory.';
            } elseif (!is_writable($qrDirectory)) {
                $qrError = 'QR directory is not writable.';
            } else {
                $amountText = number_format(
                    max(0, $qrAmount),
                    2,
                    '.',
                    ''
                );

                $qrHash = substr(
                    hash(
                        'sha256',
                        implode('|', [
                            $id,
                            $invoice['invoice_number'],
                            $upiId,
                            $amountText,
                        ])
                    ),
                    0,
                    18
                );

                $resolvedQrPath =
                    $qrDirectory .
                    '/invoice_' .
                    $id .
                    '_' .
                    $qrHash .
                    '.png';

                $upiUrl =
                    'upi://pay?pa=' . rawurlencode($upiId) .
                    '&pn=' . rawurlencode(
                        (string)$invoice['business_name']
                    ) .
                    '&am=' . rawurlencode($amountText) .
                    '&cu=INR' .
                    '&tn=' . rawurlencode(
                        (string)$invoice['invoice_number']
                    );

                try {
                    QRcode::png(
                        $upiUrl,
                        $resolvedQrPath,
                        QR_ECLEVEL_M,
                        5,
                        2
                    );

                    clearstatcache(true, $resolvedQrPath);

                    if (
                        !is_file($resolvedQrPath)
                        || filesize($resolvedQrPath) <= 0
                    ) {
                        $qrError =
                            'The dynamic QR image could not be generated.';
                        $resolvedQrPath = '';
                    }
                } catch (Throwable $exception) {
                    $qrError = $exception->getMessage();
                    $resolvedQrPath = '';
                }
            }
        }
    }
}

if ($qrError !== '') {
    error_log(
        '[INVOICE QR] Invoice ID ' .
        $id .
        ': ' .
        $qrError
    );
}

/*
|--------------------------------------------------------------------------
| Fixed A4 one-page PDF
|--------------------------------------------------------------------------
*/
$pdf = new RadLinkInvoicePdf('P', 'mm', 'A4');
$pdf->SetMargins(18, 12, 18);
$pdf->SetAutoPageBreak(false);
$pdf->setInvoiceFooter($footerText);
$pdf->AddPage();
$pdf->SetTextColor(...$textColour);

$pageLeft = 18.0;
$pageRight = 192.0;
$contentWidth = $pageRight - $pageLeft;

/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/
$headerTop = 12.0;
$headerBottom = 58.0;
$logoX = $pageLeft;
$logoY = $headerTop + 3.0;
$logoW = min($logoWidth, 25.0);
$logoH = min($logoHeight, 25.0);

if ($showLogo && !empty($invoice['logo_path'])) {
    $logoPath = safe_storage_path((string)$invoice['logo_path']);

    if (is_file($logoPath)) {
        $pdf->Image(
            $logoPath,
            $logoX,
            $logoY,
            $logoW,
            $logoH
        );
    }
}

$companyX = $pageLeft + 30.0;
$companyWidth = 70.0;
$pdf->SetXY($companyX, $headerTop + 3.0);
$pdf->SetTextColor(...$headingColour);
$pdf->SetFont('Helvetica', 'B', 11.2);
$pdf->MultiCell(
    $companyWidth,
    5.2,
    invoice_pdf_text($brandHeading),
    0,
    'L'
);

$pdf->SetTextColor(...$textColour);

if ($subHeading !== '') {
    $pdf->SetX($companyX);
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->MultiCell(
        $companyWidth,
        3.6,
        invoice_pdf_text($subHeading),
        0,
        'L'
    );
}

$pdf->SetFont('Helvetica', '', 7.5);

if ($showAddress && $invoiceAddress !== '') {
    $pdf->SetX($companyX);
    $pdf->MultiCell(
        $companyWidth,
        3.55,
        invoice_pdf_text($invoiceAddress),
        0,
        'L'
    );
}

if ($invoiceMobile !== '') {
    $pdf->SetX($companyX);
    $pdf->Cell(
        $companyWidth,
        3.8,
        invoice_pdf_text(
            'Mobile: ' . $invoiceMobile
        ),
        0,
        1
    );
}

if ($invoiceEmail !== '') {
    $pdf->SetX($companyX);
    $pdf->Cell(
        $companyWidth,
        3.8,
        invoice_pdf_text(
            'Email: ' . $invoiceEmail
        ),
        0,
        1
    );
}

$metaX = 123.0;
$copyWidth = 24.0;

$pdf->SetXY($metaX, $headerTop + 2.0);
$pdf->SetFont('Helvetica', 'B', 11.8);
$pdf->Cell(
    43,
    7,
    invoice_pdf_text($title),
    0,
    0,
    'L'
);

$pdf->SetDrawColor(125, 125, 125);
$pdf->SetFont('Helvetica', 'B', 8.0);
$pdf->Cell(
    $copyWidth,
    7,
    invoice_pdf_text($copyLabel),
    1,
    1,
    'C'
);

$metaY = $headerTop + 17.0;

foreach ([
    ['Invoice No.', (string)$invoice['invoice_number']],
    [
        'Invoice Date',
        date(
            'd/m/Y',
            strtotime((string)$invoice['invoice_date'])
        ),
    ],
    [
        'Due Date',
        !empty($invoice['due_date'])
            ? date(
                'd/m/Y',
                strtotime((string)$invoice['due_date'])
            )
            : '-',
    ],
] as [$label, $value]) {
    $pdf->SetXY($metaX, $metaY);
    $pdf->SetFont('Helvetica', '', 8.0);
    $pdf->Cell(29, 5.2, invoice_pdf_text($label), 0, 0);
    $pdf->Cell(4, 5.2, ':', 0, 0, 'C');
    $pdf->SetFont('Helvetica', 'B', 8.0);
    $pdf->Cell(34, 5.2, invoice_pdf_text($value), 0, 1, 'R');
    $metaY += 6.0;
}

/*
|--------------------------------------------------------------------------
| Bill-to
|--------------------------------------------------------------------------
*/
$billTop = $headerBottom + 2.0;
$pdf->SetXY($pageLeft, $billTop);
$pdf->SetFillColor(...$themeColour);
$pdf->SetFont('Helvetica', 'B', 9.0);
$pdf->Cell(31, 7, 'BILL TO', 0, 1, 'L', true);

$pdf->SetXY($pageLeft, $billTop + 8.5);
$pdf->SetFont('Helvetica', 'B', 8.8);
$pdf->Cell(
    110,
    5.2,
    invoice_pdf_text((string)$invoice['bill_to_name']),
    0,
    1
);

$billCursorY = $billTop + 13.7;

if (!empty($invoice['bill_to_address'])) {
    $pdf->SetXY($pageLeft, $billCursorY);
    $pdf->SetFont('Helvetica', '', 7.2);
    $pdf->MultiCell(
        116,
        3.55,
        invoice_pdf_text(
            (string)$invoice['bill_to_address']
        ),
        0,
        'L'
    );
    $billCursorY = $pdf->GetY();
}

if (
    $showPatient
    && !empty($invoice['patient_name'])
) {
    $pdf->SetXY($pageLeft, $billCursorY + 0.3);
    $pdf->SetFont('Helvetica', '', 7.3);
    $pdf->Cell(
        116,
        3.8,
        invoice_pdf_text(
            'Patient: ' . $invoice['patient_name']
        ),
        0,
        1
    );
    $billCursorY = $pdf->GetY();
}

/*
|--------------------------------------------------------------------------
| Services
|--------------------------------------------------------------------------
*/
$tableTop = max(89.0, $billCursorY + 4.0);
$tableWidths = [15.0, 84.0, 26.0, 24.0, 25.0];
$tableHeaders = [
    'S.NO.',
    'SERVICES',
    'QTY.',
    'RATE',
    'AMOUNT',
];

$pdf->SetXY($pageLeft, $tableTop);
$pdf->SetFillColor(...$themeColour);
$pdf->SetFont('Helvetica', 'B', 7.9);

foreach ($tableHeaders as $index => $header) {
    $pdf->Cell(
        $tableWidths[$index],
        8.4,
        invoice_pdf_text($header),
        0,
        0,
        $index === 1 ? 'L' : 'C',
        true
    );
}

$pdf->Ln();

$itemCount = count($items);
$subtotalTop = 157.0;
$availableItemHeight = max(
    25.0,
    $subtotalTop - ($tableTop + 8.4) - 1.5
);

$rowHeight = $itemCount > 0
    ? min(
        6.4,
        max(
            3.0,
            $availableItemHeight / $itemCount
        )
    )
    : 6.4;

$itemFontSize = $rowHeight < 3.8
    ? 5.9
    : (
        $rowHeight < 4.8
            ? 6.5
            : 7.4
    );

$pdf->SetFont(
    'Helvetica',
    '',
    $itemFontSize
);

foreach ($items as $index => $item) {
    $pdf->SetX($pageLeft);
    $pdf->Cell(
        $tableWidths[0],
        $rowHeight,
        (string)($index + 1),
        0,
        0,
        'C'
    );

    $pdf->Cell(
        $tableWidths[1],
        $rowHeight,
        invoice_pdf_text(
            (string)$item['service_name_snapshot']
        ),
        0,
        0,
        'L'
    );

    $quantityText =
        invoice_compact_quantity(
            (float)$item['quantity']
        ) .
        ' ' .
        (string)$item['unit_name'];

    $pdf->Cell(
        $tableWidths[2],
        $rowHeight,
        invoice_pdf_text($quantityText),
        0,
        0,
        'C'
    );

    $pdf->Cell(
        $tableWidths[3],
        $rowHeight,
        number_format(
            (float)$item['applied_rate'],
            2
        ),
        0,
        0,
        'R'
    );

    $pdf->Cell(
        $tableWidths[4],
        $rowHeight,
        number_format(
            (float)$item['line_total'],
            2
        ),
        0,
        1,
        'R'
    );
}

/*
|--------------------------------------------------------------------------
| Subtotal
|--------------------------------------------------------------------------
*/
$pdf->SetXY($pageLeft, $subtotalTop);
$pdf->SetFillColor(...$themeColour);
$pdf->SetFont('Helvetica', 'B', 8.1);

$totalQuantity = array_sum(
    array_map(
        static fn (array $item): float =>
            (float)$item['quantity'],
        $items
    )
);

$pdf->Cell(99, 8.5, 'SUBTOTAL', 0, 0, 'C', true);
$pdf->Cell(
    26,
    8.5,
    invoice_compact_quantity($totalQuantity),
    0,
    0,
    'C',
    true
);
$pdf->Cell(
    49,
    8.5,
    'Rs. ' . number_format(
        (float)$invoice['subtotal'],
        2
    ),
    0,
    1,
    'R',
    true
);

/*
|--------------------------------------------------------------------------
| Footer content area
|--------------------------------------------------------------------------
*/
$footerTop = 169.5;
$leftX = $pageLeft;
$leftWidth = 88.0;
$rightX = 112.0;
$rightWidth = 80.0;

$pdf->SetXY($leftX, $footerTop);
$pdf->SetFont('Helvetica', 'B', 7.8);
$pdf->Cell(
    $leftWidth,
    4.7,
    'TERMS AND CONDITIONS',
    0,
    1
);

$pdf->SetXY(
    $leftX,
    $footerTop + 4.7
);
$pdf->SetFont('Helvetica', '', 6.5);
$pdf->MultiCell(
    $leftWidth,
    3.05,
    invoice_pdf_text($terms),
    0,
    'L'
);

$bankTop = 202.0;

if ($showBank && $bank) {
    $pdf->SetXY($leftX, $bankTop);
    $pdf->SetFont('Helvetica', 'B', 7.8);
    $pdf->Cell(
        $leftWidth,
        4.8,
        'BANK DETAILS',
        0,
        1
    );

    $pdf->SetFont('Helvetica', '', 6.7);

    foreach ([
        ['Name', (string)($bank['account_name'] ?? '')],
        ['IFSC Code', (string)($bank['ifsc_code'] ?? '')],
        ['Account No', (string)($bank['account_number'] ?? '')],
        [
            'Bank',
            trim(
                (string)($bank['bank_name'] ?? '') .
                (
                    !empty($bank['branch_name'])
                        ? ', ' . $bank['branch_name']
                        : ''
                )
            ),
        ],
    ] as [$label, $value]) {
        $pdf->SetX($leftX);
        $pdf->Cell(
            24,
            4.0,
            invoice_pdf_text($label . ':'),
            0,
            0
        );
        $pdf->Cell(
            $leftWidth - 24,
            4.0,
            invoice_pdf_text($value),
            0,
            1
        );
    }
}

/*
|--------------------------------------------------------------------------
| QR block
|--------------------------------------------------------------------------
*/
$qrTop = 232.0;

if ($showQr) {
    $pdf->SetXY($leftX, $qrTop);
    $pdf->SetFont('Helvetica', 'B', 7.8);
    $pdf->Cell(
        $leftWidth,
        4.8,
        'PAYMENT QR CODE',
        0,
        1
    );

    if ($upiId !== '') {
        $pdf->SetX($leftX);
        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->Cell(
            $leftWidth,
            4.0,
            invoice_pdf_text(
                'UPI ID: ' . $upiId
            ),
            0,
            1
        );
    }

    if (
        $qrMode !== 'upi_text_only'
        && $resolvedQrPath !== ''
        && is_file($resolvedQrPath)
    ) {
        $displayQrSize = min($qrSize, 23.0);

        $pdf->Image(
            $resolvedQrPath,
            $leftX,
            $qrTop + 9.0,
            $displayQrSize,
            $displayQrSize
        );

        $pdf->SetXY(
            $leftX + $displayQrSize + 3.0,
            $qrTop + 11.0
        );
        $pdf->SetFont('Helvetica', '', 6.2);
        $pdf->MultiCell(
            $leftWidth - $displayQrSize - 3.0,
            3.0,
            invoice_pdf_text(
                'Scan to pay Rs. ' .
                number_format(
                    max(0, $qrAmount),
                    2
                )
            ),
            0,
            'L'
        );
    } elseif (
        $qrMode === 'dynamic_upi'
        && $qrError !== ''
    ) {
        $pdf->SetX($leftX);
        $pdf->SetFont('Helvetica', 'I', 5.9);
        $pdf->SetTextColor(150, 0, 0);
        $pdf->MultiCell(
            $leftWidth,
            3.0,
            invoice_pdf_text(
                'QR unavailable: ' . $qrError
            ),
            0,
            'L'
        );
        $pdf->SetTextColor(...$textColour);
    }
}

/*
|--------------------------------------------------------------------------
| Totals and signature
|--------------------------------------------------------------------------
*/
$pdf->SetXY($rightX, $footerTop);
$pdf->SetDrawColor(90, 90, 90);
$pdf->Line(
    $rightX,
    $footerTop,
    $rightX + $rightWidth,
    $footerTop
);

$pdf->SetFont('Helvetica', 'B', 7.8);
$pdf->Cell(50, 6.7, 'Total Amount', 0, 0);
$pdf->Cell(
    30,
    6.7,
    'Rs. ' . number_format(
        (float)$invoice['grand_total'],
        2
    ),
    0,
    1,
    'R'
);

$pdf->SetX($rightX);
$pdf->SetFont('Helvetica', '', 7.5);
$pdf->Cell(50, 6.7, 'Received Amount', 0, 0);
$pdf->Cell(
    30,
    6.7,
    'Rs. ' . number_format(
        (float)$invoice['received_amount'],
        2
    ),
    0,
    1,
    'R'
);

$pdf->SetXY(
    $rightX,
    $footerTop + 22.0
);
$pdf->SetFont('Helvetica', 'B', 7.5);
$pdf->Cell(
    $rightWidth,
    4.8,
    'Total Amount (in words)',
    0,
    1,
    'C'
);

$pdf->SetX($rightX);
$pdf->SetFont('Helvetica', '', 6.7);
$pdf->MultiCell(
    $rightWidth,
    3.7,
    invoice_pdf_text(
        amount_in_words(
            (float)$invoice['grand_total']
        )
    ),
    0,
    'C'
);

if ($showSignature) {
    $signature = $signaturePath !== ''
        ? safe_storage_path($signaturePath)
        : '';

    $signatureTop = 213.0;

    if (
        $signature !== ''
        && is_file($signature)
    ) {
        $signatureX =
            $rightX +
            (($rightWidth - $signatureWidth) / 2);

        $pdf->Image(
            $signature,
            $signatureX,
            $signatureTop,
            $signatureWidth,
            $signatureHeight
        );
    }

    $pdf->SetXY($rightX, 247.0);
    $pdf->SetFont('Helvetica', 'B', 7.0);
    $pdf->MultiCell(
        $rightWidth,
        3.6,
        invoice_pdf_text(
            "Authorised Signature for\n" .
            $brandHeading
        ),
        0,
        'C'
    );
}

if ($pdf->PageNo() !== 1) {
    throw new RuntimeException(
        'Invoice exceeded one A4 page.'
    );
}

$filename = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    (string)$invoice['invoice_number']
) . '.pdf';

$mode = (
    ($_GET['download'] ?? '0') === '1'
)
    ? 'D'
    : 'I';

$pdf->Output($mode, $filename);
