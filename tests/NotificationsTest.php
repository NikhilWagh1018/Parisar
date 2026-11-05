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

    // ── taking segments away ─────────────────────────────────────

    public function test_moved_text_says_another_surveyor_has_it_now(): void
    {
        $t = unassignmentNotificationText('002', 1, 'moved');
        $this->assertSame('Assignment changed', $t['title']);
        $this->assertStringContainsString('moved 1 segment in the audit "002" to another surveyor', $t['body']);
        $this->assertStringContainsString('It is no longer on your dashboard', $t['body']);

        $many = unassignmentNotificationText('002', 3, 'moved');
        $this->assertStringContainsString('3 segments', $many['body']);
        $this->assertStringContainsString('They are no longer on your dashboard', $many['body']);
    }

    public function test_cleared_and_removed_texts_differ(): void
    {
        $this->assertStringContainsString('took you off 2 segments', unassignmentNotificationText('A', 2, 'cleared')['body']);
        $removed = unassignmentNotificationText('A', 1, 'removed');
        $this->assertSame('Assignment removed', $removed['title']);
        $this->assertStringContainsString('removed 1 segment that you were assigned from the audit "A"', $removed['body']);
    }

    public function test_plan_tells_the_old_surveyor_when_work_moves(): void
    {
        // segments 1 and 2 were with surveyor 10; both move to 11
        $plan = assignmentNotifyPlan([1 => 10, 2 => 10], [1, 2], 11);
        $this->assertSame(['gained' => 2, 'lost' => [10 => 2]], $plan);
    }

    public function test_plan_does_not_notify_when_nothing_really_changes(): void
    {
        // giving a surveyor segments they already hold changes nothing
        $this->assertSame(['gained' => 0, 'lost' => []], assignmentNotifyPlan([1 => 10, 2 => 10], [1, 2], 10));
        // a partly new set: only the new segment counts for them
        $this->assertSame(['gained' => 1, 'lost' => []], assignmentNotifyPlan([1 => 10], [1, 2], 10));
    }

    public function test_plan_for_clearing_and_for_first_time_assignment(): void
    {
        $this->assertSame(['gained' => 0, 'lost' => [10 => 1, 11 => 1]], assignmentNotifyPlan([1 => 10, 2 => 11, 3 => 11], [1, 2], null));
        $this->assertSame(['gained' => 2, 'lost' => []], assignmentNotifyPlan([], [1, 2], 10));
        $this->assertSame(['gained' => 0, 'lost' => []], assignmentNotifyPlan([], [], 10));
    }

    public function test_tell_unassigned_writes_one_notification_per_surveyor(): void
    {
        $this->repo->tellUnassigned([10 => 2, 11 => 1, 12 => 0], '002', 'moved');
        $a = $this->repo->unread(10);
        $this->assertCount(1, $a);
        $this->assertSame(NOTIFY_UNASSIGNED, $a[0]['type']);
        $this->assertSame('Assignment changed', $a[0]['title']);
        $this->assertStringContainsString('2 segments', $a[0]['body']);
        $this->assertSame('dashboard.php', $a[0]['link']);
        $this->assertCount(1, $this->repo->unread(11));
        $this->assertCount(0, $this->repo->unread(12));   // a count of 0 is skipped
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

    public function test_recent_keeps_read_notifications_and_marks_one_at_a_time(): void
    {
        $a = $this->repo->create(7, NOTIFY_ASSIGNED, 'First', 'b');
        $this->repo->create(7, NOTIFY_ASSIGNED, 'Second', 'b');
        $this->repo->create(8, NOTIFY_ASSIGNED, 'Other user', 'b');

        $this->assertTrue($this->repo->markRead(7, $a));
        $this->assertFalse($this->repo->markRead(7, $a), 'already read');
        $this->assertFalse($this->repo->markRead(8, $a), "cannot read another user's notification");

        $rows = $this->repo->recent(7);
        $this->assertSame(['Second', 'First'], array_column($rows, 'title'));
        $this->assertSame([false, true], array_column($rows, 'is_read'));

        $this->repo->markAllRead(7);
        $this->assertCount(2, $this->repo->recent(7), 'read notifications stay in the list');
        $this->assertSame([], $this->repo->unread(7));
    }
}
