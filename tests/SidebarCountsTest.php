<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/SidebarCounts.php';

/** The numbers beside sidebar links, on in-memory SQLite. */
class SidebarCountsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, status TEXT NOT NULL, leader_user_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE past_audits (id INTEGER PRIMARY KEY, deleted_at TEXT NULL)');
        $this->pdo->exec("INSERT INTO city_audits (id, status, leader_user_id) VALUES
            (1,'active',5),(2,'in_review',5),(3,'published',5),(4,'published',6),
            (5,'awaiting_approval',6),(6,'voided',5),(7,'published',5)");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, status) VALUES
            (1,'submitted'),(2,'submitted'),(2,'approved'),(3,'submitted'),(4,'submitted'),(6,'submitted')");
        $this->pdo->exec("INSERT INTO past_audits (id, deleted_at) VALUES (1,NULL),(2,'2026-10-01'),(3,NULL)");
    }

    public function test_admin_counts_awaiting_and_everything_published(): void
    {
        $c = sidebarAdminCounts($this->pdo);
        $this->assertSame(1, $c['awaiting']);
        $this->assertSame(3 + 2, $c['published']);   // 3 live published + 2 past audits not deleted
    }

    public function test_leader_counts_only_their_own_open_reviews_and_published(): void
    {
        $c = sidebarLeaderCounts($this->pdo, 5);
        $this->assertSame(2, $c['review']);      // audit 1 and 2; audit 3 is published, 6 is voided
        $this->assertSame(2, $c['completed']);   // audits 3 and 7
        $other = sidebarLeaderCounts($this->pdo, 6);
        $this->assertSame(0, $other['review']);  // audit 4 is published
        $this->assertSame(1, $other['completed']);
    }

    public function test_a_missing_table_only_hides_the_number(): void
    {
        $this->pdo->exec('DROP TABLE past_audits');
        $this->assertSame(3, sidebarAdminCounts($this->pdo)['published']);
        $this->pdo->exec('DROP TABLE segment_assignments');
        $this->assertSame(0, sidebarLeaderCounts($this->pdo, 5)['review']);
        $this->assertSame(2, sidebarLeaderCounts($this->pdo, 5)['completed']);
    }
}
