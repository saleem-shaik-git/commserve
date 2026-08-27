<?php
declare(strict_types=1);

require_once __DIR__ . '/SecurityService.php';

class TransferService
{
    /** Number of sequential OTP stages a transfer must pass before admin release. */
    public const OTP_STAGES = 4;
    private const STAGE_LABELS = [1 => 'Identity verification', 2 => 'Amount verification', 3 => 'Beneficiary verification', 4 => 'Final authorization'];

    private SecurityService $security;

    public function __construct(private PDO $db)
    {
        $this->security = new SecurityService($db);
    }

    public static function stageLabel(int $stage): string
    {
        return self::STAGE_LABELS[$stage] ?? ('Stage ' . $stage);
    }

    /**
     * Initiate a transfer - creates pending transaction + OTP
     * Returns ['reference'=>string, 'otp'=>string, 'expires_at'=>string]
     */
    public function initiate(int $fromAccountId, int $toAccountId, string $amount, string $description, int $userId, string $pin, ?string $idempotencyKey = null): array
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount) || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        if ($fromAccountId === $toAccountId) throw new RuntimeException('Source and destination must differ.');

        // Idempotency check
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) throw new InvalidArgumentException('Invalid idempotency key.');
            $stmt = $this->db->prepare('SELECT reference FROM transactions WHERE idempotency_key=? LIMIT 1');
            $stmt->execute([$idempotencyKey]);
            $existingRef = $stmt->fetchColumn();
            if ($existingRef) {
                // If pending, return its OTP if still valid
                $tx = $this->getTransactionByRef((string)$existingRef);
                if ($tx && $tx['status'] === 'pending') {
                    $otpRow = $this->latestOtp((int)$tx['id']);
                    if ($otpRow && strtotime($otpRow['expires_at']) > time()) {
                        // Return existing without regenerating
                        return ['reference' => (string)$existingRef, 'otp' => '*** existing pending - check confirmation page ***', 'expires_at' => $otpRow['expires_at'], 'is_existing' => true];
                    }
                }
                return ['reference' => (string)$existingRef, 'otp' => '', 'expires_at' => '', 'is_existing' => true];
            }
        }

        // Verify PIN first
        $this->security->verifyTransactionPin($userId, $pin);

        $this->db->beginTransaction();
        try {
            // Lock accounts in consistent order to avoid deadlock
            $ids = [$fromAccountId, $toAccountId];
            sort($ids, SORT_NUMERIC);
            $first = $this->lockAccount($ids[0]);
            $second = $this->lockAccount($ids[1]);
            $from = $fromAccountId === (int)$first['id'] ? $first : $second;
            $to   = $toAccountId === (int)$first['id'] ? $first : $second;

            if ((int)$from['user_id'] !== $userId) throw new RuntimeException('Source account does not belong to you.');
            if ($from['status'] !== 'active' || $to['status'] !== 'active') throw new RuntimeException('Both accounts must be active.');
            if ($from['currency'] !== $to['currency']) throw new RuntimeException('Currency mismatch between accounts.');

            // Limits and balance
            $this->security->assertTransferAllowed($userId, $amount, $from['currency']);
            $balance = $this->calculateBalance($fromAccountId);
            if ((float)$balance < (float)$amount) throw new RuntimeException('Insufficient funds. Available: ' . number_format((float)$balance, 2));

            // Create pending transaction
            $reference = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by,idempotency_key) VALUES(?, "transfer", "pending", ?, ?, ?, ?, ?)');
            $stmt->execute([$reference, $amount, $from['currency'], $description ?: 'Transfer', $userId, $idempotencyKey]);
            $transactionId = (int)$this->db->lastInsertId();

            // Store pending mapping
            try {
                $this->db->prepare('INSERT INTO pending_transfers(transaction_id,from_account_id,to_account_id) VALUES(?,?,?)')->execute([$transactionId, $fromAccountId, $toAccountId]);
            } catch (PDOException $e) {
                // If table not yet migrated, ignore but continue
                if (!str_contains($e->getMessage(), 'pending_transfers')) throw $e;
            }

            // Generate OTP (stage 1 of the four-stage verification chain)
            $otp = (string)random_int(100000, 999999);
            $hash = hash('sha256', $otp);
            $stmt = $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,stage,otp_hash,expires_at) VALUES(?,?,1,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
            $stmt->execute([$transactionId, $userId, $hash]);
            $stmt = $this->db->prepare('SELECT expires_at FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$transactionId]);
            $expires = $stmt->fetchColumn() ?: date('Y-m-d H:i:s', time() + 600);

            $this->recordEvent($transactionId, 'transfer_initiated', null, 'pending', $userId, ['from' => $fromAccountId, 'to' => $toAccountId, 'amount' => $amount]);

            $this->db->commit();
            return ['reference' => $reference, 'otp' => $otp, 'expires_at' => $expires, 'is_existing' => false];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($idempotencyKey && $e instanceof PDOException && $e->getCode() === '23000' && str_contains(strtolower($e->getMessage()), 'idempotency')) {
                $stmt = $this->db->prepare('SELECT reference FROM transactions WHERE idempotency_key=?');
                $stmt->execute([$idempotencyKey]);
                $ref = $stmt->fetchColumn();
                if ($ref) return ['reference' => (string)$ref, 'otp' => '', 'expires_at' => '', 'is_existing' => true];
            }
            throw $e;
        }
    }

    /**
     * Confirm a pending transfer with the OTP for the current stage.
     *
     * Stages 1-3 verified  ->  issues the OTP for the next stage and returns
     *                         ['completed'=>false, 'stage'=>n, 'next_stage'=>n+1, 'otp'=>demo OTP].
     * Stage 4 verified     ->  transaction becomes 'awaiting_approval', an
     *                         admin_action_requests row is raised for admin release,
     *                         and ['completed'=>true] is returned.
     */
    public function confirm(string $reference, int $userId, string $otp): array
    {
        $otp = trim($otp);
        if ($otp === '') throw new InvalidArgumentException('OTP is required.');

        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ((int)$tx['initiated_by'] !== $userId) throw new RuntimeException('Transaction does not belong to you.');
            if ($tx['status'] === 'awaiting_approval') throw new RuntimeException('Transfer already passed all verification stages and is awaiting admin approval.');
            if ($tx['status'] === 'completed') throw new RuntimeException('Transfer has already been completed.');
            if ($tx['status'] !== 'pending') throw new RuntimeException('Transaction is not pending. Current status: ' . $tx['status']);

            // Get pending mapping
            $fromAccountId = null;
            $toAccountId = null;
            try {
                $stmt = $this->db->prepare('SELECT from_account_id,to_account_id FROM pending_transfers WHERE transaction_id=? FOR UPDATE');
                $stmt->execute([(int)$tx['id']]);
                $map = $stmt->fetch();
                if ($map) { $fromAccountId = (int)$map['from_account_id']; $toAccountId = (int)$map['to_account_id']; }
            } catch (PDOException $e) {
                // ignore if table missing
            }

            if (!$fromAccountId || !$toAccountId) {
                throw new RuntimeException('Transfer details not found. Please re-initiate.');
            }

            // Current-stage OTP challenge
            $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? AND user_id=? AND verified_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([(int)$tx['id'], $userId]);
            $challenge = $stmt->fetch();
            if (!$challenge) throw new RuntimeException('OTP challenge not found. Please request a new transfer.');
            if (strtotime($challenge['expires_at']) < time()) throw new RuntimeException('OTP expired. Please resend the OTP for stage ' . ($challenge['stage'] ?? 1) . '.');
            if ((int)$challenge['attempts'] >= 5) throw new RuntimeException('Too many OTP attempts. Transfer locked.');

            $stage = max(1, (int)($challenge['stage'] ?? 1));

            if (!hash_equals($challenge['otp_hash'], hash('sha256', $otp))) {
                $this->db->prepare('UPDATE transaction_otp_challenges SET attempts=attempts+1 WHERE id=?')->execute([$challenge['id']]);
                throw new RuntimeException('Invalid OTP for stage ' . $stage . ' (' . self::stageLabel($stage) . ').');
            }

            // Mark this stage verified
            $this->db->prepare('UPDATE transaction_otp_challenges SET verified_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$challenge['id']]);
            $this->recordEvent((int)$tx['id'], 'otp_stage_verified', 'pending', 'pending', $userId, ['stage' => $stage, 'label' => self::stageLabel($stage)]);

            if ($stage < self::OTP_STAGES) {
                // Issue the next stage OTP
                $next = $stage + 1;
                $newOtp = (string)random_int(100000, 999999);
                $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,stage,otp_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
                    ->execute([(int)$tx['id'], $userId, $next, hash('sha256', $newOtp)]);
                $stmt = $this->db->prepare('SELECT expires_at FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
                $stmt->execute([(int)$tx['id']]);
                $expires = (string)$stmt->fetchColumn();
                $this->recordEvent((int)$tx['id'], 'otp_stage_issued', 'pending', 'pending', $userId, ['stage' => $next, 'label' => self::stageLabel($next)]);
                $this->db->commit();
                return ['completed' => false, 'reference' => $reference, 'stage' => $stage, 'next_stage' => $next,
                        'next_label' => self::stageLabel($next), 'otp' => $newOtp, 'expires_at' => $expires];
            }

            // Final stage verified: hand the transfer to admin approval.
            $balance = $this->calculateBalance($fromAccountId);
            if ((float)$balance < (float)$tx['amount']) throw new RuntimeException('Insufficient funds at confirmation time.');
            $this->db->prepare('UPDATE transactions SET status="awaiting_approval" WHERE id=?')->execute([(int)$tx['id']]);
            $this->db->prepare('INSERT INTO admin_action_requests(action_type,entity_type,entity_id,requested_by,reason) VALUES(?,?,?,?,?)')
                ->execute(['transfer_approval', 'transaction', (int)$tx['id'], $userId,
                           'Customer completed 4-stage OTP verification for transfer ' . $tx['reference'] . ' (' . $tx['amount'] . ' ' . $tx['currency'] . ')']);
            $this->recordEvent((int)$tx['id'], 'submitted_for_approval', 'pending', 'awaiting_approval', $userId);
            $this->db->commit();
            return ['completed' => true, 'awaiting_approval' => true, 'reference' => $reference, 'stage' => $stage];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel(string $reference, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ((int)$tx['initiated_by'] !== $userId) throw new RuntimeException('Not yours.');
            if ($tx['status'] !== 'pending') throw new RuntimeException('Only pending can be cancelled.');
            $this->db->prepare('UPDATE transactions SET status="cancelled" WHERE id=?')->execute([(int)$tx['id']]);
            $this->recordEvent((int)$tx['id'], 'transfer_cancelled', 'pending', 'cancelled', $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getDetails(string $reference, int $userId): array
    {
        $stmt = $this->db->prepare('SELECT t.*, pt.from_account_id, pt.to_account_id, fa.account_number AS from_account_number, ta.account_number AS to_account_number, fa.user_id AS from_user_id, ta.user_id AS to_user_id, fat.name AS from_type, tat.name AS to_type, fau.first_name AS from_first, fau.last_name AS from_last, tau.first_name AS to_first, tau.last_name AS to_last FROM transactions t LEFT JOIN pending_transfers pt ON pt.transaction_id=t.id LEFT JOIN accounts fa ON fa.id=pt.from_account_id LEFT JOIN accounts ta ON ta.id=pt.to_account_id LEFT JOIN account_types fat ON fat.id=fa.account_type_id LEFT JOIN account_types tat ON tat.id=ta.account_type_id LEFT JOIN users fau ON fau.id=fa.user_id LEFT JOIN users tau ON tau.id=ta.user_id WHERE t.reference=? LIMIT 1');
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Transfer not found.');
        if ((int)$row['initiated_by'] !== $userId) throw new RuntimeException('Access denied.');
        // If completed, also fetch ledger to get accounts if pending mapping missing
        if (empty($row['from_account_id'])) {
            $stmt = $this->db->prepare('SELECT le.account_id, le.entry_type, a.account_number, at.name AS type_name, u.first_name, u.last_name FROM ledger_entries le JOIN accounts a ON a.id=le.account_id JOIN account_types at ON at.id=a.account_type_id JOIN users u ON u.id=a.user_id WHERE le.transaction_id=? ORDER BY le.id');
            $stmt->execute([(int)$row['id']]);
            $entries = $stmt->fetchAll();
            foreach ($entries as $e) {
                if ($e['entry_type'] === 'debit') {
                    $row['from_account_id'] = $e['account_id'];
                    $row['from_account_number'] = $e['account_number'];
                    $row['from_type'] = $e['type_name'];
                    $row['from_first'] = $e['first_name'];
                    $row['from_last'] = $e['last_name'];
                } else {
                    $row['to_account_id'] = $e['account_id'];
                    $row['to_account_number'] = $e['account_number'];
                    $row['to_type'] = $e['type_name'];
                    $row['to_first'] = $e['first_name'];
                    $row['to_last'] = $e['last_name'];
                }
            }
        }
        // OTP info
        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int)$row['id']]);
        $row['otp_challenge'] = $stmt->fetch() ?: null;
        $row['otp_stages_total'] = self::OTP_STAGES;
        $row['otp_stage'] = max(1, (int)($row['otp_challenge']['stage'] ?? 1));
        $row['otp_stage_label'] = self::stageLabel($row['otp_stage']);

        $stmt = $this->db->prepare('SELECT * FROM transaction_events WHERE transaction_id=? ORDER BY id ASC');
        $stmt->execute([(int)$row['id']]);
        $row['events'] = $stmt->fetchAll();

        return $row;
    }

    public function requestNewOtp(string $reference, int $userId): string
    {
        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ((int)$tx['initiated_by'] !== $userId) throw new RuntimeException('Not yours.');
            if ($tx['status'] !== 'pending') throw new RuntimeException('Only pending can get new OTP.');
            $stage = 1;
            $stmt = $this->db->prepare('SELECT stage FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$tx['id']]);
            $found = $stmt->fetchColumn();
            if ($found !== false && $found !== null) $stage = max(1, (int)$found);
            $otp = (string)random_int(100000, 999999);
            $hash = hash('sha256', $otp);
            $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,stage,otp_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([(int)$tx['id'], $userId, $stage, $hash]);
            $this->db->commit();
            return $otp;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT a.*, at.currency, at.name AS type_name FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=? FOR UPDATE');
        $stmt->execute([$accountId]);
        $acc = $stmt->fetch();
        if (!$acc) throw new RuntimeException('Account not found: ' . $accountId);
        return $acc;
    }

    private function lockTransaction(string $reference): array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE reference=? FOR UPDATE');
        $stmt->execute([$reference]);
        $tx = $stmt->fetch();
        if (!$tx) throw new RuntimeException('Transaction not found.');
        return $tx;
    }

    private function getTransactionByRef(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE reference=?');
        $stmt->execute([$reference]);
        return $stmt->fetch() ?: null;
    }

    private function latestOtp(int $transactionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$transactionId]);
        return $stmt->fetch() ?: null;
    }

    private function calculateBalance(int $accountId): string
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');
        $stmt->execute([$accountId]);
        return number_format((float)$stmt->fetchColumn(), 4, '.', '');
    }

    private function recalculateBalance(int $accountId): void
    {
        $balance = $this->calculateBalance($accountId);
        $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$balance, $accountId]);
    }

    private function recordEvent(int $transactionId, string $type, ?string $old, ?string $new, ?int $actor, array $meta = []): void
    {
        $this->db->prepare('INSERT INTO transaction_events(transaction_id,event_type,old_status,new_status,actor_user_id,metadata) VALUES(?,?,?,?,?,?)')
            ->execute([$transactionId, $type, $old, $new, $actor, $meta ? json_encode($meta, JSON_THROW_ON_ERROR) : null]);
    }
}
