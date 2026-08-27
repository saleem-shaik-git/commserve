<?php

declare(strict_types=1);

final class BeneficiaryService
{
    public function __construct(private PDO $db) {}

    public function create(int $userId, string $name, string $accountNumber, string $bankName='CommServe Bank', ?string $nickname=null, ?string $routingNumber=null): int
    {
        $accountNumber=trim($accountNumber);$requestedName=trim($name);$bankName=trim($bankName);$nickname=$nickname!==null?trim($nickname):null;
        $routingNumber=$routingNumber!==null?preg_replace('/[^A-Za-z0-9-]/','',trim($routingNumber)):'';
        if($routingNumber!==''&&!preg_match('/^[A-Za-z0-9-]{4,20}$/',$routingNumber))throw new InvalidArgumentException('Routing number must be 4-20 letters/digits.');
        if(!preg_match('/^\d{10}$/',$accountNumber))throw new InvalidArgumentException('Account number must contain 10 digits.');
        if($requestedName===''||mb_strlen($requestedName)>160)throw new InvalidArgumentException('Beneficiary name is required.');
        if($bankName==='')$bankName='CommServe Bank';
        $stmt=$this->db->prepare('SELECT a.id,a.user_id,a.account_number,a.status,u.first_name,u.last_name FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.account_number=? AND a.status="active" LIMIT 1');$stmt->execute([$accountNumber]);$account=$stmt->fetch();
        if(!$account)throw new RuntimeException('Destination account does not exist or is not active.');
        if((int)$account['user_id']===$userId)throw new RuntimeException('You cannot add your own account as a beneficiary.');
        $stmt=$this->db->prepare('SELECT id,status FROM beneficiaries WHERE user_id=? AND account_number=? LIMIT 1');$stmt->execute([$userId,$accountNumber]);$existing=$stmt->fetch();
        if($existing){if($existing['status']==='disabled'){ $stmt=$this->db->prepare('UPDATE beneficiaries SET status="active",name=?,bank_name=?,nickname=?,routing_number=? WHERE id=? AND user_id=?');$stmt->execute([$account['first_name'].' '.$account['last_name'],$bankName,$nickname?:$requestedName,$routingNumber!==''?$routingNumber:null,$existing['id'],$userId]);$this->audit($userId,'beneficiary_reactivated','beneficiary',(int)$existing['id'],['account_number'=>$accountNumber]);return (int)$existing['id'];}throw new RuntimeException('This beneficiary already exists.');}
        $verifiedName=trim($account['first_name'].' '.$account['last_name']);
        $displayNickname=$nickname!==null&&$nickname!==''?$nickname:$requestedName;
        $stmt=$this->db->prepare('INSERT INTO beneficiaries(user_id,name,account_number,routing_number,bank_name,nickname,status) VALUES(?,?,?,?,?,?,"active")');$stmt->execute([$userId,$verifiedName,$accountNumber,$routingNumber!==''?$routingNumber:null,$bankName,$displayNickname]);$id=(int)$this->db->lastInsertId();
        $this->audit($userId,'beneficiary_created','beneficiary',$id,['account_number'=>$accountNumber,'verified_name'=>$verifiedName]);return $id;
    }

    public function list(int $userId):array{$stmt=$this->db->prepare('SELECT b.*,a.status account_status,u.first_name,u.last_name FROM beneficiaries b LEFT JOIN accounts a ON a.account_number=b.account_number LEFT JOIN users u ON u.id=a.user_id WHERE b.user_id=? ORDER BY b.id DESC');$stmt->execute([$userId]);return $stmt->fetchAll();}
    public function disable(int $userId,int $id):void{$this->changeStatus($userId,$id,'disabled');}
    public function enable(int $userId,int $id):void{$this->changeStatus($userId,$id,'active');}
    public function delete(int $userId,int $id):void{$stmt=$this->db->prepare('SELECT account_number FROM beneficiaries WHERE id=? AND user_id=?');$stmt->execute([$id,$userId]);$account=$stmt->fetchColumn();if(!$account)throw new RuntimeException('Beneficiary not found.');$stmt=$this->db->prepare('DELETE FROM beneficiaries WHERE id=? AND user_id=?');$stmt->execute([$id,$userId]);$this->audit($userId,'beneficiary_deleted','beneficiary',$id,['account_number'=>$account]);}
    public function activeOwned(int $userId,int $id):array{$stmt=$this->db->prepare('SELECT b.*,a.id account_id,a.status account_status,u.first_name,u.last_name FROM beneficiaries b LEFT JOIN accounts a ON a.account_number=b.account_number LEFT JOIN users u ON u.id=a.user_id WHERE b.id=? AND b.user_id=? AND b.status="active"');$stmt->execute([$id,$userId]);$b=$stmt->fetch();if(!$b)throw new RuntimeException('Active beneficiary not found.');if(!$b['account_id']||$b['account_status']!=='active')throw new RuntimeException('Beneficiary account is no longer active.');return $b;}
    private function changeStatus(int $userId,int $id,string $status):void{$stmt=$this->db->prepare('UPDATE beneficiaries SET status=? WHERE id=? AND user_id=?');$stmt->execute([$status,$id,$userId]);if($stmt->rowCount()===0)throw new RuntimeException('Beneficiary not found or unchanged.');$this->audit($userId,'beneficiary_'.$status,'beneficiary',$id,['status'=>$status]);}
    private function audit(int $userId,string $action,string $entityType,int $entityId,array $details):void{$this->db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,ip_address,details) VALUES(?,?,?,?,?,?)')->execute([$userId,$action,$entityType,$entityId,$_SERVER['REMOTE_ADDR']??null,json_encode($details,JSON_THROW_ON_ERROR)]);}
}
