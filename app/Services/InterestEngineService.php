<?php
declare(strict_types=1);

/**
 * Interest engine: computes daily balances from the ledger, accrues product
 * interest and POSTS it as a real ledger entry (transactions type
 * 'interest_credit') so statements reflect it. Idempotent per period.
 */
final class InterestEngineService
{
    public function __construct(private PDO $db) {}

    /**
     * Run the engine up to (and excluding) $today. Daily products accrue for
     * every elapsed day; monthly products post when a calendar month has
     * fully elapsed. Returns per-account posting summaries.
     */
    public function run(string $today = 'today'): array
    {
        $today = date('Y-m-d', $today === 'today' ? time() : strtotime($today));
        $posted = [];
        $accounts = $this->db->query(
            'SELECT a.id, a.user_id, a.account_number, a.currency, p.id product_id, p.interest_rate, p.calc_frequency, p.min_daily_balance, p.withdrawal_restriction, p.status product_status
             FROM accounts a JOIN savings_products p ON p.id=a.savings_product_id
             WHERE a.status="active" AND p.status="active" ORDER BY a.id'
        )->fetchAll();

        foreach ($accounts as $a) {
            // Term/locked products accrue into the fixed deposit, not the account.
            if ($a['withdrawal_restriction'] === 'locked') continue;
            $lastEnd = $this->lastPostedEnd((int)$a['id']); // inclusive
            $start = $lastEnd ? date('Y-m-d', strtotime($lastEnd . ' +1 day')) : date('Y-m-d', strtotime((string)$this->accountOpenedDate((int)$a['id'])));
            $end = date('Y-m-d', strtotime($today . ' -1 day'));
            while (strtotime($start) <= strtotime($end)) {
                $periodEnd = $this->periodEnd($start, $end, (string)$a['calc_frequency']);
                $row = $this->postPeriod($a, $start, $periodEnd);
                if ($row) $posted[] = $row;
                $start = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
            }
        }
        return $posted;
    }

    /** Post interest for [start..end]; returns a summary row or null. */
    private function postPeriod(array $a, string $start, string $end): ?array
    {
        $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
        if ($days <= 0) return null;
        $avg = $this->averageDailyBalance((int)$a['id'], $start, $end, (float)$a['min_daily_balance']);
        $amount = round($avg * ((float)$a['interest_rate'] / 100.0) / 365.0 * $days, 2);
        if ($amount < 0.01) return null;

        try {
            $this->db->beginTransaction();
            $ref = 'INT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"interest_credit","processing",?,?,NULL,?)')
                ->execute([$ref, number_format($amount, 2, '.', ''), $a['currency'], $a['user_id']]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$txId, $a['id'], number_format($amount, 2, '.', '')]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('INSERT INTO interest_postings(account_id,savings_product_id,period_start,period_end,days,avg_daily_balance,annual_rate,amount,reference,transaction_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$a['id'], $a['product_id'], $start, $end, $days, number_format($avg, 4, '.', ''), $a['interest_rate'], number_format($amount, 2, '.', ''), $ref, $txId]);
            $this->recalc((int)$a['id']);
            $this->db->commit();
            return ['account' => $a['account_number'], 'period' => "$start..$end", 'days' => $days, 'avg' => $avg, 'rate' => $a['interest_rate'], 'amount' => $amount, 'reference' => $ref];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_interest_period') || (str_contains(strtolower($msg), 'duplicate') && str_contains($msg, 'interest_postings'))) return null; // already posted
            throw $e;
        }
    }

    /**
     * Average daily closing balance for the period, derived from the ledger.
     * Days whose closing balance is below the product minimum earn nothing.
     */
    public function averageDailyBalance(int $accountId, string $start, string $end, float $minDaily = 0.0): float
    {
        $s = $this->db->prepare('SELECT le.entry_type, le.amount, t.status, DATE(le.created_at) d FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND le.created_at < ? + INTERVAL 1 DAY ORDER BY le.id');
        $s->execute([$accountId, $end]);
        $bal = 0.0; $movements = [];
        foreach ($s->fetchAll() as $r) {
            if ($r['status'] !== 'completed') continue;
            $day = (string)$r['d'];
            $movements[$day] = ($movements[$day] ?? 0.0) + ($r['entry_type'] === 'credit' ? (float)$r['amount'] : -(float)$r['amount']);
        }
        $total = 0.0; $n = 0; $bal = 0.0;
        // opening balance = everything before the period
        $s2 = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed" AND le.created_at < ?');
        $s2->execute([$accountId, $start]);
        $bal = (float)$s2->fetchColumn();
        for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
            $key = date('Y-m-d', $d);
            if (isset($movements[$key])) $bal += $movements[$key];
            if ($minDaily <= 0 || $bal >= $minDaily) $total += $bal;
            $n++;
        }
        return $n > 0 ? round($total / $n, 4) : 0.0;
    }

    /** Projection for the UI: next ~30 days of interest at the current balance. */
    public function preview30(int $accountId): float
    {
        $s = $this->db->prepare('SELECT a.available_balance, p.interest_rate FROM accounts a JOIN savings_products p ON p.id=a.savings_product_id WHERE a.id=? LIMIT 1');
        $s->execute([$accountId]);
        $r = $s->fetch();
        if (!$r) return 0.0;
        return round((float)$r['available_balance'] * ((float)$r['interest_rate'] / 100.0) / 365.0 * 30, 2);
    }

    public function history(?int $accountId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT ip.*, a.account_number FROM interest_postings ip JOIN accounts a ON a.id=ip.account_id';
        $params = [];
        if ($accountId) { $sql .= ' WHERE ip.account_id=?'; $params[] = $accountId; }
        $sql .= ' ORDER BY ip.id DESC LIMIT ' . $limit;
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    private function periodEnd(string $start, string $hardEnd, string $frequency): string
    {
        if ($frequency === 'daily') return $start;
        $monthEnd = date('Y-m-t', strtotime($start));
        return $monthEnd < $hardEnd ? $monthEnd : $hardEnd;
    }

    private function lastPostedEnd(int $accountId): ?string
    {
        $s = $this->db->prepare('SELECT MAX(period_end) FROM interest_postings WHERE account_id=?');
        $s->execute([$accountId]);
        $v = $s->fetchColumn();
        return $v ? (string)$v : null;
    }

    private function accountOpenedDate(int $accountId): string
    {
        $s = $this->db->prepare('SELECT DATE(created_at) FROM accounts WHERE id=?');
        $s->execute([$accountId]);
        return (string)($s->fetchColumn() ?: date('Y-m-d'));
    }

    private function recalc(int $accountId): void
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');
        $s->execute([$accountId]);
        $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([number_format((float)$s->fetchColumn(), 4, '.', ''), $accountId]);
    }
}
