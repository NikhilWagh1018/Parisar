<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/PasswordReset.php';
require_once __DIR__ . '/../helpers/StaffPasswordReset.php';

/** Staff-driven password reset, on in-memory SQLite. */
class StaffPasswordResetTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL,
            password TEXT NULL, must_change_password INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NOT NULL,
            requested_ip TEXT NULL)');
        $this->pdo->exec("INSERT INTO users (id, name, email, password) VALUES
            (1, 'Asha Patil', 'asha@example.org', 'old-hash-1'),
            (2, 'Ravi Kulkarni', 'ravi@example.org', 'old-hash-2')");
    }

    private function passwordOf(int $id): string
    {
        return (string)$this->pdo->query("SELECT password FROM users WHERE id = $id")->fetchColumn();
    }

    // -- who may reset whom ---------------------------------------

    /** @return array<string, array{0:string,1:int,2:?int,3:int,4:string,5:?int,6:bool,7:bool}> */
    public static function permissionCases(): array
    {
        //            actorRole      actorId actorCity targetId targetRole    targetCity active  allowed
        return [
            'admin resets a surveyor'            => ['admin',       1, null, 5, 'surveyor',    1,    true,  true],
            'admin resets a city leader'         => ['admin',       1, null, 5, 'city_leader', 2,    true,  true],
            'admin resets another admin'         => ['admin',       1, null, 5, 'admin',       null, true,  true],
            'admin resets a user with no city'   => ['admin',       1, null, 5, 'surveyor',    null, true,  true],
            'admin cannot reset themselves'      => ['admin',       1, null, 1, 'admin',       null, true,  false],
            'admin cannot reset a deactivated'   => ['admin',       1, null, 5, 'surveyor',    1,    false, false],
            'leader resets own-city surveyor'    => ['city_leader', 3, 1,    5, 'surveyor',    1,    true,  true],
            'leader cannot reset other city'     => ['city_leader', 3, 1,    5, 'surveyor',    2,    true,  false],
            'leader cannot reset no-city user'   => ['city_leader', 3, 1,    5, 'surveyor',    null, true,  false],
            'leader without a city resets none'  => ['city_leader', 3, null, 5, 'surveyor',    null, true,  false],
            'leader cannot reset another leader' => ['city_leader', 3, 1,    6, 'city_leader', 1,    true,  false],
            'leader cannot reset an admin'       => ['city_leader', 3, 1,    7, 'admin',       null, true,  false],
            'leader cannot reset themselves'     => ['city_leader', 3, 1,    3, 'city_leader', 1,    true,  false],
            'leader cannot reset deactivated'    => ['city_leader', 3, 1,    5, 'surveyor',    1,    false, false],
            'surveyor resets nobody'             => ['surveyor',    4, 1,    5, 'surveyor',    1,    true,  false],
        ];
    }

    #[DataProvider('permissionCases')]
    public function test_permission_matrix(
        string $actorRole, int $actorId, ?int $actorCity, int $targetId, string $targetRole, ?int $targetCity, bool $active, bool $allowed
    ): void {
        $err = staffPasswordResetError($actorRole, $actorId, $actorCity, $targetId, $targetRole, $targetCity, $active);
        if ($allowed) {
            $this->assertNull($err);
        } else {
            $this->assertIsString($err);
            $this->assertNotSame('', $err);
        }
    }

    // -- temporary password ---------------------------------------

    public function test_temp_password_has_three_words_and_four_digits(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $p = tempPasswordGenerate();
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]+-[A-Z][a-z]+-[A-Z][a-z]+-\d{4}$/', $p);
            $this->assertNull(passwordRuleError($p, $p), "$p must satisfy the sign-up password rules");
        }
    }

    public function test_temp_passwords_are_not_all_the_same(): void
    {
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen[tempPasswordGenerate()] = true;
        }
        $this->assertGreaterThan(20, count($seen));
    }

    public function test_every_word_is_plain_letters(): void
    {
        $this->assertGreaterThanOrEqual(60, count(STAFF_TEMP_PASSWORD_WORDS));
        $this->assertSame(count(STAFF_TEMP_PASSWORD_WORDS), count(array_unique(STAFF_TEMP_PASSWORD_WORDS)));
        foreach (STAFF_TEMP_PASSWORD_WORDS as $w) {
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]+$/', $w);
        }
    }

    // -- apply / flag / clear -------------------------------------

    public function test_apply_sets_the_temporary_password_and_the_flag(): void
    {
        $this->assertFalse(userMustChangePassword($this->pdo, 1));

        staffPasswordResetApply($this->pdo, 1, 'Coral-Tiger-Maple-4821');

        $this->assertTrue(password_verify('Coral-Tiger-Maple-4821', $this->passwordOf(1)));
        $this->assertTrue(userMustChangePassword($this->pdo, 1));
        $this->assertSame('old-hash-2', $this->passwordOf(2), 'other accounts untouched');
        $this->assertFalse(userMustChangePassword($this->pdo, 2));
    }

    public function test_apply_closes_open_emailed_links_of_that_account_only(): void
    {
        $this->pdo->exec("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES
            (1, 'h1', '2099-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2, 'h2', '2099-01-01 00:00:00', '2026-01-01 00:00:00')");

        staffPasswordResetApply($this->pdo, 1, 'Coral-Tiger-Maple-4821', 1_800_000_000);

        $used1 = $this->pdo->query("SELECT used_at FROM password_resets WHERE user_id = 1")->fetchColumn();
        $used2 = $this->pdo->query("SELECT used_at FROM password_resets WHERE user_id = 2")->fetchColumn();
        $this->assertNotNull($used1);
        $this->assertNull($used2);
    }

    public function test_apply_still_works_when_the_resets_table_is_missing(): void
    {
        $this->pdo->exec('DROP TABLE password_resets');
        // The helper logs the missing table; keep that out of the test output.
        $previous = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'staff_password_reset_test.log');
        try {
            staffPasswordResetApply($this->pdo, 1, 'Coral-Tiger-Maple-4821');
        } finally {
            ini_set('error_log', (string)$previous);
        }
        $this->assertTrue(userMustChangePassword($this->pdo, 1));
    }

    public function test_choosing_a_password_clears_the_flag(): void
    {
        staffPasswordResetApply($this->pdo, 1, 'Coral-Tiger-Maple-4821');

        $this->assertTrue(staffPasswordSetOwn($this->pdo, 1, 'my-own-pass1'));

        $this->assertFalse(userMustChangePassword($this->pdo, 1));
        $this->assertTrue(password_verify('my-own-pass1', $this->passwordOf(1)));
        $this->assertFalse(password_verify('Coral-Tiger-Maple-4821', $this->passwordOf(1)), 'the temporary password stops working');
    }

    public function test_set_own_for_an_unknown_user_changes_nothing(): void
    {
        $this->assertFalse(staffPasswordSetOwn($this->pdo, 99, 'my-own-pass1'));
    }

    public function test_missing_column_means_no_change_is_owed(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password TEXT)');
        $pdo->exec("INSERT INTO users (id, password) VALUES (1, 'x')");

        $this->assertFalse(userMustChangePassword($pdo, 1));
    }

    public function test_unknown_user_owes_nothing(): void
    {
        $this->assertFalse(userMustChangePassword($this->pdo, 99));
    }

    // -- pages that stay open -------------------------------------

    public function test_only_the_change_page_and_sign_out_are_exempt(): void
    {
        $this->assertTrue(mustChangePasswordExempt('/auth/force_password.php'));
        $this->assertTrue(mustChangePasswordExempt('/auth/logout.php'));
        $this->assertTrue(mustChangePasswordExempt('/app/auth/force_password.php'), 'app in a sub-folder');
        $this->assertTrue(mustChangePasswordExempt('\\auth\\logout.php'), 'Windows separators');

        $this->assertFalse(mustChangePasswordExempt('/pages/dashboard.php'));
        $this->assertFalse(mustChangePasswordExempt('/api/admin/surveyors.php'));
        $this->assertFalse(mustChangePasswordExempt('/auth/login.php'));
        $this->assertFalse(mustChangePasswordExempt('/auth/force_password.php.evil'));
        $this->assertFalse(mustChangePasswordExempt('/evil/force_password.php'));
        $this->assertFalse(mustChangePasswordExempt(''));
    }
}
