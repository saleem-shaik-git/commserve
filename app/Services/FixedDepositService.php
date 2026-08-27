<?php
declare(strict_types=1);
require_once __DIR__ . '/SecurityService.php';

/**
 * Fixed/term deposits: creation (funds leave the account and are held),
 * maturity processing, early withdrawal with penalty - all through the
 * ledger.
 */
final class FixedDepositService
{
    public function __construct(private PDO $db) {}

    public function termProducts(): array
    {
        return $this->db->query('SELECT * FROM savings_products WHERE is_term=1 AND status="active" ORDER BY sort')->fetchAll();
    }

    public function listForUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT f.*, p.name product_name, p.code product_code, a.account_number FROM fixed_deposits f JOIN savings_products p ON p.id=f.savings_product_id JOIN accounts a ON a.id=f.account_id WHERE f.user_id=? ORDER BY f.id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    /** Simple interest: P * r% * days/365. */
    public static function interestFor(string $principal, string $annualRate, int $days): string
    {
        return number_format(round((float)$principal * ((float)$annualRate / 100.0) * ($days / 365.0), 2), 2, '.', '');
    }

    public function create(int $userId, int $productId, int $fromAccountId, string $amount, ?string $pin, ?int $termDays = null): array
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        if ($pin !== null) (new SecurityService($this->db))->verifyTransactionPin($userId, $pin);
        $s = $this->db->prepare('SELECT * FROM savings_products WHERE id=? AND is_term=1 AND status="active" LIMIT 1');
        $s->execute([$productId]);
        $product = $s->fetch();
        if (!$product) throw new RuntimeException('Fixed deposit product not found.');
        if ((float)$amount < (float)$product['min_opening_balance']) throw new RuntimeException('Minimum for ' . $product['name'] . ' is ' . number_format((float)$product['min_opening_balance'], 2) . '.');
        $term = $termDays ?? (int)$product['default_term_days'];
        if ($term < 7 || $term > 3650) throw new InvalidArgumentException('Term must be between 7 and 3650 days.');

        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($fromAccountId, $userId);
            $balance = $this->ledgerBalance($fromAccountId);
            if ((float)$balance < (float)$amount) throw new RuntimeException('Insufficient funds. Available: ' . number_format((float)$balance, 2));

            $ref = 'FD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"fixed_deposit","processing",?,?,?,?)')
                ->execute([$ref, $amount, $account['currency'], 'Fixed deposit placement', $userId]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"debit",?)')->execute([$txId, $fromAccountId, $amount]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($fromAccountId), $fromAccountId]);

            $interest = self::interestFor($amount, (string)$product['interest_rate'], $term);
            $this->db->prepare('INSERT INTO fixed_deposits(user_id,account_id,savings_product_id,reference,principal,annual_rate,term_days,penalty_pct,interest_amount,maturity_value,start_date,maturity_date,status) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_DATE(),DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY),"active")')
                ->execute([$userId, $fromAccountId, $productId, $ref, $amount, $product['interest_rate'], $term, $product['early_penalty_pct'], $interest, number_format((float)$amount + (float)$interest, 2, '.', ''), $term]);
            $fdId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return ['id' => $fdId, 'reference' => $ref, 'principal' => $amount, 'interest' => $interest, 'maturity_value' => number_format((float)$amount + (float)$interest, 2, '.', ''), 'maturity_date' => date('Y-m-d', strtotime("+$term days")), 'term_days' => $term];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Mark deposits whose maturity date has arrived. */
    public function processMaturities(): int
    {
        $s = $this->db->prepare('UPDATE fixed_deposits SET status="matured" WHERE status="active" AND maturity_date<=CURRENT_DATE');
        $s->execute();
        return $s->rowCount();
    }

    /**
     * Withdraw a deposit. Matured deposits pay principal + full interest;
     * early withdrawals forfeit penalty_pct of the pro-rated interest.
     */
    public function withdraw(int $userId, int $fdId, ?string $pin = null): array
    {
        if ($pin !== null) (new SecurityService($this->db))->verifyTransactionPin($userId, $pin);
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT f.* FROM fixed_deposits f WHERE f.id=? FOR UPDATE');
            $s->execute([$fdId]);
            $fd = $s->fetch();
            if (!$fd || (int)$fd['user_id'] !== $userId) throw new RuntimeException('Fixed deposit not found.');
            if (!in_array($fd['status'], ['active', 'matured'], true)) throw new RuntimeException('This deposit is already closed.');

            $elapsed = (int)floor((time() - strtotime((string)$fd['start_date'])) / 86400);
            $early = $fd['status'] === 'active' && $elapsed < (int)$fd['term_days'];
            $rate = (float)$fd['annual_rate'];
            if ($early) {
                $interest = self::interestFor((string)$fd['principal'], (string)$fd['annual_rate'], max(0, $elapsed));
                $penalty = round($interest * ((float)$fd['penalty_pct'] / 100.0), 2);
                $payout = round((float)$fd['principal'] + $interest - $penalty, 2);
                $status = 'withdrawn_early';
            } else {
                $interest = (float)$fd['interest_amount'];
                $penalty = 0.0;
                $payout = round((float)$fd['principal'] + $interest, 2);
                $status = 'closed';
            }

            $this->lockAccount((int)$fd['account_id'], $userId);
            $ref = 'FDP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"fixed_deposit_payout","processing",?,?,?,?)')
                ->execute([$ref, number_format($payout, 2, '.', ''), $this->accountCurrency((int)$fd['account_id']), $early ? 'Fixed deposit early payout (penalty applied)' : 'Fixed deposit maturity payout', $userId]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$txId, $fd['account_id'], number_format($payout, 2, '.', '')]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance((int)$fd['account_id']), (int)$fd['account_id']]);

            $this->db->prepare('UPDATE fixed_deposits SET status=?,interest_amount=?,penalty_amount=?,payout_amount=?,payout_reference=?,closed_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$status, number_format($interest, 2, '.', ''), number_format($penalty, 2, '.', ''), number_format($payout, 2, '.', ''), $ref, $fdId]);
            $this->db->commit();
            return ['reference' => $ref, 'payout' => number_format($payout, 2, '.', ''), 'interest' => number_format($interest, 2, '.', ''), 'penalty' => number_format($penalty, 2, '.', ''), 'early' => $early];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function maturedTotal(int $userId): float
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(principal+interest_amount),0) FROM fixed_deposits WHERE user_id=? AND status="matured"');
        $s->execute([$userId]);
        return (float)$s->fetchColumn();
    }

    private function accountCurrency(int $accountId): string
    {
        $s = $this->db->prepare('SELECT at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=?');
        $s->execute([$accountId]);
        return (string)($s->fetchColumn() ?: 'USD');
    }

    private function lockAccount(int $accountId, int $userId): array
    {
        $s = $this->db->prepare('SELECT a.*,at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=? FOR UPDATE');
        $s->execute([$accountId]);
        $a = $s->fetch();
        if (!$a) throw new RuntimeException('Account not found.');
        if ((int)$a['user_id'] !== $userId) throw new RuntimeException('Account does not belong to you.');
        if ($a['status'] !== 'active') throw new RuntimeException('Account is not active.');
        return $a;
    }

    private function ledgerBalance(int $accountId): string
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');
        $s->execute([$accountId]);
        return number_format((float)$s->fetchColumn(), 4, '.', '');
    }
}
