<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityDetailedReport.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';

/** Rules behind the detailed city audit report, and its one batched query. */
class CityDetailedReportTest extends TestCase
{
    private function row(int $n, float $final, float $s, float $c, float $m, int $auditId, float $len = 300): array
    {
        return ['segment_number' => $n, 'length' => $len, 'final' => $final, 'safety_score' => $s,
                'continuity_score' => $c, 'comfort_score' => $m, 'latest_audit_id' => $auditId, 'rating' => 'OK'];
    }

    private function detail(array $audit = [], int $obs = 0, int $ramps = 0, int $sign = 0): array
    {
        return ['audit' => $audit + ['surface_issues' => '[]', 'cycle_track_missing' => 'No', 'missing_length' => 0],
                'intersections' => 0, 'no_ramps' => $ramps, 'no_sign' => $sign, 'obs_total' => $obs,
                'obs_partial' => 0, 'cyclist_slowed' => 0];
    }

    public function test_nothing_scored_gives_an_empty_analysis(): void
    {
        $an = cityRoadAnalysis([['segment_number' => 1, 'length' => 300, 'latest_audit_id' => null]], []);
        $this->assertSame(0, $an['scored']);
        $this->assertNull($an['weak']);
        $this->assertNull($an['best']);
        $this->assertSame([], $an['recommend']);
        $this->assertSame([], $an['observations']);
    }

    public function test_best_is_the_lowest_score_and_worst_the_highest(): void
    {
        $an = cityRoadAnalysis([$this->row(1, 62.04, 40, 50, 80, 1), $this->row(2, 13.33, 10, 5, 20, 2)], []);
        $this->assertSame(2, $an['best']['segment_number']);
        $this->assertSame(1, $an['worst']['segment_number']);
        $this->assertSame(2, $an['scored']);
        $this->assertSame(600.0, $an['audited_len']);
    }

    public function test_main_concern_is_the_highest_penalty_dimension(): void
    {
        $an = cityRoadAnalysis([$this->row(1, 40, 20, 30, 70, 1)], []);
        $this->assertSame('comfort', $an['weak']);
        $this->assertSame(['safety' => 20.0, 'continuity' => 30.0, 'comfort' => 70.0], $an['avg']);
    }

    public function test_a_clean_good_road_gets_no_recommendations(): void
    {
        $an = cityRoadAnalysis([$this->row(1, 8, 5, 10, 8, 1)], [1 => $this->detail()]);
        $this->assertSame([], $an['recommend']);
        $this->assertSame([], $an['critical']);
    }

    public function test_problems_become_observations_issues_and_recommendations(): void
    {
        $d = [1 => $this->detail(['cycle_track_missing' => 'Yes', 'missing_length' => 40, 'surface_issues' => '["potholes"]'], 12, 2, 1)];
        $an = cityRoadAnalysis([$this->row(1, 70, 65, 40, 60, 1)], $d);

        $texts = array_column($an['observations'], 'text');
        $this->assertContains('Segment 1: High obstruction count (12 total)', $texts);
        $this->assertContains('Segment 1: Cycle track section missing (40m)', $texts);
        $this->assertContains('Segment 1: 2 intersection(s) missing ramps', $texts);
        $this->assertContains('Segment 1: 1 intersection(s) missing markings/signage', $texts);
        $this->assertSame(['Missing cycle track in Segment 1'], $an['critical']);

        $rec = implode('|', $an['recommend']);
        foreach (['remove all obstructions', 'Build on/off ramps', 'Construct missing cycle track', 'Repair damaged',
                  'buffer zones', 'markings and signage', 'no-encroachment', 'after-sunset lighting'] as $needle) {
            $this->assertStringContainsString($needle, $rec);
        }
    }

    public function test_unscored_segments_are_ignored(): void
    {
        $an = cityRoadAnalysis([$this->row(1, 20, 10, 10, 10, 1), ['segment_number' => 2, 'length' => 300, 'latest_audit_id' => 2]], [2 => $this->detail([], 50)]);
        $this->assertSame(1, $an['scored']);
        $this->assertSame([], $an['observations']);
    }

    public function test_detail_values_are_formatted(): void
    {
        $this->assertSame(['N/A', 'na'], cityDetailValue(null));
        $this->assertSame(['N/A', 'na'], cityDetailValue(''));
        $this->assertSame(['N/A', 'na'], cityDetailValue('n/a'));
        $this->assertSame(['Yes', 'yes'], cityDetailValue('yes'));
        $this->assertSame(['No', 'no'], cityDetailValue('NO'));
        $this->assertSame(['Asphalt', ''], cityDetailValue('Asphalt'));
        $this->assertSame(['0', ''], cityDetailValue(0));
    }

    public function test_details_query_counts_per_audit(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE segment_audits (id INTEGER PRIMARY KEY, surface_material TEXT, buffer_zone TEXT, light_after_sunset TEXT,
            shade TEXT, surface_issues TEXT, overhead_issues TEXT, cycle_track_missing TEXT, missing_length REAL, people_walking TEXT,
            cyclist_use TEXT, better_surface TEXT, signage_count INTEGER)');
        $pdo->exec('CREATE TABLE intersections (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, off_ramp TEXT, on_ramp TEXT, markings TEXT, signage TEXT)');
        $pdo->exec('CREATE TABLE obstructions (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER, partial_obstructions INTEGER, total_obstructions INTEGER, cyclist_slowed INTEGER)');
        $pdo->exec("INSERT INTO segment_audits (id, surface_material, signage_count) VALUES (1,'Asphalt',3),(2,'Concrete',0)");
        $pdo->exec("INSERT INTO intersections (audit_id, off_ramp, on_ramp, markings, signage) VALUES
            (1,'No Ramp','Ramp','Present','Present'),(1,'Ramp','Ramp','Absent','Present'),(1,'Ramp','Ramp','Present','Present')");
        $pdo->exec('INSERT INTO obstructions (audit_id, partial_obstructions, total_obstructions, cyclist_slowed) VALUES (1,2,3,1),(1,1,4,0)');

        $repo = new AuditReportRepository($pdo);
        $this->assertSame([], $repo->detailsForAuditIds([]));

        $d = $repo->detailsForAuditIds([1, 2, 2, 99]);
        $this->assertSame([1, 2], array_keys($d));
        $this->assertSame(3, $d[1]['intersections']);
        $this->assertSame(1, $d[1]['no_ramps']);
        $this->assertSame(1, $d[1]['no_sign']);
        $this->assertSame(7, $d[1]['obs_total']);
        $this->assertSame(3, $d[1]['obs_partial']);
        $this->assertSame(1, $d[1]['cyclist_slowed']);
        $this->assertSame('Asphalt', $d[1]['audit']['surface_material']);
        $this->assertSame(0, $d[2]['intersections']);
        $this->assertSame(0, $d[2]['obs_total']);
    }
}
