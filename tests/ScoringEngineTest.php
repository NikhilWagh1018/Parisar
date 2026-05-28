<?php
declare(strict_types=1);

/**
 * tests/ScoringEngineTest.php
 *
 * End-to-end tests for the scoring engine (_computeScoreFromData).
 * These verify that known audit inputs produce correct scores,
 * protecting against regressions in the N+1 fix and any future changes.
 *
 * Tests are pure PHP — no DB required.
 */

use PHPUnit\Framework\TestCase;

class ScoringEngineTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────

    private function audit(array $overrides = []): array
    {
        return array_merge([
            'buffer_zone'          => 'Segregated',   // 0 penalty
            'light_after_sunset'   => 'Yes',          // 0 penalty
            'shade'                => 'Yes',          // 0 penalty
            'surface_material'     => 'Concrete',     // 0 penalty
            'cycle_track_missing'  => 'No',
            'missing_length'       => 0,
            'segment_length'       => 500,
            'road_name'            => 'TEST ROAD',
        ], $overrides);
    }

    private function obs(array $overrides = []): array
    {
        return array_merge([
            'partial' => 0,
            'total'   => 0,
            'slowed'  => 0,
        ], $overrides);
    }

    // ── Perfect segment — all zeros ────────────────────────────

    public function test_perfect_segment_scores_zero(): void
    {
        $result = _computeScoreFromData($this->audit(), $this->obs(), []);

        $this->assertSame(0.0, $result['final']);
        $this->assertSame(0.0, $result['safety_score']);
        $this->assertSame(0.0, $result['continuity_score']);
        $this->assertSame(0.0, $result['comfort_score']);
        $this->assertSame('Good', $result['condition']);
    }

    // ── Worst segment — all 100 penalties ─────────────────────

    public function test_worst_segment_scores_100(): void
    {
        $audit = $this->audit([
            'buffer_zone'        => 'None',   // 100
            'light_after_sunset' => 'No',     // 100
            'shade'              => 'No',     // 100
            'surface_material'   => 'Interlock Blocks', // 100
        ]);
        $obs = $this->obs(['partial' => 20, 'total' => 20, 'slowed' => 30]);

        // 5 intersections all bad
        $ints = array_fill(0, 5, [
            'off_ramp'       => 'No ramp',
            'on_ramp'        => 'No ramp',
            'markings'       => 'Absent',
            'signage'        => 'Absent',
            'traffic_calming'=> 'Absent',
        ]);

        $result = _computeScoreFromData($audit, $obs, $ints);

        $this->assertSame(100.0, $result['final']);
        $this->assertSame('Very Bad', $result['condition']);
    }

    // ── Fully missing track ────────────────────────────────────

    public function test_fully_missing_track_scores_100(): void
    {
        $audit = $this->audit([
            'cycle_track_missing' => 'Yes',
            'missing_length'      => 500,
            'segment_length'      => 500,
        ]);

        $result = _computeScoreFromData($audit, $this->obs(), []);

        $this->assertSame(100.0, $result['final']);
        $this->assertSame(100.0, $result['safety_score']);
        $this->assertSame(100.0, $result['continuity_score']);
        $this->assertSame(100.0, $result['comfort_score']);
    }

    // ── Half missing track ─────────────────────────────────────

    public function test_half_missing_track_blends_score(): void
    {
        // Perfect audit data but half the track is missing
        // presentLen=250, missingLen=250, totalLen=500
        // applyMissingLength(0.0, 250, 250, 500) = (100*250 + 0*250)/500 = 50.0
        $audit = $this->audit([
            'cycle_track_missing' => 'Yes',
            'missing_length'      => 250,
            'segment_length'      => 500,
        ]);

        $result = _computeScoreFromData($audit, $this->obs(), []);

        $this->assertEqualsWithDelta(50.0, $result['safety_score'],     0.01);
        $this->assertEqualsWithDelta(50.0, $result['continuity_score'], 0.01);
        $this->assertEqualsWithDelta(50.0, $result['comfort_score'],    0.01);
        $this->assertEqualsWithDelta(50.0, $result['final'],            0.01);
    }

    // ── Weighted score formula verification ───────────────────

    public function test_weighted_score_continuity_weighs_most(): void
    {
        // Only continuity is bad (total_obs=20 → 100 penalty, rest perfect)
        $audit = $this->audit(['surface_material' => 'Concrete']);
        $obs   = $this->obs(['total' => 20]);  // totalObs=20 → 100

        $result = _computeScoreFromData($audit, $obs, []);

        // continuityRaw = (0 + 0 + 100) / 3 = 33.33
        // final = (0*1.0 + 0*1.25 + 33.33*1.5) / 3.75 = 13.33
        $this->assertEqualsWithDelta(13.33, $result['final'], 0.1);
        $this->assertSame(0.0, $result['safety_score']);
        $this->assertSame(0.0, $result['comfort_score']);
    }

    public function test_weighted_score_safety_only_bad(): void
    {
        // Only safety is bad: buffer_zone=None(100), light=No(100), rest=0
        $audit = $this->audit([
            'buffer_zone'        => 'None',
            'light_after_sunset' => 'No',
        ]);

        $result = _computeScoreFromData($audit, $this->obs(), []);

        // safetyRaw = (100+100+0+0)/4 = 50
        // final = (50*1.0 + 0*1.25 + 0*1.5) / 3.75 = 13.33
        $this->assertEqualsWithDelta(13.33, $result['final'],        0.1);
        $this->assertEqualsWithDelta(50.0,  $result['safety_score'], 0.1);
        $this->assertSame(0.0, $result['continuity_score']);
        $this->assertSame(0.0, $result['comfort_score']);
    }

    // ── Intersection logic ────────────────────────────────────

    public function test_one_bad_intersection_ramp(): void
    {
        $ints = [[
            'off_ramp'        => 'No ramp',
            'on_ramp'         => 'Ramp present',
            'markings'        => 'Present',
            'signage'         => 'Present',
            'traffic_calming' => 'Present',
        ]];

        $result = _computeScoreFromData($this->audit(), $this->obs(), $ints);

        // missingRamps=1 → 25, rest=0 → continuityRaw=(25+0+0)/3=8.33
        // final = (0*1.0 + 0*1.25 + 8.33*1.5) / 3.75 = 3.33
        $this->assertEqualsWithDelta(8.33,  $result['continuity_score'], 0.1);
        $this->assertEqualsWithDelta(3.33,  $result['final'],            0.1);
        $this->assertSame(0.0, $result['safety_score']);
    }

    public function test_case_insensitive_intersection_matching(): void
    {
        // Values from DB may have different casing — must still match
        $ints = [[
            'off_ramp'        => 'NO RAMP',    // uppercase
            'on_ramp'         => 'no ramp',    // lowercase
            'markings'        => 'ABSENT',
            'signage'         => 'absent',
            'traffic_calming' => 'Absent',
        ]];

        $result = _computeScoreFromData($this->audit(), $this->obs(), $ints);

        // All bad intersection values — should have high continuity/safety penalty
        $this->assertGreaterThan(0.0, $result['continuity_score']);
        $this->assertGreaterThan(0.0, $result['safety_score']);
    }

    // ── Score bounds ──────────────────────────────────────────

    public function test_final_score_never_below_zero(): void
    {
        $result = _computeScoreFromData($this->audit(), $this->obs(), []);
        $this->assertGreaterThanOrEqual(0.0, $result['final']);
    }

    public function test_final_score_never_above_100(): void
    {
        $audit = $this->audit(['buffer_zone' => 'None', 'light_after_sunset' => 'No']);
        $obs   = $this->obs(['partial' => 100, 'total' => 100, 'slowed' => 100]);
        $ints  = array_fill(0, 10, [
            'off_ramp' => 'No ramp', 'on_ramp' => 'No ramp',
            'markings' => 'Absent',  'signage' => 'Absent',
            'traffic_calming' => 'Absent',
        ]);

        $result = _computeScoreFromData($audit, $obs, $ints);
        $this->assertLessThanOrEqual(100.0, $result['final']);
    }

    // ── condition labels ──────────────────────────────────────

    public function test_condition_labels_match_score_ranges(): void
    {
        $cases = [
            [0.0,   'Good'],
            [20.0,  'Good'],
            [20.1,  'OK'],
            [40.0,  'OK'],
            [40.1,  'Poor'],
            [60.0,  'Poor'],
            [60.1,  'Bad'],
            [80.0,  'Bad'],
            [80.1,  'Very Bad'],
            [100.0, 'Very Bad'],
        ];

        foreach ($cases as [$score, $expected]) {
            $this->assertSame(
                $expected,
                scoreToCondition($score),
                "Expected '{$expected}' for score {$score}"
            );
        }
    }

    // ── Road score weighted average ───────────────────────────

    public function test_road_score_is_length_weighted_average(): void
    {
        // Simulate what calculateRoadScore() does in PHP
        // Segment A: 500m, perfect (score=0)
        // Segment B: 500m, worst   (score=100)
        // Expected road score: (0*500 + 100*500) / 1000 = 50.0

        $segA = _computeScoreFromData($this->audit(['segment_length' => 500]), $this->obs(), []);
        $segB = _computeScoreFromData($this->audit([
            'segment_length'     => 500,
            'buffer_zone'        => 'None',
            'light_after_sunset' => 'No',
            'shade'              => 'No',
            'surface_material'   => 'Interlock Blocks',
        ]), $this->obs(['partial' => 20, 'total' => 20, 'slowed' => 30]), array_fill(0, 5, [
            'off_ramp' => 'No ramp', 'on_ramp' => 'No ramp',
            'markings' => 'Absent',  'signage' => 'Absent',
            'traffic_calming' => 'Absent',
        ]));

        $roadScore = ($segA['final'] * 500 + $segB['final'] * 500) / 1000;

        $this->assertSame(0.0,   $segA['final']);
        $this->assertSame(100.0, $segB['final']);
        $this->assertEqualsWithDelta(50.0, $roadScore, 0.01);
    }
}
