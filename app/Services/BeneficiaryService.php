<?php

declare(strict_types=1);

final class BeneficiaryService
{
    public function __construct(private PDO $db) {}

    public function create(int $userId, string $name, string $accountNumber, string $bankName): int
    {
        if (!preg_match('/^\d{10}$/', $accountNumber)) throw new InvalidArgumentException('Account number must contain 10 digits.');
        $stmt=$this->db->prepare('SELECT id FROM accounts WHERE account_number=? AND status="active"');$stmt->execute([$accountNumber]);
        if(!$stmt->fetchColumn()) throw new RuntimeException('Destination account does not exist.');
        $stmt=$this->db->prepare('INSERT INTO beneficiaries(user_id,name,account_number,bank_name) VALUES(?,?,?,?)');$stmt->execute([$userId,trim($name),$accountNumber,trim($bankName)]);
        return (int)$this->db->lastInsertId();
    }
    public function list(int $userId):array{$stmt=$this->db->prepare('SELECT * FROM beneficiaries WHERE user_id=? ORDER BY id DESC');$stmt->execute([$userId]);return $stmt->fetchAll();}
    public function disable(int $userId,int $id):void{$stmt=$this->db->prepare('UPDATE beneficiaries SET status="disabled" WHERE id=? AND user_id=?');$stmt->execute([$id,$userId]);if($stmt->rowCount()===0)throw new RuntimeException('Beneficiary not found.');}
    public function activeOwned(int $userId,int $id):array{$stmt=$this->db->prepare('SELECT * FROM beneficiaries WHERE id=? AND user_id=? AND status="active"');$stmt->execute([$id,$userId]);$b=$stmt->fetch();if(!$b)throw new RuntimeException('Beneficiary not found.');return $b;}
}
