<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/SurveyorWorkRepository.php';

/** Surveyor work rules and queries, on in-memory SQLite shaped like the live schema (plus migration 013). */
class SurveyorWorkTest extends TestCase
{
    private PDO $pdo;
    private SurveyorWorkRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, name TEXT, audit_year INTEGER, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT, audit_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL, length REAL NOT NULL)');
        $this->pdo->exec('CREATE TABLE audit_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, road_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'active\')');
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL,
            segment_id INTEGER NOT NULL UNIQUE, surveyor_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'assigned\', assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at TEXT NULL, reviewed_at TEXT NULL, reviewed_by INTEGER NULL, review_note TEXT NULL)');
        $this->pdo->exec("INSERT INTO city_audits VALUES (1,'Pune Audit',2026,'active'),(2,'Draft Audit',2026,'draft'),(3,'Done Audit',2025,'published')");
        $this->pdo->exec("INSERT INTO roads VALUES (1,'F.C. Road',1),(2,'Draft Road',2),(3,'Old Road',NULL),(4,'Published Road',3)");
        $this->pdo->exec('INSERT INTO segments VALUES (1,1,1,250),(2,1,2,250),(3,2,1,250),(4,3,1,250),(5,4,1,250)');
        $this->pdo->exec('INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by) VALUES
            (1,1,10,2),(1,2,11,2),(2,3,10,2),(3,5,10,2)');
        $this->repo = new SurveyorWorkRepository($this->pdo);
    }

    // ── helper functions ────────────────────────────────────────

    public function test_state_labels(): void
    {
        $this->assertSame('todo', surveyorWorkState('assigned', false)['key']);
        $this->assertSame('To do', surveyorWorkState('assigned', false)['label']);
        $this->assertSame('in_progress', surveyorWorkState('assigned', true)['key']);
        $this->assertSame('needs_revisit', surveyorWorkState('needs_revisit', true)['key']);
        $this->assertSame('submitted', surveyorWorkState('submitted', false)['key']);
        $this->assertSame('approved', surveyorWorkState('approved', false)['key']);
    }

    public function test_summary_counts_each_state(): void
    {
        $rows = [['state_key' => 'todo'], ['state_key' => 'todo'], ['state_key' => 'submitted'], ['state_key' => 'bogus']];
        $s = surveyorWorkSummary($rows);
        $this->assertSame(2, $s['todo']);
        $this->assertSame(1, $s['submitted']);
        $this->assertSame(0, $s['approved']);
    }

    public function test_can_audit_only_when_to_do_or_sent_back(): void
    {
        $this->assertTrue(surveyorCanAudit('assigned'));
        $this->assertTrue(surveyorCanAudit('needs_revisit'));
        $this->assertFalse(surveyorCanAudit('submitted'));
        $this->assertFalse(surveyorCanAudit('approved'));
    }

    public function test_block_reason_rules(): void
    {
        $this->assertNull(surveyorSubmitBlockReason(null, null, null, null, 10));
        $this->assertNull(surveyorSubmitBlockReason(1, 'active', 10, 'assigned', 10));
        $this->assertNull(surveyorSubmitBlockReason(1, 'in_review', 10, 'needs_revisit', 10));
        $this->assertSame('This audit is not open for surveying.', surveyorSubmitBlockReason(1, 'draft', 10, 'assigned', 10));
        $this->assertSame('This audit is not open for surveying.', surveyorSubmitBlockReason(1, 'published', 10, 'assigned', 10));
        $this->assertSame('This segment is not assigned to you.', surveyorSubmitBlockReason(1, 'active', 11, 'assigned', 10));
        $this->assertSame('This segment is not assigned to you.', surveyorSubmitBlockReason(1, 'active', null, null, 10));
        $this->assertSame('This segment has already been submitted.', surveyorSubmitBlockReason(1, 'active', 10, 'submitted', 10));
        $this->assertSame('This segment has already been submitted.', surveyorSubmitBlockReason(1, 'active', 10, 'approved', 10));
    }

    // ── repository ──────────────────────────────────────────────

    public function test_for_surveyor_lists_only_own_non_draft_work(): void
    {
        $rows = $this->repo->forSurveyor(10);
        $segIds = array_map(static fn(array $r): int => (int)$r['segment_id'], $rows);
        sort($segIds);
        $this->assertSame([1, 5], $segIds);          // segment 3 is in a draft audit
        $this->assertSame(1, count($this->repo->forSurveyor(11)));
        $this->assertSame(0, count($this->repo->forSurveyor(99)));
    }

    public function test_for_surveyor_row_fields_and_in_progress(): void
    {
        $first = null;
        foreach ($this->repo->forSurveyor(10) as $r) {
            if ((int)$r['segment_id'] === 1) {
                $first = $r;
            }
        }
        $this->assertNotNull($first);
        $this->assertSame('To do', $first['state_label']);
        $this->assertSame('F.C. Road', $first['road_name']);
        $this->assertTrue($first['can_audit']);

        $this->pdo->exec("INSERT INTO audit_sessions (user_id, road_id, status) VALUES (10, 1, 'active')");
        foreach ($this->repo->forSurveyor(10) as $r) {
            if ((int)$r['segment_id'] === 1) {
                $this->assertSame('in_progress', $r['state_key']);
            }
        }
    }

    public function test_published_audit_work_is_visible_but_not_auditable(): void
    {
        foreach ($this->repo->forSurveyor(10) as $r) {
            if ((int)$r['segment_id'] === 5) {
                $this->assertFalse($r['can_audit']);
                return;
            }
        }
        $this->fail('segment 5 should be listed');
    }

    public function test_submit_block_reason_from_database(): void
    {
        $this->assertNull($this->repo->submitBlockReason(1, 10));
        $this->assertSame('This segment is not assigned to you.', $this->repo->submitBlockReason(1, 11));
        $this->assertNull($this->repo->submitBlockReason(4, 99));            // road outside any audit
        $this->assertNull($this->repo->submitBlockReason(999, 10));          // unknown segment
        $this->assertSame('This audit is not open for surveying.', $this->repo->submitBlockReason(3, 10)); // draft
        $this->assertSame('This audit is not open for surveying.', $this->repo->submitBlockReason(5, 10)); // published
    }

    public function test_mark_submitted_only_for_the_assigned_surveyor(): void
    {
        $this->repo->markSubmitted(1, 11);   // wrong surveyor: no change
        $this->assertSame('assigned', $this->assignmentStatus(1));

        $this->repo->markSubmitted(1, 10);
        $this->assertSame('submitted', $this->assignmentStatus(1));
        $this->assertNotNull($this->pdo->query('SELECT submitted_at FROM segment_assignments WHERE segment_id = 1')->fetchColumn());
        $this->assertSame('This segment has already been submitted.', $this->repo->submitBlockReason(1, 10));
    }

    public function test_sent_back_segment_can_be_resubmitted(): void
    {
        $this->pdo->exec("UPDATE segment_assignments SET status = 'needs_revisit' WHERE segment_id = 1");
        $this->assertNull($this->repo->submitBlockReason(1, 10));
        $this->repo->markSubmitted(1, 10);
        $this->assertSame('submitted', $this->assignmentStatus(1));
    }

    public function test_approved_segment_is_not_changed_by_a_late_submit(): void
    {
        $this->pdo->exec("UPDATE segment_assignments SET status = 'approved' WHERE segment_id = 1");
        $this->repo->markSubmitted(1, 10);
        $this->assertSame('approved', $this->assignmentStatus(1));
    }

    public function test_may_open_road(): void
    {
        $this->assertTrue($this->repo->mayOpenRoad(1, 10));
        $this->assertTrue($this->repo->mayOpenRoad(1, 11));    // has segment 2 on the road
        $this->assertFalse($this->repo->mayOpenRoad(1, 12));   // nothing assigned
        $this->assertTrue($this->repo->mayOpenRoad(3, 12));    // road outside any audit
        $this->assertFalse($this->repo->mayOpenRoad(2, 10));   // draft audit
        $this->assertFalse($this->repo->mayOpenRoad(4, 10));   // published audit
        $this->assertTrue($this->repo->mayOpenRoad(999, 10));  // unknown road: caller handles it
    }

    private function assignmentStatus(int $segmentId): string
    {
        return (string)$this->pdo->query("SELECT status FROM segment_assignments WHERE segment_id = $segmentId")->fetchColumn();
    }
}
