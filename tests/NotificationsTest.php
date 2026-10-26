<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Notifications.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';

/** In-app notifications: the wording, and the repository on in-memory SQLite. */
class NotificationsTest extends TestCase
{
    private PDO $pdo;
    private NotificationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            type TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT \'\', link TEXT NULL,
            is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->repo = new NotificationRepository($this->pdo);
    }

    // ── wording ─────────────────────────────────────────────────

    public function test_whole_road_assignment_text_names_the_road(): void
    {
        $t = assignmentNotificationText('Pune 2026', 'active', 3, 'BANER ROAD');
        $this->assertSame('New assignment', $t['title']);
        $this->assertStringContainsString('3 segments of BANER ROAD', $t['body']);
        $this->assertStringContainsString('"Pune 2026"', $t['body']);
        $this->assertStringContainsString('start from your dashboard', $t['body']);
    }

    public function test_single_segment_text_is_singular_and_draft_audits_say_to_wait(): void
    {
        $t = assignmentNotificationText('Pune 2026', 'draft', 1, null);
        $this->assertStringContainsString('1 segment in the audit', $t['body']);
        $this->assertStringNotContainsString('segments', $t['body']);
        $this->assertStringContainsString('once the audit is activated', $t['body']);
    }

    // ── repository ──────────────────────────────────────────────

    public function test_unread_lists_newest_first_and_only_for_that_user(): void
    {
        $this->repo->create(7, NOTIFY_ASSIGNED, 'First', 'a');
        $this->repo->create(7, NOTIFY_ASSIGNED, 'Second', 'b', 'dashboard.php');
        $this->repo->create(8, NOTIFY_ASSIGNED, 'Someone else', 'c');

        $rows = $this->repo->unread(7);
        $this->assertSame(['Second', 'First'], array_column($rows, 'title'));
        $this->assertSame('dashboard.php', $rows[0]['link']);
        $this->assertNull($rows[1]['link']);
        $this->assertSame(2, $this->repo->unreadCount(7));
        $this->assertSame(1, $this->repo->unreadCount(8));
        $this->assertSame(0, $this->repo->unreadCount(99));
    }

    public function test_mark_all_read_only_touches_that_users_notifications(): void
    {
        $this->repo->create(7, NOTIFY_ASSIGNED, 'One', 'a');
        $this->repo->create(7, NOTIFY_ASSIGNED, 'Two', 'b');
        $this->repo->create(8, NOTIFY_ASSIGNED, 'Other', 'c');

        $this->assertSame(2, $this->repo->markAllRead(7));
        $this->assertSame(0, $this->repo->unreadCount(7));
        $this->assertSame([], $this->repo->unread(7));
        $this->assertSame(1, $this->repo->unreadCount(8), 'another user is untouched');
        $this->assertSame(0, $this->repo->markAllRead(7), 'nothing left to mark');
    }

    public function test_long_text_is_cut_to_the_column_sizes(): void
    {
        $this->repo->create(7, NOTIFY_ASSIGNED, str_repeat('t', 300), str_repeat('b', 900));
        $row = $this->repo->unread(7)[0];
        $this->assertSame(150, mb_strlen($row['title']));
        $this->assertSame(500, mb_strlen($row['body']));
    }
}
