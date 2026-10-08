<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/PublicAudits.php';
require_once __DIR__ . '/../repositories/PublicAuditRepository.php';

/** The public landing page "Audit data": audit dates and which audits are shown (in-memory SQLite). */
class PublicAuditsTest extends TestCase
{
    // ── audit date wording ──────────────────────────────────────

    public function test_audit_date_label(): void
    {
        $this->assertSame('4 Oct 2026', cityAuditDateLabel('2026-10-04', 2026));
        $this->assertSame('1 Jan 2026', cityAuditDateLabel('2026-01-01 00:00:00', 2026));
        $this->assertSame('2026', cityAuditDateLabel(null, 2026), 'older audits with no date show the year');
        $this->assertSame('2025', cityAuditDateLabel('', 2025));
    }

    public function test_public_audit_date_falls_back_to_the_first_of_january(): void
    {
        $this->assertSame('2026-10-04', publicAuditDate('2026-10-04', 2026));
        $this->assertSame('2025-01-01', publicAuditDate(null, 2025));
        $this->assertSame('2025-01-01', publicAuditDate('garbage', 2025));
    }

    // ── shaping ─────────────────────────────────────────────────

    private function row(array $o = []): array
    {
        return $o + ['id' => 1, 'name' => 'Pune Audit', 'state' => 'Maharashtra', 'audit_year' => 2026,
                     'audit_date' => '2026-10-04', 'city_id' => 1, 'city_name' => 'Pune',
                     'road_count' => '3', 'segment_count' => '12', 'length_m' => '4560.4'];
    }

    public function test_shape_exposes_only_public_fields(): void
    {
        $a = publicAuditShape($this->row(['created_by' => 9, 'programme_info' => 'secret']), ['score' => 37.69, 'condition' => 'OK']);
        $this->assertSame(
            ['id', 'name', 'city_id', 'city', 'state', 'date', 'year', 'month', 'roads', 'segments', 'length_km', 'score', 'condition'],
            array_keys($a)
        );
        $this->assertSame([2026, 10, 3, 12, 4.6, 37.69, 'OK'],
            [$a['year'], $a['month'], $a['roads'], $a['segments'], $a['length_km'], $a['score'], $a['condition']]);
    }

    public function test_year_and_month_follow_the_audit_date_not_the_stored_year(): void
    {
        $a = publicAuditShape($this->row(['audit_date' => '2025-03-15', 'audit_year' => 2024]), null);
        $this->assertSame([2025, 3, null, null], [$a['year'], $a['month'], $a['score'], $a['condition']]);
    }

    public function test_audits_without_a_stored_date_land_in_january(): void
    {
        $a = publicAuditShape($this->row(['audit_date' => null, 'audit_year' => 2025]), null);
        $this->assertSame([2025, 1, '2025-01-01'], [$a['year'], $a['month'], $a['date']]);
    }

    public function test_newest_audit_first_and_cities_are_listed_once_a_to_z(): void
    {
        $x = publicAuditShape($this->row(['id' => 1, 'audit_date' => '2026-01-10']), null);
        $y = publicAuditShape($this->row(['id' => 2, 'audit_date' => '2026-09-01', 'city_id' => 2, 'city_name' => 'Nashik']), null);
        $z = publicAuditShape($this->row(['id' => 3, 'audit_date' => '2026-09-01', 'city_id' => 1]), null);
        $sorted = publicSortAudits([$x, $y, $z]);
        $this->assertSame([3, 2, 1], array_column($sorted, 'id'));
        $this->assertSame(['Nashik', 'Pune'], array_column(publicAuditCities($sorted), 'name'));
    }

    // ── queries ─────────────────────────────────────────────────

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER, name TEXT, state TEXT, audit_year INTEGER,
                    audit_date TEXT NULL, status TEXT)');
        $pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, audit_id INTEGER NULL)');
        $pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER, length REAL)');
        $pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Mumbai')");
        $pdo->exec("INSERT INTO city_audits VALUES
            (1,1,'Published one','Maharashtra',2026,'2026-10-04','published'),
            (2,1,'Still in review','Maharashtra',2026,'2026-10-05','in_review'),
            (3,2,'Awaiting','Maharashtra',2026,'2026-10-06','awaiting_approval'),
            (4,2,'Old published','Maharashtra',2025,NULL,'published'),
            (5,1,'Voided','Maharashtra',2026,'2026-10-07','voided')");
        $pdo->exec("INSERT INTO roads VALUES (1,1),(2,1),(3,2),(4,4)");
        $pdo->exec("INSERT INTO segments VALUES (1,1,500),(2,1,500),(3,2,250),(4,3,500),(5,4,100)");
        return $pdo;
    }

    public function test_only_published_audits_are_public(): void
    {
        $rows = (new PublicAuditRepository($this->db()))->published();
        $byId = array_column($rows, null, 'id');
        $this->assertSame([1, 4], array_keys($byId), 'review, awaiting and voided audits never appear');
        $this->assertSame('Pune', $byId[1]['city_name']);
    }

    public function test_size_of_a_published_audit(): void
    {
        $byId = array_column((new PublicAuditRepository($this->db()))->published(), null, 'id');
        $this->assertSame([2, 3, 1250.0], [(int)$byId[1]['road_count'], (int)$byId[1]['segment_count'], (float)$byId[1]['length_m']]);
        $this->assertNull($byId[4]['audit_date'], 'an older audit has no stored date yet');
    }

    public function test_road_to_audit_map(): void
    {
        $repo = new PublicAuditRepository($this->db());
        $this->assertSame([1 => 1, 2 => 1, 4 => 4], $repo->roadAuditMap([1, 4]));
        $this->assertSame([], $repo->roadAuditMap([]));
    }
}
