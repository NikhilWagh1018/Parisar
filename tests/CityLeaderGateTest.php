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

    public function test_every_other_page_is_closed(): void
    {
        foreach (['dashboard', 'my_audits', 'map', 'leaderboard', 'admin', 'admin_surveyors',
                  'admin_activity', 'profile', 'segment', 'form', 'view', 'report',
                  'road_result', 'platform_dashboard'] as $page) {
            $this->assertFalse(cityLeaderMayAccess('/pages/' . $page . '.php'), $page);
        }
    }

    public function test_apis_are_closed(): void
    {
        $this->assertFalse(cityLeaderMayAccess('/api/admin/roads.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/admin/surveyors.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/dashboard/stats.php'));
        $this->assertFalse(cityLeaderMayAccess('/api/user/profile.php'));
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
}
