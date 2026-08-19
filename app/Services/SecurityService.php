<?php

declare(strict_types=1);

final class SecurityService
{
    public function __construct(private PDO $db) {}

    public function setTransactionPin(int $userId, string $pin): void
    {
        $this->validatePin($pin);
        $stmt=$this->db->prepare('UPDATE users SET transaction_pin_hash=? WHERE id=?');
        $stmt->execute([password_hash($pin,PASSWORD_DEFAULT),$userId]);
    }

    public function verifyTransactionPin(int $userId, string $pin): void
    {
        $stmt=$this->db->prepare('SELECT transaction_pin_hash FROM users WHERE id=?');$stmt->execute([$userId]);$hash=$stmt->fetchColumn();
        if(!$hash || !password_verify($pin,(string)$hash)) throw new RuntimeException('Invalid transaction PIN.');
    }

    public function assertTransferAllowed(int $userId,string $amount,string $currency='NGN'): void
    {
        if(!preg_match('/^\d+(\.\d{1,4})?$/',$amount) || (float)$amount<=0) throw new InvalidArgumentException('Invalid transfer amount.');
        $stmt=$this->db->prepare('SELECT per_transaction_limit,daily_limit FROM transfer_limits WHERE (user_id=? OR user_id IS NULL) AND currency=? ORDER BY user_id IS NULL ASC LIMIT 1');$stmt->execute([$userId,$currency]);$limit=$stmt->fetch() ?: ['per_transaction_limit'=>1000000,'daily_limit'=>5000000];
        if((float)$amount>(float)$limit['per_transaction_limit']) throw new RuntimeException('Transfer exceeds the per-transaction limit.');
        $stmt=$this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE initiated_by=? AND type="transfer" AND status IN ("processing","completed") AND currency=? AND created_at>=CURRENT_DATE');$stmt->execute([$userId,$currency]);
        if((float)$stmt->fetchColumn()+(float)$amount>(float)$limit['daily_limit']) throw new RuntimeException('Transfer exceeds the daily limit.');
    }

    public function assertIdempotency(string $key): ?string
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{8,100}$/',$key)) throw new InvalidArgumentException('Invalid idempotency key.');
        $stmt=$this->db->prepare('SELECT reference FROM transactions WHERE idempotency_key=?');$stmt->execute([$key]);$reference=$stmt->fetchColumn();return $reference?:null;
    }

    private function validatePin(string $pin):void{if(!preg_match('/^\d{4,6}$/',$pin))throw new InvalidArgumentException('Transaction PIN must be 4 to 6 digits.');}
}
