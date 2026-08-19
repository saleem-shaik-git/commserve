<?php

declare(strict_types=1);

final class AdminOperationsService
{
    public function __construct(private PDO $db) {}

    public function transactionSearch(string $q='', string $status='', int $limit=100): array
    {
        $limit=max(1,min(200,$limit));$where=[];$params=[];
        if($q!==''){ $where[]='(t.reference LIKE ? OR t.description LIKE ? OR u.email LIKE ? OR a.account_number LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like); }
        if($status!==''){ $where[]='t.status=?';$params[]=$status; }
        $sql='SELECT t.*,u.email,a.account_number FROM transactions t LEFT JOIN users u ON u.id=t.initiated_by LEFT JOIN ledger_entries le ON le.transaction_id=t.id LEFT JOIN accounts a ON a.id=le.account_id'.($where?' WHERE '.implode(' AND ',$where):'').' GROUP BY t.id ORDER BY t.id DESC LIMIT '.$limit;
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
    }

    public function customerSearch(string $q='',int $limit=100):array{$limit=max(1,min(200,$limit));$stmt=$this->db->prepare('SELECT u.id,u.first_name,u.last_name,u.email,u.status,r.name role,COUNT(DISTINCT a.id) accounts,COALESCE(SUM(a.available_balance),0) balance FROM users u LEFT JOIN roles r ON r.id=u.role_id LEFT JOIN accounts a ON a.user_id=u.id WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?) GROUP BY u.id ORDER BY u.id DESC LIMIT '.$limit);$like='%'.$q.'%';$stmt->execute([$like,$like,$like]);return $stmt->fetchAll();}

    public function setCustomerStatus(int $userId,string $status):void{if(!in_array($status,['active','suspended','locked'],true))throw new InvalidArgumentException('Invalid customer status.');$stmt=$this->db->prepare('UPDATE users SET status=? WHERE id=? AND role_id=(SELECT id FROM roles WHERE name="customer")');$stmt->execute([$status,$userId]);if($stmt->rowCount()===0)throw new RuntimeException('Customer not found or unchanged.');}

    public function setAccountStatus(int $accountId,string $status):void{if(!in_array($status,['active','blocked','closed'],true))throw new InvalidArgumentException('Invalid account status.');$stmt=$this->db->prepare('UPDATE accounts SET status=? WHERE id=?');$stmt->execute([$status,$accountId]);if($stmt->rowCount()===0)throw new RuntimeException('Account not found or unchanged.');}

    public function latestReconciliation():?array{$stmt=$this->db->query('SELECT * FROM reconciliation_runs ORDER BY id DESC LIMIT 1');return $stmt->fetch()?:null;}
    public function recentAuditEvents(int $limit=100):array{$limit=max(1,min(200,$limit));$stmt=$this->db->query('SELECT te.*,t.reference,u.email FROM transaction_events te JOIN transactions t ON t.id=te.transaction_id LEFT JOIN users u ON u.id=te.actor_user_id ORDER BY te.id DESC LIMIT '.$limit);return $stmt->fetchAll();}
}
