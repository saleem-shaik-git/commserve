<?php
declare(strict_types=1);

require_once __DIR__ . '/SecurityService.php';
require_once __DIR__ . '/MailerService.php';
require_once __DIR__ . '/SavingsProductService.php';

class TransferService
{
    /** Number of sequential OTP stages a transfer must pass. */
    public const OTP_STAGES = 4;
    private const STAGE_LABELS = [1 => 'COT verification', 2 => 'IMF verification', 3 => 'Tax Code verification', 4 => 'Final authorization'];

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
     * Initiate a transfer - creates pending transaction + stage 1 OTP.
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
            // Savings-product withdrawal rules (locked/restricted products).
            try { (new SavingsProductService($this->db))->assertWithdrawalAllowed($fromAccountId); } catch (PDOException $e) { if (!str_contains($e->getMessage(), 'savings_products')) throw $e; }
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
            $this->createChallenge($transactionId, $userId, 1, $otp, $hash);
            $stmt = $this->db->prepare('SELECT expires_at FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$transactionId]);
            $expires = $stmt->fetchColumn() ?: date('Y-m-d H:i:s', time() + 600);

            $this->recordEvent($transactionId, 'transfer_initiated', null, 'pending', $userId, ['from' => $fromAccountId, 'to' => $toAccountId, 'amount' => $amount]);

            $this->db->commit();
            $this->emailOtp($userId, 1, $otp, $reference);
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
     * Confirm a pending transfer: check the OTP for the current stage.
     *
     * The OTP is verified against its hash, then the stage submission is
     * queued for ADMIN approval - the transfer does not advance until an
     * administrator approves the stage (Admin -> Approvals):
     *   stages 1-3 approval -> next stage OTP is issued;
     *   stage 4 approval    -> transfer is released (ledger posted).
     *
     * Returns ['completed'=>false, 'submitted_for_approval'=>true,
     *           'stage'=>n, 'stage_label'=>string].
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

            // Current-stage OTP challenge (latest unresolved challenge)
            $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? AND user_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([(int)$tx['id'], $userId]);
            $challenge = $this->fetchChallenge($stmt);
            $stage = max(1, (int)($challenge['stage'] ?? 1));

            if (!empty($challenge['verified_at'])) {
                if (($challenge['admin_status'] ?? null) === 'pending') throw new RuntimeException('Stage ' . $stage . ' (' . self::stageLabel($stage) . ') is already verified and awaiting admin approval.');
                throw new RuntimeException('This stage has already been processed.');
            }
            if (strtotime($challenge['expires_at']) < time()) throw new RuntimeException('OTP expired. Please resend the OTP for stage ' . $stage . '.');
            if ((int)$challenge['attempts'] >= 5) throw new RuntimeException('Too many OTP attempts. Transfer locked.');

            if (!hash_equals($challenge['otp_hash'], hash('sha256', $otp))) {
                $this->db->prepare('UPDATE transaction_otp_challenges SET attempts=attempts+1 WHERE id=?')->execute([$challenge['id']]);
                throw new RuntimeException('Invalid OTP for stage ' . $stage . ' (' . self::stageLabel($stage) . ').');
            }

            // Stage passed by the customer -> queue it for admin approval.
            $this->db->prepare('UPDATE transaction_otp_challenges SET verified_at=CURRENT_TIMESTAMP, admin_status="pending" WHERE id=?')->execute([$challenge['id']]);
            $this->db->prepare('INSERT INTO admin_action_requests(action_type,entity_type,entity_id,requested_by,reason) VALUES(?,?,?,?,?)')
                ->execute(['otp_stage_approval', 'transaction', (int)$tx['id'], $userId,
                           'OTP stage ' . $stage . ' (' . self::stageLabel($stage) . ') verified by customer for transfer ' . $tx['reference'] . ' (' . $tx['amount'] . ' ' . $tx['currency'] . ')']);
            $this->recordEvent((int)$tx['id'], 'otp_stage_submitted', 'pending', 'pending', $userId, ['stage' => $stage, 'label' => self::stageLabel($stage), 'awaiting' => 'admin_approval']);

            $this->db->commit();
            return ['completed' => false, 'submitted_for_approval' => true, 'reference' => $reference,
                    'stage' => $stage, 'stage_label' => self::stageLabel($stage)];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Admin decision hook (called from AdminOperationsService inside its
     * transaction): approve a submitted OTP stage.
     *   stage < 4  -> issue the OTP for the next stage;
     *   stage = 4  -> set the transaction awaiting approval (the caller then
     *                 releases it on the ledger, still within the same
     *                 transaction, so funds move only on this approval).
     * Returns ['stage'=>n,'released'=>bool,'next_stage'=>?int,'next_otp'=>?string].
     */
    public function approveOtpStageLocked(int $transactionId, int $adminId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id=? FOR UPDATE');
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch();
        if (!$tx) throw new RuntimeException('Transfer transaction not found.');
        if ($tx['status'] !== 'pending') throw new RuntimeException('Transfer is no longer pending. Current status: ' . $tx['status'] . '.');

        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$transactionId]);
        $challenge = $this->fetchChallenge($stmt);
        $stage = max(1, (int)($challenge['stage'] ?? 1));
        if (empty($challenge['verified_at'])) throw new RuntimeException('This stage has not been verified by the customer yet.');
        if (($challenge['admin_status'] ?? null) !== 'pending') throw new RuntimeException('This stage submission is not awaiting approval.');

        $this->db->prepare('UPDATE transaction_otp_challenges SET admin_status="approved" WHERE id=?')->execute([$challenge['id']]);
        $this->recordEvent($transactionId, 'otp_stage_admin_approved', 'pending', 'pending', $adminId, ['stage' => $stage, 'label' => self::stageLabel($stage)]);

        if ($stage < self::OTP_STAGES) {
            // Issue the OTP for the next stage; the customer sees it on the confirmation page.
            $next = $stage + 1;
            $newOtp = (string)random_int(100000, 999999);
            $this->createChallenge($transactionId, (int)$tx['initiated_by'], $next, $newOtp, hash('sha256', $newOtp));
            $this->recordEvent($transactionId, 'otp_stage_issued', 'pending', 'pending', $adminId, ['stage' => $next, 'label' => self::stageLabel($next)]);
            $this->emailOtp((int)$tx['initiated_by'], $next, $newOtp, (string)$tx['reference']);
            return ['stage' => $stage, 'released' => false, 'next_stage' => $next, 'next_otp' => $newOtp, 'next_label' => self::stageLabel($next)];
        }

        // Final stage approved: hand the transfer to release (still inside the caller's transaction).
        $this->db->prepare('UPDATE transactions SET status="awaiting_approval" WHERE id=?')->execute([$transactionId]);
        $this->recordEvent($transactionId, 'submitted_for_approval', 'pending', 'awaiting_approval', $adminId);
        return ['stage' => $stage, 'released' => true];
    }

    /**
     * Admin decision hook: reject a submitted OTP stage - the transfer fails
     * with the given reason. Must run inside the caller's transaction.
     */
    public function rejectOtpStageLocked(int $transactionId, int $adminId, string $reason): void
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id=? FOR UPDATE');
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch();
        if (!$tx) throw new RuntimeException('Transfer transaction not found.');
        if ($tx['status'] !== 'pending') throw new RuntimeException('Transfer is no longer pending. Current status: ' . $tx['status'] . '.');

        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$transactionId]);
        $challenge = $this->fetchChallenge($stmt);
        $stage = max(1, (int)($challenge['stage'] ?? 1));

        $this->db->prepare('UPDATE transaction_otp_challenges SET admin_status="rejected" WHERE id=?')->execute([$challenge['id']]);
        $this->db->prepare('UPDATE transactions SET status="failed",failure_reason=? WHERE id=?')
            ->execute(['Rejected by admin at stage ' . $stage . ': ' . mb_substr($reason, 0, 160), $transactionId]);
        $this->recordEvent($transactionId, 'otp_stage_admin_rejected', 'pending', 'failed', $adminId, ['stage' => $stage, 'label' => self::stageLabel($stage), 'reason' => $reason]);
    }

    public function cancel(string $reference, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ((int)$tx['initiated_by'] !== $userId) throw new RuntimeException('Not yours.');
            if ($tx['status'] !== 'pending') throw new RuntimeException('Only pending can be cancelled.');
            $this->db->prepare('UPDATE transactions SET status="cancelled" WHERE id=?')->execute([(int)$tx['id']]);
            // Close any open OTP-stage approval request for this transfer.
            try {
                $this->db->prepare('UPDATE admin_action_requests SET status="cancelled",decision_reason="Customer cancelled the transfer",decided_at=CURRENT_TIMESTAMP WHERE action_type="otp_stage_approval" AND entity_type="transaction" AND entity_id=? AND status="pending"')
                    ->execute([(int)$tx['id']]);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'admin_action_requests')) throw $e;
            }
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
        // OTP info (latest challenge drives the current stage)
        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int)$row['id']]);
        $row['otp_challenge'] = $stmt->fetch() ?: null;
        $row['otp_stages_total'] = self::OTP_STAGES;
        $row['otp_stage'] = max(1, (int)($row['otp_challenge']['stage'] ?? 1));
        $row['otp_stage_label'] = self::stageLabel($row['otp_stage']);
        // A submitted stage is verified and queued for admin approval.
        $row['otp_stage_submitted'] = $row['otp_challenge'] && !empty($row['otp_challenge']['verified_at']) && ($row['otp_challenge']['admin_status'] ?? null) === 'pending';
        // Highest stage fully approved by an admin.
        $stmt = $this->db->prepare('SELECT MAX(stage) FROM transaction_otp_challenges WHERE transaction_id=? AND admin_status="approved"');
        $stmt->execute([(int)$row['id']]);
        $row['otp_stage_approved_count'] = max(0, (int)$stmt->fetchColumn());

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
            $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([(int)$tx['id']]);
            $challenge = $this->fetchChallenge($stmt);
            $stage = max(1, (int)($challenge['stage'] ?? 1));
            if (!empty($challenge['verified_at'])) {
                if (($challenge['admin_status'] ?? null) === 'pending') throw new RuntimeException('Stage ' . $stage . ' is awaiting admin approval. You cannot request a new OTP yet.');
                throw new RuntimeException('This stage has already been processed.');
            }
            $otp = (string)random_int(100000, 999999);
            $hash = hash('sha256', $otp);
            $this->createChallenge((int)$tx['id'], $userId, $stage, $otp, $hash);
            $this->db->commit();
            $this->emailOtp($userId, $stage, $otp, $reference);
            return $otp;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Email an OTP code to the customer (development mailer: log/mail/smtp). */
    private function emailOtp(int $userId, int $stage, string $otp, string $reference): void
    {
        try {
            $s = $this->db->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
            $s->execute([$userId]);
            $email = (string)$s->fetchColumn();
            if ($email === '') return;
            $label = self::stageLabel($stage);
            $format = function_exists('t') ? t('Your CommServe Bank OTP — Stage %s of %s (%s)') : 'Your CommServe Bank OTP - Stage %s of %s (%s)';
            $subject = sprintf($format, (string)$stage, (string)self::OTP_STAGES, $label);
            $body = "Your one-time password for transfer $reference is:

    $otp

"
                  . "Stage: $stage of " . self::OTP_STAGES . " ($label)
"
                  . "It expires in 10 minutes. Never share this code with anyone.

"
                  . APP_NAME . "
";
            MailerService::send($email, $subject, $body);
        } catch (Throwable $e) {
            error_log('CommServe OTP email: ' . $e->getMessage());
        }
    }

    /** Insert an OTP challenge row, tolerating databases upgraded before migration 019/020. */
    private function createChallenge(int $transactionId, int $userId, int $stage, string $otp, string $hash): void
    {
        try {
            // OTP codes are delivered by email only - no display copy is stored.
            $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,stage,otp_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
                ->execute([$transactionId, $userId, $stage, $hash]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'otp_code')) {
                // Column not yet applied (migration 020 pending): fall back without the display copy.
                $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,stage,otp_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
                    ->execute([$transactionId, $userId, $stage, $hash]);
                return;
            }
            if (str_contains($e->getMessage(), 'stage')) {
                // Pre-019 database: single-stage OTP.
                $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,otp_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
                    ->execute([$transactionId, $userId, $hash]);
                return;
            }
            throw $e;
        }
    }

    /** Fetch the single expected challenge row or fail with a clear error. */
    private function fetchChallenge(PDOStatement $stmt): array
    {
        $challenge = $stmt->fetch();
        if (!$challenge) throw new RuntimeException('OTP challenge not found. Please request a new transfer.');
        return $challenge;
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
