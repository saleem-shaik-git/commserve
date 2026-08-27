<?php
declare(strict_types=1);

final class AccountService
{
    public function __construct(private PDO $db) {}

    public function getAccounts(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.name AS type_name, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id=? ORDER BY at.name, a.id');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getAccount(int $userId, int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.name AS type_name, at.currency, u.first_name, u.last_name, u.email FROM accounts a JOIN account_types at ON at.id=a.account_type_id JOIN users u ON u.id=a.user_id WHERE a.id=? AND a.user_id=?');
        $stmt->execute([$accountId, $userId]);
        $acc = $stmt->fetch();
        if (!$acc) throw new RuntimeException('Account not found.');
        return $acc;
    }

    public function getAccountByNumber(string $accountNumber): ?array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.name AS type_name, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.account_number=? LIMIT 1');
        $stmt->execute([$accountNumber]);
        return $stmt->fetch() ?: null;
    }

    public function getTotalBalance(int $userId): float
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(available_balance),0) FROM accounts WHERE user_id=? AND status="active"');
        $stmt->execute([$userId]);
        return (float)$stmt->fetchColumn();
    }

    public function getAvailableBalance(int $userId): float
    {
        // For demo, available = total active
        return $this->getTotalBalance($userId);
    }

    public function getRecentTransactions(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at, le.entry_type, a.account_number, at.name AS account_type
             FROM ledger_entries le
             JOIN transactions t ON t.id=le.transaction_id
             JOIN accounts a ON a.id=le.account_id
             JOIN account_types at ON at.id=a.account_type_id
             WHERE a.user_id=? AND t.status="completed"
             ORDER BY t.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getPendingTransactions(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $sql = '
            SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at,
                   pt.from_account_id, pt.to_account_id,
                   fa.account_number AS from_account_number, ta.account_number AS to_account_number,
                   fat.name AS from_type, tat.name AS to_type
            FROM transactions t
            LEFT JOIN pending_transfers pt ON pt.transaction_id=t.id
            LEFT JOIN accounts fa ON fa.id=pt.from_account_id
            LEFT JOIN accounts ta ON ta.id=pt.to_account_id
            LEFT JOIN account_types fat ON fat.id=fa.account_type_id
            LEFT JOIN account_types tat ON tat.id=ta.account_type_id
            WHERE t.initiated_by=? AND t.status IN ("pending","processing")
            ORDER BY t.id DESC LIMIT ' . $limit;
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Fallback if pending_transfers not migrated yet
            $stmt = $this->db->prepare('SELECT reference,type,status,amount,currency,description,created_at FROM transactions WHERE initiated_by=? AND status IN ("pending","processing") ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
    }

    public function getAccountTransactions(int $accountId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->prepare(
            'SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at, t.completed_at, le.entry_type
             FROM ledger_entries le
             JOIN transactions t ON t.id=le.transaction_id
             WHERE le.account_id=? AND t.status="completed"
             ORDER BY t.created_at DESC, t.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function getAccountTransactionsRange(int $accountId, ?string $from, ?string $to, int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $where = 'le.account_id=? AND t.status="completed"';
        $params = [$accountId];
        if ($from) { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $from; }
        if ($to) { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $to; }
        $sql = "SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at, le.entry_type
                FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id
                WHERE $where ORDER BY t.created_at ASC, t.id ASC LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getOpeningBalance(int $accountId, ?string $fromDate): float
    {
        // No start date = statement begins at account inception (balance 0
        // before the first ledger entry), so 0.0 is correct by definition.
        if (!$fromDate) return 0.0;
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0)
             FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id
             WHERE le.account_id=? AND t.status="completed" AND DATE(t.created_at) < ?'
        );
        $stmt->execute([$accountId, $fromDate]);
        return (float)$stmt->fetchColumn();
    }

    public function getSavingsAndCurrent(int $userId): array
    {
        $accounts = $this->getAccounts($userId);
        $savings = array_values(array_filter($accounts, fn($a) => strtolower($a['type_name']) === 'savings'));
        $current = array_values(array_filter($accounts, fn($a) => strtolower($a['type_name']) === 'current'));
        return ['savings' => $savings, 'current' => $current, 'all' => $accounts];
    }
}
