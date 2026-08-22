<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/StatementService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$svc = new StatementService($db);

$accountId = (int)($_GET['account'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$format = strtolower(trim($_GET['format'] ?? 'pdf'));
$month = trim($_GET['month'] ?? '');

if ($accountId === 0) { http_response_code(400); exit('Account required'); }
if (!in_array($format, ['pdf','csv'], true)) $format = 'pdf';

try {
    if ($month !== '') {
        [$from, $to] = $svc->monthlyRange($month);
    }
    if ($from === '') $from = date('Y-m-01');
    if ($to === '') $to = date('Y-m-d');

    $account = $svc->getAccount((int)$user['id'], $accountId);
    $transactions = $svc->getTransactionsRange($accountId, $from, $to, 2000);
    $opening = $svc->getOpeningBalance($accountId, $from);
    $closing = $svc->getClosingBalance($accountId, $to);

    // Running balance for closing if no transactions? Already calculated.
    if (empty($transactions)) {
        $running = $opening;
    } else {
        $running = $opening;
        foreach ($transactions as $tx) {
            $amt = (float)$tx['amount'];
            $running += $tx['entry_type'] === 'credit' ? $amt : -$amt;
        }
        $closing = $running;
    }

    $svc->logRequest((int)$user['id'], $accountId, $format, $from, $to);

    $filenameBase = 'CommServe_Statement_' . $account['account_number'] . '_' . $from . '_to_' . $to;

    if ($format === 'csv') {
        $csv = $svc->generateCSV($account, $transactions, $from, $to, $opening);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        header('Pragma: no-cache');
        echo $csv;
        exit;
    } else {
        $pdf = $svc->generatePDF($account, $transactions, $from, $to, $opening, $closing);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Pragma: no-cache');
        echo $pdf;
        exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo 'Error generating statement: ' . htmlspecialchars($e->getMessage());
    exit;
}
