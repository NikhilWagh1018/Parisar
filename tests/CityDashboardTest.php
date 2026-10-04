<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../repositories/CityDashboardRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';

/** Pure dashboard / report rules, and the two read-only repositories on in-memory SQLite. */
class CityDashboardTest extends TestCase
{
    // ── pure rules ────────────────────────────────────────────

    public function test_plural_and_status_label(): void
    {
        $this->assertSame('1 segment', cityPlural(1, 'segment', 'segments'));
        $this->assertSame('0 segments', cityPlural(0, 'segment', 'segments'));
        $this->assertSame('3 roads', cityPlural(3, 'road', 'roads'));
        $this->assertSame('In review', cityStatusLabel('in_review'));
        $this->assertSame('Awaiting approval', cityStatusLabel('awaiting_approval'));
    }

    public function test_progress_is_a_clamped_whole_percentage(): void
    {
        $this->assertSame(0, cityDashProgress(0, 0));
        $this->assertSame(0, cityDashProgress(3, 0));
        $this->assertSame(33, cityDashProgress(1, 3));
        $this->assertSame(100, cityDashProgress(4, 4));
        $this->assertSame(100, cityDashProgress(9, 4));
        $this->assertSame(0, cityDashProgress(-2, 4));
    }

    public function test_condition_class(): void
    {
        $this->assertSame('good', cityConditionClass('Good'));
        $this->assertSame('very-bad', cityConditionClass('Very Bad'));
        $this->assertSame('none', cityConditionClass(null));
        $this->assertSame('none', cityConditionClass('  '));
    }

    public function test_answers_recorded_needs_at_least_one_real_answer(): void
    {
        $this->assertFalse(cityAnswersRecorded([]));
        $this->assertFalse(cityAnswersRecorded(['shade' => null, 'comments' => '  ', 'signage_count' => 0]));
        $this->assertTrue(cityAnswersRecorded(['shade' => 'Yes']));
        $this->assertTrue(cityAnswersRecorded(['comments' => 'cracked kerb']));
        $this->assertTrue(cityAnswersRecorded(['segment_width' => '0']));
    }

    public function test_attention_for_a_draft_audit(): void
    {
        $none = cityAuditAttention('draft', ['total' => 0]);
        $this->assertSame('action', $none[0]['level']);
        $this->assertStringContainsString('Add a road', $none[0]['text']);

        $some = cityAuditAttention('draft', ['total' => 4, 'unassigned' => 2]);
        $this->assertSame('2 segments still need a surveyor.', $some[0]['text']);

        $one = cityAuditAttention('draft', ['total' => 4, 'unassigned' => 1]);
        $this->assertSame('1 segment still needs a surveyor.', $one[0]['text']);

        $ready = cityAuditAttention('draft', ['total' => 4, 'unassigned' => 0]);
        $this->assertStringContainsString('Activate', $ready[0]['text']);
    }

    public function test_attention_for_an_open_audit(): void
    {
        $items = cityAuditAttention('in_review', ['total' => 5, 'submitted' => 2, 'needs_revisit' => 1, 'approved' => 1]);
        $this->assertCount(2, $items);
        $this->assertSame('action', $items[0]['level']);
        $this->assertSame('2 segments are waiting for your review.', $items[0]['text']);
        $this->assertSame('info', $items[1]['level']);

        $working = cityAuditAttention('active', ['total' => 5, 'approved' => 1]);
        $this->assertCount(1, $working);
        $this->assertSame('info', $working[0]['level']);
        $this->assertSame('4 segments are still being audited.', $working[0]['text']);

        $all = cityAuditAttention('in_review', ['total' => 3, 'approved' => 3]);
        $this->assertSame('action', $all[0]['level']);
        $this->assertStringContainsString('Close the audit', $all[0]['text']);
    }

    public function test_attention_after_the_audit_is_closed(): void
    {
        $this->assertSame('action', cityAuditAttention('finalised', [])[0]['level']);
        $this->assertSame('info', cityAuditAttention('awaiting_approval', [])[0]['level']);
        $this->assertSame('done', cityAuditAttention('published', [])[0]['level']);
        $this->assertSame([], cityAuditAttention('voided', []));
    }

    public function test_dashboard_totals(): void
    {
        $t = cityDashTotals([
            ['status' => 'active', 'segment_count' => 4, 'approved_count' => 1, 'submitted_count' => 1, 'assigned_count' => 1, 'needs_revisit_count' => 1],
            ['status' => 'published', 'segment_count' => 2, 'approved_count' => 2, 'submitted_count' => 0, 'assigned_count' => 0, 'needs_revisit_count' => 0],
        ]);
        $this->assertSame(2, $t['audits']);
        $this->assertSame(1, $t['open']);
        $this->assertSame(6, $t['segments']);
        $this->assertSame(3, $t['approved']);
        $this->assertSame(1, $t['submitted']);
        $this->assertSame(2, $t['with_surveyors']);
        $this->assertSame(0, cityDashTotals([])['audits']);
    }

    public function test_report_aggregate_is_weighted_by_length(): void
    {
        $agg = cityReportAggregate([
            ['length' => 100, 'final' => 10, 'safety_score' => 10, 'continuity_score' => 20, 'comfort_score' => 30],
            ['length' => 300, 'final' => 50, 'safety_score' => 50, 'continuity_score' => 60, 'comfort_score' => 70],
            ['length' => 500],   // not scored: ignored
        ]);
        $this->assertSame(40.0, $agg['score']);
        $this->assertSame(40.0, $agg['safety']);
        $this->assertSame(50.0, $agg['continuity']);
        $this->assertSame(60.0, $agg['comfort']);
        $this->assertSame('OK', $agg['condition']);
        $this->assertSame(2, $agg['scored']);
        $this->assertNull(cityReportAggregate([]));
        $this->assertNull(cityReportAggregate([['length' => 100]]));
        $this->assertNull(cityReportAggregate([['length' => 0, 'final' => 5]]));
    }

    public function test_condition_counts_always_have_all_five_keys(): void
    {
        $c = cityConditionCounts([['condition' => 'Good'], ['condition' => 'Good'], ['condition' => 'Bad'], ['condition' => 'nonsense']]);
        $this->assertSame(['Good', 'OK', 'Poor', 'Bad', 'Very Bad'], array_keys($c));
        $this->assertSame(2, $c['Good']);
        $this->assertSame(1, $c['Bad']);
        $this->assertSame(0, $c['Very Bad']);
    }

    public function test_local_time_converts_utc_to_india_time(): void
    {
        $this->assertSame('4 Oct 2026, 7:05 PM', cityLocalTime('2026-10-04 13:35:05'));
        $this->assertSame('—', cityLocalTime(null));
        $this->assertSame('—', cityLocalTime('  '));
    }

    // ── repositories ──────────────────────────────────────────

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER, name TEXT, state TEXT, audit_year INTEGER,
            status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT, audit_id INTEGER)');
        $pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER, segment_number INTEGER, length REAL,
            start_distance REAL, end_distance REAL)');
        $pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, segment_id INTEGER UNIQUE,
            surveyor_id INTEGER, status TEXT, submitted_at TEXT, reviewed_at TEXT, review_note TEXT)');
        $pdo->exec('CREATE TABLE segment_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, segment_id INTEGER, shade TEXT, comments TEXT)');
        $pdo->exec('CREATE TABLE obstructions (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, obstruction_category TEXT,
            obstruction_type TEXT, partial_obstructions INTEGER, total_obstructions INTEGER, cyclist_slowed INTEGER)');
        $pdo->exec('CREATE TABLE intersections (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, intersection_num INTEGER,
            landmark_name TEXT, off_ramp TEXT, on_ramp TEXT, markings TEXT, signage TEXT, traffic_calming TEXT,
            discontinuity TEXT, tapering TEXT)');

        $pdo->exec("INSERT INTO users VALUES (10,'Asha')");
        $pdo->exec("INSERT INTO city_audits (id, city_id, name, state, audit_year, status) VALUES
            (1,1,'A1','MH',2026,'in_review'),(2,1,'A2','MH',2025,'draft'),(3,2,'Other city','MH',2026,'active')");
        $pdo->exec("INSERT INTO roads VALUES (1,'F.C. Road',1),(2,'JM Road',1),(3,'Empty',2),(4,'Elsewhere',3)");
        $pdo->exec("INSERT INTO segments VALUES (1,1,1,300,0,300),(2,1,2,300,300,600),(3,2,1,500,0,500),(4,4,1,100,0,100)");
        $pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, status, submitted_at) VALUES
            (1,1,10,'approved','2026-10-04 10:00:00'),(1,2,10,'submitted','2026-10-04 11:00:00')");
        $pdo->exec("INSERT INTO segment_audits (segment_id, shade, comments) VALUES (2,'No','old'),(2,'Yes','new')");
        $pdo->exec("INSERT INTO obstructions (audit_id, obstruction_category, obstruction_type, partial_obstructions, total_obstructions, cyclist_slowed)
            VALUES (2,'fixed','Pole',1,0,1)");
        $pdo->exec("INSERT INTO intersections (audit_id, intersection_num, landmark_name) VALUES (2,2,'B'),(2,1,'A')");
        return $pdo;
    }

    public function test_audit_summaries_count_roads_segments_and_review_states(): void
    {
        $rows = (new CityDashboardRepository($this->db()))->auditSummaries(1);
        $this->assertCount(2, $rows);
        $byId = [];
        foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

        $a1 = $byId[1];
        $this->assertSame(2, $a1['road_count']);
        $this->assertSame(3, $a1['segment_count']);
        $this->assertSame(1, $a1['approved_count']);
        $this->assertSame(1, $a1['submitted_count']);
        $this->assertSame(0, $a1['needs_revisit_count']);
        $this->assertSame(0, $a1['assigned_count']);
        $this->assertSame(1, $a1['unassigned_count']);

        $a2 = $byId[2];
        $this->assertSame(1, $a2['road_count']);    // a road with no segments yet
        $this->assertSame(0, $a2['segment_count']);
        $this->assertSame(0, $a2['unassigned_count']);
    }

    public function test_audit_summaries_only_cover_the_asked_city(): void
    {
        $this->assertCount(1, (new CityDashboardRepository($this->db()))->auditSummaries(2));
        $this->assertSame([], (new CityDashboardRepository($this->db()))->auditSummaries(99));
    }

    public function test_segment_detail_uses_the_latest_audit_row(): void
    {
        $d = (new AuditReportRepository($this->db()))->segmentDetail(1, 2);
        $this->assertSame('submitted', $d['assignment_status']);
        $this->assertSame('Asha', $d['surveyor_name']);
        $this->assertSame('F.C. Road', $d['road_name']);
        $this->assertSame('Yes', $d['data']['shade']);
        $this->assertSame('new', $d['data']['comments']);
        $this->assertCount(1, $d['obstructions']);
        $this->assertSame('Pole', $d['obstructions'][0]['obstruction_type']);
        $this->assertSame([1, 2], array_map('intval', array_column($d['intersections'], 'intersection_num')));
    }

    public function test_segment_detail_is_null_for_a_segment_outside_the_audit(): void
    {
        $repo = new AuditReportRepository($this->db());
        $this->assertNull($repo->segmentDetail(1, 3));    // segment 3 has no assignment in audit 1
        $this->assertNull($repo->segmentDetail(2, 2));    // segment 2 belongs to audit 1
        $this->assertNull($repo->segmentDetail(1, 999));
    }

    public function test_segment_detail_without_any_recorded_audit_has_empty_data(): void
    {
        $d = (new AuditReportRepository($this->db()))->segmentDetail(1, 1);
        $this->assertSame('approved', $d['assignment_status']);
        $this->assertSame([], $d['data']);
        $this->assertSame([], $d['obstructions']);
        $this->assertSame([], $d['intersections']);
    }

    public function test_next_to_review_skips_the_current_segment(): void
    {
        $pdo = $this->db();
        $repo = new AuditReportRepository($pdo);
        $this->assertNull($repo->nextToReview(1, 2));
        $pdo->exec("INSERT INTO segment_assignments (audit_id, segment_id, surveyor_id, status, submitted_at)
            VALUES (1,3,10,'submitted','2026-10-04 09:00:00')");
        $this->assertSame(3, $repo->nextToReview(1, 2));
        $this->assertSame(3, $repo->nextToReview(1, 1));
    }

    public function test_report_segments_list_every_segment_road_by_road(): void
    {
        $rows = (new AuditReportRepository($this->db()))->reportSegments(1);
        $this->assertCount(3, $rows);
        $this->assertSame(['F.C. Road', 'F.C. Road', 'JM Road'], array_column($rows, 'road_name'));
        $this->assertSame([1, 2, 1], array_map('intval', array_column($rows, 'segment_number')));
        $this->assertSame('approved', $rows[0]['assignment_status']);
        $this->assertNull($rows[2]['assignment_status']);
        $this->assertNull($rows[0]['latest_audit_id']);
        $this->assertSame(2, (int)$rows[1]['latest_audit_id']);
    }
}
