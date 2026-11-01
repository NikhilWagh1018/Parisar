<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/TeamRoster.php';

/** A City Leader's own team of surveyors, on in-memory SQLite. */
class TeamRosterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT NOT NULL,
            city_id INTEGER NULL, team_leader_id INTEGER NULL, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->exec("INSERT INTO users (id,name,email,role,city_id) VALUES
            (1,'Anita Admin','a@t.org','admin',NULL),
            (2,'Lata Leader','lata@t.org','city_leader',1),
            (3,'Sam Surveyor','sam@t.org','surveyor',1),
            (4,'Bala Leader','bala@t.org','city_leader',2),
            (5,'Sita Surveyor','sita@t.org','surveyor',2),
            (8,'Pooja Leader','pooja@t.org','city_leader',1),
            (9,'Sunil Surveyor','sunil@t.org','surveyor',1),
            (10,'Dead Surveyor','dead@t.org','surveyor',1)");
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 10');
    }

    private function leaderOf(int $userId): ?int
    {
        $v = $this->pdo->query("SELECT team_leader_id FROM users WHERE id = $userId")->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    }

    private function states(int $leaderId, int $cityId = 1): array
    {
        $out = [];
        foreach (teamRoster($this->pdo, $leaderId, $cityId) as $r) { $out[$r['name']] = $r['state']; }
        return $out;
    }

    public function test_roster_lists_only_active_surveyors_of_the_city(): void
    {
        $this->assertSame(['Sam Surveyor' => 'free', 'Sunil Surveyor' => 'free'], $this->states(2));
        $this->assertSame(['Sita Surveyor' => 'free'], $this->states(4, 2));
    }

    public function test_add_and_remove(): void
    {
        $this->assertSame(200, teamSetMember($this->pdo, 2, 1, 3, true)['http']);
        $this->assertSame(2, $this->leaderOf(3));
        $this->assertSame('mine', $this->states(2)['Sam Surveyor']);
        $this->assertSame('other', $this->states(8)['Sam Surveyor']);
        $this->assertSame('Lata Leader', teamRoster($this->pdo, 8, 1)[0]['other_leader']);

        $this->assertSame(200, teamSetMember($this->pdo, 2, 1, 3, false)['http']);
        $this->assertNull($this->leaderOf(3));
        $this->assertSame('free', $this->states(8)['Sam Surveyor']);
    }

    public function test_adding_someone_already_in_my_team_is_harmless(): void
    {
        teamSetMember($this->pdo, 2, 1, 3, true);
        $this->assertSame(200, teamSetMember($this->pdo, 2, 1, 3, true)['http']);
        $this->assertSame(2, $this->leaderOf(3));
    }

    public function test_cannot_take_someone_from_another_leaders_team(): void
    {
        teamSetMember($this->pdo, 2, 1, 3, true);
        $r = teamSetMember($this->pdo, 8, 1, 3, true);
        $this->assertSame(409, $r['http']);
        $this->assertStringContainsString("Lata Leader's team", $r['error']);
        $this->assertSame(2, $this->leaderOf(3));
    }

    public function test_cannot_remove_someone_who_is_not_in_my_team(): void
    {
        teamSetMember($this->pdo, 2, 1, 3, true);
        $this->assertSame(409, teamSetMember($this->pdo, 8, 1, 3, false)['http'], 'not Pooja\'s');
        $this->assertSame(409, teamSetMember($this->pdo, 8, 1, 9, false)['http'], 'in nobody\'s team');
        $this->assertSame(2, $this->leaderOf(3));
    }

    public function test_only_active_surveyors_of_my_own_city_can_be_added(): void
    {
        $this->assertSame(404, teamSetMember($this->pdo, 2, 1, 5, true)['http'], 'surveyor of another city');
        $this->assertSame(404, teamSetMember($this->pdo, 2, 1, 8, true)['http'], 'another City Leader');
        $this->assertSame(404, teamSetMember($this->pdo, 2, 1, 1, true)['http'], 'an Admin');
        $this->assertSame(404, teamSetMember($this->pdo, 2, 1, 999, true)['http'], 'no such user');
        $this->assertSame(409, teamSetMember($this->pdo, 2, 1, 10, true)['http'], 'deactivated');
        $this->assertSame(403, teamSetMember($this->pdo, 2, null, 3, true)['http'], 'leader with no city');
        $this->assertNull($this->leaderOf(5));
        $this->assertNull($this->leaderOf(8));
    }

    public function test_a_gone_leaders_team_can_be_taken_over(): void
    {
        teamSetMember($this->pdo, 8, 1, 3, true);                 // Sam is Pooja's
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 8');   // Pooja deactivated
        $this->assertSame('free', $this->states(2)['Sam Surveyor']);
        $this->assertSame(200, teamSetMember($this->pdo, 2, 1, 3, true)['http']);
        $this->assertSame(2, $this->leaderOf(3));
    }

    public function test_a_stale_value_is_not_overwritten_by_a_second_click(): void
    {
        // Lata reads "free", but Pooja wins the race before Lata's update runs.
        $this->pdo->exec('UPDATE users SET team_leader_id = 8 WHERE id = 3');
        $this->assertSame(409, teamSetMember($this->pdo, 2, 1, 3, true)['http']);
        $this->assertSame(8, $this->leaderOf(3));
    }

    public function test_add_many_reports_what_failed_and_adds_the_rest(): void
    {
        teamSetMember($this->pdo, 8, 1, 9, true);                       // Sunil is Pooja's
        $r = teamAddMany($this->pdo, 2, 1, [3, 9, 5, 10, 3, 0, 999]);   // Sam ok, Sunil taken, Sita other city, Dead inactive, dup, junk
        $this->assertSame([3], $r['added']);
        $this->assertSame([9, 5, 10, 999], array_keys($r['failed']));
        $this->assertSame(2, $this->leaderOf(3));
        $this->assertSame(8, $this->leaderOf(9));
        $this->assertNull($this->leaderOf(5));
    }
}
