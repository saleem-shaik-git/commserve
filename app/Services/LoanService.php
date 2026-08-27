<?php
declare(strict_types=1);
require_once __DIR__ . '/SecurityService.php';
require_once __DIR__ . '/CreditService.php';

/**
 * Simulated lending engine: application -> KYC gate -> risk assessment
 * (credit score) -> decision (auto/admin) -> disbursement -> amortized
 * schedule -> repayments (ledger-backed) -> late/default processing.
 */
final class LoanService
{
    public function __construct(private PDO $db) {}

    /** Amortized monthly payment. */
    public static function monthlyPayment(float $principal, float $annualRate, int $months): float
    {
        if ($months <= 0) throw new InvalidArgumentException('Invalid tenor.');
        $r = $annualRate / 100.0 / 12.0;
        if ($r == 0.0) return round($principal / $months, 2);
        return round($principal * $r / (1 - pow(1 + $r, -$months)), 2);
    }

    public function loanProducts(): array
    {
        return $this->db->query('SELECT * FROM loan_products WHERE status="active" ORDER BY id')->fetchAll();
    }

    /**
     * Submit an application. KYC must be verified; the credit score drives
     * the initial decision: >=700 auto-approve, 600-699 manual review,
     * <600 reject. Returns the application row.
     */
    public function apply(int $userId, int $loanProductId, ?int $accountId, string $amount, int $tenor, string $purpose): array
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        $amount = number_format((float)$amount, 2, '.', '');
        $s = $this->db->prepare('SELECT * FROM loan_products WHERE id=? AND status="active" LIMIT 1');
        $s->execute([$loanProductId]);
        $product = $s->fetch();
        if (!$product) throw new RuntimeException('Loan product not found.');
        if ((float)$amount < (float)$product['min_amount'] || (float)$amount > (float)$product['max_amount']) throw new RuntimeException('Amount must be between ' . number_format((float)$product['min_amount'], 2) . ' and ' . number_format((float)$product['max_amount'], 2) . '.');
        if ($tenor < (int)$product['min_tenor_months'] || $tenor > (int)$product['max_tenor_months']) throw new RuntimeException('Tenor must be between ' . (int)$product['min_tenor_months'] . ' and ' . (int)$product['max_tenor_months'] . ' months.');
        if ($accountId) {
            $s = $this->db->prepare('SELECT id FROM accounts WHERE id=? AND user_id=? AND status="active" LIMIT 1');
            $s->execute([$accountId, $userId]);
            if (!$s->fetchColumn()) throw new RuntimeException('Invalid disbursement account.');
        }

        $kyc = $this->db->prepare('SELECT status FROM customer_kyc WHERE user_id=? LIMIT 1');
        $kyc->execute([$userId]);
        if ((string)$kyc->fetchColumn() !== 'verified') throw new RuntimeException('Your KYC must be verified before applying for a loan.');

        $credit = (new CreditService($this->db))->score($userId);
        $score = $credit['score'];
        if ($score >= 700) { $status = 'approved'; $reason = 'Auto-approved: credit score ' . $score . ' (Excellent/Good).'; }
        elseif ($score >= 600) { $status = 'under_review'; $reason = 'Manual review required: credit score ' . $score . '.'; }
        else { $status = 'rejected'; $reason = 'Rejected: credit score ' . $score . ' (High Risk).'; }

        $this->db->prepare('INSERT INTO loan_applications(user_id,loan_product_id,account_id,amount,tenor_months,purpose,status,credit_score,decision_reason) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $loanProductId, $accountId, $amount, $tenor, mb_substr(trim($purpose), 0, 255), $status, $score, $reason]);
        $id = (int)$this->db->lastInsertId();
        return $this->application($id);
    }

    public function application(int $id): array
    {
        $s = $this->db->prepare('SELECT a.*, p.name product_name, p.annual_rate, u.email FROM loan_applications a JOIN loan_products p ON p.id=a.loan_product_id JOIN users u ON u.id=a.user_id WHERE a.id=? LIMIT 1');
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException('Application not found.');
        return $row;
    }

    public function applicationsForUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT a.*, p.name product_name, p.annual_rate FROM loan_applications a JOIN loan_products p ON p.id=a.loan_product_id WHERE a.user_id=? ORDER BY a.id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public function pendingApplications(): array
    {
        return $this->db->query('SELECT a.*, p.name product_name, p.annual_rate, u.email FROM loan_applications a JOIN loan_products p ON p.id=a.loan_product_id JOIN users u ON u.id=a.user_id WHERE a.status IN ("pending","under_review","approved") ORDER BY a.id DESC LIMIT 200')->fetchAll();
    }

    /** Admin decision (approve/reject), with maker-style audit. */
    public function decide(int $applicationId, int $adminId, bool $approve, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('A decision reason is required.');
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT * FROM loan_applications WHERE id=? FOR UPDATE');
            $s->execute([$applicationId]);
            $app = $s->fetch();
            if (!$app) throw new RuntimeException('Application not found.');
            if (!in_array($app['status'], ['pending', 'under_review'], true)) throw new RuntimeException('Application is not awaiting a decision.');
            $this->db->prepare('UPDATE loan_applications SET status=?, decision_reason=?, decided_by=?, decided_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$approve ? 'approved' : 'rejected', mb_substr($reason, 0, 255), $adminId, $applicationId]);
            $this->audit($adminId, $approve ? 'loan_application_approved' : 'loan_application_rejected', 'loan_application', $applicationId, ['reason' => $reason]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Disburse an approved application: creates the loan + schedule and credits the account. */
    public function disburse(int $applicationId, int $adminId): array
    {
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT a.* FROM loan_applications a WHERE a.id=? FOR UPDATE');
            $s->execute([$applicationId]);
            $app = $s->fetch();
            if (!$app) throw new RuntimeException('Application not found.');
            if ($app['status'] !== 'approved') throw new RuntimeException('Only approved applications can be disbursed.');
            $accountId = (int)($app['account_id'] ?? 0);
            if (!$accountId) throw new RuntimeException('The customer has not selected a disbursement account.');
            $account = $this->lockAccount($accountId, (int)$app['user_id']);

            $principal = (float)$app['amount'];
            $rate = (float)$app['annual_rate'];
            $tenor = (int)$app['tenor_months'];
            $payment = self::monthlyPayment($principal, $rate, $tenor);

            // Build the amortized schedule.
            $outstanding = $principal; $totalInterest = 0.0; $rows = [];
            $r = $rate / 100.0 / 12.0;
            for ($i = 1; $i <= $tenor; $i++) {
                $interest = round($outstanding * $r, 2);
                $principalPart = $i === $tenor ? round($outstanding, 2) : round($payment - $interest, 2);
                $total = round($principalPart + $interest, 2);
                $rows[] = [$i, date('Y-m-d', strtotime('+' . $i . ' months')), number_format($principalPart, 2, '.', ''), number_format($interest, 2, '.', ''), number_format($total, 2, '.', '')];
                $outstanding -= $principalPart;
                $totalInterest += $interest;
            }
            if (abs($outstanding) > 0.01) { // rounding correction on the final installment
                $last = &$rows[count($rows) - 1];
                $last[2] = number_format((float)$last[2] + $outstanding, 2, '.', '');
                $last[4] = number_format((float)$last[2] + (float)$last[3], 2, '.', '');
                $outstanding = 0.0;
                unset($last);
            }

            $ref = 'LOAN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"loan_disbursement","processing",?,?,?,?)')
                ->execute([$ref, number_format($principal, 2, '.', ''), $account['currency'], 'Loan disbursement ' . $app['id'], (int)$app['user_id']]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$txId, $accountId, number_format($principal, 2, '.', '')]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($accountId), $accountId]);

            $this->db->prepare('INSERT INTO loans(reference,application_id,user_id,account_id,loan_product_id,principal,annual_rate,tenor_months,monthly_payment,total_interest,outstanding_principal,status,next_due_date,start_date) VALUES(?,?,?,?,?,?,?,?,?,?,"active",?,CURRENT_DATE())')
                ->execute([$ref, $applicationId, (int)$app['user_id'], $accountId, (int)$app['loan_product_id'], number_format($principal, 2, '.', ''), $app['annual_rate'], $tenor, number_format($payment, 2, '.', ''), number_format($totalInterest, 2, '.', ''), number_format($principal, 2, '.', ''), $rows[0][1]]);
            $loanId = (int)$this->db->lastInsertId();
            $ins = $this->db->prepare('INSERT INTO loan_schedule(loan_id,installment_no,due_date,principal_due,interest_due,total_due) VALUES(?,?,?,?,?,?)');
            foreach ($rows as $row) $ins->execute(array_merge([$loanId], $row));

            $this->db->prepare('UPDATE loan_applications SET status="disbursed" WHERE id=?')->execute([$applicationId]);
            $this->audit($adminId, 'loan_disbursed', 'loan', $loanId, ['application' => $applicationId, 'reference' => $ref]);
            $this->db->commit();
            return ['loan_id' => $loanId, 'reference' => $ref, 'monthly_payment' => $payment, 'next_due' => $rows[0][1]];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function loansForUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT l.*, p.name product_name, a.account_number FROM loans l JOIN loan_products p ON p.id=l.loan_product_id JOIN accounts a ON a.id=l.account_id WHERE l.user_id=? ORDER BY l.id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public function portfolio(): array
    {
        return $this->db->query('SELECT l.*, p.name product_name, u.email, a.account_number FROM loans l JOIN loan_products p ON p.id=l.loan_product_id JOIN users u ON u.id=l.user_id JOIN accounts a ON a.id=l.account_id ORDER BY l.id DESC LIMIT 300')->fetchAll();
    }

    public function loan(int $loanId, ?int $userId = null): array
    {
        $s = $this->db->prepare('SELECT * FROM loans WHERE id=? LIMIT 1');
        $s->execute([$loanId]);
        $l = $s->fetch();
        if (!$l) throw new RuntimeException('Loan not found.');
        if ($userId !== null && (int)$l['user_id'] !== $userId) throw new RuntimeException('Loan does not belong to you.');
        return $l;
    }

    public function schedule(int $loanId): array
    {
        $s = $this->db->prepare('SELECT * FROM loan_schedule WHERE loan_id=? ORDER BY installment_no');
        $s->execute([$loanId]);
        return $s->fetchAll();
    }

    /**
     * Repay from the customer's account. Interest is settled first, then
     * principal, oldest installment first. Extra amounts prepay principal.
     */
    public function repay(int $userId, int $loanId, int $accountId, string $amount, string $pin): array
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) || (float)$amount <= 0) throw new InvalidArgumentException('Invalid amount.');
        $amount = number_format((float)$amount, 2, '.', '');
        (new SecurityService($this->db))->verifyTransactionPin($userId, $pin);

        $this->db->beginTransaction();
        try {
            $loan = $this->loan($loanId, $userId);
            if ($loan['status'] !== 'active') throw new RuntimeException('This loan is not active.');
            $account = $this->lockAccount($accountId, $userId);
            $balance = $this->ledgerBalance($accountId);
            if ((float)$balance < (float)$amount) throw new RuntimeException('Insufficient account balance. Available: ' . number_format((float)$balance, 2));

            // Allocate the payment across due installments (interest first, then principal).
            $s = $this->db->prepare('SELECT * FROM loan_schedule WHERE loan_id=? AND status IN ("pending","partial","late") ORDER BY installment_no FOR UPDATE');
            $s->execute([$loanId]);
            $installments = $s->fetchAll();
            $remaining = (float)$amount; $principalPart = 0.0; $interestPart = 0.0;
            foreach ($installments as $inst) {
                if ($remaining <= 0) break;
                $outstandingInst = round((float)$inst['total_due'] - (float)$inst['paid_amount'], 2);
                if ($outstandingInst <= 0) continue;
                // interest portion of what is still owed on this installment
                $interestOwed = max(0.0, min($remaining, round((float)$inst['interest_due'] - min((float)$inst['paid_amount'], (float)$inst['interest_due']), 2)));
                $apply = min($remaining, $outstandingInst);
                $interestPart += $interestOwed;
                $principalPart += round($apply - $interestOwed, 2);
                $remaining = round($remaining - $apply, 2);
                $paid = round((float)$inst['paid_amount'] + $apply, 2);
                $status = $paid >= (float)$inst['total_due'] - 0.005 ? 'paid' : 'partial';
                $this->db->prepare('UPDATE loan_schedule SET paid_amount=?, status=?, paid_at=IF(?="paid",CURRENT_TIMESTAMP,paid_at) WHERE id=?')
                    ->execute([number_format($paid, 2, '.', ''), $status, $status, $inst['id']]);
            }
            if ($remaining > 0) { // prepay remaining principal outright
                $principalPart += $remaining;
            }

            $ref = 'LRP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,"loan_repayment","processing",?,?,?,?)')
                ->execute([$ref, $amount, $account['currency'], 'Loan repayment ' . $loan['reference'], $userId]);
            $txId = (int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"debit",?)')->execute([$txId, $accountId, $amount]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$txId]);
            $this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$this->ledgerBalance($accountId), $accountId]);

            $this->db->prepare('INSERT INTO loan_repayments(reference,loan_id,user_id,amount,principal_part,interest_part,transaction_id) VALUES(?,?,?,?,?,?,?)')
                ->execute([$ref, $loanId, $userId, $amount, number_format($principalPart, 2, '.', ''), number_format($interestPart, 2, '.', ''), $txId]);

            $newOutstanding = round(max(0.0, (float)$loan['outstanding_principal'] - $principalPart), 2);
            $s = $this->db->prepare('SELECT MIN(due_date) FROM loan_schedule WHERE loan_id=? AND status IN ("pending","partial","late")');
            $s->execute([$loanId]);
            $nextDue = $s->fetchColumn() ?: null;
            $completed = $newOutstanding <= 0.005;
            $this->db->prepare('UPDATE loans SET outstanding_principal=?, status=?, next_due_date=?, completed_at=IF(?,CURRENT_TIMESTAMP,completed_at) WHERE id=?')
                ->execute([number_format($newOutstanding, 2, '.', ''), $completed ? 'completed' : 'active', $nextDue, $completed ? 1 : 0, $loanId]);
            if ($completed) $this->db->prepare('UPDATE loan_schedule SET status="paid", paid_at=COALESCE(paid_at,CURRENT_TIMESTAMP) WHERE loan_id=? AND status IN ("partial","late")')->execute([$loanId]);

            $this->db->commit();
            (new CreditService($this->db))->score($userId); // refresh score
            return ['reference' => $ref, 'amount' => $amount, 'principal_part' => number_format($principalPart, 2, '.', ''), 'interest_part' => number_format($interestPart, 2, '.', ''), 'outstanding' => number_format($newOutstanding, 2, '.', ''), 'completed' => $completed];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Mark overdue installments late; 3+ late -> loan defaulted. */
    public function processLate(): array
    {
        $this->db->exec('UPDATE loan_schedule ls JOIN loans l ON l.id=ls.loan_id SET ls.status="late" WHERE l.status="active" AND ls.status IN ("pending","partial") AND ls.due_date < CURRENT_DATE');
        $this->db->exec('UPDATE loans l SET l.late_count=(SELECT COUNT(*) FROM loan_schedule ls WHERE ls.loan_id=l.id AND ls.status="late") WHERE l.status="active"');
        $s = $this->db->prepare('UPDATE loans SET status="defaulted" WHERE status="active" AND late_count>=3');
        $s->execute();
        return ['defaulted' => $s->rowCount()];
    }

    public function repaymentsForUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT r.*, l.reference loan_reference FROM loan_repayments r JOIN loans l ON l.id=r.loan_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 100');
        $s->execute([$userId]);
        return $s->fetchAll();
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
