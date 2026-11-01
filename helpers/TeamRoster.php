<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/TeamRoster.php
//  A City Leader's own team of surveyors (users.team_leader_id).
//
//  Why: a city can have several City Leaders. A surveyor's "forgot password"
//  request (helpers/PasswordRequest.php) goes only to that surveyor's team
//  leader. A surveyor in no team is shown to every City Leader of the city,
//  so no request is left without someone to answer it.
//
//  Rules:
//    - A City Leader can add an active surveyor of their OWN city who is in no
//      team (or whose old team leader is no longer an active City Leader of the
//      city), and can remove people from their OWN team.
//    - Nobody can take a surveyor out of another leader's team.
//    - Only surveyors can be in a team.
//
//  Needs migration 027 (users.team_leader_id).
// ═══════════════════════════════════════════════════════════════

/**
 * Active surveyors of the city with their team state for this leader.
 * state: 'mine' (in this leader's team), 'free' (no team, or the old leader is
 * gone), 'other' (in another City Leader's team; other_leader names them).
 *
 * @return list<array{id:int,name:string,email:string,state:string,other_leader:?string}>
 */
function teamRoster(PDO $pdo, int $leaderId, int $cityId): array
{
    $st = $pdo->prepare(
        "SELECT s.id, s.name, s.email, s.team_leader_id,
                tl.id AS tl_id, tl.name AS tl_name
           FROM users s
           LEFT JOIN users tl
                  ON tl.id = s.team_leader_id AND tl.role = 'city_leader'
                 AND tl.is_active = 1 AND tl.city_id = s.city_id
          WHERE s.role = 'surveyor' AND s.is_active = 1 AND s.city_id = ?
          ORDER BY s.name ASC, s.id ASC"
    );
    $st->execute([$cityId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $leader = $r['tl_id'] !== null ? (int)$r['tl_id'] : null;
        $out[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'email'        => (string)$r['email'],
            'state'        => $leader === null ? 'free' : ($leader === $leaderId ? 'mine' : 'other'),
            'other_leader' => ($leader !== null && $leader !== $leaderId) ? (string)$r['tl_name'] : null,
        ];
    }
    return $out;
}

/**
 * Add a surveyor to this leader's team, or take them out of it.
 *
 * @return array{ok:bool, http:int, error?:string, name?:string}
 */
function teamSetMember(PDO $pdo, int $leaderId, ?int $leaderCityId, int $surveyorId, bool $add): array
{
    if ($leaderCityId === null) {
        return ['ok' => false, 'http' => 403, 'error' => 'No city is assigned to your account yet.'];
    }
    $st = $pdo->prepare('SELECT id, name, role, city_id, is_active, team_leader_id FROM users WHERE id = ? LIMIT 1');
    $st->execute([$surveyorId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || (string)$u['role'] !== 'surveyor' || (int)($u['city_id'] ?? 0) !== $leaderCityId) {
        return ['ok' => false, 'http' => 404, 'error' => 'That surveyor was not found in your city.'];
    }
    if ((int)$u['is_active'] !== 1) {
        return ['ok' => false, 'http' => 409, 'error' => 'This surveyor is deactivated.'];
    }

    // Who really leads this person now? An old leader who was deactivated, demoted or moved counts as nobody.
    $current = $u['team_leader_id'] !== null ? (int)$u['team_leader_id'] : null;
    $holder  = null;
    if ($current !== null) {
        $h = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'city_leader' AND is_active = 1 AND city_id = ? LIMIT 1");
        $h->execute([$current, $leaderCityId]);
        $holder = $h->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $holderId = $holder ? (int)$holder['id'] : null;

    if ($add) {
        if ($holderId !== null && $holderId !== $leaderId) {
            return ['ok' => false, 'http' => 409, 'error' => $u['name'] . ' is already in ' . $holder['name'] . "'s team."];
        }
        if ($holderId === $leaderId) {
            return ['ok' => true, 'http' => 200, 'name' => (string)$u['name']];   // already mine
        }
    } else {
        if ($holderId !== $leaderId) {
            return ['ok' => false, 'http' => 409, 'error' => $u['name'] . ' is not in your team.'];
        }
    }

    // Compare-and-swap on the value we read, so two leaders clicking at once cannot both win.
    $newValue = $add ? $leaderId : null;
    if ($current === null) {
        $up = $pdo->prepare('UPDATE users SET team_leader_id = ? WHERE id = ? AND team_leader_id IS NULL');
        $up->execute([$newValue, $surveyorId]);
    } else {
        $up = $pdo->prepare('UPDATE users SET team_leader_id = ? WHERE id = ? AND team_leader_id = ?');
        $up->execute([$newValue, $surveyorId, $current]);
    }
    if ($up->rowCount() !== 1) {
        return ['ok' => false, 'http' => 409, 'error' => 'Someone else just changed this team. Reload the page and try again.'];
    }
    return ['ok' => true, 'http' => 200, 'name' => (string)$u['name']];
}
