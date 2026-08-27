<?php
/**
 * Daily banking worker: interest posting, fixed-deposit maturities and loan
 * late/default processing. Run once a day from cron / Task Scheduler:
 *
 *   php scripts/run-daily-banking.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/database.php';
require_once dirname(__DIR__) . '/app/Services/InterestEngineService.php';
require_once dirname(__DIR__) . '/app/Services/FixedDepositService.php';
require_once dirname(__DIR__) . '/app/Services/LoanService.php';

fwrite(STDOUT, "CommServe daily banking worker\n");
fwrite(STDOUT, "==============================\n");

$db = Database::connection();

$posted = (new InterestEngineService($db))->run();
fwrite(STDOUT, 'Interest postings: ' . count($posted) . "\n");
foreach ($posted as $p) {
    fwrite(STDOUT, "  {$p['account']} {$p['period']} ({$p['days']}d @ {$p['rate']}%) -> \${$p['amount']} {$p['reference']}\n");
}

$matured = (new FixedDepositService($db))->processMaturities();
fwrite(STDOUT, "Fixed deposits matured: {$matured}\n");

$late = (new LoanService($db))->processLate();
fwrite(STDOUT, "Loans defaulted: {$late['defaulted']}\n");

fwrite(STDOUT, "Done.\n");
