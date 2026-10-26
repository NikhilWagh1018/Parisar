<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  repositories/NotificationRepository.php
//  In-app notifications (migration 017). Every method is scoped to
//  one user, so nobody can read or change another user's notifications.
// ═══════════════════════════════════════════════════════════════

class NotificationRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(int $userId, string $type, string $title, string $body, ?string $link = null): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $type, mb_substr($title, 0, 150), mb_substr($body, 0, 500), $link]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Newest unread notifications first.
     *  @return list<array{id:int,type:string,title:string,body:string,link:?string,created_at:string}> */
    public function unread(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, type, title, body, link, created_at FROM notifications
             WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT ' . $limit
        );
        $st->execute([$userId]);
        return array_map(static fn(array $r): array => [
            'id'         => (int)$r['id'],
            'type'       => (string)$r['type'],
            'title'      => (string)$r['title'],
            'body'       => (string)$r['body'],
            'link'       => $r['link'] === null ? null : (string)$r['link'],
            'created_at' => (string)$r['created_at'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unreadCount(int $userId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    /** Marks every unread notification of this user as read; returns how many changed. */
    public function markAllRead(int $userId): int
    {
        $st = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return $st->rowCount();
    }
}
