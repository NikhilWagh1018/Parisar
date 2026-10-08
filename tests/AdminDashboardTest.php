<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/AdminDashboard.php';
require_once __DIR__ . '/../repositories/AdminDashboardRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';

/** Admin dashboard: attention list, audit ordering, and the queries behind it (in-memory SQLite). */
class AdminDashboardTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('UTC'));
    }

    private function audit(int $id, string $status, string $last, string $city = 'Pune'): array
    {
        return ['id' => $id, 'name' => "Audit $id", 'audit_year' => 2026, 'status' => $status,
                'city_name' => $city, 'road_count' => 2, 'segment_count' => 5, 'done_count' => 1,
                'last_activity' => $last];
    }

    // ── wording and ordering ────────────────────────────────────

    public function test_status_words(): void
    {
        $this->assertSame('Awaiting approval', adminAuditStatusLabel('awaiting_approval'));
        $this->assertSame('Closed by city', adminAuditStatusLabel('finalised'));
        $this->assertSame('Auditing', adminAuditStatusLabel('active'));
        $this->assertSame('Something new', adminAuditStatusLabel('something_new'));
        $this->assertSame('awaiting-approval', adminAuditStatusClass('awaiting_approval'));
    }

    public function test_audits_needing_the_admin_come_first_then_newest_activity(): void
    {
        $sorted = adminSortAudits([
            $this->audit(1, 'published',         '2026-10-04 10:00:00'),
            $this->audit(2, 'active',            '2026-09-01 10:00:00'),
            $this->audit(3, 'awaiting_approval', '2026-09-30 10:00:00'),
            $this->audit(4, 'active',            '2026-10-03 10:00:00'),
        ]);
        $this->assertSame([3, 4, 2, 1], array_column($sorted, 'id'));
    }

    public function test_setup_audit_opens_the_setup_page_others_open_the_report(): void
    {
        $this->assertSame('city_audit.php?id=7', adminAuditLink(['id' => 7, 'status' => 'draft']));
        $this->assertSame('city_audit_report.php?id=7', adminAuditLink(['id' => 7, 'status' => 'published']));
    }

    // ── stalled audits ──────────────────────────────────────────

    public function test_idle_days(): void
    {
        $this->assertSame(0, adminIdleDays('2026-10-05 11:59:00', $this->now()));
        $this->assertSame(3, adminIdleDays('2026-10-02 06:00:00', $this->now()));
        $this->assertSame(0, adminIdleDays('2026-10-09 06:00:00', $this->now()), 'a future time is never negative');
        $this->assertNull(adminIdleDays(null, $this->now()));
        $this->assertNull(adminIdleDays('not a date', $this->now()));
    }

    public function test_only_quiet_audits_in_progress_are_stalled_longest_first(): void
    {
        $stalled = adminStalledAudits([
            $this->audit(1, 'active',    '2026-09-10 00:00:00'),   // 25 days
            $this->audit(2, 'in_review', '2026-09-20 00:00:00'),   // 15 days
            $this->audit(3, 'active',    '2026-10-01 00:00:00'),   // 4 days: fine
            $this->audit(4, 'published', '2026-01-01 00:00:00'),   // done: never flagged
            $this->audit(5, 'draft',     '2026-01-01 00:00:00'),   // not started: never flagged
        ], $this->now());
        $this->assertSame([1, 2], array_column($stalled, 'id'));
        $this->assertSame(25, $stalled[0]['idle_days']);
    }

    // ── attention list ──────────────────────────────────────────

    public function test_attention_is_empty_when_all_is_well(): void
    {
        $this->assertSame([], adminAttentionItems([], [], 0, []));
    }

    public function test_attention_lists_approvals_first_then_stalled_roads_and_cities(): void
    {
        $awaiting = [['id' => 9, 'name' => 'Pune Audit', 'audit_year' => 2026, 'city_name' => 'Pune',
                      'road_count' => 1, 'segment_count' => 3]];
        $stalled  = [$this->audit(2, 'active', '2026-09-10 00:00:00') + ['idle_days' => 25]];
        $items    = adminAttentionItems($awaiting, $stalled, 2, [['name' => 'Mumbai', 'leaders' => 0]]);

        $this->assertSame(['urgent', 'warn', 'info', 'warn'], array_column($items, 'tone'));
        $this->assertSame('city_audit_report.php?id=9', $items[0]['href']);
        $this->assertSame('Review', $items[0]['cta']);
        $this->assertStringContainsString('1 road, 3 segments', $items[0]['detail']);
        $this->assertStringContainsString('25 days', $items[1]['detail']);
        $this->assertSame('2 roads to verify', $items[2]['title']);
        $this->assertSame('admin.php', $items[2]['href']);
        $this->assertSame('Mumbai has no City Leader', $items[3]['title']);
    }

    public function test_cities_without_a_leader(): void
    {
        $out = adminCitiesWithoutLeader([['name' => 'Pune', 'leaders' => 1], ['name' => 'Nashik', 'leaders' => 0]]);
        $this->assertSame(['Nashik'], array_column($out, 'name'));
    }

    // ── queries ─────────────────────────────────────────────────

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, city_id INTEGER NULL, role TEXT)');
        $pdo->exec('CREATE TABLE road_groups (id INTEGER PRIMARY KEY, city_id INTEGER, is_verified INTEGER NOT NULL DEFAULT 1)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER, name TEXT, audit_year INTEGER, audit_date TEXT NULL, status TEXT,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, road_group_id INTEGER, audit_id INTEGER NULL)');
        $pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE segment_audits (id INTEGER PRIMARY KEY, segment_id INTEGER, created_at TEXT)');
        $pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Mumbai')");
        $pdo->exec("INSERT INTO users VALUES (1,1,'city_admin'),(2,1,'surveyor'),(3,1,'surveyor'),(4,NULL,'national_admin')");
        $pdo->exec("INSERT INTO road_groups VALUES (1,1,1),(2,1,0),(3,2,0)");
        $pdo->exec("INSERT INTO city_audits (id,city_id,name,audit_year,status,updated_at) VALUES
            (1,1,'Pune Audit',2026,'active','2026-09-01 00:00:00'),
            (2,1,'Old',2025,'voided','2025-01-01 00:00:00'),
            (3,2,'Empty',2026,'draft','2026-09-15 00:00:00')");
        $pdo->exec("INSERT INTO roads VALUES (1,1,1),(2,2,1),(3,3,NULL)");
        $pdo->exec("INSERT INTO segments VALUES (1,1,'completed'),(2,1,'pending'),(3,2,'completed'),(4,3,'completed')");
        $pdo->exec("INSERT INTO segment_audits VALUES (1,1,'2026-09-10 08:00:00'),(2,3,'2026-09-12 08:00:00')");
        return $pdo;
    }

    public function test_cities_report_people_roads_and_progress(): void
    {
        $rows = (new AdminDashboardRepository($this->db()))->cities();
        $this->assertSame(['Mumbai', 'Pune'], array_column($rows, 'name'));
        $pune = $rows[1];
        $this->assertSame([1, 2, 2, 3, 2], [$pune['leaders'], $pune['surveyors'], $pune['road_groups'], $pune['segs'], $pune['done']]);
        $this->assertSame(0, $rows[0]['leaders'], 'Mumbai has no City Leader');
    }

    public function test_audits_skip_voided_and_use_latest_segment_activity(): void
    {
        $audits = (new AdminDashboardRepository($this->db()))->audits();
        $byId = array_column($audits, null, 'id');
        $this->assertSame([1, 3], array_keys($byId));
        $this->assertSame([2, 3, 2], [$byId[1]['road_count'], $byId[1]['segment_count'], $byId[1]['done_count']]);
        $this->assertSame('2026-09-12 08:00:00', $byId[1]['last_activity']);
        $this->assertSame('2026-09-15 00:00:00', $byId[3]['last_activity'], 'no submissions yet: falls back to the audit itself');
        $this->assertSame(0, $byId[3]['segment_count']);
        $this->assertSame('Mumbai', $byId[3]['city_name']);
    }

    public function test_roads_to_verify_count(): void
    {
        $this->assertSame(2, (new AdminDashboardRepository($this->db()))->roadsToVerifyCount());
    }
}
