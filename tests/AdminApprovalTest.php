<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/CityLeaderGate.php';

/** Admin approval of an audit: approve, or return with a note. In-memory SQLite shaped like the live schema (plus migration 014). */
class AdminApprovalTest extends TestCase
{
    private PDO $pdo;
    private AuditReviewRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, name TEXT, audit_year INTEGER,
            status TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_note TEXT NULL, admin_decided_at TEXT NULL, admin_decided_by INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, name TEXT, audit_id INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER NOT NULL, segment_number INTEGER NOT NULL, length REAL NOT NULL, status TEXT)');
        $this->pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Mumbai')");
        $this->pdo->exec("INSERT INTO city_audits (id, city_id, name, audit_year, status, updated_at) VALUES
            (1,1,'Pune Audit',2026,'awaiting_approval','2026-10-04 10:00:00'),
            (2,2,'Mumbai Audit',2026,'awaiting_approval','2026-10-03 09:00:00'),
            (3,1,'Old Audit',2025,'in_review','2026-10-01 09:00:00')");
        $this->pdo->exec("INSERT INTO roads VALUES (1,'F.C. Road',1),(2,'J.M. Road',1),(3,'Marine Drive',2)");
        $this->pdo->exec("INSERT INTO segments VALUES (1,1,1,300,'completed'),(2,1,2,300,'completed'),(3,2,1,200,'completed'),(4,3,1,500,'completed')");
        $this->repo = new AuditReviewRepository($this->pdo);
    }

    private function audit(int $id = 1): array
    {
        return $this->pdo->query("SELECT id, city_id, status FROM city_audits WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    private function row(int $id = 1): array
    {
        return $this->pdo->query("SELECT * FROM city_audits WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    // ── note rules ──────────────────────────────────────────────

    public function test_note_error_names_who_must_be_told(): void
    {
        $this->assertSame('Tell the surveyor what needs to be fixed.', auditReviewCleanNote('  ')['error']);
        $this->assertSame('Tell the City Leader what needs to be fixed.', auditReviewCleanNote('', 'the City Leader')['error']);
    }

    // ── approve ─────────────────────────────────────────────────

    public function test_approve_publishes_and_records_who_decided(): void
    {
        $this->repo->approveAudit($this->audit(), 7);
        $r = $this->row();
        $this->assertSame('published', $r['status']);
        $this->assertSame(7, (int)$r['admin_decided_by']);
        $this->assertNotNull($r['admin_decided_at']);
        $this->assertNull($r['admin_note']);
    }

    public function test_approve_clears_an_earlier_return_note(): void
    {
        $this->pdo->exec("UPDATE city_audits SET admin_note = 'Fix road names' WHERE id = 1");
        $this->repo->approveAudit($this->audit(), 7);
        $this->assertNull($this->row()['admin_note']);
    }

    public function test_approve_only_from_awaiting_approval(): void
    {
        foreach ([3] as $id) {
            try {
                $this->repo->approveAudit($this->audit($id), 7);
                $this->fail('only an audit waiting for approval can be approved');
            } catch (DomainException $e) {
                $this->assertSame('in_review', $this->row($id)['status']);
            }
        }
    }

    public function test_cannot_decide_twice(): void
    {
        $stale = $this->audit();                       // page loaded before the first decision
        $this->repo->approveAudit($this->audit(), 7);
        $this->expectException(DomainException::class);
        $this->repo->returnAudit($stale, 8, 'Too late');
    }

    // ── return with a note ──────────────────────────────────────

    public function test_return_requires_a_note(): void
    {
        foreach (['', '   '] as $note) {
            try {
                $this->repo->returnAudit($this->audit(), 7, $note);
                $this->fail('a note is required');
            } catch (DomainException $e) {
                $this->assertSame('Tell the City Leader what needs to be fixed.', $e->getMessage());
            }
        }
        $this->assertSame('awaiting_approval', $this->row()['status']);
    }

    public function test_return_note_is_limited(): void
    {
        $this->expectException(DomainException::class);
        $this->repo->returnAudit($this->audit(), 7, str_repeat('x', AUDIT_REVIEW_NOTE_MAX + 1));
    }

    public function test_return_sends_the_audit_back_to_in_review_with_the_note(): void
    {
        $this->repo->returnAudit($this->audit(), 7, '  Segment 3 on F.C. Road looks wrong.  ');
        $r = $this->row();
        $this->assertSame('in_review', $r['status']);
        $this->assertSame('Segment 3 on F.C. Road looks wrong.', $r['admin_note']);
        $this->assertSame(7, (int)$r['admin_decided_by']);
        $this->assertContains($r['status'], AUDIT_REVIEW_OPEN_STATUSES, 'City Leader can review and close it again');
    }

    public function test_return_only_from_awaiting_approval(): void
    {
        $this->expectException(DomainException::class);
        $this->repo->returnAudit($this->audit(3), 7, 'Not waiting');
    }

    public function test_a_returned_audit_can_be_sent_and_decided_again(): void
    {
        $this->repo->returnAudit($this->audit(), 7, 'Please fix');
        $this->pdo->exec("UPDATE city_audits SET status = 'awaiting_approval' WHERE id = 1");   // closed + sent again
        $this->assertSame('Please fix', $this->repo->adminDecision(1)['admin_note'], 'Admin still sees the earlier note');
        $this->repo->approveAudit($this->audit(), 7);
        $this->assertSame('published', $this->row()['status']);
    }

    // ── reading notes and the waiting list ──────────────────────

    public function test_admin_decision_and_city_notes(): void
    {
        $this->assertNull($this->repo->adminDecision(1)['admin_note']);
        $this->assertNull($this->repo->adminDecision(999)['admin_note']);
        $this->pdo->exec("UPDATE city_audits SET admin_note = 'Fix it' WHERE id = 3");
        $this->assertSame('Fix it', $this->repo->adminDecision(3)['admin_note']);
        $this->assertSame([3 => 'Fix it'], $this->repo->adminNotesForCity(1));
        $this->assertSame([], $this->repo->adminNotesForCity(2));
    }

    public function test_awaiting_list_has_only_waiting_audits_oldest_first_with_counts(): void
    {
        $list = $this->repo->awaitingApproval();
        $this->assertSame([2, 1], array_column($list, 'id'));
        $this->assertSame('Mumbai', $list[0]['city_name']);
        $this->assertSame(1, $list[0]['segment_count']);
        $this->assertSame(2, $list[1]['road_count']);
        $this->assertSame(3, $list[1]['segment_count']);
        $this->repo->approveAudit($this->audit(), 7);
        $this->assertSame([2], array_column($this->repo->awaitingApproval(), 'id'));
    }

    // ── wording per role ────────────────────────────────────────

    public function test_attention_text_for_the_admin_and_the_city_leader(): void
    {
        $leader = cityAuditAttention('awaiting_approval', []);
        $this->assertSame('info', $leader[0]['level']);
        $admin = cityAuditAttention('awaiting_approval', [], null, true);
        $this->assertSame('action', $admin[0]['level']);
        $this->assertStringContainsString('your approval', $admin[0]['text']);
    }

    public function test_city_leader_sees_the_admins_note_on_a_returned_audit(): void
    {
        $items = cityAuditAttention('in_review', ['total' => 2, 'approved' => 2], 'Fix road names');
        $this->assertSame('action', $items[0]['level']);
        $this->assertStringContainsString('Fix road names', $items[0]['text']);
        $none = cityAuditAttention('in_review', ['total' => 2, 'approved' => 1, 'assigned' => 1]);
        $this->assertStringNotContainsString('Admin', $none[0]['text']);
    }

    public function test_pages_keep_working_before_migration_014_is_run(): void
    {
        $old = new PDO('sqlite::memory:');
        $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $old->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, status TEXT NOT NULL)');
        $repo = new AuditReviewRepository($old);
        $this->assertNull($repo->adminDecision(1)['admin_note']);
        $this->assertSame([], $repo->adminNotesForCity(1));
    }

    public function test_city_leaders_cannot_call_the_admin_decision_api(): void
    {
        $this->assertFalse(cityLeaderMayAccess('/api/admin/audit_decide.php'));
    }
}
