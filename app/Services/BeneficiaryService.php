<?php

declare(strict_types=1);

final class BeneficiaryService
{
    public function __construct(private PDO $db) {}

    public function create(int $userId, string $name, string $accountNumber, string $bankName='CommServe Demo Bank', ?string $nickname=null): int
    {
        if (!preg_match('/^\d{10}$/', $accountNumber)) throw new InvalidArgumentException('Account number must contain 10 digits.');
        $stmt=$this->db->prepare('SELECT a.id,a.user_id,u.first_name,u.last_name FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.account_number=? AND a.status="active" LIMIT 1');$stmt->execute([$accountNumber]);$account=$stmt->fetch();
        if(!$account) throw new RuntimeException('Destination account does not exist or is not active.');
        if((int)$account['user_id']===$userId) throw new RuntimeException('You cannot add your own account as a beneficiary.');
        $stmt=$this->db->prepare('SELECT id FROM beneficiaries WHERE user_id=? AND account_number=? LIMIT 1');$stmt->execute([$userId,$accountNumber]);if($stmt->fetchColumn())throw new RuntimeException('This beneficiary already exists.');
        $stmt=$this->db->prepare('INSERT INTO beneficiaries(user_id,name,account_number,bank_name,nickname,status) VALUES(?,?,?,?,?,"active")');$stmt->execute([$userId,trim($name),$accountNumber,trim($bankName),$nickname!==null?trim($nickname):null]);
        return (int)$this->db->lastInsertId();
    }
    public function list(int $userId):array{$stmt=$this->db->prepare('SELECT b.*,a.status account_status,u.first_name,u.last_name FROM beneficiaries b LEFT JOIN accounts a ON a.account_number=b.account_number LEFT JOIN users u ON u.id=a.user_id WHERE b.user_id=? ORDER BY b.id DESC');$stmt->execute([$userId]);return $stmt->fetchAll();}
    public function disable(int $userId,int $id):void{$this->changeStatus($userId,$id,'disabled');}
    public function enable(int $userId,int $id):void{$this->changeStatus($userId,$id,'active');}
    public function delete(int $userId,int $id):void{$stmt=$this->db->prepare('DELETE FROM beneficiaries WHERE id=? AND user_id=?');$stmt->execute([$id,$userId]);if($stmt->rowCount()===0)throw new RuntimeException('Beneficiary not found.');}
    public function activeOwned(int $userId,int $id):array{$stmt=$this->db->prepare('SELECT * FROM beneficiaries WHERE id=? AND user_id=? AND status="active"');$stmt->execute([$id,$userId]);$b=$stmt->fetch();if(!$b)throw new RuntimeException('Active beneficiary not found.');return $b;}
    private function changeStatus(int $userId,int $id,string $status):void{$stmt=$this->db->prepare('UPDATE beneficiaries SET status=? WHERE id=? AND user_id=?');$stmt->execute([$status,$id,$userId]);if($stmt->rowCount()===0)throw new RuntimeException('Beneficiary not found or unchanged.');}
}
