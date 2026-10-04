<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/RoleHome.php
//  Which page is each role's home after login. Every login path
//  already lands on pages/dashboard.php, which forwards by role.
// ═══════════════════════════════════════════════════════════════

function roleHomePage(string $role): string
{
    return match ($role) {
        'national_admin' => 'platform_dashboard.php',
        'city_admin'     => 'city_dashboard.php',
        default          => 'dashboard.php',
    };
}

// Display name for a role. Stored values stay national_admin / city_admin.
function roleLabel(string $role): string
{
    return match ($role) {
        'national_admin' => 'Admin',
        'city_admin'     => 'City Leader',
        'surveyor'       => 'Surveyor',
        default          => ucfirst(str_replace('_', ' ', $role)),
    };
}
