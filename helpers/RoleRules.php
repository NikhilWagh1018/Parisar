<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/RoleRules.php
//  Who may change whom. Pure functions (no DB) so they are unit-tested.
//
//  - Only the Admin (national_admin) can change roles, so
//    only the Admin can create City Leaders (city_admin).
//  - A City Leader manages surveyors in their own city only.
//  - A City Leader needs a city, otherwise they would see nothing.
// ═══════════════════════════════════════════════════════════════

const ROLE_VALUES = ['national_admin', 'city_admin', 'surveyor'];

/**
 * May this actor act on (activate/deactivate/etc.) the target user?
 * Admin: anyone. City Leader: surveyors only.
 */
function canManageUser(string $actorRole, string $targetRole): bool
{
    if ($actorRole === 'national_admin') {
        return true;
    }
    return $actorRole === 'city_admin' && $targetRole === 'surveyor';
}

/**
 * Validate a role change. Returns an error message, or null if allowed.
 *
 * @param mixed $newRole  raw value from the request
 */
function validateRoleChange(
    string $actorRole,
    string $targetRole,
    ?int $targetCityId,
    mixed $newRole,
    bool $isSelf,
    int $nationalAdminCount
): ?string {
    if ($actorRole !== 'national_admin') {
        return 'Only the Admin can change roles.';
    }
    if (!is_string($newRole) || !in_array($newRole, ROLE_VALUES, true)) {
        return 'Invalid role.';
    }
    if ($newRole === 'city_admin' && ($targetCityId === null || $targetCityId <= 0)) {
        return 'Assign this user to a city before making them a City Leader.';
    }
    if ($isSelf && $newRole !== 'national_admin' && $newRole !== $targetRole) {
        return 'You cannot demote yourself.';
    }
    if ($newRole !== 'national_admin' && $targetRole === 'national_admin' && $nationalAdminCount <= 1) {
        return 'Cannot demote the last remaining admin.';
    }
    return null;
}
