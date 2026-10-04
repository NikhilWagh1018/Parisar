<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityLeaderGate.php';

class CityLeaderGateTest extends TestCase
{
    public function test_city_dashboard_is_allowed(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/pages/city_dashboard.php'));
        $this->assertTrue(cityLeaderMayAccess('/Parisar/pages/city_dashboard.php'));
        $this->assertTrue(cityLeaderMayAccess('\\Parisar\\pages\\city_dashboard.php'));
    }

    public function test_audit_report_pages_are_allowed(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/pages/city_audit_report.php'));
        $this->assertTrue(cityLeaderMayAccess('/pages/city_audit_report_detail.php'));
        $this->assertTrue(cityLeaderMayAccess('/Parisar/pages/city_audit_report_detail.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/city_audit_report_detail.php.bak'));
    }

    public function test_every_other_page_is_closed(): void
    {
        foreach (['dashboard', 'my_audits', 'map', 'leaderboard', 'admin', 'admin_surveyors',
                  'admin_activity', 'segment', 'form', 'view', 'report',
                  'road_result', 'platform_dashboard'] as $page) {
            $this->assertFalse(cityLeaderMayAccess('/pages/' . $page . '.php'), $page);
        }
    }

    public function test_apis_are_closed(): void
    {
        $this->assertFalse(cityLeaderMayAccess('/api/admin/roads.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/surveyors.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/dashboard/stats.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/user/audit_history.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/user/audit_export.php'));
    }

    public function test_lookalike_names_do_not_match(): void
    {
        $this->assertFalse(cityLeaderMayAccess('/pages/xcity_dashboard.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/city_dashboard.php.bak'));
        $this->assertFalse(cityLeaderMayAccess('/other/city_dashboard.php'));
        $this->assertFalse(cityLeaderMayAccess(''));
    }

    public function test_closed_pages_redirect_to_city_dashboard(): void
    {
        $this->assertSame('/pages/city_dashboard.php', cityLeaderRedirectTarget('/pages/map.php'));
        $this->assertSame('/Parisar/pages/city_dashboard.php', cityLeaderRedirectTarget('/Parisar/pages/admin.php'));
    }

    public function test_closed_apis_and_unknown_paths_get_403_not_redirect(): void
    {
        $this->assertNull(cityLeaderRedirectTarget('/api/admin/roads.php'));
        $this->assertNull(cityLeaderRedirectTarget('/Parisar/api/segments/submit.php'));
        $this->assertNull(cityLeaderRedirectTarget('/something_else.php'));
    }

    public function test_profile_page_and_its_api_are_allowed(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/pages/profile.php'));
        $this->assertTrue(cityLeaderMayAccess('/Parisar/api/user/profile.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/xprofile.php'));
    }

    public function test_audit_screens_are_allowed(): void
    {
        foreach (['/pages/city_audit.php', '/api/city/audit_create.php',
                  '/api/city/audit_road_add.php', '/api/city/audit_road_remove.php',
                  '/api/city/audit_assign.php', '/api/city/audit_activate.php'] as $path) {
            $this->assertTrue(cityLeaderMayAccess($path), $path);
            $this->assertTrue(cityLeaderMayAccess('/Parisar' . $path), $path);
        }
        $this->assertFalse(cityLeaderMayAccess('/api/city/anything_else.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/city_audits.php'));
    }

    public function test_review_and_report_pages_are_allowed(): void
    {
        foreach (['/pages/city_segment_review.php', '/pages/city_audit_report.php'] as $path) {
            $this->assertTrue(cityLeaderMayAccess($path), $path);
            $this->assertTrue(cityLeaderMayAccess('/Parisar' . $path), $path);
        }
        $this->assertFalse(cityLeaderMayAccess('/pages/city_segment_reviews.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/city_audit_report.php.bak'));
    }
}
