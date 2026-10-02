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
            'audit_year' => '2026', 'programme_info' => '  Funded by X  ',
        ]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('Pune Cycle Audit', $r['clean']['name']);
        $this->assertSame('Maharashtra', $r['clean']['state']);
        $this->assertSame(2026, $r['clean']['audit_year']);
        $this->assertSame('Funded by X', $r['clean']['programme_info']);
    }

    public function test_programme_info_is_optional(): void
    {
        $r = cityAuditValidate(['name' => 'Audit one', 'state' => 'Goa', 'audit_year' => 2026]);
        $this->assertSame([], $r['errors']);
        $this->assertNull($r['clean']['programme_info']);
    }

    public function test_bad_audit_input_is_rejected(): void
    {
        $r = cityAuditValidate(['name' => 'ab', 'state' => 'M4harashtra', 'audit_year' => '1999']);
        $this->assertTrue(isset($r['errors']['name']));
        $this->assertTrue(isset($r['errors']['state']));
        $this->assertTrue(isset($r['errors']['audit_year']));

        $r = cityAuditValidate(['name' => 'Valid name', 'state' => 'Goa', 'audit_year' => 'abc']);
        $this->assertTrue(isset($r['errors']['audit_year']));

        $r = cityAuditValidate(['name' => 'Valid name', 'state' => 'Goa', 'audit_year' => 2026,
                                'programme_info' => str_repeat('x', 2001)]);
        $this->assertTrue(isset($r['errors']['programme_info']));
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
}
