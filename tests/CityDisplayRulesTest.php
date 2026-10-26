<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/SurveyLabels.php';

/** How scores, times and surveyor answers are shown to a City Leader. */
class CityDisplayRulesTest extends TestCase
{
    // ── score colours: lower is better, same rule on every screen ──

    public function test_score_bar_class_treats_low_scores_as_good(): void
    {
        $this->assertSame('', cityScoreBarClass(0.0));
        $this->assertSame('', cityScoreBarClass(20.0));
        $this->assertSame('mid', cityScoreBarClass(20.01));
        $this->assertSame('mid', cityScoreBarClass(34.67));
        $this->assertSame('mid', cityScoreBarClass(38.75));
        $this->assertSame('mid', cityScoreBarClass(60.0));
        $this->assertSame('bad', cityScoreBarClass(60.01));
        $this->assertSame('bad', cityScoreBarClass(100.0));
    }

    // ── India time ─────────────────────────────────────────────

    public function test_local_time_converts_utc_to_india_time(): void
    {
        $this->assertSame('26 Oct 2026, 12:54 PM', cityLocalTime('2026-10-26 07:24:25'));
        $this->assertSame('—', cityLocalTime(null));
        $this->assertSame('—', cityLocalTime('  '));
    }

    // ── readable answers ───────────────────────────────────────

    public function test_known_keys_use_the_wording_from_the_form(): void
    {
        $this->assertSame(
            ['Gravel / Sand / Debris / Dirt', 'Water Stagnation / Surface Undulation', 'Tree Roots'],
            surveyAnswerLabels('surface_issues', '["gravel","water","roots"]')
        );
        $this->assertSame(['Cables'], surveyAnswerLabels('overhead_issues', '["overheadCables"]'));
        $this->assertSame(
            ['Min 1.8 m wide', 'Continuous and levelled', 'Disabled friendly (access ramps with tactile tiles)'],
            surveyAnswerLabels('footpath_rating', '["minWidth","continuous","disabledFriendly"]')
        );
    }

    public function test_every_footpath_key_the_scorer_uses_has_a_label(): void
    {
        foreach (['minWidth', 'continuous', 'obstructionFree', 'disabledFriendly', 'comfort'] as $key) {
            $this->assertArrayHasKey($key, SURVEY_ANSWER_LABELS['footpath_rating']);
        }
    }

    public function test_unknown_keys_are_humanised_not_dropped(): void
    {
        $this->assertSame(['Some New Option'], surveyAnswerLabels('surface_issues', '["someNewOption"]'));
        $this->assertSame('Garbage Bins', surveyHumanize('GarbageBins'));
        $this->assertSame('Traffic Signal', surveyHumanize('TrafficSignal'));
        $this->assertSame('CCTV', surveyHumanize('CCTV'));
        $this->assertSame('Fixed', surveyHumanize('fixed'));
        $this->assertSame('', surveyHumanize('  '));
    }

    public function test_empty_or_broken_answers_give_no_labels(): void
    {
        $this->assertSame([], surveyAnswerLabels('surface_issues', null));
        $this->assertSame([], surveyAnswerLabels('surface_issues', '[]'));
        $this->assertSame([], surveyAnswerLabels('surface_issues', ''));
        $this->assertSame([], surveyAnswerLabels('surface_issues', 'not json'));
        $this->assertSame([], surveyAnswerLabels('surface_issues', '["", " "]'));
    }

    public function test_an_array_answer_works_too(): void
    {
        $this->assertSame(['Branches'], surveyAnswerLabels('overhead_issues', ['branches']));
    }
}
