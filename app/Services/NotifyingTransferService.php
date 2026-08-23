<?php

declare(strict_types=1);
require_once __DIR__ . '/TransferService.php';
require_once __DIR__ . '/NotificationService.php';

final class NotifyingTransferService extends TransferService
{
    private NotificationService $notifications;
    public function __construct(PDO $db){parent::__construct($db);$this->notifications=new NotificationService($db);}
    public function confirm(string $reference,int $userId,string $otp):string{$ref=parent::confirm($reference,$userId,$otp);$this->notifications->notify($userId,'transfer_completed',['amount'=>$this->amount($ref),'recipient'=>$this->recipient($ref,$userId),'reference'=>$ref],'transaction',$this->transactionId($ref));return $ref;}
    public function cancel(string $reference,int $userId):void{parent::cancel($reference,$userId);$this->notifications->notify($userId,'transfer_failed',['amount'=>$this->amount($reference),'recipient'=>'transfer','reference'=>$reference],'transaction',$this->transactionId($reference));}
    private function amount(string $ref):string{$s=$this->db()->prepare('SELECT amount FROM transactions WHERE reference=?');$s->execute([$ref]);return (string)$s->fetchColumn();}
    private function recipient(string $ref,int $userId):string{$s=$this->db()->prepare('SELECT a.account_number FROM transactions t JOIN pending_transfers pt ON pt.transaction_id=t.id JOIN accounts a ON a.id=pt.to_account_id WHERE t.reference=? AND t.initiated_by=?');$s->execute([$ref,$userId]);return (string)($s->fetchColumn()?:'beneficiary');}
    private function transactionId(string $ref):?int{$s=$this->db()->prepare('SELECT id FROM transactions WHERE reference=?');$s->execute([$ref]);$v=$s->fetchColumn();return $v===false?null:(int)$v;}
    private function db():PDO{$r=(new ReflectionClass(TransferService::class))->getParentClass();$p=$r;while($p&&$p->getName()!==TransferService::class)$p=$p->getParentClass();$prop=(new ReflectionClass(TransferService::class))->getProperty('db');$prop->setAccessible(true);return $prop->getValue($this);}
}
