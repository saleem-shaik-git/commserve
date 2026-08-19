<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/database.php';
require_once dirname(__DIR__) . '/app/Database/MigrationRunner.php';

$runner = new MigrationRunner(Database::connection(), dirname(__DIR__) . '/database/migrations');
$applied = $runner->migrate();
echo $applied ? "Applied: " . implode(', ', $applied) . PHP_EOL : "Database is up to date." . PHP_EOL;
