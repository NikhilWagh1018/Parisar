<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/RoadRepository.php';

/**
 * Road names are unique per city, not globally: the same name in two
 * cities is two different roads. Uses in-memory SQLite with the same
 * UNIQUE (city_id, canonical_name) shape as the live road_groups table.
 */
class RoadRepositoryCityTest extends TestCase
{
    private PDO $pdo;
    private RoadRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, city_id INTEGER NULL)');
        $this->pdo->exec(
            'CREATE TABLE road_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                canonical_name TEXT NOT NULL,
                city_id INTEGER NOT NULL,
                assigned_surveyor_id INTEGER NULL,
                is_verified INTEGER NOT NULL DEFAULT 0,
                UNIQUE (city_id, canonical_name)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE roads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                creator_id INTEGER, name TEXT, road_group_id INTEGER,
                total_length REAL NULL, segment_method TEXT, segment_length REAL NULL,
                public_id TEXT NULL
            )'
        );
        $this->pdo->exec("INSERT INTO cities (name) VALUES ('Pune'), ('Mumbai')");
        $this->pdo->exec('INSERT INTO users (id, city_id) VALUES (1, 1), (2, 2), (3, NULL)');
        $this->repo = new RoadRepository($this->pdo);
    }

    private function groupCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM road_groups')->fetchColumn();
    }

    public function test_same_name_in_two_cities_gets_two_groups(): void
    {
        $a = $this->repo->create(1, ['name' => 'Karve Road'], 1);
        $b = $this->repo->create(2, ['name' => 'Karve Road'], 2);

        $this->assertSame(2, $this->groupCount());
        $groupOf = fn(int $roadId) => (int)$this->pdo
            ->query("SELECT road_group_id FROM roads WHERE id = $roadId")->fetchColumn();
        $this->assertNotSame($groupOf($a['road_id']), $groupOf($b['road_id']));

        $cities = $this->pdo->query('SELECT city_id FROM road_groups ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], array_map('intval', $cities));
    }

    public function test_same_name_in_same_city_reuses_the_group(): void
    {
        $this->repo->create(1, ['name' => 'Karve Road'], 1);
        $this->repo->create(1, ['name' => '  karve road '], 1);
        $this->assertSame(1, $this->groupCount());
    }

    public function test_create_without_city_uses_the_creators_city(): void
    {
        $this->repo->create(2, ['name' => 'Law College Road']);
        $this->assertSame(
            2,
            (int)$this->pdo->query("SELECT city_id FROM road_groups WHERE canonical_name = 'LAW COLLEGE ROAD'")->fetchColumn()
        );
    }

    public function test_roadGroupExists_only_sees_the_given_city(): void
    {
        $this->repo->create(1, ['name' => 'Karve Road'], 1);
        $this->assertTrue($this->repo->roadGroupExists('karve road', 1));
        $this->assertFalse($this->repo->roadGroupExists('KARVE ROAD', 2));
    }

    public function test_getAssignedSurveyorId_is_scoped_to_the_city(): void
    {
        $this->repo->create(1, ['name' => 'Karve Road'], 1);
        $this->repo->create(2, ['name' => 'Karve Road'], 2);
        $this->pdo->exec("UPDATE road_groups SET assigned_surveyor_id = 7 WHERE city_id = 1");

        $this->assertSame(7, $this->repo->getAssignedSurveyorId('KARVE ROAD', 1));
        $this->assertNull($this->repo->getAssignedSurveyorId('KARVE ROAD', 2));
        $this->assertNull($this->repo->getAssignedSurveyorId('NO SUCH ROAD', 1));
    }

    public function test_user_without_city_and_several_cities_cannot_default_a_city(): void
    {
        $this->expectException(RuntimeException::class);
        $this->repo->create(3, ['name' => 'Karve Road']);
    }

    public function test_user_without_city_gets_the_only_city_when_one_exists(): void
    {
        $this->pdo->exec('DELETE FROM cities WHERE id = 2');
        $this->assertSame(1, $this->repo->resolveCityIdForNewRoadGroup(3));
    }
}
