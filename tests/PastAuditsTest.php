<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/PublicAudits.php';
require_once __DIR__ . '/../helpers/PastAudits.php';
require_once __DIR__ . '/../repositories/PastAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReviewRepository.php';
require_once __DIR__ . '/../repositories/PublicAuditRepository.php';

/** Past audits the Admin adds by hand, and the Admin's Unpublish (in-memory SQLite). */
class PastAuditsTest extends TestCase
{
    private const TODAY = '2026-11-01';

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function input(array $o = []): array
    {
        return $o + ['city_name' => 'Pune', 'name' => 'Pune Cycle Track Audit 2024', 'audit_date' => '2024-12-15',
                     'road_count' => '4', 'segment_count' => '38', 'length_km' => '19.0', 'score' => '31.5'];
    }

    private function pdf(string $content = "%PDF-1.4\n%fake\n", string $name = 'My Report (final).pdf'): array
    {
        $f = tempnam(sys_get_temp_dir(), 'pa');
        file_put_contents($f, $content);
        $this->tmp[] = $f;
        return ['name' => $name, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    // ── validation ──────────────────────────────────────────────

    public function test_a_complete_entry_is_accepted_and_the_state_follows_the_city(): void
    {
        $v = pastAuditValidate($this->input(), null, self::TODAY);
        $this->assertSame([], $v['errors']);
        $c = $v['clean'];
        $this->assertSame(['Pune', 'Maharashtra', 4, 38, 19.0], [$c['city_name'], $c['state'], $c['road_count'], $c['segment_count'], $c['length_km']]);
        $this->assertSame([31.5, 'OK'], [$c['score'], $c['condition_label']]);
        $this->assertNull($c['report']);
    }

    public function test_the_condition_follows_the_same_bands_as_live_audits(): void
    {
        $cond = fn(string $s) => pastAuditValidate($this->input(['score' => $s]), null, self::TODAY)['clean']['condition_label'];
        $this->assertSame(['Good', 'OK', 'Poor', 'Bad', 'Very Bad'], [$cond('20'), $cond('40'), $cond('60'), $cond('80'), $cond('100')]);
    }

    public function test_the_score_is_optional_but_must_be_0_to_100(): void
    {
        $none = pastAuditValidate($this->input(['score' => '']), null, self::TODAY);
        $this->assertSame([], $none['errors']);
        $this->assertSame([null, null], [$none['clean']['score'], $none['clean']['condition_label']]);
        $this->assertArrayHasKey('score', pastAuditValidate($this->input(['score' => '101']), null, self::TODAY)['errors']);
        $this->assertArrayHasKey('score', pastAuditValidate($this->input(['score' => 'abc']), null, self::TODAY)['errors']);
    }

    public function test_required_fields_are_named_in_the_errors(): void
    {
        $v = pastAuditValidate(['city_name' => '', 'name' => '  ', 'audit_date' => '', 'road_count' => '0', 'segment_count' => '', 'length_km' => ''], null, self::TODAY);
        $this->assertSame(['city_name', 'name', 'audit_date', 'road_count', 'segment_count', 'length_km'], array_keys($v['errors']));
    }

    public function test_only_cities_from_the_list_are_accepted(): void
    {
        $this->assertArrayHasKey('city_name', pastAuditValidate($this->input(['city_name' => 'Atlantis']), null, self::TODAY)['errors']);
        $alias = pastAuditValidate($this->input(['city_name' => 'Bangalore']), null, self::TODAY);
        $this->assertSame('Bengaluru', $alias['clean']['city_name']);
    }

    public function test_the_date_cannot_be_in_the_future_or_invalid(): void
    {
        $this->assertSame([], pastAuditValidate($this->input(['audit_date' => self::TODAY]), null, self::TODAY)['errors']);
        foreach (['2026-11-02', '1999-12-31', '2024-02-30', 'yesterday'] as $bad) {
            $this->assertArrayHasKey('audit_date', pastAuditValidate($this->input(['audit_date' => $bad]), null, self::TODAY)['errors'], $bad);
        }
    }

    public function test_length_accepts_a_comma_and_rejects_zero_and_text(): void
    {
        $this->assertSame(12.5, pastAuditValidate($this->input(['length_km' => '12,5']), null, self::TODAY)['clean']['length_km']);
        foreach (['0', '-3', 'ten', '1.234'] as $bad) {
            $this->assertArrayHasKey('length_km', pastAuditValidate($this->input(['length_km' => $bad]), null, self::TODAY)['errors'], $bad);
        }
    }

    // ── the PDF ─────────────────────────────────────────────────

    public function test_a_real_pdf_is_accepted_with_a_safe_file_name(): void
    {
        $v = pastAuditValidate($this->input(), $this->pdf(), self::TODAY);
        $this->assertSame([], $v['errors']);
        $this->assertSame('My Report final.pdf', $v['clean']['report']['name']);
    }

    public function test_a_file_that_is_not_a_pdf_is_refused(): void
    {
        $v = pastAuditValidate($this->input(), $this->pdf('<html>not a pdf</html>', 'x.pdf'), self::TODAY);
        $this->assertSame('The report must be a PDF file.', $v['errors']['report']);
    }

    public function test_an_oversize_or_failed_upload_is_refused_and_no_file_means_no_report(): void
    {
        $big = $this->pdf();
        $big['size'] = PAST_AUDIT_MAX_PDF_BYTES + 1;
        $this->assertArrayHasKey('report', pastAuditValidate($this->input(), $big, self::TODAY)['errors']);
        $this->assertArrayHasKey('report', pastAuditValidate($this->input(), ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '', 'name' => 'a.pdf'], self::TODAY)['errors']);
        $none = pastAuditValidate($this->input(), ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => ''], self::TODAY);
        $this->assertSame([], $none['errors']);
        $this->assertNull($none['clean']['report']);
    }

    public function test_file_names_are_cleaned(): void
    {
        $this->assertSame('evil.pdf', pastAuditFileName('..\\..\\evil<>.pdf'));
        $this->assertSame('audit-report.pdf', pastAuditFileName(''));
        $this->assertSame('notes.pdf', pastAuditFileName('notes'));
    }

    // ── storing and listing ─────────────────────────────────────

    private function repo(): PastAuditRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO cities VALUES (1, 'Pune')");
        $pdo->exec('CREATE TABLE past_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, city_name TEXT NOT NULL, state TEXT NOT NULL DEFAULT \'\',
            name TEXT NOT NULL, audit_date TEXT NOT NULL, road_count INTEGER NOT NULL DEFAULT 0, segment_count INTEGER NOT NULL DEFAULT 0,
            length_km REAL NOT NULL DEFAULT 0, score REAL NULL, condition_label TEXT NULL, report_name TEXT NULL, report_data BLOB NULL,
            created_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT NULL)');
        return new PastAuditRepository($pdo);
    }

    public function test_a_past_audit_is_listed_in_the_public_shape(): void
    {
        $repo = $this->repo();
        $id = $repo->create(pastAuditValidate($this->input(), $this->pdf(), self::TODAY)['clean'], 1);
        $rows = $repo->published();
        $this->assertCount(1, $rows);
        $a = pastAuditShape($rows[0]);
        $this->assertSame([$id, 'Pune Cycle Track Audit 2024', 'Pune', 'Maharashtra', '2024-12-15', 2024, 12], [$a['id'], $a['name'], $a['city'], $a['state'], $a['date'], $a['year'], $a['month']]);
        $this->assertSame([4, 38, 19.0, 31.5, 'OK', true], [$a['roads'], $a['segments'], $a['length_km'], $a['score'], $a['condition'], $a['past']]);
        $this->assertSame(1, $a['city_id'], 'a city that exists in the app uses its real id');
        $this->assertSame('/api/public/past_audit_report.php?id=' . $id, $a['report_url']);
    }

    public function test_a_city_only_known_from_past_audits_gets_a_stable_key(): void
    {
        $k1 = pastAuditCityKey('Nagpur', null);
        $this->assertSame($k1, pastAuditCityKey(' nagpur ', null));
        $this->assertGreaterThan(10000000000, $k1);
        $this->assertNotSame($k1, pastAuditCityKey('Nashik', null));
    }

    public function test_without_a_pdf_there_is_no_report_link(): void
    {
        $repo = $this->repo();
        $repo->create(pastAuditValidate($this->input(['score' => '']), null, self::TODAY)['clean'], 1);
        $a = pastAuditShape($repo->published()[0]);
        $this->assertSame([null, null], [$a['report_url'], $a['condition']]);
    }

    public function test_the_pdf_comes_back_byte_for_byte_and_only_while_not_deleted(): void
    {
        $repo = $this->repo();
        $bytes = "%PDF-1.4\n" . random_bytes(300);
        $id = $repo->create(pastAuditValidate($this->input(), $this->pdf($bytes), self::TODAY)['clean'], 1);
        [$name, $got] = $repo->report($id);
        $this->assertSame([$bytes, 'My Report final.pdf'], [$got, $name]);

        $this->assertTrue($repo->delete($id));
        $this->assertNull($repo->report($id), 'a deleted past audit no longer serves its PDF');
        $this->assertSame([], $repo->published());
        $this->assertFalse($repo->delete($id), 'deleting twice does nothing');
        $this->assertNotNull($repo->find($id)['deleted_at'], 'the row is kept');
    }

    public function test_newest_past_audit_is_listed_first(): void
    {
        $repo = $this->repo();
        $repo->create(pastAuditValidate($this->input(['name' => 'Older', 'audit_date' => '2023-05-01']), null, self::TODAY)['clean'], 1);
        $repo->create(pastAuditValidate($this->input(['name' => 'Newer', 'audit_date' => '2025-05-01']), null, self::TODAY)['clean'], 1);
        $this->assertSame(['Newer', 'Older'], array_column($repo->published(), 'name'));
    }

    // ── unpublish ───────────────────────────────────────────────

    public function test_unpublish_only_hides_the_audit_and_publish_again_shows_it(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, name TEXT, audit_year INTEGER,
            status TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_note TEXT NULL, admin_decided_at TEXT NULL, admin_decided_by INTEGER NULL, public_hidden_at TEXT NULL)');
        $pdo->exec("INSERT INTO city_audits (id, city_id, name, audit_year, status) VALUES (1,1,'A',2026,'published'),(2,1,'B',2026,'in_review')");
        $repo = new AuditReviewRepository($pdo);
        $row = static fn() => $pdo->query('SELECT status, public_hidden_at, admin_decided_by FROM city_audits WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        $repo->unpublishAudit(['id' => 1, 'status' => 'published'], 9);
        $r = $row();
        // still approved (so nothing to approve again), only hidden
        $this->assertSame('published', $r['status']);
        $this->assertNotNull($r['public_hidden_at']);
        $this->assertNull($r['admin_decided_by']);

        $repo->republishAudit(['id' => 1, 'status' => 'published']);
        $r = $row();
        $this->assertSame('published', $r['status']);
        $this->assertNull($r['public_hidden_at']);
    }

    public function test_unpublish_and_publish_again_refuse_a_wrong_state(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER NOT NULL, name TEXT, audit_year INTEGER,
            status TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, public_hidden_at TEXT NULL)');
        $pdo->exec("INSERT INTO city_audits (id, city_id, name, audit_year, status) VALUES (1,1,'A',2026,'published'),(2,1,'B',2026,'in_review')");
        $repo = new AuditReviewRepository($pdo);

        $caught = [];
        foreach ([
            fn() => $repo->unpublishAudit(['id' => 2, 'status' => 'in_review'], 9),            // not published
            fn() => $repo->republishAudit(['id' => 1, 'status' => 'published']),               // not hidden
        ] as $call) {
            try { $call(); } catch (DomainException $e) { $caught[] = true; }
        }
        $this->assertSame([true, true], $caught);

        $repo->unpublishAudit(['id' => 1, 'status' => 'published'], 9);
        $this->expectException(DomainException::class);   // hiding twice
        $repo->unpublishAudit(['id' => 1, 'status' => 'published'], 9);
    }

    public function test_a_hidden_audit_is_left_out_of_the_public_list_and_report(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE city_audits (id INTEGER PRIMARY KEY, city_id INTEGER, state TEXT, name TEXT, audit_year INTEGER, audit_date TEXT,
            status TEXT, public_hidden_at TEXT NULL)');
        $pdo->exec('CREATE TABLE roads (id INTEGER PRIMARY KEY, audit_id INTEGER)');
        $pdo->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, road_id INTEGER, length REAL)');
        $pdo->exec("INSERT INTO cities VALUES (1,'Pune')");
        $pdo->exec("INSERT INTO city_audits (id, city_id, state, name, audit_year, audit_date, status, public_hidden_at) VALUES
            (1,1,'MH','Shown',2026,'2026-10-01','published',NULL),
            (2,1,'MH','Hidden',2026,'2026-10-02','published','2026-11-01 10:00:00')");
        $repo = new PublicAuditRepository($pdo);
        $this->assertSame(['Shown'], array_column($repo->published(), 'name'));
        $this->assertSame([false, true], [$repo->isHidden(1), $repo->isHidden(2)]);
    }
}
