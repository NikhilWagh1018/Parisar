<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/PasswordRequest.php
//  "Forgot password" that needs no email service.
//
//  1. On auth/forgot_password.php the person types their email and chooses a
//     NEW password. It is hashed at once and kept as a pending request; the
//     old password keeps working. A 6-digit code is shown on their screen.
//  2. The request goes to the person responsible:
//        surveyor with a City Leader  -> the City Leader of their city. When the
//                                        surveyor is in a City Leader's team
//                                        (users.team_leader_id) only that
//                                        leader sees it; a surveyor in no team is
//                                        shown to every City Leader of the city.
//        surveyor with no City Leader -> an Admin
//        City Leader                  -> an Admin
//        Admin                        -> nobody (use scripts/make_admin_reset_sql.php)
//  3. The approver phones the person on a number they already know, checks it
//     is really them, asks for the code, types it and presses Approve. Only
//     then does the new password start working, and every older sign-in of
//     that person is ended (users.password_changed_at).
//
//  Nobody ever sees or passes on a password. A request nobody approves
//  changes nothing. Five wrong codes close a request.
//
//  Times are Unix timestamps passed in (default: now) and stored as UTC
//  'Y-m-d H:i:s' strings, so the database clock never matters.
//  Needs migrations 026 and 027.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/StaffPasswordReset.php';

const PASSWORD_REQUEST_TTL_HOURS = 48;   // an unanswered request expires after this
const PASSWORD_REQUEST_MAX_TRIES = 5;    // wrong codes before the request is closed
const PASSWORD_REQUEST_DAILY_CAP = 10;   // requests per account per 24 hours
const PASSWORD_REQUEST_KEEP_DAYS = 90;   // finished requests are deleted after this

function passwordRequestStamp(int $ts): string
{
    return gmdate('Y-m-d H:i:s', $ts);
}

/** Six digits, e.g. "048213". */
function passwordRequestCodeGenerate(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Keeps digits only, so "048 213" and "048-213" both work. */
function passwordRequestCodeNormalise(string $raw): string
{
    return (string)preg_replace('/\D+/', '', $raw);
}

function passwordRequestCodeHash(string $code): string
{
    return hash('sha256', $code);
}

/**
 * The account for an email address, or null.
 *
 * @return array{id:int, name:string, email:string, role:string, city_id:?int, is_active:int}|null
 */
function passwordRequestFindUser(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT id, name, email, role, city_id, is_active FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    return [
        'id'        => (int)$u['id'],
        'name'      => (string)$u['name'],
        'email'     => (string)$u['email'],
        'role'      => (string)$u['role'],
        'city_id'   => $u['city_id'] !== null ? (int)$u['city_id'] : null,
        'is_active' => (int)$u['is_active'],
    ];
}

/**
 * Who answers this person's request? Returns ['approver_role' => ..., 'city_id' => ...]
 * or null when nobody does (Admins have nobody above them).
 *
 * @param array{id:int, role:string, city_id:?int} $user
 * @return array{approver_role:string, city_id:?int}|null
 */
function passwordRequestRoute(PDO $pdo, array $user): ?array
{
    if ($user['role'] === 'admin') {
        return null;
    }
    if ($user['role'] === 'city_leader' || $user['city_id'] === null) {
        return ['approver_role' => 'admin', 'city_id' => $user['city_id']];
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE role = 'city_leader' AND is_active = 1 AND city_id = ?"
    );
    $st->execute([$user['city_id']]);
    return (int)$st->fetchColumn() > 0
        ? ['approver_role' => 'city_leader', 'city_id' => $user['city_id']]
        : ['approver_role' => 'admin',       'city_id' => $user['city_id']];
}

/**
 * Stores a pending request for an active account and replaces any older
 * pending one. Returns the new request id, or null when nothing was stored
 * (unknown or deactivated account, an Admin, or the daily cap was reached).
 * The caller shows the same page either way.
 *
 * @param array{id:int, role:string, city_id:?int, is_active:int}|null $user
 */
function passwordRequestCreate(
    PDO $pdo,
    ?array $user,
    string $newPasswordHash,
    string $code,
    string $ip,
    ?int $now = null
): ?int {
    $now ??= time();
    if ($user === null || $user['is_active'] === 0) {
        return null;
    }
    $route = passwordRequestRoute($pdo, $user);
    if ($route === null) {
        return null;
    }

    $recent = $pdo->prepare('SELECT COUNT(*) FROM password_requests WHERE user_id = ? AND created_at >= ?');
    $recent->execute([$user['id'], passwordRequestStamp($now - 86400)]);
    if ((int)$recent->fetchColumn() >= PASSWORD_REQUEST_DAILY_CAP) {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE password_requests SET status = 'replaced', handled_at = ? WHERE user_id = ? AND status = 'pending'")
            ->execute([passwordRequestStamp($now), $user['id']]);
        $pdo->prepare(
            'INSERT INTO password_requests
                (user_id, approver_role, city_id, new_password_hash, code_hash, requested_ip, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $route['approver_role'],
            $route['city_id'],
            $newPasswordHash,
            passwordRequestCodeHash($code),
            substr($ip, 0, 45),
            passwordRequestStamp($now),
            passwordRequestStamp($now + PASSWORD_REQUEST_TTL_HOURS * 3600),
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * SQL condition + parameters limiting requests to those addressed to this
 * approver. The query must join the requester as `u` (users) and the request as `pr`.
 *
 *  - Admin: requests addressed to Admins.
 *  - City Leader: requests addressed to City Leaders for their own city, and
 *    only those from surveyors in their own team or in no team. A surveyor whose
 *    team leader is no longer an active City Leader of that city counts as in no
 *    team, so the request is never stranded.
 *  - Anyone else: none.
 *
 * @return array{0:string, 1:list<mixed>}
 */
function passwordRequestScope(string $actorRole, ?int $actorCityId, ?int $actorId = null): array
{
    if ($actorRole === 'admin') {
        return ["pr.approver_role = 'admin'", []];
    }
    if ($actorRole === 'city_leader' && $actorCityId !== null && $actorId !== null) {
        return [
            "pr.approver_role = 'city_leader' AND pr.city_id = ?
             AND (u.team_leader_id IS NULL
                  OR u.team_leader_id = ?
                  OR NOT EXISTS (SELECT 1 FROM users tl
                                  WHERE tl.id = u.team_leader_id AND tl.role = 'city_leader'
                                    AND tl.is_active = 1 AND tl.city_id = pr.city_id))",
            [$actorCityId, $actorId],
        ];
    }
    return ['1 = 0', []];
}

/**
 * Open requests this approver may act on, oldest first. Nothing secret is returned.
 *
 * @return list<array<string,mixed>>
 */
function passwordRequestOpenFor(PDO $pdo, string $actorRole, ?int $actorCityId, ?int $actorId = null, ?int $now = null): array
{
    $now ??= time();
    [$cond, $params] = passwordRequestScope($actorRole, $actorCityId, $actorId);
    $st = $pdo->prepare(
        "SELECT pr.id, pr.created_at, pr.requested_ip, u.name, u.email, u.phone, u.role, u.team_leader_id,
                (SELECT c.name FROM cities c WHERE c.id = u.city_id) AS city_name
           FROM password_requests pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.status = 'pending' AND pr.expires_at > ? AND u.is_active = 1 AND $cond
          ORDER BY pr.created_at ASC, pr.id ASC"
    );
    $st->execute(array_merge([passwordRequestStamp($now)], $params));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Number of open requests for this approver (the badge). */
function passwordRequestOpenCount(PDO $pdo, string $actorRole, ?int $actorCityId, ?int $actorId = null, ?int $now = null): int
{
    return count(passwordRequestOpenFor($pdo, $actorRole, $actorCityId, $actorId, $now));
}

/** @return array<string,mixed>|null the request, only when addressed to this approver */
function passwordRequestFindScoped(PDO $pdo, int $id, string $actorRole, ?int $actorCityId, ?int $actorId = null): ?array
{
    [$cond, $params] = passwordRequestScope($actorRole, $actorCityId, $actorId);
    $st = $pdo->prepare("SELECT pr.* FROM password_requests pr JOIN users u ON u.id = pr.user_id WHERE pr.id = ? AND $cond LIMIT 1");
    $st->execute(array_merge([$id], $params));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function passwordRequestIsOpen(array $row, int $now): bool
{
    return (string)$row['status'] === 'pending'
        && (string)$row['expires_at'] > passwordRequestStamp($now);
}

const PASSWORD_REQUEST_NOT_OPEN = 'This request is no longer open. It was already handled, replaced by a newer one, or it expired.';

/**
 * Approve: check the code, then make the person's chosen password the real one.
 *
 * @return array{ok:bool, http:int, error?:string, tries_left?:int, name?:string, user_id?:int}
 */
function passwordRequestApprove(
    PDO $pdo,
    int $id,
    string $rawCode,
    string $actorRole,
    int $actorId,
    ?int $actorCityId,
    ?int $now = null
): array {
    $now ??= time();
    $req = passwordRequestFindScoped($pdo, $id, $actorRole, $actorCityId, $actorId);
    if ($req === null) {
        return ['ok' => false, 'http' => 404, 'error' => 'Request not found.'];
    }
    if (!passwordRequestIsOpen($req, $now)) {
        return ['ok' => false, 'http' => 409, 'error' => PASSWORD_REQUEST_NOT_OPEN];
    }

    // Re-check against the account as it is now (role or city may have changed).
    $ut = $pdo->prepare('SELECT id, name, role, city_id, is_active FROM users WHERE id = ? LIMIT 1');
    $ut->execute([(int)$req['user_id']]);
    $target = $ut->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        return ['ok' => false, 'http' => 404, 'error' => 'Request not found.'];
    }
    $err = staffPasswordResetError(
        $actorRole, $actorId, $actorCityId,
        (int)$target['id'], (string)$target['role'],
        $target['city_id'] !== null ? (int)$target['city_id'] : null,
        (int)$target['is_active'] === 1
    );
    if ($err !== null) {
        return ['ok' => false, 'http' => 403, 'error' => $err];
    }

    $code = passwordRequestCodeNormalise($rawCode);
    if (strlen($code) !== 6) {
        return ['ok' => false, 'http' => 422, 'error' => 'Enter the 6-digit code the person was shown.'];
    }

    if (!hash_equals((string)$req['code_hash'], passwordRequestCodeHash($code))) {
        $pdo->prepare("UPDATE password_requests SET code_tries = code_tries + 1 WHERE id = ? AND status = 'pending'")
            ->execute([$id]);
        $t = $pdo->prepare('SELECT code_tries FROM password_requests WHERE id = ?');
        $t->execute([$id]);
        $tries = (int)$t->fetchColumn();
        if ($tries >= PASSWORD_REQUEST_MAX_TRIES) {
            $pdo->prepare("UPDATE password_requests SET status = 'locked', handled_at = ? WHERE id = ? AND status = 'pending'")
                ->execute([passwordRequestStamp($now), $id]);
            return ['ok' => false, 'http' => 422, 'tries_left' => 0,
                'error' => 'Too many wrong codes, so this request was closed. Ask the person to send a new request.'];
        }
        $left = PASSWORD_REQUEST_MAX_TRIES - $tries;
        return ['ok' => false, 'http' => 422, 'tries_left' => $left,
            'error' => 'That code does not match. Ask the person to read it again from their screen. '
                     . $left . ($left === 1 ? ' try left.' : ' tries left.')];
    }

    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE password_requests SET status = 'approved', handled_by = ?, handled_at = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$actorId, passwordRequestStamp($now), $id]);
        if ($claim->rowCount() !== 1) {            // someone else got there first
            $pdo->rollBack();
            return ['ok' => false, 'http' => 409, 'error' => PASSWORD_REQUEST_NOT_OPEN];
        }
        $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0, password_changed_at = ? WHERE id = ?')
            ->execute([(string)$req['new_password_hash'], passwordRequestStamp($now), (int)$target['id']]);
        $pdo->prepare("UPDATE password_requests SET status = 'replaced', handled_at = ? WHERE user_id = ? AND status = 'pending'")
            ->execute([passwordRequestStamp($now), (int)$target['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    staffPasswordResetCancelLinks($pdo, (int)$target['id'], $now);

    return ['ok' => true, 'http' => 200, 'name' => (string)$target['name'], 'user_id' => (int)$target['id']];
}

/**
 * Dismiss: close the request without changing anything.
 *
 * @return array{ok:bool, http:int, error?:string, user_id?:int}
 */
function passwordRequestDismiss(PDO $pdo, int $id, string $actorRole, int $actorId, ?int $actorCityId, ?int $now = null): array
{
    $now ??= time();
    $req = passwordRequestFindScoped($pdo, $id, $actorRole, $actorCityId, $actorId);
    if ($req === null) {
        return ['ok' => false, 'http' => 404, 'error' => 'Request not found.'];
    }
    $st = $pdo->prepare("UPDATE password_requests SET status = 'dismissed', handled_by = ?, handled_at = ? WHERE id = ? AND status = 'pending'");
    $st->execute([$actorId, passwordRequestStamp($now), $id]);
    if ($st->rowCount() !== 1) {
        return ['ok' => false, 'http' => 409, 'error' => PASSWORD_REQUEST_NOT_OPEN];
    }
    return ['ok' => true, 'http' => 200, 'user_id' => (int)$req['user_id']];
}

/** Deletes finished requests older than PASSWORD_REQUEST_KEEP_DAYS and long-expired pending ones. */
function passwordRequestPrune(PDO $pdo, ?int $now = null): void
{
    $now ??= time();
    $pdo->prepare('DELETE FROM password_requests WHERE created_at < ?')
        ->execute([passwordRequestStamp($now - PASSWORD_REQUEST_KEEP_DAYS * 86400)]);
}

/**
 * True when this session started before the password was last changed by an
 * approval, so it must be signed out. Returns false (instead of failing) when
 * the column is missing, so the site keeps working until migration 026 has run.
 */
function passwordChangedSinceLogin(PDO $pdo, int $userId, int $sessionStartedAt): bool
{
    try {
        $st = $pdo->prepare('SELECT password_changed_at FROM users WHERE id = ? LIMIT 1');
        if ($st === false || !$st->execute([$userId])) {
            return false;
        }
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return false;
        }
        $changed = strtotime((string)$v . ' UTC');
        return $changed !== false && $changed >= $sessionStartedAt;
    } catch (Throwable $e) {
        return false;
    }
}
