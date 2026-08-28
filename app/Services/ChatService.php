<?php
declare(strict_types=1);

/**
 * Customer <-> administrator live chat.
 * Threads are opened by customers; both sides post messages. Message rows
 * carry separate read flags so each side can count its unread messages.
 */
final class ChatService
{
    public function __construct(private PDO $db) {}

    /**
     * Every customer has exactly ONE conversation with the admin team.
     * Returns its id, creating the thread on first contact.
     */
    public function ensureThread(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM chat_threads WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        $this->db->prepare('INSERT INTO chat_threads(user_id,subject,status,last_message_at) VALUES (?,\'\',\'open\',CURRENT_TIMESTAMP)')->execute([$userId]);
        return (int)$this->db->lastInsertId();
    }

    public function sendCustomer(int $userId, string $body): int
    {
        $threadId = $this->ensureThread($userId);
        $id = $this->insert($threadId, $userId, 'customer', $body);
        // A customer reply always reopens the conversation with the team.
        $this->db->prepare('UPDATE chat_threads SET status="open" WHERE id=? AND status="closed"')->execute([$threadId]);
        return $id;
    }

    public function sendAdmin(int $adminId, int $threadId, string $body): int
    {
        $stmt = $this->db->prepare('SELECT id FROM chat_threads WHERE id=? LIMIT 1');
        $stmt->execute([$threadId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Conversation not found.');
        return $this->insert($threadId, $adminId, 'admin', $body);
    }

    /** Messages of a thread (optionally only those after $afterId). Marks them read for the viewer. */
    public function messages(int $threadId, string $viewer, int $afterId = 0, bool $markRead = true): array
    {
        $column = $viewer === 'admin' ? 'read_by_admin' : 'read_by_customer';
        $stmt = $this->db->prepare("SELECT m.*,u.first_name,u.last_name,u.email FROM chat_messages m LEFT JOIN users u ON u.id=m.sender_user_id WHERE m.thread_id=? AND m.id>? ORDER BY m.id ASC LIMIT 500");
        $stmt->execute([$threadId, $afterId]);
        $rows = $stmt->fetchAll();
        if ($markRead && $rows) {
            $upd = $this->db->prepare("UPDATE chat_messages SET $column=1 WHERE thread_id=? AND $column=0");
            $upd->execute([$threadId]);
        }
        return $rows;
    }

    public function thread(int $threadId): ?array
    {
        $stmt = $this->db->prepare('SELECT t.*,u.email,u.first_name,u.last_name,(SELECT body FROM chat_messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) last_message FROM chat_threads t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1');
        $stmt->execute([$threadId]);
        return $stmt->fetch() ?: null;
    }

    /** All threads for the admin console. */
    public function threadsForAdmin(): array
    {
        return $this->db->query('SELECT t.*,u.email,u.first_name,u.last_name,(SELECT body FROM chat_messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) last_message,(SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id=t.id AND m.sender_role="customer" AND m.read_by_admin=0) unread FROM chat_threads t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.last_message_at DESC LIMIT 200')->fetchAll();
    }

    public function setStatus(int $threadId, string $status, bool $byAdmin, int $userId): void
    {
        if (!in_array($status, ['open', 'closed'], true)) throw new InvalidArgumentException('Invalid conversation status.');
        if ($byAdmin) {
            $stmt = $this->db->prepare('UPDATE chat_threads SET status=? WHERE id=?');
            $stmt->execute([$status, $threadId]);
            return;
        }
        $stmt = $this->db->prepare('UPDATE chat_threads SET status=? WHERE id=? AND user_id=?');
        $stmt->execute([$status, $threadId, $userId]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Conversation not found.');
    }

    /** Unread admin replies for a customer (sidebar badge). */
    public function unreadForCustomer(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chat_messages m JOIN chat_threads t ON t.id=m.thread_id WHERE t.user_id=? AND m.sender_role="admin" AND m.read_by_customer=0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Unread customer messages across open threads (admin badge). */
    public function unreadForAdmin(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM chat_messages m JOIN chat_threads t ON t.id=m.thread_id WHERE m.sender_role="customer" AND m.read_by_admin=0');
        return (int)$stmt->fetchColumn();
    }

    private function insert(int $threadId, int $senderId, string $role, string $body): int
    {
        $body = trim($body);
        if ($body === '') throw new InvalidArgumentException('Message is required.');
        if (mb_strlen($body) > 4000) $body = mb_substr($body, 0, 4000);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO chat_messages(thread_id,sender_user_id,sender_role,body,read_by_customer,read_by_admin) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$threadId, $senderId, $role, $body, $role === 'customer' ? 1 : 0, $role === 'admin' ? 1 : 0]);
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE chat_threads SET last_message_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$threadId]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function assertThread(int $threadId, int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM chat_threads WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$threadId, $userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Conversation not found.');
    }
}
