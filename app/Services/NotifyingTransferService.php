<?php

declare(strict_types=1);
require_once __DIR__ . '/TransferService.php';
require_once __DIR__ . '/NotificationService.php';
final class NotifyingTransferService extends TransferService
{
    private PDO $pdo; private NotificationService $notifications;
    public function __construct(PDO $db){parent::__construct($db);$this->pdo=$db;$this->notifications=new NotificationService($db);}
    public function confirm(string $reference,int $userId,string $otp):array{
        $result=parent::confirm($reference,$userId,$otp);
        if(!empty($result['completed'])){
            // All four OTP stages passed: the transfer now awaits admin release.
            $this->notifications->notify($userId,'transfer_pending_approval',[
                'amount'=>$this->amount($result['reference']),
                'recipient'=>$this->recipient($result['reference'],$userId),
                'reference'=>$result['reference'],
            ],'transaction',$this->transactionId($result['reference']));
        }
        return $result;
    }
    /** Notify + finalize hooks used by AdminOperationsService when an admin releases a transfer. */
    public function notifyApproved(string $reference,int $userId):void{$this->notifications->notify($userId,'transfer_completed',['amount'=>$this->amount($reference),'recipient'=>$this->recipient($reference,$userId),'reference'=>$reference],'transaction',$this->transactionId($reference));}
    public function notifyRejected(string $reference,int $userId):void{$this->notifications->notify($userId,'transfer_rejected',['amount'=>$this->amount($reference),'recipient'=>$this->recipient($reference,$userId),'reference'=>$reference],'transaction',$this->transactionId($reference));}
    public function cancel(string $reference,int $userId):void{parent::cancel($reference,$userId);$this->notifications->notify($userId,'transfer_failed',['amount'=>$this->amount($reference),'recipient'=>'transfer','reference'=>$reference],'transaction',$this->transactionId($reference));}
    private function amount(string $ref):string{$s=$this->pdo->prepare('SELECT amount FROM transactions WHERE reference=?');$s->execute([$ref]);return (string)$s->fetchColumn();}
    private function recipient(string $ref,int $userId):string{$s=$this->pdo->prepare('SELECT a.account_number FROM transactions t JOIN pending_transfers pt ON pt.transaction_id=t.id JOIN accounts a ON a.id=pt.to_account_id WHERE t.reference=? AND t.initiated_by=?');$s->execute([$ref,$userId]);return (string)($s->fetchColumn()?:'beneficiary');}
    private function transactionId(string $ref):?int{$s=$this->pdo->prepare('SELECT id FROM transactions WHERE reference=?');$s->execute([$ref]);$v=$s->fetchColumn();return $v===false?null:(int)$v;}
}
