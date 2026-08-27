<?php

declare(strict_types=1);
require_once __DIR__ . '/TransferService.php';
require_once __DIR__ . '/NotificationService.php';
final class NotifyingTransferService extends TransferService
{
    private PDO $pdo; private NotificationService $notifications;
    public function __construct(PDO $db){parent::__construct($db);$this->pdo=$db;$this->notifications=new NotificationService($db);}
    public function confirm(string $reference,int $userId,string $otp):array{
        // Each submitted stage is queued for admin approval; the customer is
        // notified when an administrator approves (next OTP ready) or rejects.
        return parent::confirm($reference,$userId,$otp);
    }
    /** Notify + finalize hooks used by AdminOperationsService when an admin releases a transfer. */
    public function notifyApproved(string $reference,int $userId):void{$this->notifications->notify($userId,'transfer_completed',['amount'=>$this->amount($reference),'recipient'=>$this->recipient($reference,$userId),'reference'=>$reference],'transaction',$this->transactionId($reference));}
    public function notifyRejected(string $reference,int $userId):void{$this->notifications->notify($userId,'transfer_rejected',['amount'=>$this->amount($reference),'recipient'=>$this->recipient($reference,$userId),'reference'=>$reference],'transaction',$this->transactionId($reference));}
    /** Admin approved OTP stage 1-3: tell the customer the next OTP is ready. */
    public function notifyStageApproved(string $reference,int $userId,int $stage,string $label,string $nextLabel):void{$this->notifications->notify($userId,'otp_stage_approved',['stage'=>$stage,'label'=>$label,'reference'=>$reference,'next_label'=>$nextLabel],'transaction',$this->transactionId($reference));}
    /** Admin rejected an OTP stage: tell the customer with the reason. */
    public function notifyStageRejected(string $reference,int $userId,int $stage,string $label,string $reason):void{$this->notifications->notify($userId,'otp_stage_rejected',['stage'=>$stage,'label'=>$label,'reference'=>$reference,'reason'=>$reason],'transaction',$this->transactionId($reference));}
    public function cancel(string $reference,int $userId):void{parent::cancel($reference,$userId);$this->notifications->notify($userId,'transfer_failed',['amount'=>$this->amount($reference),'recipient'=>'transfer','reference'=>$reference],'transaction',$this->transactionId($reference));}
    private function amount(string $ref):string{$s=$this->pdo->prepare('SELECT amount FROM transactions WHERE reference=?');$s->execute([$ref]);return (string)$s->fetchColumn();}
    private function recipient(string $ref,int $userId):string{$s=$this->pdo->prepare('SELECT a.account_number FROM transactions t JOIN pending_transfers pt ON pt.transaction_id=t.id JOIN accounts a ON a.id=pt.to_account_id WHERE t.reference=? AND t.initiated_by=?');$s->execute([$ref,$userId]);return (string)($s->fetchColumn()?:'beneficiary');}
    private function transactionId(string $ref):?int{$s=$this->pdo->prepare('SELECT id FROM transactions WHERE reference=?');$s->execute([$ref]);$v=$s->fetchColumn();return $v===false?null:(int)$v;}
}
