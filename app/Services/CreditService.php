<?php
declare(strict_types=1);

/**
 * Credit scoring (300-850) from observable behaviour:
 * account age, transaction history, balances, loan/repayment history,
 * failed payments and risk alerts. Cached in credit_scores.
 */
final class CreditService
{
    public const BANDS = [
        ['min' => 750, 'max' => 850, 'band' => 'Excellent'],
        ['min' => 700, 'max' => 749, 'band' => 'Good'],
        ['min' => 650, 'max' => 699, 'band' => 'Fair'],
        ['min' => 600, 'max' => 649, 'band' => 'Weak'],
        ['min' => 300, 'max' => 599, 'band' => 'High Risk'],
    ];

    public function __construct(private PDO $db) {}

    public static function bandFor(int $score): string
    {
        foreach (self::BANDS as $b) if ($score >= $b['min'] && $score <= $b['max']) return $b['band'];
        return 'High Risk';
    }

    /** Compute (and cache) the credit score for a user. */
    public function score(int $userId, bool $refresh = true): array
    {
        if (!$refresh) {
            $s = $this->db->prepare('SELECT * FROM credit_scores WHERE user_id=? LIMIT 1');
            $s->execute([$userId]);
            $row = $s->fetch();
            if ($row) return ['score' => (int)$row['score'], 'band' => $row['band'], 'factors' => json_decode((string)$row['factors'], true) ?: [], 'computed_at' => $row['computed_at']];
        }
        $factors = $this->compute($userId);
        $score = max(300, min(850, (int)round(array_sum(array_column($factors, 'points')))));
        $band = self::bandFor($score);
        try {
            $this->db->prepare('INSERT INTO credit_scores(user_id,score,band,factors,computed_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE score=VALUES(score),band=VALUES(band),factors=VALUES(factors),computed_at=CURRENT_TIMESTAMP')
                ->execute([$userId, $score, $band, json_encode($factors, JSON_THROW_ON_ERROR)]);
        } catch (Throwable $e) { /* cache is best-effort */ }
        return ['score' => $score, 'band' => $band, 'factors' => $factors, 'computed_at' => date('Y-m-d H:i:s')];
    }

    private function compute(int $userId): array
    {
        $f = [];

        // 1. Account age (2 pts per month, max 60)
        $s = $this->db->prepare('SELECT DATEDIFF(CURRENT_DATE, DATE(MIN(created_at))) FROM accounts WHERE user_id=?');
        $s->execute([$userId]);
        $ageDays = max(0, (int)$s->fetchColumn());
        $f[] = ['factor' => 'Account age', 'detail' => $ageDays . ' days', 'points' => min(60, (int)($ageDays / 30 * 2))];

        // 2. Transaction history (last 90 days)
        $s = $this->db->prepare('SELECT COUNT(*), COALESCE(SUM(t.amount),0) FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id JOIN accounts a ON a.id=le.account_id WHERE a.user_id=? AND t.status="completed" AND t.created_at>=CURRENT_DATE - INTERVAL 90 DAY');
        $s->execute([$userId]);
        [$cnt, $vol] = $s->fetch(PDO::FETCH_NUM);
        $f[] = ['factor' => 'Transaction activity (90d)', 'detail' => (int)$cnt . ' tx', 'points' => min(60, (int)$cnt * 2)];
        $f[] = ['factor' => 'Turnover (90d)', 'detail' => '$' . number_format((float)$vol, 2), 'points' => min(40, (int)round((float)$vol / 5000))];

        // 3. Average balance (last 30 days)
        $s = $this->db->prepare('SELECT COALESCE(AVG(a.available_balance),0) FROM accounts a WHERE a.user_id=? AND a.status="active"');
        $s->execute([$userId]);
        $avgBal = (float)$s->fetchColumn();
        $f[] = ['factor' => 'Average balance', 'detail' => '$' . number_format($avgBal, 2), 'points' => min(50, (int)round($avgBal / 2000))];

        // 4. Loan history
        $s = $this->db->prepare('SELECT COALESCE(SUM(status="completed"),0), COALESCE(SUM(status="defaulted"),0), COALESCE(SUM(status="active"),0) FROM loans WHERE user_id=?');
        $s->execute([$userId]);
        [$completed, $defaulted, $active] = $s->fetch(PDO::FETCH_NUM);
        $f[] = ['factor' => 'Completed loans', 'detail' => (int)$completed, 'points' => min(60, (int)$completed * 30)];
        $f[] = ['factor' => 'Defaults', 'detail' => (int)$defaulted, 'points' => -80 * (int)$defaulted];
        if ((int)$active > 0) $f[] = ['factor' => 'Active loan (on track)', 'detail' => (int)$active, 'points' => 10];

        // 5. Late installments
        $s = $this->db->prepare('SELECT COUNT(*) FROM loan_schedule ls JOIN loans l ON l.id=ls.loan_id WHERE l.user_id=? AND ls.status="late"');
        $s->execute([$userId]);
        $f[] = ['factor' => 'Late payments', 'detail' => (int)$s->fetchColumn(), 'points' => -15 * (int)$s->fetchColumn()];

        // 6. Failed payments (180 days)
        $s = $this->db->prepare('SELECT COUNT(*) FROM transactions t WHERE t.initiated_by=? AND t.status="failed" AND t.created_at>=CURRENT_DATE - INTERVAL 180 DAY');
        $s->execute([$userId]);
        $failed = (int)$s->fetchColumn();
        $f[] = ['factor' => 'Failed payments (180d)', 'detail' => $failed, 'points' => -min(60, $failed * 10)];

        // 7. Risk alerts
        $s = $this->db->prepare('SELECT COUNT(*) FROM risk_alerts WHERE user_id=? AND status IN ("open","reviewing")');
        $s->execute([$userId]);
        $alerts = (int)$s->fetchColumn();
        $f[] = ['factor' => 'Open risk alerts', 'detail' => $alerts, 'points' => -min(60, $alerts * 20)];

        // 8. KYC
        $s = $this->db->prepare('SELECT status FROM customer_kyc WHERE user_id=? LIMIT 1');
        $s->execute([$userId]);
        $kyc = (string)$s->fetchColumn();
        $f[] = ['factor' => 'KYC', 'detail' => $kyc !== '' ? $kyc : 'not submitted', 'points' => $kyc === 'verified' ? 20 : 0];

        // base
        array_unshift($f, ['factor' => 'Base', 'detail' => '—', 'points' => 620]);
        return $f;
    }
}

/**
 * Customer lifecycle stage, derived from data (plus admin overrides).
 */
final class LifecycleService
{
    public const STAGES = ['registered', 'kyc_pending', 'kyc_approved', 'active', 'dormant', 'restricted', 'closed'];

    public function __construct(private PDO $db) {}

    public function stage(int $userId): array
    {
        $s = $this->db->prepare('SELECT u.id, u.lifecycle_override, u.created_at, (SELECT status FROM customer_kyc k WHERE k.user_id=u.id LIMIT 1) kyc_status FROM users u WHERE u.id=? LIMIT 1');
        $s->execute([$userId]);
        $u = $s->fetch();
        if (!$u) return ['stage' => 'registered', 'label' => 'Registered', 'override' => ''];

        if ($u['lifecycle_override'] === 'restricted') return ['stage' => 'restricted', 'label' => 'Restricted', 'override' => 'restricted'];
        if ($u['lifecycle_override'] === 'closed') return ['stage' => 'closed', 'label' => 'Closed', 'override' => 'closed'];

        $s = $this->db->prepare('SELECT COUNT(*) FROM accounts WHERE user_id=?');
        $s->execute([$userId]);
        $hasAccount = (int)$s->fetchColumn() > 0;

        $s = $this->db->prepare('SELECT MAX(t.created_at) FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id JOIN accounts a ON a.id=le.account_id WHERE a.user_id=?');
        $s->execute([$userId]);
        $lastTx = $s->fetchColumn();
        $activeDays = $lastTx ? (int)floor((time() - strtotime((string)$lastTx)) / 86400) : null;

        if ($u['kyc_status'] !== 'verified') {
            return ['stage' => $u['kyc_status'] ? 'kyc_pending' : 'registered', 'label' => $u['kyc_status'] ? 'KYC Pending' : 'Registered', 'override' => ''];
        }
        if ($hasAccount && $activeDays !== null && $activeDays > 90) return ['stage' => 'dormant', 'label' => 'Dormant', 'override' => ''];
        if ($hasAccount) return ['stage' => 'active', 'label' => 'Active Customer', 'override' => ''];
        return ['stage' => 'kyc_approved', 'label' => 'KYC Approved', 'override' => ''];
    }

    public function setOverride(int $userId, string $override, int $adminId): void
    {
        if (!in_array($override, ['', 'restricted', 'closed'], true)) throw new InvalidArgumentException('Invalid lifecycle override.');
        $this->db->prepare('UPDATE users SET lifecycle_override=? WHERE id=?')->execute([$override, $userId]);
        $this->db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,ip_address,details) VALUES(?,?,?,?,?,?)')
            ->execute([$adminId, 'lifecycle_override_set', 'user', $userId, $_SERVER['REMOTE_ADDR'] ?? null, json_encode(['override' => $override], JSON_THROW_ON_ERROR)]);
    }
}
