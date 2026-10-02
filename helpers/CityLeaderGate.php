<?php
declare(strict_types=1);

// ===============================================================
//  helpers/CityLeaderGate.php
//  City Leaders (role city_admin) may only open the screens listed
//  here. Everything else -- pages and APIs -- is closed to them.
//  config/auth_guard.php enforces this on every protected request.
//  Pure functions (no DB, no session) so they are unit-tested.
//
//  As new City Leader screens are built, add their paths below.
// ===============================================================

const CITY_LEADER_ALLOWED_SCRIPTS = [
    'pages/city_dashboard.php',
    'pages/city_audit.php',
    'api/city/audit_create.php',
    'api/city/audit_road_add.php',
    'api/city/audit_road_remove.php',
];

function cityLeaderNormalize(string $scriptName): string
{
    return '/' . ltrim(str_replace('\\', '/', $scriptName), '/');
}

/** True when a City Leader may open this script (e.g. "/pages/city_dashboard.php"). */
function cityLeaderMayAccess(string $scriptName): bool
{
    $path = cityLeaderNormalize($scriptName);
    foreach (CITY_LEADER_ALLOWED_SCRIPTS as $allowed) {
        if (str_ends_with($path, '/' . $allowed)) {
            return true;
        }
    }
    return false;
}

/**
 * Where to send a City Leader who opened a closed PAGE, or null when the
 * request should get a 403 JSON response instead (APIs and unknown paths).
 */
function cityLeaderRedirectTarget(string $scriptName): ?string
{
    $path = cityLeaderNormalize($scriptName);
    if (str_contains($path, '/api/')) {
        return null;
    }
    $pos = strrpos($path, '/pages/');
    if ($pos === false) {
        return null;
    }
    return substr($path, 0, $pos) . '/pages/city_dashboard.php';
}
