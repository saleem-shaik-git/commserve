<?php

declare(strict_types=1);

final class BankingService
{
    public function __construct(private PDO $db) {}

    public function deposit(int $accountId, string $amount, string $description = 'Simulated deposit'): string
    {
        return $this->singleAccountMovement($accountId, $amount, 'deposit', 'credit', $description);
    }

    public function withdraw(int $accountId, string $amount, string $description = 'Simulated withdrawal'): string
    {
        $this->assertPositiveAmount($amount);
        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($accountId);
            if ($account['status'] !== 'active') throw new RuntimeException('Account is not active.');
            if ((float)$account['available_balance'] < (float)$amount) throw new RuntimeException('Insufficient funds.');
            $reference = $this->createTransaction('withdrawal', $amount, $account['currency'], $description);
            $this->addLedgerEntry($reference, $accountId, 'debit', $amount);
            $this->completeTransaction($reference);
            $this->recalculateBalance($accountId);
            $this->db->commit();
            return $reference;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function transfer(int $fromAccountId, int $toAccountId, string $amount, string $description = 'Internal transfer'): string
    {
        $this->assertPositiveAmount($amount);
        if ($fromAccountId === $toAccountId) throw new RuntimeException('Source and destination accounts must differ.');
        $this->db->beginTransaction();
        try {
            $ids = [$fromAccountId, $toAccountId]; sort($ids, SORT_NUMERIC);
            $first = $this->lockAccount($ids[0]);
            $second = $this->lockAccount($ids[1]);
            $from = $fromAccountId === (int)$first['id'] ? $first : $second;
            $to = $toAccountId === (int)$first['id'] ? $first : $second;
            if ($from['status'] !== 'active' || $to['status'] !== 'active') throw new RuntimeException('Both accounts must be active.');
            if ($from['currency'] !== $to['currency']) throw new RuntimeException('Currency mismatch.');
            if ((float)$from['available_balance'] < (float)$amount) throw new RuntimeException('Insufficient funds.');
            $reference = $this->createTransaction('transfer', $amount, $from['currency'], $description);
            $this->addLedgerEntry($reference, $fromAccountId, 'debit', $amount);
            $this->addLedgerEntry($reference, $toAccountId, 'credit', $amount);
            $this->completeTransaction($reference);
            $this->recalculateBalance($fromAccountId);
            $this->recalculateBalance($toAccountId);
            $this->db->commit();
            return $reference;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function balance(int $accountId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type = "credit" THEN le.amount ELSE -le.amount END), 0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');
        $stmt->execute([$accountId]);
        return number_format((float)$stmt->fetchColumn(), 4, '.', '');
    }

    public function transactionsForAccount(int $accountId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare('SELECT t.reference,t.type,t.status,t.amount,t.currency,t.description,t.created_at,le.entry_type FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? ORDER BY t.id DESC LIMIT '.$limit);
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    private function singleAccountMovement(int $accountId, string $amount, string $type, string $entryType, string $description): string
    {
        $this->assertPositiveAmount($amount);
        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($accountId);
            if ($account['status'] !== 'active') throw new RuntimeException('Account is not active.');
            $reference = $this->createTransaction($type, $amount, $account['currency'], $description);
            $this->addLedgerEntry($reference, $accountId, $entryType, $amount);
            $this->completeTransaction($reference);
            $this->recalculateBalance($accountId);
            $this->db->commit();
            return $reference;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=? FOR UPDATE');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) throw new RuntimeException('Account not found.');
        return $account;
    }

    private function createTransaction(string $type, string $amount, string $currency, string $description): string
    {
        $reference = 'TXN-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
        $stmt = $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description) VALUES(?, ?, "processing", ?, ?, ?)');
        $stmt->execute([$reference, $type, $amount, $currency, $description]);
        return $reference;
    }

    private function addLedgerEntry(string $reference, int $accountId, string $entryType, string $amount): void
    {
        $stmt = $this->db->prepare('SELECT id FROM transactions WHERE reference=?');
        $stmt->execute([$reference]);
        $transactionId = $stmt->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,?,?)');
        $stmt->execute([$transactionId, $accountId, $entryType, $amount]);
    }

    private function recalculateBalance(int $accountId): void
    {
        $balance = $this->balance($accountId);
        $stmt = $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?');
        $stmt->execute([$balance, $accountId]);
    }

    private function completeTransaction(string $reference): void
    {
        $stmt = $this->db->prepare('UPDATE transactions SET status="completed", completed_at=CURRENT_TIMESTAMP WHERE reference=?');
        $stmt->execute([$reference]);
    }

    private function assertPositiveAmount(string $amount): void
    {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount) || (float)$amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }
}
