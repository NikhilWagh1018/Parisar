<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/RoleHome.php';

class RoleHomeTest extends TestCase
{
    public function test_national_admin_goes_to_platform_dashboard(): void
    {
        $this->assertSame('platform_dashboard.php', roleHomePage('national_admin'));
    }

    public function test_city_admin_goes_to_city_dashboard(): void
    {
        $this->assertSame('city_dashboard.php', roleHomePage('city_admin'));
    }

    public function test_surveyor_and_unknown_roles_stay_on_dashboard(): void
    {
        $this->assertSame('dashboard.php', roleHomePage('surveyor'));
        $this->assertSame('dashboard.php', roleHomePage(''));
        $this->assertSame('dashboard.php', roleHomePage('something_else'));
    }
}
