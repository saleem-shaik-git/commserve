<?php

declare(strict_types=1);

final class FundingService
{
    public function __construct(private PDO $db) {}

    public function fund(int $accountId,string $amount,string $description='Account funding',?int $actorUserId=null,?string $idempotencyKey=null):string
    {
        if(!preg_match('/^\d+(\.\d{1,4})?$/',$amount) || (float)$amount<=0) throw new InvalidArgumentException('Funding amount must be greater than zero.');
        $this->db->beginTransaction();
        try {
            $account=$this->lockAccount($accountId);
            if($account['status']!=='active') throw new RuntimeException('Account is not active.');
            if($idempotencyKey!==null){$stmt=$this->db->prepare('SELECT reference FROM transactions WHERE idempotency_key=? FOR UPDATE');$stmt->execute([$idempotencyKey]);$existing=$stmt->fetchColumn();if($existing){$this->db->rollBack();return (string)$existing;}}
            $reference='FUND-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
            $stmt=$this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by,idempotency_key) VALUES(?,?,"processing",?,?,?,?,?)');
            $stmt->execute([$reference,'deposit',$amount,$account['currency'],$description,$actorUserId,$idempotencyKey]);
            $transactionId=(int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$transactionId,$accountId,$amount]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$transactionId]);
            $this->recalculateBalance($accountId);
            $this->db->prepare('INSERT INTO account_balance_snapshots(account_id,balance) SELECT id,available_balance FROM accounts WHERE id=?')->execute([$accountId]);
            $this->db->commit();return $reference;
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($idempotencyKey!==null && $e instanceof PDOException && $e->getCode()==='23000'){$stmt=$this->db->prepare('SELECT reference FROM transactions WHERE idempotency_key=?');$stmt->execute([$idempotencyKey]);$existing=$stmt->fetchColumn();if($existing)return (string)$existing;}
            throw $e;
        }
    }

    public function openingBalance(int $accountId,string $amount,?int $actorUserId=null):string
    {
        $stmt=$this->db->prepare('SELECT COUNT(*) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.type="opening_balance"');$stmt->execute([$accountId]);
        if((int)$stmt->fetchColumn()>0) throw new RuntimeException('An opening balance already exists for this account. Use account funding for additional funds.');
        return $this->createMovement($accountId,$amount,'opening_balance','Opening balance', $actorUserId);
    }

    private function createMovement(int $accountId,string $amount,string $type,string $description,?int $actorUserId):string
    {
        if(!preg_match('/^\d+(\.\d{1,4})?$/',$amount)||(float)$amount<=0)throw new InvalidArgumentException('Opening balance must be greater than zero.');
        $this->db->beginTransaction();
        try{$account=$this->lockAccount($accountId);if($account['status']!=='active')throw new RuntimeException('Account is not active.');$reference='OPEN-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));$stmt=$this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,initiated_by) VALUES(?,?,"processing",?,?,?,?,?)');$stmt->execute([$reference,$type,$amount,$account['currency'],$description,$actorUserId]);$id=(int)$this->db->lastInsertId();$this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,"credit",?)')->execute([$id,$accountId,$amount]);$this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);$this->recalculateBalance($accountId);$this->db->prepare('INSERT INTO account_balance_snapshots(account_id,balance) SELECT id,available_balance FROM accounts WHERE id=?')->execute([$accountId]);$this->db->commit();return $reference;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function lockAccount(int $accountId):array{$stmt=$this->db->prepare('SELECT a.*,at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.id=? FOR UPDATE');$stmt->execute([$accountId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Account not found.');return $row;}
    private function recalculateBalance(int $accountId):void{$stmt=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');$stmt->execute([$accountId]);$balance=number_format((float)$stmt->fetchColumn(),4,'.','');$this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$balance,$accountId]);}
}
