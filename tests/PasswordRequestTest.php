<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PasswordReset.php';
require_once __DIR__ . '/../helpers/PasswordRequest.php';

/** "Forgot password" requests approved by a City Leader or Admin, on in-memory SQLite. */
class PasswordRequestTest extends TestCase
{
    private PDO $pdo;
    private int $now;

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone TEXT, role TEXT NOT NULL,
            city_id INTEGER NULL, team_leader_id INTEGER NULL, password TEXT NULL, must_change_password INTEGER NOT NULL DEFAULT 0,
            password_changed_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NOT NULL, requested_ip TEXT NULL)');
        $this->pdo->exec('CREATE TABLE password_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            approver_role TEXT NOT NULL, city_id INTEGER NULL, new_password_hash TEXT NOT NULL, code_hash TEXT NOT NULL,
            code_tries INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'pending\', requested_ip TEXT NOT NULL DEFAULT \'\',
            handled_by INTEGER NULL, handled_at TEXT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO cities VALUES (1,'Pune'),(2,'Bengaluru'),(3,'Nashik')");
        $this->pdo->exec("INSERT INTO users (id,name,email,phone,role,city_id,password) VALUES
            (1,'Anita Admin','admin@t.org','9800000001','admin',NULL,'old1'),
            (2,'Lata Leader','lata@t.org','9800000002','city_leader',1,'old2'),
            (3,'Sam Surveyor','sam@t.org','9800000003','surveyor',1,'old3'),
            (4,'Bala Leader','bala@t.org','9800000004','city_leader',2,'old4'),
            (5,'Sita Surveyor','sita@t.org','9800000005','surveyor',2,'old5'),
            (6,'Lee Loner','lee@t.org','9800000006','surveyor',NULL,'old6'),
            (7,'Nina Nashik','nina@t.org','9800000007','surveyor',3,'old7'),   -- Nashik has no City Leader
            (8,'Pooja Leader','pooja@t.org','9800000008','city_leader',1,'old8'),  -- Pune's second City Leader
            (9,'Sunil Surveyor','sunil@t.org','9800000009','surveyor',1,'old9')");
    }

    /** Files a request as the person with this email; returns [requestId|null, code]. */
    private function ask(string $email, string $pw = 'NewPass123', ?int $at = null): array
    {
        $code = passwordRequestCodeGenerate();
        $id = passwordRequestCreate($this->pdo, passwordRequestFindUser($this->pdo, $email), password_hash($pw, PASSWORD_DEFAULT), $code, '1.2.3.4', $at ?? $this->now);
        return [$id, $code];
    }

    private function field(string $col, int $id, string $table = 'password_requests'): mixed
    {
        return $this->pdo->query("SELECT $col FROM $table WHERE id = $id")->fetchColumn();
    }

    private function pwWorks(int $userId, string $pw): bool
    {
        return password_verify($pw, (string)$this->field('password', $userId, 'users'));
    }

    // -- code helpers ---------------------------------------------

    public function test_code_is_six_digits_and_normalised(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', passwordRequestCodeGenerate());
        }
        $this->assertSame('048213', passwordRequestCodeNormalise('048 213'));
        $this->assertSame('048213', passwordRequestCodeNormalise(' 048-213 '));
    }

    // -- routing ---------------------------------------------------

    public function test_routing(): void
    {
        [$sam]  = $this->ask('sam@t.org');
        [$lata] = $this->ask('lata@t.org');
        [$lee]  = $this->ask('lee@t.org');
        [$nina] = $this->ask('nina@t.org');
        $this->assertSame(['city_leader', 1],  [$this->field('approver_role', $sam),  (int)$this->field('city_id', $sam)]);
        $this->assertSame('admin', $this->field('approver_role', $lata), 'a City Leader asks an Admin');
        $this->assertSame('admin', $this->field('approver_role', $lee),  'no city -> Admin');
        $this->assertSame('admin', $this->field('approver_role', $nina), 'city without an active leader -> Admin');
    }

    public function test_nothing_is_stored_for_admins_unknown_or_deactivated_accounts(): void
    {
        $this->assertNull($this->ask('admin@t.org')[0]);
        $this->assertNull($this->ask('ghost@t.org')[0]);
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 3');
        $this->assertNull($this->ask('sam@t.org')[0]);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM password_requests')->fetchColumn());
    }

    public function test_a_new_request_replaces_the_pending_one_and_the_daily_cap_applies(): void
    {
        [$a] = $this->ask('sam@t.org');
        [$b] = $this->ask('sam@t.org', 'Other12345');
        $this->assertSame('replaced', $this->field('status', $a));
        $this->assertSame('pending',  $this->field('status', $b));
        for ($i = 0; $i < PASSWORD_REQUEST_DAILY_CAP; $i++) { $this->ask('sam@t.org'); }
        $this->assertNull($this->ask('sam@t.org')[0], 'cap reached: nothing stored');
        $this->assertNotNull($this->ask('sam@t.org', 'x1x1x1x1x1', $this->now + 86401 + 1)[0], 'a day later it works again');
    }

    // -- who sees what ---------------------------------------------

    public function test_inbox_scoping(): void
    {
        $this->ask('sam@t.org'); $this->ask('sita@t.org'); $this->ask('lata@t.org'); $this->ask('lee@t.org');
        $names = fn(array $rows) => array_column($rows, 'name');
        $this->assertSame(['Sam Surveyor'], $names(passwordRequestOpenFor($this->pdo, 'city_leader', 1, 2, $this->now)));
        $this->assertSame(['Sita Surveyor'], $names(passwordRequestOpenFor($this->pdo, 'city_leader', 2, 4, $this->now)));
        $this->assertSame(['Lata Leader', 'Lee Loner'], $names(passwordRequestOpenFor($this->pdo, 'admin', null, 1, $this->now)));
        $this->assertSame([], passwordRequestOpenFor($this->pdo, 'city_leader', null, 2, $this->now), 'leader with no city sees nothing');
        $this->assertSame([], passwordRequestOpenFor($this->pdo, 'surveyor', 1, 3, $this->now));
        $row = passwordRequestOpenFor($this->pdo, 'city_leader', 1, 2, $this->now)[0];
        $this->assertArrayNotHasKey('code_hash', $row);
        $this->assertArrayNotHasKey('new_password_hash', $row);
    }

    public function test_expired_requests_are_not_listed(): void
    {
        $this->ask('sam@t.org');
        $later = $this->now + PASSWORD_REQUEST_TTL_HOURS * 3600 + 1;
        $this->assertSame([], passwordRequestOpenFor($this->pdo, 'city_leader', 1, 2, $later));
    }

    // -- teams: two City Leaders in one city -------------------------

    private function openNames(int $leaderId, int $cityId = 1): array
    {
        return array_column(passwordRequestOpenFor($this->pdo, 'city_leader', $cityId, $leaderId, $this->now), 'name');
    }

    public function test_surveyor_in_no_team_is_shown_to_every_leader_of_the_city(): void
    {
        $this->ask('sam@t.org');
        $this->assertSame(['Sam Surveyor'], $this->openNames(2), 'Lata');
        $this->assertSame(['Sam Surveyor'], $this->openNames(8), 'Pooja');
        $this->assertSame([], $this->openNames(4, 2), 'a leader of another city never sees it');
    }

    public function test_surveyor_in_a_team_is_shown_only_to_that_leader(): void
    {
        $this->pdo->exec('UPDATE users SET team_leader_id = 8 WHERE id = 3');   // Sam is in Pooja's team
        $this->pdo->exec('UPDATE users SET team_leader_id = 2 WHERE id = 9');   // Sunil is in Lata's team
        $this->ask('sam@t.org'); $this->ask('sunil@t.org');
        $this->assertSame(['Sunil Surveyor'], $this->openNames(2), 'Lata sees only her own team');
        $this->assertSame(['Sam Surveyor'],   $this->openNames(8), 'Pooja sees only hers');
    }

    public function test_only_the_team_leader_can_approve_or_dismiss(): void
    {
        $this->pdo->exec('UPDATE users SET team_leader_id = 8 WHERE id = 3');
        [$id, $code] = $this->ask('sam@t.org');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http'], 'Lata is not Sam\'s leader');
        $this->assertSame(404, passwordRequestDismiss($this->pdo, $id, 'city_leader', 2, 1, $this->now)['http']);
        $this->assertSame('pending', $this->field('status', $id));
        $this->assertSame(200, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 8, 1, $this->now)['http'], 'Pooja is');
        $this->assertSame(8, (int)$this->field('handled_by', $id));
    }

    public function test_a_leader_cannot_act_without_a_known_id(): void
    {
        [$id] = $this->ask('sam@t.org');
        $this->assertSame([], passwordRequestOpenFor($this->pdo, 'city_leader', 1, null, $this->now), 'no actor id: fail closed');
        $this->assertSame('pending', $this->field('status', $id));
    }

    public function test_request_is_not_stranded_when_the_team_leader_is_gone(): void
    {
        $this->pdo->exec('UPDATE users SET team_leader_id = 8 WHERE id = 3');
        $this->ask('sam@t.org');
        $this->assertSame([], $this->openNames(2), 'hidden from Lata while Pooja is active');
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 8');            // Pooja deactivated
        $this->assertSame(['Sam Surveyor'], $this->openNames(2), 'Lata can answer now');
        $this->pdo->exec('UPDATE users SET is_active = 1, role = \'surveyor\' WHERE id = 8');   // Pooja demoted
        $this->assertSame(['Sam Surveyor'], $this->openNames(2));
        $this->pdo->exec('UPDATE users SET role = \'city_leader\', city_id = 2 WHERE id = 8');    // Pooja moved to another city
        $this->assertSame(['Sam Surveyor'], $this->openNames(2));
    }

    public function test_admin_inbox_is_not_affected_by_teams(): void
    {
        $this->pdo->exec('UPDATE users SET team_leader_id = 8 WHERE id = 2');   // even a leader row with a team value
        $this->ask('lata@t.org'); $this->ask('pooja@t.org');
        $this->assertSame(['Lata Leader', 'Pooja Leader'], array_column(passwordRequestOpenFor($this->pdo, 'admin', null, 1, $this->now), 'name'));
    }

    // -- approve: the permission matrix -----------------------------

    public function test_only_the_right_approver_can_act(): void
    {
        [$sam, $code] = $this->ask('sam@t.org');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $sam, $code, 'city_leader', 4, 2, $this->now)['http'], 'other city leader');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $sam, $code, 'admin', 1, null, $this->now)['http'], 'not addressed to Admins');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $sam, $code, 'surveyor', 3, 1, $this->now)['http'], 'a surveyor');
        $this->assertSame(404, passwordRequestDismiss($this->pdo, $sam, 'city_leader', 4, 2, $this->now)['http']);
        $this->assertFalse($this->pwWorks(3, 'NewPass123'));

        [$lata, $lcode] = $this->ask('lata@t.org');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $lata, $lcode, 'city_leader', 2, 1, $this->now)['http'], 'a leader cannot approve a leader, not even their own');
        $this->assertSame(404, passwordRequestApprove($this->pdo, $lata, $lcode, 'city_leader', 4, 2, $this->now)['http']);
        $this->assertTrue(passwordRequestApprove($this->pdo, $lata, $lcode, 'admin', 1, null, $this->now)['ok']);
    }

    public function test_approve_activates_the_new_password_only(): void
    {
        [$id, $code] = $this->ask('sam@t.org', 'SamNew12345');
        $this->assertTrue($this->pwWorks(3, 'old3') || (string)$this->field('password', 3, 'users') === 'old3');
        $res = passwordRequestApprove($this->pdo, $id, substr($code, 0, 3) . ' ' . substr($code, 3), 'city_leader', 2, 1, $this->now);
        $this->assertTrue($res['ok']);
        $this->assertTrue($this->pwWorks(3, 'SamNew12345'));
        $this->assertSame('approved', $this->field('status', $id));
        $this->assertSame(2, (int)$this->field('handled_by', $id));
        $this->assertSame(0, (int)$this->field('must_change_password', 3, 'users'));
        $this->assertSame(passwordRequestStamp($this->now), $this->field('password_changed_at', 3, 'users'));
        $this->assertSame('old2', $this->field('password', 2, 'users'), 'nobody else changes');
        // replay
        $this->assertSame(409, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http']);
    }

    public function test_wrong_codes_count_down_then_close_the_request(): void
    {
        [$id, $code] = $this->ask('sam@t.org');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertSame(422, passwordRequestApprove($this->pdo, $id, '', 'city_leader', 2, 1, $this->now)['http']);
        $this->assertSame(0, (int)$this->field('code_tries', $id), 'a missing code is not a try');
        for ($left = 4; $left >= 1; $left--) {
            $r = passwordRequestApprove($this->pdo, $id, $wrong, 'city_leader', 2, 1, $this->now);
            $this->assertSame([422, $left], [$r['http'], $r['tries_left']]);
        }
        $r = passwordRequestApprove($this->pdo, $id, $wrong, 'city_leader', 2, 1, $this->now);
        $this->assertSame(0, $r['tries_left']);
        $this->assertSame('locked', $this->field('status', $id));
        $this->assertSame(409, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http'], 'even the right code is refused now');
        $this->assertFalse($this->pwWorks(3, 'NewPass123'));
    }

    public function test_expired_dismissed_and_replaced_requests_cannot_be_approved(): void
    {
        [$a, $ac] = $this->ask('sam@t.org');
        [$b, $bc] = $this->ask('sam@t.org', 'Second12345');
        $this->assertSame(409, passwordRequestApprove($this->pdo, $a, $ac, 'city_leader', 2, 1, $this->now)['http'], 'replaced');
        $stale = $ac === $bc ? ($bc === '999999' ? '888888' : '999999') : $ac;
        $this->assertSame(422, passwordRequestApprove($this->pdo, $b, $stale, 'city_leader', 2, 1, $this->now)['http'], 'the old code does not open the new request');
        $this->assertTrue(passwordRequestDismiss($this->pdo, $b, 'city_leader', 2, 1, $this->now)['ok']);
        $this->assertSame(409, passwordRequestApprove($this->pdo, $b, $bc, 'city_leader', 2, 1, $this->now)['http'], 'dismissed');
        $this->assertSame(409, passwordRequestDismiss($this->pdo, $b, 'city_leader', 2, 1, $this->now)['http']);

        [$c, $cc] = $this->ask('sam@t.org', 'Third123456');
        $late = $this->now + PASSWORD_REQUEST_TTL_HOURS * 3600 + 1;
        $this->assertSame(409, passwordRequestApprove($this->pdo, $c, $cc, 'city_leader', 2, 1, $late)['http'], 'expired');
        $this->assertFalse($this->pwWorks(3, 'Third123456'));
    }

    public function test_account_changes_after_the_request_are_respected(): void
    {
        [$id, $code] = $this->ask('sam@t.org');
        $this->pdo->exec('UPDATE users SET city_id = 2 WHERE id = 3');            // moved to Bengaluru
        $this->assertSame(403, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http'], 'no longer in the leader\'s city');
        $this->pdo->exec('UPDATE users SET city_id = 1, is_active = 0 WHERE id = 3');
        $this->assertSame(403, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http'], 'deactivated');
        $this->assertSame([], passwordRequestOpenFor($this->pdo, 'city_leader', 1, 2, $this->now));
        $this->pdo->exec('UPDATE users SET is_active = 1, role = \'admin\' WHERE id = 3');   // promoted to Admin meanwhile
        $this->assertSame(403, passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['http']);
    }

    public function test_approval_closes_open_emailed_links_and_other_pending_requests(): void
    {
        $this->pdo->exec("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (3, 'h', '2999-01-01 00:00:00', '2026-01-01 00:00:00')");
        [$id, $code] = $this->ask('sam@t.org');
        $this->assertTrue(passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now)['ok']);
        $this->assertNotNull($this->pdo->query('SELECT used_at FROM password_resets WHERE user_id = 3')->fetchColumn());
    }

    // -- sessions ----------------------------------------------------

    public function test_sessions_started_before_a_password_change_end(): void
    {
        $this->assertFalse(passwordChangedSinceLogin($this->pdo, 3, $this->now), 'no change recorded');
        [$id, $code] = $this->ask('sam@t.org');
        passwordRequestApprove($this->pdo, $id, $code, 'city_leader', 2, 1, $this->now);
        $this->assertTrue(passwordChangedSinceLogin($this->pdo, 3, $this->now - 3600), 'old session ends');
        $this->assertTrue(passwordChangedSinceLogin($this->pdo, 3, 0), 'a session with no start time ends');
        $this->assertFalse(passwordChangedSinceLogin($this->pdo, 3, $this->now + 5), 'a later sign-in stays');
        $this->assertFalse(passwordChangedSinceLogin($this->pdo, 4, 0), 'other people are not affected');
    }

    public function test_session_check_survives_a_missing_column(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $this->assertFalse(passwordChangedSinceLogin($pdo, 1, 0));
    }

    public function test_prune_removes_old_rows_only(): void
    {
        $this->ask('sam@t.org', 'OldOne12345', $this->now - (PASSWORD_REQUEST_KEEP_DAYS + 1) * 86400);
        [$new] = $this->ask('sita@t.org');
        passwordRequestPrune($this->pdo, $this->now);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM password_requests')->fetchColumn());
        $this->assertSame('pending', $this->field('status', $new));
    }
}
