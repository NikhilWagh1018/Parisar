<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/SurveyorWorkRepository.php';

/** Surveyors work only on segments assigned to them; they cannot create or delete roads. */
class SurveyorAssignedOnlyTest extends TestCase
{
    private PDO $pdo;
    private SurveyorWorkRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE city_audits (id INTEGER PRIMARY KEY, name TEXT, audit_year INTEGER, status TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT, audit_id INTEGER NULL)");
        $this->pdo->exec("CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL, length REAL NOT NULL)");
        $this->pdo->exec("CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL,
            segment_id INTEGER NOT NULL UNIQUE, surveyor_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'assigned', assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at TEXT NULL, reviewed_at TEXT NULL, reviewed_by INTEGER NULL, review_note TEXT NULL)");
        $this->pdo->exec("INSERT INTO city_audits VALUES (1,'Pune Audit',2026,'active'),(2,'Draft Audit',2026,'draft'),(3,'Done Audit',2025,'published')");
        $this->pdo->exec("INSERT INTO roads VALUES (1,'F.C. Road',1),(2,'Draft Road',2),(3,'Old Road',NULL),(4,'Published Road',3)");
        $this->pdo->exec("INSERT INTO segments VALUES (1,1,1,300),(2,1,2,300),(3,2,1,300),(4,3,1,300),(5,4,1,300)");
        // Segment 1 -> surveyor 10, segment 2 -> surveyor 11 (the F.C. Road case from the live test).
        $this->pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, assigned_by) VALUES
            (1,1,10,2),(1,2,11,2),(2,3,10,2),(3,5,10,2)");
        $this->repo = new SurveyorWorkRepository($this->pdo);
    }

    public function test_road_in_city_audit_detection(): void
    {
        $this->assertTrue($this->repo->roadIsInCityAudit(1));
        $this->assertFalse($this->repo->roadIsInCityAudit(3));   // older stand-alone road
        $this->assertFalse($this->repo->roadIsInCityAudit(999));
    }

    public function test_assigned_segment_ids_are_per_surveyor(): void
    {
        $this->assertSame([1], $this->repo->assignedSegmentIds(1, 10));
        $this->assertSame([2], $this->repo->assignedSegmentIds(1, 11));
        $this->assertSame([], $this->repo->assignedSegmentIds(1, 99));
    }

    public function test_may_open_only_own_segment(): void
    {
        $this->assertTrue($this->repo->mayOpenSegment(1, 10));
        $this->assertFalse($this->repo->mayOpenSegment(2, 10), 'segment 2 belongs to surveyor 11');
        $this->assertTrue($this->repo->mayOpenSegment(2, 11));
        $this->assertFalse($this->repo->mayOpenSegment(1, 99));
    }

    public function test_may_open_segment_needs_a_workable_audit(): void
    {
        $this->assertFalse($this->repo->mayOpenSegment(3, 10), 'draft audit');
        $this->assertFalse($this->repo->mayOpenSegment(5, 10), 'published audit');
        $this->pdo->exec("UPDATE city_audits SET status = 'in_review' WHERE id = 1");
        $this->assertTrue($this->repo->mayOpenSegment(1, 10));
        $this->pdo->exec("UPDATE city_audits SET status = 'finalised' WHERE id = 1");
        $this->assertFalse($this->repo->mayOpenSegment(1, 10));
    }

    public function test_older_roads_and_unknown_segments_are_not_restricted(): void
    {
        $this->assertTrue($this->repo->mayOpenSegment(4, 10));
        $this->assertTrue($this->repo->mayOpenSegment(999, 10));
    }

    public function test_dashboard_totals_count_only_assigned_segments(): void
    {
        // Surveyor 10 has an active session on road 1 but only ONE assigned segment there.
        $this->pdo->exec("CREATE TABLE audit_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, road_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active')");
        $this->pdo->exec("INSERT INTO audit_sessions (user_id, road_id, status) VALUES (10, 1, 'active')");
        $totals = surveyorAssignedTotals($this->repo->forSurveyor(10));
        // segments 1 (active audit) and 5 (published audit) are assigned; segment 3 is in a draft audit
        $this->assertSame(2, $totals['segments']);
        $this->assertSame(2, $totals['roads']);
        $this->assertSame(0, $totals['completed']);
        $this->assertSame(1, $totals['in_progress']);

        $this->pdo->exec("UPDATE segment_assignments SET status = 'submitted' WHERE segment_id = 1");
        $this->assertSame(1, surveyorAssignedTotals($this->repo->forSurveyor(10))['completed']);
    }

    public function test_totals_of_nothing_are_zero(): void
    {
        $this->assertSame(['roads' => 0, 'segments' => 0, 'completed' => 0, 'in_progress' => 0], surveyorAssignedTotals([]));
    }

    public function test_surveyors_cannot_create_or_delete_roads(): void
    {
        global $PERMISSIONS;
        require_once __DIR__ . '/../config/permissions.php';

        $this->assertFalse(can('create_road', 10, 'surveyor'));
        $this->assertTrue(can('create_road', 1, 'national_admin'));
        $this->assertTrue(can('create_road', 2, 'city_admin'));

        // Even the owner of a road cannot delete it as a surveyor.
        $this->assertFalse(can('delete_road', 10, 'surveyor', ['owner_id' => 10, 'city_id' => 1]));
        $this->assertFalse(can('delete_road', 2, 'city_admin', ['owner_id' => 2, 'city_id' => 1]));
        $this->assertTrue(can('delete_road', 1, 'national_admin', ['owner_id' => 10, 'city_id' => 1]));
    }
}
