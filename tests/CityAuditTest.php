<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityAudit.php';

class CityAuditTest extends TestCase
{
    public function test_valid_audit_input_is_cleaned(): void
    {
        $r = cityAuditValidate([
            'name' => "  Pune   <b>Cycle</b> Audit ", 'state' => ' Maharashtra ',
            'audit_date' => '2026-10-04', 'programme_info' => '  Funded by X  ',
        ]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('Pune Cycle Audit', $r['clean']['name']);
        $this->assertSame('Maharashtra', $r['clean']['state']);
        $this->assertSame('2026-10-04', $r['clean']['audit_date']);
        $this->assertSame(2026, $r['clean']['audit_year'], 'the year comes from the date');
        $this->assertSame('Funded by X', $r['clean']['programme_info']);
    }

    public function test_programme_info_is_optional(): void
    {
        $r = cityAuditValidate(['name' => 'Audit one', 'state' => 'Goa', 'audit_date' => '2026-02-28']);
        $this->assertSame([], $r['errors']);
        $this->assertNull($r['clean']['programme_info']);
    }

    public function test_bad_audit_input_is_rejected(): void
    {
        $r = cityAuditValidate(['name' => 'ab', 'state' => 'M4harashtra', 'audit_date' => '1999-12-31']);
        $this->assertTrue(isset($r['errors']['name']));
        $this->assertTrue(isset($r['errors']['state']));
        $this->assertTrue(isset($r['errors']['audit_date']));

        $r = cityAuditValidate(['name' => 'Valid name', 'state' => 'Goa', 'audit_date' => 'abc']);
        $this->assertTrue(isset($r['errors']['audit_date']));

        $r = cityAuditValidate(['name' => 'Valid name', 'state' => 'Goa', 'audit_date' => '2026-10-04',
                                'programme_info' => str_repeat('x', 2001)]);
        $this->assertTrue(isset($r['errors']['programme_info']));
    }

    public function test_audit_date_must_be_a_real_calendar_date(): void
    {
        $base = ['name' => 'Valid name', 'state' => 'Goa'];
        foreach (['', '2026-02-30', '2026-13-01', '2026-1-5', '04/10/2026', '2026-10-04 10:00', '2101-01-01', '2026'] as $bad) {
            $r = cityAuditValidate($base + ['audit_date' => $bad]);
            $this->assertTrue(isset($r['errors']['audit_date']), "should reject '$bad'");
        }
        $this->assertSame([], cityAuditValidate($base + ['audit_date' => '2024-02-29'])['errors'], 'leap day is valid');
        $this->assertTrue(isset(cityAuditValidate($base)['errors']['audit_date']), 'a missing date is rejected');
    }

    public function test_road_input_validation(): void
    {
        $ok = cityAuditValidateRoad(['road_group_id' => '3', 'total_length' => '1500', 'segment_length' => '500']);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(3, $ok['clean']['road_group_id']);
        $this->assertSame(1500.0, $ok['clean']['total_length']);

        $bad = cityAuditValidateRoad(['road_group_id' => '', 'total_length' => '49', 'segment_length' => '5']);
        $this->assertTrue(isset($bad['errors']['road_group_id']));
        $this->assertTrue(isset($bad['errors']['total_length']));
        $this->assertTrue(isset($bad['errors']['segment_length']));

        $many = cityAuditValidateRoad(['road_group_id' => 1, 'total_length' => 100000, 'segment_length' => 10]);
        $this->assertTrue(isset($many['errors']['segment_length']));
    }

    public function test_plan_equal_segments(): void
    {
        $p = cityAuditSegmentPlan(1500.0, 500.0);
        $this->assertSame(3, count($p));
        $this->assertSame(0.0, $p[0]['start_distance']);
        $this->assertSame(500.0, $p[0]['end_distance']);
        $this->assertSame(1000.0, $p[2]['start_distance']);
        $this->assertSame(1500.0, $p[2]['end_distance']);
        $this->assertSame('0m', $p[0]['start_label']);
        $this->assertSame('1500m', $p[2]['end_label']);
    }

    public function test_plan_last_segment_is_the_remainder(): void
    {
        $p = cityAuditSegmentPlan(2000.0, 300.0);
        $this->assertSame(7, count($p));
        $this->assertSame(1800.0, $p[6]['start_distance']);
        $this->assertSame(2000.0, $p[6]['end_distance']);
        $this->assertSame(200.0, $p[6]['length']);

        $q = cityAuditSegmentPlan(1000.0, 300.0);
        $this->assertSame(4, count($q));
        $this->assertSame(100.0, $q[3]['length']);
    }

    public function test_plan_short_road_is_one_segment(): void
    {
        $p = cityAuditSegmentPlan(50.0, 100.0);
        $this->assertSame(1, count($p));
        $this->assertSame(50.0, $p[0]['length']);
        $this->assertSame('50m', $p[0]['end_label']);
    }

    public function test_plan_decimal_lengths_do_not_drift(): void
    {
        $p = cityAuditSegmentPlan(1000.5, 250.25);
        $this->assertSame(4, count($p));
        $this->assertSame('250.25m', $p[0]['end_label']);
        $this->assertSame('750.75m', $p[3]['start_label']);
        $this->assertSame(1000.5, $p[3]['end_distance']);
        $this->assertSame(249.75, $p[3]['length']);
    }

    public function test_plan_rejects_bad_numbers(): void
    {
        foreach ([[49.0, 10.0], [100.0, 9.0], [100000.0, 10.0]] as [$total, $seg]) {
            try {
                cityAuditSegmentPlan($total, $seg);
                $this->fail("expected an exception for $total / $seg");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_assignment_input_for_segments(): void
    {
        $r = cityAuditValidateAssignment(['segment_ids' => ['4', 5, 5], 'surveyor_id' => '9']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('segments', $r['clean']['mode']);
        $this->assertSame([4, 5], $r['clean']['segment_ids']);
        $this->assertSame(9, $r['clean']['surveyor_id']);
    }

    public function test_assignment_input_for_whole_road_and_unassign(): void
    {
        $r = cityAuditValidateAssignment(['road_id' => '3', 'surveyor_id' => 7]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('road', $r['clean']['mode']);
        $this->assertSame(3, $r['clean']['road_id']);

        foreach ([null, '', 0, '0'] as $blank) {
            $u = cityAuditValidateAssignment(['road_id' => 3, 'surveyor_id' => $blank]);
            $this->assertSame([], $u['errors']);
            $this->assertNull($u['clean']['surveyor_id']);
        }
    }

    public function test_bad_assignment_input_is_rejected(): void
    {
        $this->assertTrue(isset(cityAuditValidateAssignment(['surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['segment_ids' => [], 'surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['segment_ids' => [1, 'x'], 'surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['segment_ids' => [0], 'surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['segment_ids' => 'abc', 'surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['segment_ids' => range(1, 501), 'surveyor_id' => 1])['errors']['segment_ids']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['road_id' => 'x', 'surveyor_id' => 1])['errors']['road_id']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['road_id' => 2, 'segment_ids' => [1], 'surveyor_id' => 1])['errors']['road_id']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['road_id' => 2, 'surveyor_id' => 'abc'])['errors']['surveyor_id']));
        $this->assertTrue(isset(cityAuditValidateAssignment(['road_id' => 2, 'surveyor_id' => -4])['errors']['surveyor_id']));
    }
}
