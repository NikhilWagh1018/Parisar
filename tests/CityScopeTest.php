<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityScope.php';

class CityScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    private function addCities(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->pdo->exec("INSERT INTO cities (name) VALUES ('City $i')");
        }
    }

    public function test_national_admin_is_unscoped(): void
    {
        $this->addCities(2);
        $this->assertNull(resolveViewerCityScope($this->pdo, 'national_admin', null));
        $this->assertNull(resolveViewerCityScope($this->pdo, 'national_admin', 2));
    }

    public function test_user_with_city_gets_that_city(): void
    {
        $this->addCities(2);
        $this->assertSame(2, resolveViewerCityScope($this->pdo, 'surveyor', 2));
        $this->assertSame(1, resolveViewerCityScope($this->pdo, 'city_admin', 1));
    }

    public function test_user_without_city_falls_back_to_the_only_city(): void
    {
        $this->addCities(1);
        $this->assertSame(1, resolveViewerCityScope($this->pdo, 'surveyor', null));
    }

    public function test_user_without_city_fails_closed_with_several_cities(): void
    {
        $this->addCities(2);
        $this->assertSame(0, resolveViewerCityScope($this->pdo, 'surveyor', null));
    }

    public function test_user_without_city_fails_closed_with_no_cities(): void
    {
        $this->assertSame(0, resolveViewerCityScope($this->pdo, 'city_admin', null));
    }
}
