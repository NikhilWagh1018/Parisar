<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/SegmentAnswers.php';

/** The answers a surveyor must give before a segment can be submitted. */
class SegmentAnswersTest extends TestCase
{
    /** A complete submission: no obstructions, no intersections. */
    private function good(array $over = []): array
    {
        return array_merge([
            'buffer_zone'         => 'None',
            'light_after_sunset'  => 'Yes',
            'surface_material'    => 'Concrete',
            'shade'               => 'Partial',
            'cycle_track_missing' => 'No',
            'no_obstructions'     => '1',
            'no_intersections'    => '1',
        ], $over);
    }

    public function test_complete_form_passes(): void
    {
        $this->assertNull(segmentAnswersError($this->good(), 500.0, []));
    }

    public function test_blank_form_is_rejected(): void
    {
        $this->assertNotNull(segmentAnswersError([], 500.0, []));
    }

    /** @dataProvider requiredRadios */
    public function test_each_scoring_radio_is_required(string $field): void
    {
        $post = $this->good();
        unset($post[$field]);
        $err = segmentAnswersError($post, 500.0, []);
        $this->assertNotNull($err);
        $this->assertStringStartsWith('Please answer:', $err);

        $post[$field] = '   ';
        $this->assertNotNull(segmentAnswersError($post, 500.0, []));

        $post[$field] = 'Made up value';
        $this->assertNotNull(segmentAnswersError($post, 500.0, []));
    }

    public static function requiredRadios(): array
    {
        return [['buffer_zone'], ['light_after_sunset'], ['surface_material'], ['shade'], ['cycle_track_missing']];
    }

    public function test_missing_length_needed_only_when_track_is_missing(): void
    {
        $this->assertNull(segmentAnswersError($this->good(['cycle_track_missing' => 'No']), 500.0, []));

        $yes = $this->good(['cycle_track_missing' => 'Yes']);
        $this->assertStringContainsString('missing cycle track length', (string)segmentAnswersError($yes, 500.0, []));
        $this->assertNotNull(segmentAnswersError($yes + ['missing_length' => 'abc'], 500.0, []));
        $this->assertNotNull(segmentAnswersError(array_merge($yes, ['missing_length' => '0']), 500.0, []));
        $this->assertNotNull(segmentAnswersError(array_merge($yes, ['missing_length' => '-5']), 500.0, []));
        $this->assertStringContainsString('500 m', (string)segmentAnswersError(array_merge($yes, ['missing_length' => '501']), 500.0, []));
        $this->assertNull(segmentAnswersError(array_merge($yes, ['missing_length' => '120.5']), 500.0, []));
        $this->assertNull(segmentAnswersError(array_merge($yes, ['missing_length' => '500']), 500.0, []));
    }

    public function test_obstructions_need_a_count_or_a_none_confirmation(): void
    {
        $post = $this->good();
        unset($post['no_obstructions']);
        $this->assertStringContainsString('No obstructions', (string)segmentAnswersError($post, 500.0, []));

        $post['fixed_Poles_partial'] = '3';
        $this->assertNull(segmentAnswersError($post, 500.0, []));

        $both = $post + ['no_obstructions' => '1'];
        $this->assertStringContainsString('Untick', (string)segmentAnswersError($both, 500.0, []));

        $zero = $this->good(['fixed_Poles_partial' => '0']);
        unset($zero['no_obstructions']);
        $this->assertNotNull(segmentAnswersError($zero, 500.0, []));
    }

    public function test_obstruction_count_sums_all_categories(): void
    {
        $this->assertSame(0, segmentObstructionCount([]));
        $this->assertSame(6, segmentObstructionCount([
            'fixed_Poles_partial' => '3', 'movable_Hawkers_total' => '2', 'parked_PublicSpace_slowed' => '1',
        ]));
        $this->assertSame(0, segmentObstructionCount(['fixed_Poles_partial' => '-4', 'unknown_Type_total' => '9']));
    }

    public function test_intersections_need_rows_or_a_none_confirmation(): void
    {
        $post = $this->good();
        unset($post['no_intersections']);
        $this->assertStringContainsString('No intersections', (string)segmentAnswersError($post, 500.0, []));

        $row = ['off_ramp' => 'Comfortable', 'on_ramp' => 'No Ramp', 'markings' => 'Present',
                'signage' => 'Absent', 'traffic_calming' => 'Absent'];
        $this->assertNull(segmentAnswersError($post, 500.0, [$row]));

        $both = $this->good();
        $this->assertStringContainsString('Untick', (string)segmentAnswersError($both, 500.0, [$row]));
    }

    /** @dataProvider intersectionFields */
    public function test_every_intersection_field_is_required(string $field): void
    {
        $post = $this->good();
        unset($post['no_intersections']);
        $row = ['off_ramp' => 'Comfortable', 'on_ramp' => 'Comfortable', 'markings' => 'Present',
                'signage' => 'Present', 'traffic_calming' => 'Present'];

        $row[$field] = null;
        $err = segmentAnswersError($post, 500.0, [$row]);
        $this->assertNotNull($err);
        $this->assertStringStartsWith('Intersection 1:', $err);

        $row[$field] = 'Bogus';
        $this->assertNotNull(segmentAnswersError($post, 500.0, [$row]));
    }

    public static function intersectionFields(): array
    {
        return [['off_ramp'], ['on_ramp'], ['markings'], ['signage'], ['traffic_calming']];
    }

    public function test_second_incomplete_intersection_is_named(): void
    {
        $post = $this->good();
        unset($post['no_intersections']);
        $ok  = ['off_ramp' => 'Comfortable', 'on_ramp' => 'Comfortable', 'markings' => 'Present',
                'signage' => 'Present', 'traffic_calming' => 'Present'];
        $bad = $ok; $bad['signage'] = '';
        $this->assertStringStartsWith('Intersection 2:', (string)segmentAnswersError($post, 500.0, [$ok, $bad]));
    }
}
