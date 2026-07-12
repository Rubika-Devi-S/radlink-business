<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';

$clientId=(int)($_GET['client_id']??0);
$serviceId=(int)($_GET['service_id']??0);
$date=(string)($_GET['date']??date('Y-m-d'));

if($clientId<=0||$serviceId<=0) json_response(false,'Client and service are required.',[],422);

$stmt=$pdo->prepare(
 "SELECT s.id,s.service_code,s.service_name,s.unit_name,s.standard_rate,s.tax_percent,
         COALESCE((
          SELECT csr.agreed_rate FROM client_service_rates csr
          WHERE csr.business_id=s.business_id AND csr.client_id=? AND csr.service_id=s.id
            AND csr.status='active' AND csr.effective_from<=?
            AND (csr.effective_to IS NULL OR csr.effective_to>=?)
          ORDER BY csr.effective_from DESC,csr.id DESC LIMIT 1
         ),s.standard_rate) AS applied_rate
  FROM services s
  WHERE s.id=? AND s.business_id=? AND s.status='active' LIMIT 1"
);
$stmt->execute([$clientId,$date,$date,$serviceId,$currentBusinessId]);
$row=$stmt->fetch();
if(!$row) json_response(false,'Service not found.',[],404);
json_response(true,'Rate loaded.',$row);
