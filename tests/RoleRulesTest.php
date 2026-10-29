<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/RoleRules.php';

class RoleRulesTest extends TestCase
{
    public function test_platform_admin_can_manage_anyone(): void
    {
        foreach (['admin', 'city_leader', 'surveyor'] as $t) {
            $this->assertTrue(canManageUser('admin', $t));
        }
    }

    public function test_city_leader_can_manage_surveyors_only(): void
    {
        $this->assertTrue(canManageUser('city_leader', 'surveyor'));
        $this->assertFalse(canManageUser('city_leader', 'city_leader'));
        $this->assertFalse(canManageUser('city_leader', 'admin'));
    }

    public function test_surveyor_manages_nobody(): void
    {
        $this->assertFalse(canManageUser('surveyor', 'surveyor'));
    }

    public function test_city_leader_cannot_change_roles(): void
    {
        $this->assertNotNull(validateRoleChange('city_leader', 'surveyor', 1, 'city_leader', false, 2));
        $this->assertNotNull(validateRoleChange('city_leader', 'city_leader', 1, 'surveyor', false, 2));
    }

    public function test_platform_admin_can_make_a_city_leader_with_a_city(): void
    {
        $this->assertNull(validateRoleChange('admin', 'surveyor', 1, 'city_leader', false, 2));
    }

    public function test_city_leader_requires_a_city(): void
    {
        $this->assertNotNull(validateRoleChange('admin', 'surveyor', null, 'city_leader', false, 2));
        $this->assertNotNull(validateRoleChange('admin', 'surveyor', 0, 'city_leader', false, 2));
    }

    public function test_invalid_role_values_are_rejected(): void
    {
        foreach (['', 'national_admin', 'city_admin', 'SURVEYOR', null, 5, ['surveyor'], true] as $bad) {
            $this->assertSame('Invalid role.', validateRoleChange('admin', 'surveyor', 1, $bad, false, 2));
        }
    }

    public function test_cannot_demote_yourself(): void
    {
        $this->assertSame('You cannot demote yourself.', validateRoleChange('admin', 'admin', null, 'surveyor', true, 2));
    }

    public function test_cannot_demote_last_platform_admin(): void
    {
        $this->assertSame('Cannot demote the last remaining admin.', validateRoleChange('admin', 'admin', null, 'surveyor', false, 1));
        $this->assertNull(validateRoleChange('admin', 'admin', null, 'surveyor', false, 2));
    }

    public function test_platform_admin_can_demote_a_city_leader(): void
    {
        $this->assertNull(validateRoleChange('admin', 'city_leader', 1, 'surveyor', false, 2));
    }
}
