<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  tests/SegmentRepositoryTest.php
//  PHPUnit 10 — integration tests for repositories/SegmentRepository.php
//
//  Uses SQLite :memory: — no real database required.
//  5 test methods covering the most critical repository paths:
//    - findWithRoad (found + not found)
//    - belongsToRoad
//    - markCompleted / resetToPending round-trip
//    - countForRoad
//    - allForRoad ordering
//
//  Run:
//    php vendor/bin/phpunit --testdox tests/SegmentRepositoryTest.php
// ═══════════════════════════════════════════════════════════════

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/SegmentRepository.php';

class SegmentRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SegmentRepository $repo;

    // ── SQLite schema (minimal — matches real MySQL shape) ─────

    private const SCHEMA = <<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            email      TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS roads (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT    NOT NULL,
            creator_id   INTEGER NOT NULL,
            finalized_at TEXT,
            FOREIGN KEY (creator_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS segments (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            road_id        INTEGER NOT NULL,
            public_id      TEXT    NOT NULL DEFAULT '',
            segment_number INTEGER NOT NULL DEFAULT 1,
            start_label    TEXT    NOT NULL DEFAULT '',
            end_label      TEXT    NOT NULL DEFAULT '',
            length         REAL    NOT NULL DEFAULT 500,
            status         TEXT    NOT NULL DEFAULT 'pending',
            completed_at   TEXT,
            FOREIGN KEY (road_id) REFERENCES roads(id)
        );

        CREATE TABLE IF NOT EXISTS audit_sessions (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            road_id      INTEGER NOT NULL,
            user_id      INTEGER NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'active',
            completed_at TEXT,
            FOREIGN KEY (road_id)  REFERENCES roads(id),
            FOREIGN KEY (user_id)  REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS segment_audits (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            segment_id  INTEGER NOT NULL,
            session_id  INTEGER NOT NULL,
            surveyor_id INTEGER,
            public_id   TEXT    NOT NULL DEFAULT '',
            FOREIGN KEY (segment_id) REFERENCES segments(id),
            FOREIGN KEY (session_id) REFERENCES audit_sessions(id)
        );

        CREATE TABLE IF NOT EXISTS obstructions (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            audit_id             INTEGER NOT NULL,
            obstruction_category TEXT,
            obstruction_type     TEXT,
            cyclist_slowed       INTEGER DEFAULT 0,
            partial_obstructions INTEGER DEFAULT 0,
            total_obstructions   INTEGER DEFAULT 0,
            FOREIGN KEY (audit_id) REFERENCES segment_audits(id)
        );

        CREATE TABLE IF NOT EXISTS intersections (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            audit_id         INTEGER NOT NULL,
            intersection_num INTEGER,
            gps_coords       TEXT,
            landmark_name    TEXT,
            off_ramp         TEXT,
            on_ramp          TEXT,
            markings         TEXT,
            signage          TEXT,
            traffic_calming  TEXT,
            discontinuity    TEXT,
            tapering         TEXT,
            obstruction_type TEXT,
            FOREIGN KEY (audit_id) REFERENCES segment_audits(id)
        );
    SQL;

    // ── Test setup ─────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Execute each CREATE TABLE statement individually
        foreach (explode(';', self::SCHEMA) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $this->pdo->exec($stmt);
            }
        }

        $this->repo = new SegmentRepository($this->pdo);
        $this->seedFixtures();
    }

    /**
     * Insert one user, one road, three segments.
     */
    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Ananya', 'a@example.com')");
        $this->pdo->exec("INSERT INTO roads (id, name, creator_id) VALUES (1, 'Baner Road', 1)");
        $this->pdo->exec("INSERT INTO roads (id, name, creator_id) VALUES (2, 'PMC Road', 1)");

        // Three segments for road 1, one for road 2
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, start_label, end_label, length, status)
                          VALUES (1, 1, 1, 'A', 'B', 400, 'pending')");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, start_label, end_label, length, status)
                          VALUES (2, 1, 2, 'B', 'C', 600, 'pending')");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, start_label, end_label, length, status)
                          VALUES (3, 1, 3, 'C', 'D', 200, 'completed')");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, start_label, end_label, length, status)
                          VALUES (4, 2, 1, 'X', 'Y', 500, 'pending')");
    }

    // ── Test 1: findWithRoad — returns row with creator_id ─────

    public function test_findWithRoad_returns_row_with_creator_id(): void
    {
        $row = $this->repo->findWithRoad(1);

        $this->assertIsArray($row);
        $this->assertSame(1,          (int)$row['id']);
        $this->assertSame(1,          (int)$row['road_id']);
        $this->assertSame('pending',  $row['status']);
        $this->assertSame(1,          (int)$row['creator_id']);
    }

    public function test_findWithRoad_returns_null_for_nonexistent_segment(): void
    {
        $row = $this->repo->findWithRoad(9999);
        $this->assertNull($row);
    }

    // ── Test 2: belongsToRoad ──────────────────────────────────

    public function test_belongsToRoad_returns_true_for_correct_road(): void
    {
        $this->assertTrue($this->repo->belongsToRoad(1, 1));
        $this->assertTrue($this->repo->belongsToRoad(2, 1));
    }

    public function test_belongsToRoad_returns_false_for_wrong_road(): void
    {
        // Segment 1 belongs to road 1, not road 2
        $this->assertFalse($this->repo->belongsToRoad(1, 2));
    }

    // ── Test 3: countForRoad ───────────────────────────────────

    public function test_countForRoad_returns_correct_count(): void
    {
        $this->assertSame(3, $this->repo->countForRoad(1));
        $this->assertSame(1, $this->repo->countForRoad(2));
        $this->assertSame(0, $this->repo->countForRoad(999)); // non-existent road
    }

    // ── Test 4: allForRoad — ordered by segment_number ─────────

    public function test_allForRoad_returns_segments_in_order(): void
    {
        $rows = $this->repo->allForRoad(1);

        $this->assertCount(3, $rows);

        // Must come back in segment_number order (1, 2, 3)
        $this->assertSame(1, (int)$rows[0]['segment_number']);
        $this->assertSame(2, (int)$rows[1]['segment_number']);
        $this->assertSame(3, (int)$rows[2]['segment_number']);
    }

    public function test_allForRoad_returns_empty_array_for_missing_road(): void
    {
        $rows = $this->repo->allForRoad(999);
        $this->assertSame([], $rows);
    }

    // ── Test 5: markCompleted / resetToPending round-trip ──────

    public function test_markCompleted_updates_status_and_returns_true(): void
    {
        // Segment 1 starts as 'pending'
        $result = $this->repo->markCompleted(1);

        $this->assertTrue($result, 'markCompleted should return true when row was updated');

        $row = $this->repo->findWithRoad(1);
        $this->assertSame('completed', $row['status']);
    }

    public function test_markCompleted_returns_false_if_already_completed(): void
    {
        // Segment 3 is already 'completed' in fixtures
        $result = $this->repo->markCompleted(3);
        $this->assertFalse($result, 'markCompleted should return false when status was already completed');
    }

    public function test_resetToPending_reverts_completed_segment(): void
    {
        // Segment 3 is 'completed', reset it
        $this->repo->resetToPending(3);

        $stmt = $this->pdo->prepare('SELECT status, completed_at FROM segments WHERE id = ?');
        $stmt->execute([3]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['completed_at']);
    }

    public function test_markCompleted_then_resetToPending_round_trip(): void
    {
        // pending → completed → pending
        $this->repo->markCompleted(1);
        $this->repo->resetToPending(1);

        $stmt = $this->pdo->prepare('SELECT status FROM segments WHERE id = ?');
        $stmt->execute([1]);
        $status = $stmt->fetchColumn();

        $this->assertSame('pending', $status);
    }

    // ── Bonus: deleteAuditData cleans child rows ───────────────

    public function test_deleteAuditData_removes_children(): void
    {
        // Insert a session, audit, obstruction, and intersection for segment 1
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'active')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id)
                          VALUES (10, 1, 1)");
        $this->pdo->exec("INSERT INTO obstructions (audit_id, cyclist_slowed)
                          VALUES (10, 3)");
        $this->pdo->exec("INSERT INTO intersections (audit_id, intersection_num)
                          VALUES (10, 1)");

        $this->repo->deleteAuditData(1);

        // All three tables should be empty for this segment
        $auditCount = $this->pdo->query('SELECT COUNT(*) FROM segment_audits WHERE segment_id = 1')->fetchColumn();
        $obsCount   = $this->pdo->query('SELECT COUNT(*) FROM obstructions WHERE audit_id = 10')->fetchColumn();
        $intCount   = $this->pdo->query('SELECT COUNT(*) FROM intersections WHERE audit_id = 10')->fetchColumn();

        $this->assertSame(0, (int)$auditCount);
        $this->assertSame(0, (int)$obsCount);
        $this->assertSame(0, (int)$intCount);
    }
}
