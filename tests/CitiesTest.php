<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Cities.php';

class CitiesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    private function addCities(string ...$names): void
    {
        foreach ($names as $n) {
            $this->pdo->prepare('INSERT INTO cities (name) VALUES (?)')->execute([$n]);
        }
    }

    public function test_listCities_is_alphabetical_with_int_ids(): void
    {
        $this->addCities('Pune', 'Mumbai');
        $this->assertSame(
            [['id' => 2, 'name' => 'Mumbai'], ['id' => 1, 'name' => 'Pune']],
            listCities($this->pdo)
        );
    }

    public function test_soleCityId(): void
    {
        $this->assertNull(soleCityId($this->pdo));
        $this->addCities('Pune');
        $this->assertSame(1, soleCityId($this->pdo));
        $this->addCities('Mumbai');
        $this->assertNull(soleCityId($this->pdo));
    }

    public function test_signup_with_no_cities_assigns_nothing(): void
    {
        $this->assertSame([null, null], resolveSignupCity([], ''));
    }

    public function test_signup_with_one_city_is_automatic_and_ignores_the_form(): void
    {
        $this->addCities('Pune');
        $cities = listCities($this->pdo);
        $this->assertSame([1, null], resolveSignupCity($cities, ''));
        $this->assertSame([1, null], resolveSignupCity($cities, '999'));
    }

    public function test_signup_with_several_cities_requires_a_valid_choice(): void
    {
        $this->addCities('Pune', 'Mumbai');
        $cities = listCities($this->pdo);
        $this->assertSame([1, null], resolveSignupCity($cities, '1'));
        $this->assertSame([2, null], resolveSignupCity($cities, 2));
        foreach (['', '0', '3', 'abc', '1 OR 1=1', null, [1]] as $bad) {
            $this->assertSame([null, 'Please select your city.'], resolveSignupCity($cities, $bad));
        }
    }
}
