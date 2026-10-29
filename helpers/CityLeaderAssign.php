<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityLeaderAssign.php
//  Rules for an Admin making someone the City Leader of a city.
//  Pure functions (no DB) so they are unit-tested.
// ═══════════════════════════════════════════════════════════════

/**
 * Why this user cannot become the City Leader of $cityId, or null when they can.
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
        return 'This user is already the City Leader of this city.';
    }
    return null;
}
