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
        foreach (['dashboard', 'admin_surveyors',
                  'admin_activity', 'segment', 'form', 'view', 'report',
                  'road_result', 'platform_dashboard'] as $page) {
            $this->assertFalse(cityLeaderMayAccess('/pages/' . $page . '.php'), $page);
        }
    }

    public function test_apis_are_closed(): void
    {
        $this->assertFalse(cityLeaderMayAccess('/api/admin/surveyors.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/export-users.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/city_leader.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/dashboard/stats.php'));
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
        $this->assertSame('/pages/city_dashboard.php', cityLeaderRedirectTarget('/pages/dashboard.php'));
        $this->assertSame('/Parisar/pages/city_dashboard.php', cityLeaderRedirectTarget('/Parisar/pages/admin_surveyors.php'));
    }

    public function test_closed_apis_and_unknown_paths_get_403_not_redirect(): void
    {
        $this->assertNull(cityLeaderRedirectTarget('/api/admin/surveyors.php'));
        $this->assertNull(cityLeaderRedirectTarget('/Parisar/api/segments/submit.php'));
        $this->assertNull(cityLeaderRedirectTarget('/something_else.php'));
    }

    public function test_profile_page_and_its_api_are_allowed(): void
    {
        $this->assertTrue(cityLeaderMayAccess('/pages/profile.php'));
        $this->assertTrue(cityLeaderMayAccess('/Parisar/api/user/profile.php'));
        $this->assertFalse(cityLeaderMayAccess('/pages/xprofile.php'));
    }

    public function test_notifications_api_is_allowed(): void
    {
        // The dashboard's "Mark all as read" posts here; a 403 made it fail silently.
        $this->assertTrue(cityLeaderMayAccess('/pages/../api/user/notifications.php'));
        $this->assertTrue(cityLeaderMayAccess('/Parisar/api/user/notifications.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/user/xnotifications.php'));
    }

    public function test_audit_screens_are_allowed(): void
    {
        foreach (['/pages/city_audit.php',
                  '/api/city/audit_road_add.php', '/api/city/audit_road_remove.php',
                  '/api/city/audit_assign.php', '/api/city/audit_activate.php'] as $path) {
            $this->assertTrue(cityLeaderMayAccess($path), $path);
            $this->assertTrue(cityLeaderMayAccess('/Parisar' . $path), $path);
        }
        // Admins create audits now; the old City Leader endpoint is closed.
        $this->assertFalse(cityLeaderMayAccess('/api/city/audit_create.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/audit_create.php'));
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

    public function test_roads_page_and_its_apis_are_allowed(): void
    {
        foreach (['/pages/admin.php', '/api/admin/roads.php', '/api/admin/export-roads.php',
                  '/api/reports/export-city-excel.php'] as $path) {
            $this->assertTrue(cityLeaderMayAccess($path), $path);
            $this->assertTrue(cityLeaderMayAccess('/Parisar' . $path), $path);
        }
        $this->assertFalse(cityLeaderMayAccess('/pages/admin.php.bak'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/roads.php.bak'));
        $this->assertFalse(cityLeaderMayAccess('/api/reports/export-excel.php'));
    }
}
