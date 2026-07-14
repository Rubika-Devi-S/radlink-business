<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/invoice-functions.php';
require_once __DIR__.'/includes/invoice-settings-functions.php';
$id=(int)($_GET['id']??0);
$auto=invoice_setting($pdo,$currentBusinessId,'invoice_auto_print','1')==='1';
$showDownload=invoice_setting($pdo,$currentBusinessId,'invoice_show_download_fallback','1')==='1';
$pdfUrl=app_url('invoice-print.php?id='.$id.'&v='.time());
$downloadUrl=app_url('invoice-print.php?id='.$id.'&download=1&v='.time());
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print Invoice</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>html,body{height:100%;margin:0;background:#eef0f6}.bar{height:64px;background:#fff;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:8px;padding:10px 14px}.pdf{width:100%;height:calc(100% - 64px);border:0}.hint{margin-left:auto;font-size:12px;color:#667085}@media(max-width:600px){.bar{height:auto;flex-wrap:wrap}.pdf{height:calc(100% - 110px)}.hint{width:100%;margin-left:0}}</style></head><body>
<div class="bar"><button class="btn btn-primary" id="printBtn">Print</button><a class="btn btn-outline-secondary" target="_blank" href="<?= e($pdfUrl) ?>">Open PDF</a><?php if($showDownload): ?><a class="btn btn-outline-secondary" href="<?= e($downloadUrl) ?>">Download</a><?php endif; ?><a class="btn btn-light" href="<?= e(app_url('invoice-view.php?id='.$id)) ?>">Back</a><span class="hint">If automatic printing is blocked, use Print or Open PDF.</span></div>
<iframe class="pdf" id="pdfFrame" src="<?= e($pdfUrl) ?>"></iframe>
<script>
const frame=document.getElementById('pdfFrame');
function tryPrint(){try{frame.contentWindow.focus();frame.contentWindow.print();}catch(e){window.open(<?= json_encode($pdfUrl) ?>,'_blank');}}
document.getElementById('printBtn').addEventListener('click',tryPrint);
<?php if($auto): ?>frame.addEventListener('load',()=>setTimeout(tryPrint,700));<?php endif; ?>
</script></body></html>
