<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/RoleRules.php';

class RoleRulesTest extends TestCase
{
    public function test_platform_admin_can_manage_anyone(): void
    {
        foreach (['national_admin', 'city_admin', 'surveyor'] as $t) {
            $this->assertTrue(canManageUser('national_admin', $t));
        }
    }

    public function test_city_leader_can_manage_surveyors_only(): void
    {
        $this->assertTrue(canManageUser('city_admin', 'surveyor'));
        $this->assertFalse(canManageUser('city_admin', 'city_admin'));
        $this->assertFalse(canManageUser('city_admin', 'national_admin'));
    }

    public function test_surveyor_manages_nobody(): void
    {
        $this->assertFalse(canManageUser('surveyor', 'surveyor'));
    }

    public function test_city_leader_cannot_change_roles(): void
    {
        $this->assertNotNull(validateRoleChange('city_admin', 'surveyor', 1, 'city_admin', false, 2));
        $this->assertNotNull(validateRoleChange('city_admin', 'city_admin', 1, 'surveyor', false, 2));
    }

    public function test_platform_admin_can_make_a_city_leader_with_a_city(): void
    {
        $this->assertNull(validateRoleChange('national_admin', 'surveyor', 1, 'city_admin', false, 2));
    }

    public function test_city_leader_requires_a_city(): void
    {
        $this->assertNotNull(validateRoleChange('national_admin', 'surveyor', null, 'city_admin', false, 2));
        $this->assertNotNull(validateRoleChange('national_admin', 'surveyor', 0, 'city_admin', false, 2));
    }

    public function test_invalid_role_values_are_rejected(): void
    {
        foreach (['', 'admin', 'SURVEYOR', null, 5, ['surveyor'], true] as $bad) {
            $this->assertSame('Invalid role.', validateRoleChange('national_admin', 'surveyor', 1, $bad, false, 2));
        }
    }

    public function test_cannot_demote_yourself(): void
    {
        $this->assertSame('You cannot demote yourself.', validateRoleChange('national_admin', 'national_admin', null, 'surveyor', true, 2));
    }

    public function test_cannot_demote_last_platform_admin(): void
    {
        $this->assertSame('Cannot demote the last remaining admin.', validateRoleChange('national_admin', 'national_admin', null, 'surveyor', false, 1));
        $this->assertNull(validateRoleChange('national_admin', 'national_admin', null, 'surveyor', false, 2));
    }

    public function test_platform_admin_can_demote_a_city_leader(): void
    {
        $this->assertNull(validateRoleChange('national_admin', 'city_admin', 1, 'surveyor', false, 2));
    }
}
