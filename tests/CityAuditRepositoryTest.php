<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/CityAuditRepository.php';

/** In-memory SQLite with the same table shapes as the live schema (plus migration 011). */
class CityAuditRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CityAuditRepository $repo;
    private array $clean = ['name' => 'Pune Audit', 'state' => 'Maharashtra', 'audit_year' => 2026, 'programme_info' => null];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE road_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, canonical_name TEXT NOT NULL,
            city_id INTEGER NOT NULL, is_flagged INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, city_id INTEGER NOT NULL,
            state TEXT NOT NULL, name TEXT NOT NULL, audit_year INTEGER NOT NULL, programme_info TEXT NULL,
            status TEXT NOT NULL DEFAULT \'draft\', created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (city_id, audit_year, name))');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE,
            creator_id INTEGER NOT NULL, name TEXT NOT NULL, road_group_id INTEGER NULL, audit_id INTEGER NULL,
            total_length REAL NULL, segment_method TEXT NOT NULL DEFAULT \'auto\', segment_length REAL NULL,
            finalized_at TEXT NULL, UNIQUE (audit_id, road_group_id))');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE,
            road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL, start_label TEXT, end_label TEXT,
            start_distance REAL NOT NULL, end_distance REAL NOT NULL, length REAL NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\')');
        $this->pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Mumbai')");
        $this->pdo->exec("INSERT INTO users VALUES (2,'Nick')");
        $this->pdo->exec("INSERT INTO road_groups (canonical_name, city_id, is_flagged) VALUES
            ('F.C. ROAD',1,0),('PMC ROAD',1,0),('BAD ROAD',1,1),('MARINE DRIVE',2,0)");
        $this->repo = new CityAuditRepository($this->pdo);
    }

    private function newAudit(): array
    {
        return $this->repo->find($this->repo->createAudit(1, 2, $this->clean));
    }

    public function test_create_find_and_duplicate(): void
    {
        $a = $this->newAudit();
        $this->assertSame('Pune', $a['city_name']);
        $this->assertSame('Nick', $a['created_by_name']);
        $this->assertSame('draft', $a['status']);
        try {
            $this->repo->createAudit(1, 2, $this->clean);
            $this->fail('duplicate audit should be rejected');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_available_roads_exclude_flagged_other_city_and_already_added(): void
    {
        $a = $this->newAudit();
        $this->assertSame(2, count($this->repo->availableRoadGroups(1, (int)$a['id'])));
        $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        $left = $this->repo->availableRoadGroups(1, (int)$a['id']);
        $this->assertSame(1, count($left));
        $this->assertSame('PMC ROAD', $left[0]['canonical_name']);
    }

    public function test_add_road_creates_segments_and_ids(): void
    {
        $a   = $this->newAudit();
        $res = $this->repo->addRoad($a, 2, 2, 2000.0, 300.0);
        $this->assertSame(7, $res['segment_count']);
        $this->assertTrue(str_starts_with($res['public_id'], 'ROAD-'));

        $roads = $this->repo->roadsWithSegments((int)$a['id']);
        $this->assertSame(1, count($roads));
        $this->assertSame('PMC ROAD', $roads[0]['name']);
        $this->assertSame(7, count($roads[0]['segments']));
        $this->assertSame(200.0, (float)$roads[0]['segments'][6]['length']);

        $bad = (int)$this->pdo->query("SELECT COUNT(*) FROM segments WHERE public_id NOT LIKE 'SEG-%' OR public_id LIKE 'SEG-R%'")->fetchColumn();
        $this->assertSame(0, $bad);
    }

    public function test_cannot_add_same_flagged_or_foreign_road(): void
    {
        $a = $this->newAudit();
        $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        foreach ([1, 3, 4] as $groupId) {   // same road, flagged road, other city's road
            try {
                $this->repo->addRoad($a, 2, $groupId, 500.0, 100.0);
                $this->fail("road $groupId should be rejected");
            } catch (DomainException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn());
    }

    public function test_a_failed_add_leaves_nothing_behind(): void
    {
        $a = $this->newAudit();
        try {
            $this->repo->addRoad($a, 2, 1, 49.0, 100.0);   // below the 50 m minimum
        } catch (InvalidArgumentException $e) {
        }
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM roads')->fetchColumn());
    }

    public function test_remove_road_rules(): void
    {
        $a  = $this->newAudit();
        $r1 = $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        $r2 = $this->repo->addRoad($a, 2, 2, 600.0, 300.0);

        $this->pdo->exec("UPDATE segments SET status = 'in_progress' WHERE road_id = " . $r1['road_id'] . ' AND segment_number = 1');
        try {
            $this->repo->removeRoad($a, $r1['road_id']);
            $this->fail('started road must not be removable');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }

        $this->repo->removeRoad($a, $r2['road_id']);
        $this->assertSame(1, count($this->repo->roadsWithSegments((int)$a['id'])));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM segments WHERE road_id = ' . $r2['road_id'])->fetchColumn());

        // a road that is not part of this audit (e.g. a legacy one) cannot be removed here
        $this->pdo->exec("INSERT INTO roads (public_id, creator_id, name, total_length) VALUES ('ROAD-9999', 2, 'OLD', 100)");
        try {
            $this->repo->removeRoad($a, (int)$this->pdo->lastInsertId());
            $this->fail('legacy road must not be removable through an audit');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_roads_cannot_change_once_audit_leaves_draft(): void
    {
        $a = $this->newAudit();
        $this->pdo->exec("UPDATE city_audits SET status = 'active' WHERE id = " . (int)$a['id']);
        $a = $this->repo->find((int)$a['id']);
        $this->assertFalse($this->repo->isEditable($a));
        try {
            $this->repo->addRoad($a, 2, 1, 500.0, 100.0);
            $this->fail('no adds after draft');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_list_counts(): void
    {
        $a = $this->newAudit();
        $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        $this->pdo->exec("UPDATE segments SET status = 'completed' WHERE segment_number = 1");
        $row = $this->repo->listForCity(1)[0];
        $this->assertSame(1, (int)$row['road_count']);
        $this->assertSame(3, (int)$row['segment_count']);
        $this->assertSame(1, (int)$row['done_count']);
        $this->assertSame([], $this->repo->listForCity(2));
    }
}
