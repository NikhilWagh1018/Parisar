<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  tests/SegmentRepositoryTest.php
//  PHPUnit 10 — integration tests for repositories/SegmentRepository.php
//
//  Uses SQLite :memory: — no real database required.
//  Test methods covering the most critical repository paths:
//    - findWithRoad (found + not found)
//    - belongsToRoad
//    - markCompleted / resetToPending round-trip
//    - countForRoad
//    - allForRoad ordering
//    - personalStats (re-audit de-dup)
//    - personalAuditList (latest-per-segment, ordering)
//    - personalContinueAudits (resume-where-left-off)
//    - leaderboardRows (all-time + this-week windowing)
//    - auditDatesForUser (distinct dates, DESC)
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

        -- NOTE: started_at added to match production audit_sessions shape.
        -- Needed for personalContinueAudits(), which selects it directly.
        CREATE TABLE IF NOT EXISTS audit_sessions (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            road_id      INTEGER NOT NULL,
            user_id      INTEGER NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'active',
            started_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT,
            FOREIGN KEY (road_id)  REFERENCES roads(id),
            FOREIGN KEY (user_id)  REFERENCES users(id)
        );

        -- NOTE: created_at added to match production segment_audits shape
        -- (this is the exact column the Session 38 schema-drift bug was about —
        -- keeping this test schema in sync with prod is the whole point).
        CREATE TABLE IF NOT EXISTS segment_audits (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            segment_id  INTEGER NOT NULL,
            session_id  INTEGER NOT NULL,
            surveyor_id INTEGER,
            public_id   TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            segment_width REAL,
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

    // ── Test setup ───────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // MySQL-only date functions used by leaderboardRows() — emulate
        // them so the "this week" windowing branch can be exercised
        // under SQLite too, not just skipped.
        $this->pdo->sqliteCreateFunction('CURDATE', function (): string {
            return date('Y-m-d');
        }, 0);
        $this->pdo->sqliteCreateFunction('YEARWEEK', function (string $dateStr, int $mode = 0): int {
            // Approximates MySQL's YEARWEEK(date, 3) — ISO year + ISO week.
            $ts = strtotime($dateStr);
            return (int)date('oW', $ts);
        }, -1);

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

    // ── Test 2: belongsToRoad ───────────────────────────────────

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

    // ── Test 3: countForRoad ────────────────────────────────────

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

    // ── Bonus: deleteAuditData cleans child rows ────────────────

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

    // ── Test 6: personalStats — de-dups re-audited segments ─────
    //
    // Regression coverage for the Session 38 bug: this method (and its
    // siblings below) referenced segment_audits.audited_at, a column
    // that never existed in production. None of these methods had any
    // test coverage, so CI stayed green while the live app 500'd.

    public function test_personalStats_dedupes_reaudited_segments(): void
    {
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'completed')");

        // Segment 1 audited twice by user 1 (re-audit) — should only
        // count once, using the LATEST audit's date.
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (10, 1, 1, 1, '2026-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (11, 1, 1, 1, '2026-01-05 10:00:00')");
        // Segment 2 audited once by user 1.
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (12, 2, 1, 1, '2026-01-03 10:00:00')");

        $stats = $this->repo->personalStats(1);

        // Deduped: 2 distinct segments, not 3 audit rows.
        $this->assertSame(2, $stats['segments_audited']);
        // 400 (segment 1) + 600 (segment 2), each counted once.
        $this->assertSame(1000.0, $stats['total_length_m']);
        $this->assertSame(1, $stats['roads_touched']);
        // MIN of each segment's LATEST audit date: min(01-05, 01-03) = 01-03.
        $this->assertSame('2026-01-03 10:00:00', $stats['first_audit_at']);
    }

    // ── Test 7: personalAuditList — latest-per-segment, DESC order ──

    public function test_personalAuditList_returns_latest_audit_per_segment_desc(): void
    {
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'completed')");

        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (10, 1, 1, 1, '2026-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (11, 1, 1, 1, '2026-01-05 10:00:00')"); // latest for segment 1
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (12, 2, 1, 1, '2026-01-03 10:00:00')"); // latest for segment 2

        $rows = $this->repo->personalAuditList(1);

        $this->assertCount(2, $rows, 'should return one row per segment, not per audit');

        // Most recent first: segment 1's latest (01-05) before segment 2's (01-03).
        $this->assertSame(11, (int)$rows[0]['audit_id']);
        $this->assertSame('2026-01-05 10:00:00', $rows[0]['created_at']);
        $this->assertSame(12, (int)$rows[1]['audit_id']);
        $this->assertSame('2026-01-03 10:00:00', $rows[1]['created_at']);
    }

    // ── Test 8: personalContinueAudits — resume-where-left-off ──

    public function test_personalContinueAudits_returns_road_with_pending_segment(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Nick', 'nick@example.com')");

        // Road 2 (segment 4, still pending) has an active session for user 2.
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status, started_at)
                          VALUES (1, 2, 2, 'active', '2026-01-01 09:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (20, 4, 1, 2, '2026-01-02 10:00:00')");

        $rows = $this->repo->personalContinueAudits(2);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int)$rows[0]['road_id']);
        $this->assertSame(1, (int)$rows[0]['total_segments']);
        $this->assertSame(0, (int)$rows[0]['completed_segments']);
        $this->assertSame(4, (int)$rows[0]['next_segment_id']);
        $this->assertSame('2026-01-02 10:00:00', $rows[0]['last_activity_at']);
    }

    public function test_personalContinueAudits_excludes_completed_sessions(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Nick', 'nick@example.com')");
        // Session is 'completed', not 'active' — should not show up as resumable.
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 2, 2, 'completed')");

        $rows = $this->repo->personalContinueAudits(2);

        $this->assertSame([], $rows);
    }

    // ── Test 9: leaderboardRows — all-time and this-week windows ──

    public function test_leaderboardRows_counts_every_audit_row_not_deduped(): void
    {
        // Unlike personalStats, leaderboard rewards every submission,
        // including re-audits — so this deliberately does NOT dedupe.
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'completed')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (10, 1, 1, 1, '2026-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (11, 1, 1, 1, '2026-01-05 10:00:00')"); // re-audit of same segment
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (12, 2, 1, 1, '2026-01-03 10:00:00')");

        $rows = $this->repo->leaderboardRows(false);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['surveyor_id']);
        $this->assertSame(3, $rows[0]['segments_completed']); // 3 audit rows, not 2 distinct segments
        $this->assertSame(1400.0, $rows[0]['distance_m']);    // 400 + 400 + 600
    }

    public function test_leaderboardRows_this_week_excludes_older_audits(): void
    {
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'completed')");

        // One audit today (this week), one audit far in the past.
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (10, 1, 1, 1, '" . date('Y-m-d H:i:s') . "')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (11, 2, 1, 1, '2020-01-01 10:00:00')");

        $rows = $this->repo->leaderboardRows(true);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['segments_completed'], 'only the current-week audit should count');
    }

    // ── Test 10: auditDatesForUser — distinct dates, DESC ────────

    public function test_auditDatesForUser_returns_distinct_dates_descending(): void
    {
        $this->pdo->exec("INSERT INTO audit_sessions (id, road_id, user_id, status)
                          VALUES (1, 1, 1, 'completed')");

        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (10, 1, 1, 1, '2026-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (11, 2, 1, 1, '2026-01-05 08:00:00')");
        $this->pdo->exec("INSERT INTO segment_audits (id, segment_id, session_id, surveyor_id, created_at)
                          VALUES (12, 3, 1, 1, '2026-01-05 20:00:00')"); // same day as above, different time

        $dates = $this->repo->auditDatesForUser(1);

        // Three audits, but only two distinct calendar dates.
        $this->assertCount(2, $dates);
        $this->assertSame('2026-01-05', $dates[0]); // most recent first
        $this->assertSame('2026-01-01', $dates[1]);
    }
}
