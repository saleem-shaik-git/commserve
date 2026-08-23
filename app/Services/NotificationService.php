<?php

declare(strict_types=1);

final class NotificationService
{
    public function __construct(private PDO $db) {}

    public function notify(int $userId,string $eventType,array $data=[],?string $entityType=null,?int $entityId=null):array
    {
        $templates=$this->templates($eventType);
        $created=[];
        foreach($templates as $t){
            if(!$this->enabled($userId,$eventType,$t['channel'])) continue;
            $title=$this->render($t['title_template'],$data);
            $message=$this->render($t['body_template'],$data);
            $stmt=$this->db->prepare('INSERT INTO notifications(user_id,channel,event_type,title,message,entity_type,entity_id) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$userId,$t['channel'],$eventType,$title,$message,$entityType,$entityId]);
            $created[]=(int)$this->db->lastInsertId();
        }
        return $created;
    }

    public function unread(int $userId,int $limit=50):array
    {
        $limit=max(1,min(100,$limit));
        $stmt=$this->db->prepare('SELECT * FROM notifications WHERE user_id=? AND status IN (\'queued\',\'sent\') ORDER BY created_at DESC LIMIT '.$limit);
        $stmt->execute([$userId]); return $stmt->fetchAll();
    }
    public function countUnread(int $userId):int{$stmt=$this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status IN ('queued','sent')");$stmt->execute([$userId]);return (int)$stmt->fetchColumn();}
    public function markRead(int $userId,int $id):void{$this->db->prepare("UPDATE notifications SET status='read',read_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND status IN ('queued','sent')")->execute([$id,$userId]);}
    public function markAllRead(int $userId):void{$this->db->prepare("UPDATE notifications SET status='read',read_at=CURRENT_TIMESTAMP WHERE user_id=? AND status IN ('queued','sent')")->execute([$userId]);}
    public function preferences(int $userId):array{$stmt=$this->db->prepare('SELECT * FROM notification_preferences WHERE user_id=? ORDER BY event_type');$stmt->execute([$userId]);return $stmt->fetchAll();}
    public function setPreference(int $userId,string $eventType,bool $inApp,bool $email,bool $sms):void{$stmt=$this->db->prepare('INSERT INTO notification_preferences(user_id,event_type,in_app_enabled,email_enabled,sms_enabled) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE in_app_enabled=VALUES(in_app_enabled),email_enabled=VALUES(email_enabled),sms_enabled=VALUES(sms_enabled)');$stmt->execute([$userId,$eventType,$inApp?1:0,$email?1:0,$sms?1:0]);}
    public function processQueue(int $limit=100):int{$limit=max(1,min(500,$limit));$stmt=$this->db->query("SELECT * FROM notifications WHERE status='queued' AND available_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT ".$limit);$rows=$stmt->fetchAll();$count=0;foreach($rows as $n){try{$this->deliver($n);$this->db->prepare("UPDATE notifications SET status='sent',sent_at=CURRENT_TIMESTAMP,attempts=attempts+1,last_error=NULL WHERE id=? AND status='queued'")->execute([$n['id']]);$count++;}catch(Throwable $e){$this->db->prepare("UPDATE notifications SET attempts=attempts+1,last_error=?,available_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 5 MINUTE),status=IF(attempts+1>=5,'failed','queued') WHERE id=?")->execute([substr($e->getMessage(),0,500),$n['id']]);}}return $count;}

    private function templates(string $event):array{$stmt=$this->db->prepare('SELECT * FROM notification_templates WHERE event_type=? AND active=1');$stmt->execute([$event]);return $stmt->fetchAll();}
    private function enabled(int $user,string $event,string $channel):bool{$column=['in_app'=>'in_app_enabled','email'=>'email_enabled','sms'=>'sms_enabled'][$channel]??'in_app_enabled';$stmt=$this->db->prepare('SELECT '.$column.' FROM notification_preferences WHERE user_id=? AND event_type=?');$stmt->execute([$user,$event]);$v=$stmt->fetchColumn();return $v===false||$v===null?true:(bool)$v;}
    private function render(string $template,array $data):string{foreach($data as $k=>$v)$template=str_replace('{{'.$k.'}}',(string)$v,$template);return $template;}
    private function deliver(array $n):void{
        if($n['channel']==='in_app') return;
        if($n['channel']==='email'){
            $stmt=$this->db->prepare('SELECT email FROM users WHERE id=?');$stmt->execute([(int)$n['user_id']]);$email=$stmt->fetchColumn();if(!$email)throw new RuntimeException('No email address for notification recipient.');
            $from=env('MAIL_FROM','no-reply@commserve.local');$headers="From: ".$from."\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            if(!@mail($email,$n['title'],$n['message'],$headers)) throw new RuntimeException('Email delivery failed.');
            return;
        }
        if($n['channel']==='sms') throw new RuntimeException('SMS provider is not configured in demo mode.');
    }
}
