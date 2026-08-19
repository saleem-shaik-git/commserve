<?php

declare(strict_types=1);

final class TransactionService
{
    public function __construct(private PDO $db) {}

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE reference=?');
        $stmt->execute([$reference]);
        return $stmt->fetch() ?: null;
    }

    public function requestOtp(string $reference, int $userId): string
    {
        $tx = $this->ownedTransaction($reference, $userId);
        if (!in_array($tx['status'], ['pending','processing'], true)) throw new RuntimeException('Transaction is not eligible for OTP verification.');
        $otp = (string)random_int(100000, 999999);
        $hash = hash('sha256', $otp);
        $stmt = $this->db->prepare('INSERT INTO transaction_otp_challenges(transaction_id,user_id,otp_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
        $stmt->execute([$tx['id'], $userId, $hash]);
        return $otp;
    }

    public function verifyOtp(string $reference, int $userId, string $otp): void
    {
        $tx = $this->ownedTransaction($reference, $userId);
        $stmt = $this->db->prepare('SELECT * FROM transaction_otp_challenges WHERE transaction_id=? AND user_id=? AND verified_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$tx['id'], $userId]);
        $challenge = $stmt->fetch();
        if (!$challenge || strtotime($challenge['expires_at']) < time()) throw new RuntimeException('OTP expired or unavailable.');
        if ((int)$challenge['attempts'] >= 5) throw new RuntimeException('Too many OTP attempts.');
        if (!hash_equals($challenge['otp_hash'], hash('sha256', trim($otp)))) {
            $this->db->prepare('UPDATE transaction_otp_challenges SET attempts=attempts+1 WHERE id=?')->execute([$challenge['id']]);
            throw new RuntimeException('Invalid OTP.');
        }
        $this->db->prepare('UPDATE transaction_otp_challenges SET verified_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$challenge['id']]);
        $this->event((int)$tx['id'], 'otp_verified', $tx['status'], $tx['status'], $userId);
    }

    public function recordEvent(int $transactionId, string $eventType, ?string $oldStatus, ?string $newStatus, ?int $actorId = null, array $metadata = []): void
    {
        $stmt = $this->db->prepare('INSERT INTO transaction_events(transaction_id,event_type,old_status,new_status,actor_user_id,metadata) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$transactionId,$eventType,$oldStatus,$newStatus,$actorId,$metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null]);
    }

    public function reverse(string $reference, int $actorId): string
    {
        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ($tx['status'] !== 'completed') throw new RuntimeException('Only completed transactions can be reversed.');
            if ($tx['type'] === 'reversal') throw new RuntimeException('A reversal cannot be reversed.');
            $check = $this->db->prepare('SELECT id FROM transactions WHERE reversal_of_transaction_id=? AND status IN ("completed","processing") LIMIT 1 FOR UPDATE');
            $check->execute([$tx['id']]);
            if ($check->fetchColumn()) throw new RuntimeException('Transaction has already been reversed.');
            $entries = $this->entries((int)$tx['id']);
            if (!$entries) throw new RuntimeException('Transaction has no ledger entries.');
            $newRef = 'REV-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,reversal_of_transaction_id,initiated_by) VALUES(?,"reversal","processing",?,?,?, ?,?)');
            $stmt->execute([$newRef,$tx['amount'],$tx['currency'],'Reversal of '.$tx['reference'],$tx['id'],$actorId]);
            $newId = (int)$this->db->lastInsertId();
            foreach ($entries as $entry) {
                $reverseType = $entry['entry_type'] === 'debit' ? 'credit' : 'debit';
                $this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,?,?)')->execute([$newId,$entry['account_id'],$reverseType,$entry['amount']]);
            }
            $this->db->prepare('UPDATE transactions SET status="reversed" WHERE id=?')->execute([$tx['id']]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$newId]);
            foreach ($entries as $entry) $this->recalculateBalance((int)$entry['account_id']);
            $this->event((int)$tx['id'],'reversal_created','completed','reversed',$actorId,['reversal_reference'=>$newRef]);
            $this->event($newId,'reversal_completed','processing','completed',$actorId,['original_reference'=>$tx['reference']]);
            $this->db->commit();
            return $newRef;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function refund(string $reference, int $actorId): string
    {
        $this->db->beginTransaction();
        try {
            $tx = $this->lockTransaction($reference);
            if ($tx['status'] !== 'completed') throw new RuntimeException('Only completed transactions can be refunded.');
            if (!in_array($tx['type'], ['deposit','withdrawal','transfer'], true)) throw new RuntimeException('This transaction type cannot be refunded.');
            $check=$this->db->prepare('SELECT id FROM transactions WHERE refund_of_transaction_id=? LIMIT 1 FOR UPDATE'); $check->execute([$tx['id']]);
            if ($check->fetchColumn()) throw new RuntimeException('Transaction has already been refunded.');
            $entries=$this->entries((int)$tx['id']); if (!$entries) throw new RuntimeException('Transaction has no ledger entries.');
            $newRef='REF-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));
            $stmt=$this->db->prepare('INSERT INTO transactions(reference,type,status,amount,currency,description,refund_of_transaction_id,initiated_by) VALUES(?,"refund","processing",?,?,?, ?,?)');
            $stmt->execute([$newRef,$tx['amount'],$tx['currency'],'Refund of '.$tx['reference'],$tx['id'],$actorId]);
            $newId=(int)$this->db->lastInsertId();
            foreach($entries as $entry){$reverse=$entry['entry_type']==='debit'?'credit':'debit';$this->db->prepare('INSERT INTO ledger_entries(transaction_id,account_id,entry_type,amount) VALUES(?,?,?,?)')->execute([$newId,$entry['account_id'],$reverse,$entry['amount']]);}
            $this->db->prepare('UPDATE transactions SET status="refunded" WHERE id=?')->execute([$tx['id']]);
            $this->db->prepare('UPDATE transactions SET status="completed",completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$newId]);
            foreach($entries as $entry)$this->recalculateBalance((int)$entry['account_id']);
            $this->event((int)$tx['id'],'refund_created','completed','refunded',$actorId,['refund_reference'=>$newRef]);
            $this->event($newId,'refund_completed','processing','completed',$actorId,['original_reference'=>$tx['reference']]);
            $this->db->commit(); return $newRef;
        } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function ownedTransaction(string $reference,int $userId):array{
        $stmt=$this->db->prepare('SELECT DISTINCT t.* FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id JOIN accounts a ON a.id=le.account_id WHERE t.reference=? AND a.user_id=?');$stmt->execute([$reference,$userId]);$tx=$stmt->fetch();if(!$tx)throw new RuntimeException('Transaction not found.');return $tx;
    }
    private function lockTransaction(string $reference):array{$stmt=$this->db->prepare('SELECT * FROM transactions WHERE reference=? FOR UPDATE');$stmt->execute([$reference]);$tx=$stmt->fetch();if(!$tx)throw new RuntimeException('Transaction not found.');return $tx;}
    private function entries(int $transactionId):array{$stmt=$this->db->prepare('SELECT * FROM ledger_entries WHERE transaction_id=? ORDER BY id FOR UPDATE');$stmt->execute([$transactionId]);return $stmt->fetchAll();}
    private function recalculateBalance(int $accountId):void{$stmt=$this->db->prepare('SELECT COALESCE(SUM(CASE WHEN le.entry_type="credit" THEN le.amount ELSE -le.amount END),0) FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id WHERE le.account_id=? AND t.status="completed"');$stmt->execute([$accountId]);$balance=$stmt->fetchColumn();$this->db->prepare('UPDATE accounts SET available_balance=? WHERE id=?')->execute([$balance,$accountId]);}
    private function event(int $id,string $type,?string $old,?string $new,?int $actor,array $metadata=[]):void{$this->recordEvent($id,$type,$old,$new,$actor,$metadata);}
}
