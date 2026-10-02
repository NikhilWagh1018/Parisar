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
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT NOT NULL DEFAULT \'surveyor\',
            is_active INTEGER NOT NULL DEFAULT 1, city_id INTEGER NULL)');
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
        $this->pdo->exec('CREATE TABLE segment_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, audit_id INTEGER NOT NULL,
            segment_id INTEGER NOT NULL UNIQUE, surveyor_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL,
            assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("INSERT INTO users (id, name, role, is_active, city_id) VALUES
            (2,'Nick','city_admin',1,1),
            (10,'Asha','surveyor',1,1),
            (11,'Ravi','surveyor',1,1),
            (12,'Inactive Ian','surveyor',0,1),
            (13,'Mumbai Mona','surveyor',1,2),
            (14,'Other Admin','city_admin',1,1)");
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

    // ── Slice 3: surveyor assignment ──────────────────────────────

    private function segmentIds(int $roadId): array
    {
        return array_map('intval', $this->pdo->query(
            'SELECT id FROM segments WHERE road_id = ' . $roadId . ' ORDER BY segment_number'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_only_active_surveyors_of_the_city_can_be_picked(): void
    {
        $names = array_column($this->repo->assignableSurveyors(1), 'name');
        $this->assertSame(['Asha', 'Ravi'], $names);
        $this->assertSame(['Mumbai Mona'], array_column($this->repo->assignableSurveyors(2), 'name'));
    }

    public function test_assign_segments_and_read_them_back(): void
    {
        $a   = $this->newAudit();
        $res = $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        $ids = $this->segmentIds($res['road_id']);

        $this->assertSame(2, $this->repo->assignSegments($a, 2, [$ids[0], $ids[1]], 10));
        $this->assertSame(1, $this->repo->assignSegments($a, 2, [$ids[2]], 11));

        $segs = $this->repo->roadsWithSegments((int)$a['id'])[0]['segments'];
        $this->assertSame('Asha', $segs[0]['assigned_name']);
        $this->assertSame('Asha', $segs[1]['assigned_name']);
        $this->assertSame('Ravi', $segs[2]['assigned_name']);
        $this->assertSame(['total' => 3, 'assigned' => 3], $this->repo->assignmentCounts((int)$a['id']));
    }

    public function test_reassign_replaces_and_null_clears(): void
    {
        $a   = $this->newAudit();
        $ids = $this->segmentIds($this->repo->addRoad($a, 2, 1, 1000.0, 500.0)['road_id']);

        $this->repo->assignSegments($a, 2, $ids, 10);
        $this->repo->assignSegments($a, 2, [$ids[0]], 11);
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
        $segs = $this->repo->roadsWithSegments((int)$a['id'])[0]['segments'];
        $this->assertSame('Ravi', $segs[0]['assigned_name']);
        $this->assertSame('Asha', $segs[1]['assigned_name']);

        $this->repo->assignSegments($a, 2, $ids, null);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
        $this->assertNull($this->repo->roadsWithSegments((int)$a['id'])[0]['segments'][0]['assigned_to']);
    }

    public function test_assignment_rejects_bad_surveyors(): void
    {
        $a   = $this->newAudit();
        $ids = $this->segmentIds($this->repo->addRoad($a, 2, 1, 500.0, 500.0)['road_id']);
        foreach ([12 => 'inactive', 13 => 'other city', 14 => 'not a surveyor', 99 => 'unknown'] as $uid => $why) {
            try {
                $this->repo->assignSegments($a, 2, $ids, $uid);
                $this->fail("surveyor $uid ($why) should be rejected");
            } catch (DomainException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
    }

    public function test_assignment_rejects_foreign_started_and_empty_segments(): void
    {
        $a   = $this->newAudit();
        $ids = $this->segmentIds($this->repo->addRoad($a, 2, 1, 1000.0, 500.0)['road_id']);

        // a segment of another audit
        $b      = $this->repo->find($this->repo->createAudit(1, 2, ['name' => 'Second', 'state' => 'Maharashtra', 'audit_year' => 2026, 'programme_info' => null]));
        $other  = $this->segmentIds($this->repo->addRoad($b, 2, 1, 500.0, 500.0)['road_id']);
        $this->pdo->exec("UPDATE segments SET status = 'completed' WHERE id = " . $ids[1]);

        foreach ([[$other[0]], [$ids[0], $other[0]], [$ids[1]], [999], []] as $bad) {
            try {
                $this->repo->assignSegments($a, 2, $bad, 10);
                $this->fail('should be rejected: ' . json_encode($bad));
            } catch (DomainException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
    }

    public function test_assign_whole_road_skips_started_segments(): void
    {
        $a  = $this->newAudit();
        $r1 = $this->repo->addRoad($a, 2, 1, 1500.0, 500.0);
        $r2 = $this->repo->addRoad($a, 2, 2, 500.0, 500.0);
        $ids = $this->segmentIds($r1['road_id']);
        $this->pdo->exec("UPDATE segments SET status = 'completed' WHERE id = " . $ids[0]);

        $this->assertSame(['assigned' => 2, 'skipped' => 1], $this->repo->assignRoad($a, 2, $r1['road_id'], 10));
        $this->assertSame(['total' => 4, 'assigned' => 2], $this->repo->assignmentCounts((int)$a['id']));

        $this->pdo->exec("UPDATE segments SET status = 'completed' WHERE road_id = " . $r2['road_id']);
        try {
            $this->repo->assignRoad($a, 2, $r2['road_id'], 10);
            $this->fail('a fully started road cannot be assigned');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
        try {
            $this->repo->assignRoad($a, 2, 99999, 10);
            $this->fail('unknown road must be rejected');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_activate_needs_roads_and_every_segment_assigned(): void
    {
        $a = $this->newAudit();
        try {
            $this->repo->activate($a);
            $this->fail('an empty audit cannot be activated');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }

        $res = $this->repo->addRoad($a, 2, 1, 1000.0, 500.0);
        $ids = $this->segmentIds($res['road_id']);
        $this->repo->assignSegments($a, 2, [$ids[0]], 10);
        try {
            $this->repo->activate($a);
            $this->fail('a half-assigned audit cannot be activated');
        } catch (DomainException $e) {
            $this->assertStringContainsString('1 segment still has no surveyor', $e->getMessage());
        }

        $this->repo->assignSegments($a, 2, [$ids[1]], 11);
        $this->repo->activate($a);
        $a = $this->repo->find((int)$a['id']);
        $this->assertSame('active', $a['status']);

        try {
            $this->repo->activate($a);
            $this->fail('cannot activate twice');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_activate_refuses_a_surveyor_who_is_no_longer_active(): void
    {
        $a   = $this->newAudit();
        $ids = $this->segmentIds($this->repo->addRoad($a, 2, 1, 500.0, 500.0)['road_id']);
        $this->repo->assignSegments($a, 2, $ids, 10);
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 10');
        try {
            $this->repo->activate($a);
            $this->fail('inactive surveyor must block activation');
        } catch (DomainException $e) {
            $this->assertSame('draft', $this->repo->find((int)$a['id'])['status']);
        }
    }

    public function test_assignments_continue_after_activation_but_roads_do_not(): void
    {
        $a   = $this->newAudit();
        $ids = $this->segmentIds($this->repo->addRoad($a, 2, 1, 500.0, 500.0)['road_id']);
        $this->repo->assignSegments($a, 2, $ids, 10);
        $this->repo->activate($a);
        $a = $this->repo->find((int)$a['id']);

        $this->assertTrue($this->repo->canAssign($a));
        $this->assertFalse($this->repo->isEditable($a));
        $this->repo->assignSegments($a, 2, $ids, 11);
        $this->assertSame('Ravi', $this->repo->roadsWithSegments((int)$a['id'])[0]['segments'][0]['assigned_name']);

        $this->pdo->exec("UPDATE city_audits SET status = 'finalised' WHERE id = " . (int)$a['id']);
        $a = $this->repo->find((int)$a['id']);
        $this->assertFalse($this->repo->canAssign($a));
        try {
            $this->repo->assignSegments($a, 2, $ids, 10);
            $this->fail('no assignment once the audit is finalised');
        } catch (DomainException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_removing_a_road_clears_its_assignments(): void
    {
        $a   = $this->newAudit();
        $res = $this->repo->addRoad($a, 2, 1, 1000.0, 500.0);
        $this->repo->assignSegments($a, 2, $this->segmentIds($res['road_id']), 10);
        $this->repo->removeRoad($a, $res['road_id']);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM segment_assignments')->fetchColumn());
    }
}
