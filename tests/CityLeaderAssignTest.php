<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityLeaderAssign.php';
require_once __DIR__ . '/../helpers/Cities.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/AdminDashboard.php';

/** Rules for an Admin making someone a City Leader. */
class CityLeaderAssignTest extends TestCase
{
    private function user(string $role = 'surveyor', ?int $city = 1, int $active = 1): array
    {
        return ['role' => $role, 'is_active' => $active, 'city_id' => $city];
    }

    public function test_an_active_surveyor_in_the_city_can_be_promoted(): void
    {
        $this->assertNull(cityLeaderAssignError($this->user(), 1));
    }

    public function test_a_surveyor_with_no_city_can_be_promoted(): void
    {
        $this->assertNull(cityLeaderAssignError($this->user('surveyor', null), 1));
    }

    public function test_missing_inactive_and_admin_users_are_refused(): void
    {
        $this->assertSame('User not found.', cityLeaderAssignError(null, 1));
        $this->assertSame('This user is inactive.', cityLeaderAssignError($this->user('surveyor', 1, 0), 1));
        $this->assertSame('An Admin cannot be a City Leader.', cityLeaderAssignError($this->user('admin', null), 1));
    }

    public function test_someone_in_another_city_must_be_moved_first(): void
    {
        $this->assertStringContainsString('another city', (string)cityLeaderAssignError($this->user('surveyor', 2), 1));
        $this->assertStringContainsString('another city', (string)cityLeaderAssignError($this->user('city_leader', 2), 1));
    }

    public function test_the_current_leader_cannot_be_assigned_again(): void
    {
        $this->assertStringContainsString('already', (string)cityLeaderAssignError($this->user('city_leader', 1), 1));
    }

    public function test_the_no_leader_alert_points_at_the_assign_picker(): void
    {
        $items = adminAttentionItems([], [], 0, [['id' => 7, 'name' => 'Yavatmal']]);
        $this->assertSame('Yavatmal has no City Leader', $items[0]['title']);
        $this->assertSame('platform_dashboard.php?assign_leader=7#npCities', $items[0]['href']);
        $this->assertStringContainsString('add roads', $items[0]['detail']);
    }
}
