<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Collaborators.php';
require_once __DIR__ . '/../repositories/CollaboratorRepository.php';

/** Collaborations: the organisations the Admin lists on the home page (in-memory SQLite). */
class CollaboratorsTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE collaborators (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, role_label TEXT NULL,
            website_url TEXT NULL, logo_mime TEXT NULL, logo_data BLOB NULL, sort_order INTEGER NOT NULL DEFAULT 0,
            is_visible INTEGER NOT NULL DEFAULT 1, logo_scale INTEGER NOT NULL DEFAULT 100, created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT NULL)');
        return $pdo;
    }

    /** A file entry for a picture whose header says it is $w x $h (enough for getimagesize). */
    private function picture(string $type = 'png', int $w = 120, int $h = 60, ?int $size = null): array
    {
        $bytes = match ($type) {
            'png'  => "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . "\x08\x06\x00\x00\x00" . "\0\0\0\0",
            'gif'  => 'GIF89a' . pack('vv', $w, $h) . "\x00\x00\x00;",
            default => "<?php echo 'not a picture';",
        };
        $f = tempnam(sys_get_temp_dir(), 'co');
        file_put_contents($f, $bytes);
        $this->tmp[] = $f;
        return ['name' => 'logo.' . $type, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => $size ?? strlen($bytes)];
    }

    private function add(PDO $pdo, string $name, array $o = []): int
    {
        $v = collaboratorValidate(['name' => $name] + $o, $o['file'] ?? null);
        $this->assertSame([], $v['errors']);
        return (new CollaboratorRepository($pdo))->create($v['clean'], 1);
    }

    // ── what the Admin types ───────────────────────────────────

    public function test_only_a_name_is_required(): void
    {
        $v = collaboratorValidate(['name' => '  Pune   Municipal Corporation '], null);
        $this->assertSame([], $v['errors']);
        $this->assertSame('Pune Municipal Corporation', $v['clean']['name']);
        $this->assertNull($v['clean']['role_label']);
        $this->assertNull($v['clean']['website_url']);
        $this->assertNull($v['clean']['logo']);

        $this->assertArrayHasKey('name', collaboratorValidate(['name' => '   '], null)['errors']);
        $this->assertArrayHasKey('name', collaboratorValidate(['name' => str_repeat('a', 121)], null)['errors']);
        $this->assertArrayHasKey('role_label', collaboratorValidate(['name' => 'X', 'role_label' => str_repeat('r', 61)], null)['errors']);
    }

    public function test_website_must_be_a_web_address(): void
    {
        $this->assertSame('https://example.org', collaboratorUrlClean('example.org'));
        $this->assertSame('http://example.org/a?b=1', collaboratorUrlClean(' http://example.org/a?b=1 '));
        $this->assertSame('https://www.parisar.org', collaboratorUrlClean('https://www.parisar.org'));
        foreach (['javascript:alert(1)', 'data:text/html,hi', 'ftp://example.org', 'https://', 'not a url', 'https://localhost',
                  'https://exa mple.org', 'https://example.org/"onmouseover=x', str_repeat('a', 260) . '.org'] as $bad) {
            $this->assertNull(collaboratorUrlClean($bad), $bad);
        }
        $this->assertArrayHasKey('website_url', collaboratorValidate(['name' => 'X', 'website_url' => 'javascript:alert(1)'], null)['errors']);
    }

    // ── the logo ───────────────────────────────────────────────

    public function test_a_real_picture_is_accepted_and_typed_by_its_content(): void
    {
        $png = collaboratorValidate(['name' => 'X'], $this->picture('png'));
        $this->assertSame([], $png['errors']);
        $this->assertSame('image/png', $png['clean']['logo']['mime']);
        $gif = collaboratorValidate(['name' => 'X'], $this->picture('gif'));
        $this->assertSame('image/gif', $gif['clean']['logo']['mime']);
    }

    public function test_the_logo_is_refused_when_it_is_not_a_usable_picture(): void
    {
        $notPicture = $this->picture('txt');
        $notPicture['name'] = 'logo.png';                       // a fake extension does not help
        $this->assertArrayHasKey('logo', collaboratorValidate(['name' => 'X'], $notPicture)['errors']);
        $this->assertArrayHasKey('logo', collaboratorValidate(['name' => 'X'], $this->picture('png', 10, 10))['errors']);        // too small
        $this->assertArrayHasKey('logo', collaboratorValidate(['name' => 'X'], $this->picture('png', 5000, 100))['errors']);     // too big
        $this->assertArrayHasKey('logo', collaboratorValidate(['name' => 'X'], $this->picture('png', 120, 60, COLLAB_MAX_LOGO_BYTES + 1))['errors']);
        $tooLarge = $this->picture('png'); $tooLarge['error'] = UPLOAD_ERR_INI_SIZE;
        $this->assertStringContainsString('1 MB', (string)collaboratorValidate(['name' => 'X'], $tooLarge)['errors']['logo']);
        $svg = $this->picture('txt');
        file_put_contents($svg['tmp_name'], '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $svg['size'] = filesize($svg['tmp_name']);
        $this->assertArrayHasKey('logo', collaboratorValidate(['name' => 'X'], $svg)['errors']);                                  // SVG can carry scripts
    }

    public function test_no_file_chosen_means_no_logo_change(): void
    {
        $none = ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $v = collaboratorValidate(['name' => 'X'], $none);
        $this->assertSame([], $v['errors']);
        $this->assertNull($v['clean']['logo']);
        $this->assertFalse($v['clean']['remove_logo']);
        $this->assertTrue(collaboratorValidate(['name' => 'X', 'remove_logo' => '1'], null)['clean']['remove_logo']);
        // a new logo wins over "remove"
        $this->assertFalse(collaboratorValidate(['name' => 'X', 'remove_logo' => '1'], $this->picture())['clean']['remove_logo']);
    }

    // ── the list ───────────────────────────────────────────────

    public function test_new_collaborators_go_to_the_end_and_only_visible_ones_are_public(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $a = $this->add($pdo, 'Alpha');
        $b = $this->add($pdo, 'Beta');
        $c = $this->add($pdo, 'Gamma');
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($repo->visible(), 'name'));

        $repo->setVisible($b, false);
        $this->assertSame(['Alpha', 'Gamma'], array_column($repo->visible(), 'name'));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($repo->all(), 'name'));   // the Admin still sees it
        $repo->setVisible($b, true);
        $this->assertCount(3, $repo->visible());
        $this->assertNotNull($c);
        $this->assertNotNull($a);
    }

    public function test_delete_removes_it_everywhere_but_keeps_the_row(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $a = $this->add($pdo, 'Alpha', ['file' => $this->picture()]);
        $this->add($pdo, 'Beta');
        $this->assertTrue($repo->delete($a));
        $this->assertFalse($repo->delete($a));                                   // already gone
        $this->assertSame(['Beta'], array_column($repo->all(), 'name'));
        $this->assertSame(['Beta'], array_column($repo->visible(), 'name'));
        $this->assertNull($repo->logo($a, false));
        $this->assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM collaborators')->fetchColumn());   // nothing erased
        $this->assertNotNull($repo->find($a)['deleted_at']);
    }

    public function test_move_swaps_neighbours_and_stops_at_the_ends(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $a = $this->add($pdo, 'Alpha');
        $b = $this->add($pdo, 'Beta');
        $c = $this->add($pdo, 'Gamma');
        $this->assertTrue($repo->move($c, 'up'));
        $this->assertSame(['Alpha', 'Gamma', 'Beta'], array_column($repo->all(), 'name'));
        $this->assertTrue($repo->move($a, 'down'));
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($repo->all(), 'name'));
        $this->assertFalse($repo->move($c, 'up'));                                // already first
        $this->assertFalse($repo->move($b, 'down'));                              // already last
        $this->assertFalse($repo->move(999, 'up'));
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($repo->visible(), 'name'));   // the public list follows
    }

    public function test_ordering_ignores_removed_ones(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $a = $this->add($pdo, 'Alpha');
        $b = $this->add($pdo, 'Beta');
        $c = $this->add($pdo, 'Gamma');
        $repo->delete($b);
        $this->assertTrue($repo->move($c, 'up'));                                 // swaps with Alpha, not the removed Beta
        $this->assertSame(['Gamma', 'Alpha'], array_column($repo->all(), 'name'));
        $d = $this->add($pdo, 'Delta');
        $this->assertSame(['Gamma', 'Alpha', 'Delta'], array_column($repo->all(), 'name'));
        $this->assertNotNull($a);
        $this->assertNotNull($d);
    }

    // ── editing and the logo ───────────────────────────────────

    public function test_edit_changes_details_and_keeps_the_logo_unless_told_otherwise(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $id = $this->add($pdo, 'Alpha', ['file' => $this->picture('png')]);
        $this->assertSame('image/png', $repo->logo($id, true)[0]);

        $v = collaboratorValidate(['name' => 'Alpha Trust', 'role_label' => 'Funder', 'website_url' => 'alpha.org'], null);
        $this->assertTrue($repo->update($id, $v['clean']));
        $row = $repo->find($id);
        $this->assertSame('Alpha Trust', $row['name']);
        $this->assertSame('Funder', $row['role_label']);
        $this->assertSame('https://alpha.org', $row['website_url']);
        $this->assertSame(1, (int)$row['has_logo']);                                // logo untouched

        $repo->update($id, collaboratorValidate(['name' => 'Alpha Trust'], $this->picture('gif'))['clean']);
        $this->assertSame('image/gif', $repo->logo($id, true)[0]);                  // replaced

        $repo->update($id, collaboratorValidate(['name' => 'Alpha Trust', 'remove_logo' => '1'], null)['clean']);
        $this->assertNull($repo->logo($id, true));                                  // removed
        $this->assertSame(0, (int)$repo->find($id)['has_logo']);
        $this->assertNull($repo->find($id)['role_label'] ?? null);                  // an emptied field is cleared
    }

    public function test_a_hidden_logo_is_not_public_but_the_admin_can_still_see_it(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $id = $this->add($pdo, 'Alpha', ['file' => $this->picture('png')]);
        $repo->setVisible($id, false);
        $this->assertNull($repo->logo($id, true));
        $this->assertSame('image/png', $repo->logo($id, false)[0]);
        $this->assertNull($repo->logo(12345, true));
    }

    // ── what the home page reads ───────────────────────────────

    public function test_shape_for_the_home_page(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $this->add($pdo, 'Alpha', ['role_label' => 'Funder', 'website_url' => 'alpha.org', 'file' => $this->picture()]);
        $this->add($pdo, 'Beta');
        [$a, $b] = array_map('collaboratorShape', $repo->visible());

        $this->assertSame(['id', 'name', 'role', 'url', 'logo_url', 'logo_scale'], array_keys($a));
        $this->assertSame(100, $a['logo_scale']);
        $this->assertSame('Funder', $a['role']);
        $this->assertSame('https://alpha.org', $a['url']);
        $this->assertMatchesRegularExpression('#^/api/public/collaborator_logo\.php\?id=\d+&v=\d+$#', (string)$a['logo_url']);
        $this->assertNull($b['role']);
        $this->assertNull($b['url']);
        $this->assertNull($b['logo_url']);
    }

    // ── logo size inside the round tile ────────────────────────

    public function test_logo_size_is_checked_and_defaults_to_the_automatic_fit(): void
    {
        foreach (['49', '151', '0', '-20', 'abc', '99.5'] as $bad) {
            $v = collaboratorValidate(['name' => 'A', 'logo_scale' => $bad], null);
            $this->assertArrayHasKey('logo_scale', $v['errors'], $bad);
        }
        foreach (['50', '100', '150', ' 120 '] as $good) {
            $v = collaboratorValidate(['name' => 'A', 'logo_scale' => $good], null);
            $this->assertSame([], $v['errors'], $good);
            $this->assertSame((int)trim($good), $v['clean']['logo_scale']);
        }
        $this->assertNull(collaboratorValidate(['name' => 'A'], null)['clean']['logo_scale']);   // not sent
        $this->assertNull(collaboratorValidate(['name' => 'A', 'logo_scale' => ''], null)['clean']['logo_scale']);

        $pdo = $this->pdo();
        $id = $this->add($pdo, 'A');
        $this->assertSame(100, (int)(new CollaboratorRepository($pdo))->find($id)['logo_scale']);
    }

    public function test_logo_size_is_saved_kept_and_shown_on_the_home_page(): void
    {
        $pdo = $this->pdo();
        $repo = new CollaboratorRepository($pdo);
        $id = $this->add($pdo, 'A', ['logo_scale' => '70']);
        $this->assertSame(70, collaboratorShape($repo->find($id))['logo_scale']);

        $repo->update($id, collaboratorValidate(['name' => 'A2'], null)['clean']);               // size not sent: kept
        $this->assertSame(70, collaboratorShape($repo->find($id))['logo_scale']);

        $repo->update($id, collaboratorValidate(['name' => 'A2', 'logo_scale' => '140'], null)['clean']);
        $this->assertSame(140, collaboratorShape($repo->visible()[0])['logo_scale']);
    }

    public function test_an_unusable_stored_size_falls_back_to_the_automatic_fit(): void
    {
        foreach ([null, '', 'x', 0, 10, 500] as $junk) {
            $this->assertSame(100, collaboratorScaleOf($junk));
        }
        $this->assertSame(65, collaboratorScaleOf('65'));
    }
}
