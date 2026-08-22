<?php
declare(strict_types=1);

require_once __DIR__ . '/../Lib/SimplePDF.php';

final class StatementService
{
    public function __construct(private PDO $db) {}

    public function getAccount(int $userId, int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.name AS type_name, at.currency, u.first_name, u.last_name, u.email FROM accounts a JOIN account_types at ON at.id=a.account_type_id JOIN users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=?');
        $stmt->execute([$accountId, $userId]);
        $acc = $stmt->fetch();
        if (!$acc) throw new RuntimeException('Account not found.');
        return $acc;
    }

    public function getAccounts(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.name AS type_name, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id=? ORDER BY a.id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getTransactionsRange(int $accountId, ?string $from, ?string $to, int $limit = 1000): array
    {
        $limit = max(1, min(2000, $limit));
        $where = 'le.account_id=? AND t.status="completed"';
        $params = [$accountId];
        if ($from) { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $from; }
        if ($to)   { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $to; }
        $sql = "SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at, le.entry_type
                FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id
                WHERE $where ORDER BY t.created_at ASC, t.id ASC LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getOpeningBalance(int $accountId, ?string $fromDate): float
    {
        if (!$fromDate) return 0.0;
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed" AND DATE(t.created_at) < ?');
        $stmt->execute([$accountId, $fromDate]);
        return (float)$stmt->fetchColumn();
    }

    public function getClosingBalance(int $accountId, ?string $toDate): float
    {
        if (!$toDate) {
            $stmt = $this->db->prepare('SELECT available_balance FROM accounts WHERE id=?');
            $stmt->execute([$accountId]);
            return (float)$stmt->fetchColumn();
        }
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed" AND DATE(t.created_at) <= ?');
        $stmt->execute([$accountId, $toDate]);
        return (float)$stmt->fetchColumn();
    }

    public function generateCSV(array $account, array $transactions, string $from, string $to, float $opening): string
    {
        $fh = fopen('php://temp', 'r+');
        // Header
        fputcsv($fh, ['CommServe Demo Bank - Account Statement']);
        fputcsv($fh, ['Account Number', $account['account_number']]);
        fputcsv($fh, ['Account Type', $account['type_name']]);
        fputcsv($fh, ['Currency', $account['currency']]);
        fputcsv($fh, ['Customer', $account['first_name'] . ' ' . $account['last_name']]);
        fputcsv($fh, ['Period', ($from ?: 'Beginning') . ' to ' . ($to ?: 'Now')]);
        fputcsv($fh, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($fh, []);
        fputcsv($fh, ['Date', 'Reference', 'Type', 'Description', 'Debit', 'Credit', 'Balance', 'Status']);
        $running = $opening;
        foreach ($transactions as $tx) {
            $amount = (float)$tx['amount'];
            $debit = $tx['entry_type'] === 'debit' ? $amount : 0;
            $credit = $tx['entry_type'] === 'credit' ? $amount : 0;
            $running += $credit - $debit;
            fputcsv($fh, [
                $tx['created_at'],
                $tx['reference'],
                $tx['type'],
                $tx['description'],
                $debit ? number_format($debit, 2) : '',
                $credit ? number_format($credit, 2) : '',
                number_format($running, 2),
                $tx['status']
            ]);
        }
        fputcsv($fh, []);
        fputcsv($fh, ['Opening Balance', number_format($opening, 2)]);
        fputcsv($fh, ['Closing Balance', number_format($running, 2)]);
        fputcsv($fh, ['Note', 'SIMULATION ONLY - No real funds']);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    public function generatePDF(array $account, array $transactions, string $from, string $to, float $opening, float $closing): string
    {
        $pdf = new SimplePDF();
        $pdf->addPage();

        // Header
        $pdf->setFont('Helvetica', 'B', 18);
        $pdf->text(40, 800, 'CommServe Demo Bank');
        $pdf->setFont('Helvetica', '', 9);
        $pdf->text(40, 788, 'DEMO - Simulated Banking - No Real Funds Processed');
        $pdf->line(40, 782, 555, 782, 0.8);

        $pdf->setFont('Helvetica', 'B', 14);
        $pdf->text(40, 765, 'Account Statement');

        $pdf->setFont('Helvetica', '', 10);
        $y = 750;
        $pdf->text(40, $y, 'Account: ' . $account['account_number'] . ' - ' . $account['type_name'] . ' (' . $account['currency'] . ')'); $y -= 14;
        $pdf->text(40, $y, 'Customer: ' . $account['first_name'] . ' ' . $account['last_name'] . ' - ' . $account['email']); $y -= 14;
        $pdf->text(40, $y, 'Period: ' . ($from ?: 'Beginning') . ' to ' . ($to ?: date('Y-m-d'))); $y -= 14;
        $pdf->text(40, $y, 'Generated: ' . date('Y-m-d H:i:s')); $y -= 14;
        $pdf->text(40, $y, 'Opening Balance: ' . $account['currency'] . ' ' . number_format($opening, 2)); $y -= 14;
        $pdf->text(40, $y, 'Closing Balance: ' . $account['currency'] . ' ' . number_format($closing, 2)); $y -= 20;

        $pdf->setY($y);

        // Table header
        $pdf->ensureSpace(100);
        $pdf->setFont('Helvetica', 'B', 9);
        $headerY = $pdf->getY();
        $pdf->text(40, $headerY, 'Date');
        $pdf->text(95, $headerY, 'Reference');
        $pdf->text(175, $headerY, 'Type');
        $pdf->text(220, $headerY, 'Description');
        $pdf->text(350, $headerY, 'Debit');
        $pdf->text(400, $headerY, 'Credit');
        $pdf->text(455, $headerY, 'Balance');
        $pdf->line(40, $headerY - 4, 555, $headerY - 4, 0.5);
        $pdf->setY($headerY - 16);
        $pdf->setFont('Helvetica', '', 8);

        $running = $opening;
        $count = 0;
        foreach ($transactions as $tx) {
            $pdf->ensureSpace(20);
            $amount = (float)$tx['amount'];
            $debit = $tx['entry_type'] === 'debit' ? $amount : 0;
            $credit = $tx['entry_type'] === 'credit' ? $amount : 0;
            $running += $credit - $debit;

            $y = $pdf->getY();
            $date = substr($tx['created_at'], 0, 10);
            $ref = substr($tx['reference'], 0, 14);
            $type = substr($tx['type'], 0, 10);
            $desc = substr($tx['description'] ?? '', 0, 32);
            // Escape handled inside

            $pdf->text(40, $y, $date);
            $pdf->text(95, $y, $ref);
            $pdf->text(175, $y, $type);
            $pdf->text(220, $y, $desc);
            if ($debit) $pdf->text(350, $y, number_format($debit, 2));
            if ($credit) $pdf->text(400, $y, number_format($credit, 2));
            $pdf->text(455, $y, number_format($running, 2));

            $pdf->ln(12);
            $count++;
            if ($count % 35 === 0) {
                $pdf->line(40, $pdf->getY() + 4, 555, $pdf->getY() + 4, 0.3);
            }
        }

        $pdf->ensureSpace(40);
        $pdf->line(40, $pdf->getY() + 8, 555, $pdf->getY() + 8, 0.5);
        $pdf->ln(10);
        $pdf->setFont('Helvetica', 'B', 10);
        $pdf->writeLine('Summary: ' . count($transactions) . ' transaction(s) | Opening: ' . number_format($opening, 2) . ' | Closing: ' . number_format($closing, 2), 40, 10, 'B', 14);
        $pdf->setFont('Helvetica', '', 8);
        $pdf->writeLine('This statement is computer generated and valid without signature. SIMULATION ONLY.', 40, 8, '', 12);
        $pdf->writeLine('CommServe Demo Bank | commserve.test | No real banking rails used.', 40, 8, '', 12);

        return $pdf->output();
    }

    public function logRequest(int $userId, int $accountId, string $type, ?string $from, ?string $to): void
    {
        try {
            $this->db->prepare('INSERT INTO statement_requests(user_id,account_id,type,from_date,to_date) VALUES(?,?,?,?,?)')->execute([$userId, $accountId, $type, $from ?: null, $to ?: null]);
        } catch (PDOException $e) {
            // ignore if table not exists
        }
    }

    public function monthlyRange(string $yearMonth): array
    {
        // $yearMonth = YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) throw new InvalidArgumentException('Invalid month format. Use YYYY-MM');
        $from = $yearMonth . '-01';
        $to = date('Y-m-t', strtotime($from));
        return [$from, $to];
    }
}
