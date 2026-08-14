<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  config/permissions.php
//  Role-Based Access Control (RBAC) for Parisar.
//
//  ROLES
//  ─────
//    admin     — Parisar staff. Can do everything.
//    surveyor  — Default. Can create roads, run audits on any road,
//                but can only modify/delete their OWN roads & segments.
//
//  USAGE
//  ─────
//  1. Simple boolean check (returns true/false, no abort):
//       if (can('delete_road', $userId, ['owner_id' => $road['creator_id']])) { ... }
//
//  2. Hard gate (aborts with 403 JSON if check fails):
//       gate('delete_road', $userId, $userRole, ['owner_id' => $road['creator_id']]);
//
//  Context keys accepted per permission (all optional unless noted):
//    owner_id   int   — the creator_id / user_id of the resource
//    status     string — resource status (e.g. 'active', 'completed')
// ═══════════════════════════════════════════════════════════════

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
        // Only the road owner or an admin may edit road metadata.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'delete_road' => static function (int $userId, string $role, array $ctx): bool {
        // Only the road owner or an admin may delete a road.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'save_segments' => static function (int $userId, string $role, array $ctx): bool {
        // Only the road owner or an admin may define/replace segments.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Audit sessions ────────────────────────────────────────
    'create_session' => static function (int $userId, string $role, array $ctx): bool {
        // Any authenticated user may start an audit session.
        return true;
    },

    'view_session' => static function (int $userId, string $role, array $ctx): bool {
        // Only the session owner or an admin may view session data.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Segments ──────────────────────────────────────────────
    'submit_audit' => static function (int $userId, string $role, array $ctx): bool {
        // Any authenticated user may submit an audit (ownership of the
        // session is verified separately in the endpoint).
        return true;
    },

    'unlock_segment' => static function (int $userId, string $role, array $ctx): bool {
        // Only the road owner (creator) or an admin may unlock a completed segment.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'reset_segment' => static function (int $userId, string $role, array $ctx): bool {
        // Only the session owner or an admin may wipe and reset a segment.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    'view_audit_data' => static function (int $userId, string $role, array $ctx): bool {
        // Only the road creator or an admin may pre-fill the edit form.
        if ($role === 'admin') return true;
        return isset($ctx['owner_id']) && (int)$ctx['owner_id'] === $userId;
    },

    // ── Admin-only ────────────────────────────────────────────
    'manage_users' => static function (int $userId, string $role, array $ctx): bool {
        return $role === 'admin';
    },

    'view_all_roads' => static function (int $userId, string $role, array $ctx): bool {
        return $role === 'admin';
    },
];

// ── can() ─────────────────────────────────────────────────────

/**
 * Check a permission without aborting.
 *
 * @param  string              $permission  Key from PERMISSIONS above.
 * @param  int                 $userId      Current user's ID.
 * @param  string              $role        Current user's role ('admin'|'surveyor').
 * @param  array<string,mixed> $ctx         Optional context (owner_id, status, …).
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
