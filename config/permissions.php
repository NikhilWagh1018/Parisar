<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/permissions.php
//  Role-Based Access Control (RBAC) for Parisar.
//
//  ROLES
//  ─────
//    national_admin — Parisar staff. Can do everything, everywhere.
//    city_admin      — Scoped staff. Everything national_admin can do,
//                       but only for resources belonging to their own
//                       city (users.city_id). Pass the resource's
//                       city_id via $ctx['city_id'] so this can be
//                       checked — see USAGE below.
//    surveyor        — Default. Can create roads, run audits on any
//                       road, but can only modify/delete their OWN
//                       roads & segments.
//
//  USAGE
//  ─────
//  1. Simple boolean check (returns true/false, no abort):
//       if (can('delete_road', $userId, $role, ['owner_id' => $road['creator_id'], 'city_id' => $road['city_id']])) { ... }
//
//  2. Hard gate (aborts with 403 JSON if check fails):
//       gate('delete_road', $userId, $userRole, ['owner_id' => $road['creator_id'], 'city_id' => $road['city_id']]);
//
//  Context keys accepted per permission (all optional unless noted):
//    owner_id   int     — the creator_id / user_id of the resource
//    city_id    int|null — the city the resource belongs to. REQUIRED
//                          for a city_admin's elevated access to apply —
//                          without it, a city_admin is treated as a
//                          plain surveyor (falls through to the
//                          owner_id check) for that permission. This is
//                          a deliberate fail-closed default: an endpoint
//                          that forgets to pass city_id simply doesn't
//                          grant city_admin anything extra, rather than
//                          accidentally granting it everywhere.
//    status     string  — resource status (e.g. 'active', 'completed')
//
//  $CURRENT_USER_CITY_ID (from auth_guard.php) is what a city_admin's
//  own city_id is compared against — pass it in as part of building
//  $ctx at the call site, not as a separate function argument.
// ═══════════════════════════════════════════════════════════════

// ── Helpers ──────────────────────────────────────────────────────

/**
 * True if $role is either flavor of admin (national or city-scoped).
 * Use this instead of hand-rolling `in_array($role, [...])` everywhere.
 */
function isAnyAdmin(string $role): bool
{
    return $role === 'national_admin' || $role === 'city_admin';
}

/**
 * True if $role has elevated access to a resource in $ctx['city_id']:
 *   - national_admin: always (city-less, global scope)
 *   - city_admin: only if $ctx['city_id'] is present AND matches the
 *     admin's own city ($GLOBALS['CURRENT_USER_CITY_ID'], set by
 *     auth_guard.php on every request)
 *   - surveyor: never
 */
function hasAdminAccessToCity(string $role, array $ctx): bool
{
    if ($role === 'national_admin') {
        return true;
    }
    if ($role === 'city_admin') {
        $resourceCityId = $ctx['city_id'] ?? null;
        $adminCityId    = $GLOBALS['CURRENT_USER_CITY_ID'] ?? null;
        return $resourceCityId !== null
            && $adminCityId !== null
            && (int)$resourceCityId === (int)$adminCityId;
    }
    return false;
}

// ── Permission definitions ────────────────────────────────────
//
// Each entry maps a permission name to a callable(int $userId, string $role, array $ctx): bool
//
// $ctx is the optional context array passed by the caller.

$PERMISSIONS = [

    // ── Roads ─────────────────────────────────────────────────
    'create_road' => static function (int $userId, string $role, array $ctx): bool {
        // Any authenticated user may create roads.
        return true;
    },

    'edit_road' => static function (int $userId, string $role, array $ctx): bool {
        // The road owner, or an admin scoped to the road's city, may edit metadata.
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'delete_road' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'save_segments' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'finalize_road' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Audit sessions ────────────────────────────────────────
    'create_session' => static function (int $userId, string $role, array $ctx): bool {
        // Any authenticated user may start an audit session.
        return true;
    },

    'view_session' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Segments ──────────────────────────────────────────────
    'submit_audit' => static function (int $userId, string $role, array $ctx): bool {
        // Any authenticated user may submit an audit (ownership of the
        // session is verified separately in the endpoint).
        return true;
    },

    'unlock_segment' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'reset_segment' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'view_audit_data' => static function (int $userId, string $role, array $ctx): bool {
        if (hasAdminAccessToCity($role, $ctx)) return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Admin-only ────────────────────────────────────────────
    // These two are checked as a coarse "is this user any kind of
    // admin at all" gate — the actual city-scoping of WHICH users/
    // roads a city_admin sees happens in the calling endpoint's SQL
    // (filter by city_id when role === 'city_admin'), not here,
    // since this returns a single bool, not a filtered list.
    'manage_users' => static function (int $userId, string $role, array $ctx): bool {
        return isAnyAdmin($role);
    },

    'view_all_roads' => static function (int $userId, string $role, array $ctx): bool {
        return isAnyAdmin($role);
    },
];

// ── can() ─────────────────────────────────────────────────────

/**
 * Check a permission without aborting.
 *
 * @param  string              $permission  Key from PERMISSIONS above.
 * @param  int                 $userId      Current user's ID.
 * @param  string              $role        Current user's role ('national_admin'|'city_admin'|'surveyor').
 * @param  array<string,mixed> $ctx         Optional context (owner_id, city_id, status, …).
 * @return bool
 */
function can(string $permission, int $userId, string $role, array $ctx = []): bool
{
    global $PERMISSIONS;
    if (!array_key_exists($permission, $PERMISSIONS)) {
        // Unknown permissions are denied by default.
        return false;
    }

    return ($PERMISSIONS[$permission])($userId, $role, $ctx);
}

// ── gate() ────────────────────────────────────────────────────

/**
 * Assert a permission and abort with 403 JSON if it fails.
 * Call at the top of any endpoint that needs a permission check.
 *
 * @param  string              $permission
 * @param  int                 $userId
 * @param  string              $role
 * @param  array<string,mixed> $ctx
 */
function gate(string $permission, int $userId, string $role, array $ctx = []): void
{
    if (!can($permission, $userId, $role, $ctx)) {
        // Ensure JSON content type is set before aborting.
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'You do not have permission to perform this action.',
        ]);
        exit;
    }
}
