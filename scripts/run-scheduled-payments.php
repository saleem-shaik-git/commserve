<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/database.php';
require_once dirname(__DIR__).'/app/Services/ScheduledPaymentService.php';

$service=new ScheduledPaymentService(Database::connection());
$results=$service->runDue(100);
foreach($results as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES).PHP_EOL;}
