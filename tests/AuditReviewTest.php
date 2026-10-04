<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../helpers/CityLeaderGate.php';

/** Review loop rules and queries, on in-memory SQLite shaped like the live schema (plus migrations 011-013). */
class AuditReviewTest extends TestCase
{
    private PDO $pdo;
    private AuditReviewRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT NOT NULL DEFAULT \'surveyor\',
            is_active INTEGER NOT NULL DEFAULT 1, city_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT, audit_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL,
            length REAL NOT NULL, status TEXT NOT NULL DEFAULT \'pending\', completed_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE audit_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            road_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'active\', completed_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE segment_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, segment_id INTEGER NOT NULL,
            cycle_track_missing TEXT, missing_length TEXT, cyclist_use TEXT, surface_material TEXT, segment_width TEXT,
            shade TEXT, buffer_zone TEXT, signage_count INTEGER, comments TEXT)');
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL,
            segment_id INTEGER NOT NULL UNIQUE, surveyor_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'assigned\', assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at TEXT NULL, reviewed_at TEXT NULL, reviewed_by INTEGER NULL, review_note TEXT NULL)');
        $this->pdo->exec("INSERT INTO users VALUES (2,'Nick','city_admin',1,1),(10,'Asha','surveyor',1,1),(11,'Ravi','surveyor',1,1),
            (12,'Inactive','surveyor',0,1),(13,'Mona','surveyor',1,2)");
        $this->pdo->exec("INSERT INTO city_audits VALUES (1,1,'active')");
        $this->pdo->exec("INSERT INTO roads VALUES (1,'F.C. Road',1)");
        $this->pdo->exec("INSERT INTO segments (id, road_id, segment_number, length, status) VALUES (1,1,1,250,'completed'),(2,1,2,250,'pending')");
        $this->pdo->exec("INSERT INTO audit_sessions (user_id, road_id, status) VALUES (10,1,'completed')");
        $this->pdo->exec("INSERT INTO segment_audits (segment_id, cyclist_use, comments) VALUES (1,'High','old'),(1,'Medium','latest')");
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by, status, submitted_at) VALUES
            (1,1,10,2,'submitted','2026-10-05 10:00:00'),(1,2,10,2,'assigned',NULL)");
        $this->repo = new AuditReviewRepository($this->pdo);
    }

    private function audit(): array
    {
        return ['id' => 1, 'city_id' => 1, 'status' => (string)$this->pdo->query('SELECT status FROM city_audits WHERE id = 1')->fetchColumn()];
    }

    private function assignment(int $segmentId): array
    {
        return $this->pdo->query("SELECT * FROM segment_assignments WHERE segment_id = $segmentId")->fetch(PDO::FETCH_ASSOC);
    }

    // ── rules ───────────────────────────────────────────────────

    public function test_note_is_required_and_trimmed_and_limited(): void
    {
        $this->assertNotNull(auditReviewCleanNote('   ')['error']);
        $this->assertNotNull(auditReviewCleanNote(null)['error']);
        $this->assertSame('Fix width', auditReviewCleanNote('  Fix width ')['note']);
        $this->assertNull(auditReviewCleanNote('Fix width')['error']);
        $this->assertNotNull(auditReviewCleanNote(str_repeat('a', 501))['error']);
        $this->assertNull(auditReviewCleanNote(str_repeat('a', 500))['error']);
    }

    public function test_counts_and_close_rule(): void
    {
        $c = auditReviewCounts(['approved', 'approved', 'submitted', null, 'needs_revisit', 'assigned']);
        $this->assertSame(6, $c['total']);
        $this->assertSame(2, $c['approved']);
        $this->assertSame(1, $c['unassigned']);
        $this->assertFalse(auditReviewCanClose('active', $c));
        $all = auditReviewCounts(['approved', 'approved']);
        $this->assertTrue(auditReviewCanClose('in_review', $all));
        $this->assertFalse(auditReviewCanClose('finalised', $all));
        $this->assertFalse(auditReviewCanClose('active', auditReviewCounts([])));
        $this->assertNull(auditReviewCloseBlockReason('active', $all));
        $this->assertSame('1 segment is not approved yet.', auditReviewCloseBlockReason('active', auditReviewCounts(['approved', 'submitted'])));
    }

    // ── reads ───────────────────────────────────────────────────

    public function test_submissions_show_only_submitted_with_latest_audit_data(): void
    {
        $rows = $this->repo->submissions(1);
        $this->assertSame(1, count($rows));
        $this->assertSame('Asha', $rows[0]['surveyor_name']);
        $this->assertSame('Medium', $rows[0]['data']['cyclist_use']);
        $this->assertSame(2, (int)$rows[0]['latest_audit_id']);
    }

    public function test_counts_from_database(): void
    {
        $c = $this->repo->counts(1);
        $this->assertSame(2, $c['total']);
        $this->assertSame(1, $c['submitted']);
        $this->assertSame(1, $c['assigned']);
    }

    // ── approve ─────────────────────────────────────────────────

    public function test_approve_marks_assignment_and_starts_review(): void
    {
        $this->repo->approve($this->audit(), 1, 2);
        $a = $this->assignment(1);
        $this->assertSame('approved', $a['status']);
        $this->assertSame(2, (int)$a['reviewed_by']);
        $this->assertNotNull($a['reviewed_at']);
        $this->assertSame('in_review', $this->audit()['status']);
    }

    public function test_approve_twice_or_unsubmitted_is_refused(): void
    {
        $this->repo->approve($this->audit(), 1, 2);
        foreach ([1, 2] as $seg) {
            try {
                $this->repo->approve($this->audit(), $seg, 2);
                $this->fail('segment not waiting for review must be refused');
            } catch (DomainException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_segment_of_another_audit_is_refused(): void
    {
        try {
            $this->repo->approve($this->audit(), 99, 2);
            $this->fail('unknown segment must be refused');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    // ── send back ───────────────────────────────────────────────

    public function test_send_back_needs_note_and_resets_segment_keeping_audit_data(): void
    {
        try {
            $this->repo->sendBack($this->audit(), 1, 2, '   ');
            $this->fail('a note is required');
        } catch (DomainException $e) {
            $this->assertSame('submitted', $this->assignment(1)['status']);
        }

        $this->repo->sendBack($this->audit(), 1, 2, 'Width is wrong');
        $a = $this->assignment(1);
        $this->assertSame('needs_revisit', $a['status']);
        $this->assertSame('Width is wrong', $a['review_note']);
        $this->assertSame(10, (int)$a['surveyor_id']);
        $this->assertSame('pending', $this->pdo->query('SELECT status FROM segments WHERE id = 1')->fetchColumn());
        $this->assertNull($this->pdo->query('SELECT completed_at FROM segments WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_audits WHERE segment_id = 1')->fetchColumn());
        $this->assertSame('active', $this->pdo->query('SELECT status FROM audit_sessions WHERE user_id = 10')->fetchColumn());
        $this->assertSame('in_review', $this->audit()['status']);
    }

    public function test_send_back_to_a_different_surveyor(): void
    {
        $this->repo->sendBack($this->audit(), 1, 2, 'Redo it', 11);
        $a = $this->assignment(1);
        $this->assertSame(11, (int)$a['surveyor_id']);
        $this->assertSame('needs_revisit', $a['status']);
        $this->assertSame('Redo it', $a['review_note']);
    }

    public function test_send_back_rejects_inactive_or_other_city_surveyor(): void
    {
        foreach ([12, 13, 2, 999] as $bad) {
            try {
                $this->repo->sendBack($this->audit(), 1, 2, 'Redo it', $bad);
                $this->fail("surveyor $bad must be refused");
            } catch (DomainException $e) {
                $this->assertSame('submitted', $this->assignment(1)['status']);
            }
        }
    }

    public function test_sent_back_list_and_resubmission_returns_to_review(): void
    {
        $this->repo->sendBack($this->audit(), 1, 2, 'Fix it');
        $this->assertSame(1, count($this->repo->sentBack(1)));
        $this->assertSame(0, count($this->repo->submissions(1)));
        // The surveyor resubmits (what SurveyorWorkRepository::markSubmitted does):
        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $this->assertSame(1, count($this->repo->submissions(1)));
        $this->assertSame(0, count($this->repo->sentBack(1)));
    }

    public function test_review_is_closed_once_the_audit_is_closed(): void
    {
        $this->pdo->exec("UPDATE city_audits SET status = 'finalised'");
        foreach (['approve', 'sendBack'] as $m) {
            try {
                $m === 'approve' ? $this->repo->approve($this->audit(), 1, 2) : $this->repo->sendBack($this->audit(), 1, 2, 'x');
                $this->fail('review must be closed');
            } catch (DomainException $e) {
                $this->assertSame('submitted', $this->assignment(1)['status']);
            }
        }
    }

    // ── close and send ──────────────────────────────────────────

    public function test_close_needs_every_segment_approved(): void
    {
        $this->repo->approve($this->audit(), 1, 2);
        try {
            $this->repo->close($this->audit());
            $this->fail('segment 2 is not approved');
        } catch (DomainException $e) {
            $this->assertSame('in_review', $this->audit()['status']);
        }
        $this->pdo->exec("UPDATE segment_assignments SET status = 'approved' WHERE segment_id = 2");
        $this->repo->close($this->audit());
        $this->assertSame('finalised', $this->audit()['status']);
        try {
            $this->repo->close($this->audit());
            $this->fail('cannot close twice');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_send_to_admin_only_from_finalised(): void
    {
        try {
            $this->repo->sendToAdmin($this->audit());
            $this->fail('an open audit cannot be sent');
        } catch (DomainException $e) {
            $this->assertSame('active', $this->audit()['status']);
        }
        $this->pdo->exec("UPDATE city_audits SET status = 'finalised'");
        $this->repo->sendToAdmin($this->audit());
        $this->assertSame('awaiting_approval', $this->audit()['status']);
    }

    // ── gate + reassignment ─────────────────────────────────────

    public function test_city_leader_gate_allows_the_new_endpoints(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/api/city/audit_review.php'));
        $this->assertTrue(cityLeaderMayAccess('/api/city/audit_close.php'));
    }

    public function test_pending_segments_can_still_be_assigned_while_in_review(): void
    {
        $this->assertContains('in_review', CityAuditRepository::ASSIGNABLE_STATUSES);
        $this->assertNotContains('finalised', CityAuditRepository::ASSIGNABLE_STATUSES);
    }
}
