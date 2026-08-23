<?php
require_once __DIR__.'/../app/helpers.php';require_once __DIR__.'/../app/Services/NotificationService.php';$svc=new NotificationService(Database::connection());$count=$svc->processQueue((int)($argv[1]??100));echo 'Processed notifications: '.$count.PHP_EOL;
