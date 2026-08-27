<?php
declare(strict_types=1);
require_once __DIR__ . '/SecurityService.php';

/**
 * Configurable savings products: catalogue, funded account opening and
 * withdrawal-restriction checks (enforced by TransferService).
 */
final class SavingsProductService
{
    public function __construct(private PDO $db) {}

    public function products(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM savings_products' . ($activeOnly ? ' WHERE status="active"' : '') . ' ORDER BY sort,id';
        return $this->db->query($sql)->fetchAll();
    }

    public function product(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM savings_products WHERE id=? LIMIT 1');
        $s->execute([$id]);
        $p = $s->fetch();
        if (!$p) throw new RuntimeException('Savings product not found.');
        return $p;
    }

    public function productByCode(string $code): array
    {
        $s = $this->db->prepare('SELECT * FROM savings_products WHERE code=? LIMIT 1');
        $s->execute([$code]);
        $p = $s->fetch();
        if (!$p) throw new RuntimeException('Savings product not found.');
        return $p;
    }

    /** Product accounts for a user (accounts tied to a product). */
    public function accountsForUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT a.*, p.code product_code, p.name product_name, p.interest_rate, p.withdrawal_restriction FROM accounts a LEFT JOIN savings_products p ON p.id=a.savings_product_id WHERE a.user_id=? AND a.savings_product_id IS NOT NULL ORDER BY a.id');
        $s->execute([$userId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            $r['interest_paid'] = $this->interestPaid((int)$r['id']);
        }
        return $rows;
    }

    public function interestPaid(int $accountId): string
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM interest_postings WHERE account_id=?');
        $s->execute([$accountId]);
        return number_format((float)$s->fetchColumn(), 2, '.', '');
    }

    /**
     * Open a product account funded from an existing account (internal
     * ledger move). Verifies the transaction PIN and the product minimum.
     */
    public function openAccount(int $userId, int $productId, int $fromAccountId, string $amount, string $pin): array
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        (new SecurityService($this->db))->verifyTransactionPin($userId, $pin);
        $product = $this->product($productId);
        if ($product['status'] !== 'active') throw new RuntimeException('This product is not available.');
        if ($product['is_term']) throw new RuntimeException('Term products are opened from Fixed Deposits.');
        if ((float)$amount < (float)$product['min_opening_balance']) throw new RuntimeException('Minimum opening balance for ' . $product['name'] . ' is ' . number_format((float)$product['min_opening_balance'], 2) . '.');

        $savingsTypeId = $this->savingsTypeId();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO accounts(user_id,account_type_id,account_number,savings_product_id) VALUES(?,?,?,?)')->execute([$userId, $savingsTypeId, 'PENDING', $productId]);
            $newId = (int)$this->db->lastInsertId();
            $number = '02' . str_pad((string)$newId, 8, '0', STR_PAD_LEFT);
            $this->db->prepare('UPDATE accounts SET account_number=? WHERE id=?')->execute([$number, $newId]);

            $from = $this->lockAccount($fromAccountId, $userId);
            $balance = $this->ledgerBalance($fromAccountId);
            if ((float)$balance < (float)$amount) throw new RuntimeException('Insufficient funds in the funding account. Available: ' . number_format((float)$balance, 2));

            $ref = 'OPEN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"account_opening","processing",?,?,?,?)')
                ->execute([$ref, $amount, $from['currency'], 'Open ' . $product['name'] . ' ' . $number, $userId]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"debit",?)')->execute([$txId, $fromAccountId, $amount]);
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$txId, $newId, $amount]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($fromAccountId), $fromAccountId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($newId), $newId]);

            $this->db->commit();
            return ['account_id' => $newId, 'account_number' => $number, 'reference' => $ref, 'product' => $product['name']];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Withdrawal-restriction check for outgoing transfers from a product
     * account. Throws when the product rules do not allow the withdrawal.
     */
    public function assertWithdrawalAllowed(int $accountId): void
    {
        $s = $this->db->prepare('SELECT p.withdrawal_restriction, p.max_withdrawals_per_month FROM accounts a JOIN savings_products p ON p.id=a.savings_product_id WHERE a.id=? LIMIT 1');
        $s->execute([$accountId]);
        $p = $s->fetch();
        if (!$p) return; // not a product account -> no extra rules
        $rule = (string)$p['withdrawal_restriction'];
        if ($rule === 'locked') throw new RuntimeException('This account is locked until maturity; withdrawals are not allowed.');
        if ($rule === 'restricted' || $rule === 'limited') {
            $max = (int)$p['max_withdrawals_per_month'];
            if ($max > 0) {
                $q = $this->db->prepare('SELECT COUNT(DISTINCT t.id) FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id WHERE le.account_id=? AND le.entry_type="debit" AND t.created_at>=DATE_FORMAT(CURRENT_DATE,"%Y-%m-01") AND t.status="completed"');
                $q->execute([$accountId]);
                if ((int)$q->fetchColumn() >= $max) throw new RuntimeException('Monthly withdrawal limit (' . $max . ') reached for this product account.');
            }
        }
    }

    public function updateProduct(int $id, array $fields, int $adminId): void
    {
        $allowed = ['name' => 's', 'interest_rate' => 'f', 'min_opening_balance' => 'f', 'min_daily_balance' => 'f', 'calc_frequency' => 's', 'withdrawal_restriction' => 's', 'max_withdrawals_per_month' => 'i', 'early_penalty_pct' => 'f', 'status' => 's', 'sort' => 'i'];
        $sets = []; $params = [];
        foreach ($fields as $k => $v) {
            if (!isset($allowed[$k])) continue;
            if ($allowed[$k] === 'f' && !is_numeric($v)) continue;
            $sets[] = "$k=?"; $params[] = $allowed[$k] === 'i' ? (int)$v : ($allowed[$k] === 'f' ? (string)(float)$v : (string)$v);
        }
        if (!$sets) throw new InvalidArgumentException('Nothing to update.');
        if (isset($fields['interest_rate']) && ((float)$fields['interest_rate'] < 0 || (float)$fields['interest_rate'] > 100)) throw new InvalidArgumentException('Interest rate must be between 0 and 100.');
        $params[] = $id;
        $this->db->prepare('UPDATE savings_products SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        $this->audit($adminId, 'savings_product_updated', 'savings_product', $id, $fields);
    }

    public function createProduct(array $f, int $adminId): int
    {
        $code = strtoupper(trim($f['code'] ?? ''));
        $name = trim($f['name'] ?? '');
        if (!preg_match('/^[A-Z0-9_]{2,30}$/', $code) || $name === '') throw new InvalidArgumentException('Valid code and name are required.');
        $this->db->prepare('INSERT INTO savings_products(code,name,interest_rate,min_opening_balance,min_daily_balance,calc_frequency,withdrawal_restriction,max_withdrawals_per_month,is_term,default_term_days,early_penalty_pct,sort) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$code, $name, (string)(float)($f['interest_rate'] ?? 0), (string)(float)($f['min_opening_balance'] ?? 0), (string)(float)($f['min_daily_balance'] ?? 0), in_array($f['calc_frequency'] ?? '', ['daily', 'monthly']) ? $f['calc_frequency'] : 'monthly', in_array($f['withdrawal_restriction'] ?? '', ['none', 'limited', 'restricted', 'locked']) ? $f['withdrawal_restriction'] : 'none', (int)($f['max_withdrawals_per_month'] ?? 0), 0, null, (string)(float)($f['early_penalty_pct'] ?? 0), (int)($f['sort'] ?? 99)]);
        $id = (int)$this->db->lastInsertId();
        $this->audit($adminId, 'savings_product_created', 'savings_product', $id, ['code' => $code]);
        return $id;
    }

    public function loanProducts(bool $activeOnly = true): array
    {
        return $this->db->query('SELECT * FROM loan_products' . ($activeOnly ? ' WHERE status="active"' : '') . ' ORDER BY id')->fetchAll();
    }

    public function updateLoanProduct(int $id, array $f, int $adminId): void
    {
        $this->db->prepare('UPDATE loan_products SET annual_rate=?,min_amount=?,max_amount=?,min_tenor_months=?,max_tenor_months=?,status=? WHERE id=?')
            ->execute([(string)(float)$f['annual_rate'], (string)(float)$f['min_amount'], (string)(float)$f['max_amount'], (int)$f['min_tenor_months'], (int)$f['max_tenor_months'], ($f['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active', $id]);
        $this->audit($adminId, 'loan_product_updated', 'loan_product', $id, $f);
    }

    private function savingsTypeId(): int
    {
        $id = $this->db->query("SELECT id FROM account_types WHERE name='Savings' LIMIT 1")->fetchColumn();
        if (!$id) throw new RuntimeException('Savings account type missing.');
        return (int)$id;
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

    private function audit(int $userId, string $action, string $entityType, int $entityId, array $details): void
    {
        $this->db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,ip_address,details) VALUES(?,?,?,?,?,?)')
            ->execute([$userId, $action, $entityType, $entityId, $_SERVER['REMOTE_ADDR'] ?? null, json_encode($details, JSON_THROW_ON_ERROR)]);
    }
}
