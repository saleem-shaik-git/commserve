<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/database.php';
require_once dirname(__DIR__) . '/app/Services/ReconciliationService.php';

$result=(new ReconciliationService(Database::connection()))->run();
echo json_encode($result, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['status']==='passed'?0:1);
