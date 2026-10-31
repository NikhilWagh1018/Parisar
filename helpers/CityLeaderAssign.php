<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityLeaderAssign.php
//  Rules for an Admin making someone the City Leader of a city.
//  Pure functions (no DB) so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

/**
 * Why this user cannot become a City Leader of $cityId, or null when they can.
 * A city can have several City Leaders; the Admin assigns each project to one of them.
 *
 * @param array{role:string,is_active:mixed,city_id:mixed}|null $user  the users row, or null if missing
 */
function cityLeaderAssignError(?array $user, int $cityId): ?string
{
    if ($user === null) {
        return 'User not found.';
    }
    if ((int)$user['is_active'] !== 1) {
        return 'This user is inactive.';
    }
    if ((string)$user['role'] === 'admin') {
        return 'An Admin cannot be a City Leader.';
    }
    $userCity = $user['city_id'] !== null ? (int)$user['city_id'] : null;
    if ($userCity !== null && $userCity !== $cityId) {
        return 'This user belongs to another city. Move them to this city first on the Users page.';
    }
    if ((string)$user['role'] === 'city_leader' && $userCity === $cityId) {
        return 'This user is already a City Leader of this city.';
    }
    return null;
}

/**
 * Why this user cannot be given a project in $cityId, or null when they can.
 * Only an active City Leader of the project's own city can be assigned (never a Surveyor).
 *
 * @param array{role:string,is_active:mixed,city_id:mixed}|null $user
 */
function projectLeaderError(?array $user, int $cityId): ?string
{
    if ($user === null) {
        return 'That person was not found.';
    }
    if ((string)$user['role'] !== 'city_leader') {
        return 'Only a City Leader can be assigned to a project.';
    }
    if ((int)$user['is_active'] !== 1) {
        return 'This City Leader is inactive.';
    }
    if ((int)($user['city_id'] ?? 0) !== $cityId) {
        return 'This City Leader belongs to another city.';
    }
    return null;
}

/**
 * May this signed-in person open the project? The Admin may open any. A City Leader
 * may open only a project of their own city that the Admin assigned to them.
 *
 * @param array<string,mixed> $audit  row with city_id and leader_user_id
 */
function projectOpenableBy(array $audit, string $role, ?int $userId, ?int $cityId): bool
{
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'city_leader' || $userId === null || $cityId === null) {
        return false;
    }
    return (int)$audit['city_id'] === $cityId
        && isset($audit['leader_user_id']) && (int)$audit['leader_user_id'] === $userId;
}
