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
        'admin'       => 'platform_dashboard.php',
        'city_leader' => 'city_dashboard.php',
        default       => 'dashboard.php',
    };
}

// Display name for a role. Stored values: admin / city_leader / surveyor.
function roleLabel(string $role): string
{
    return match ($role) {
        'admin'       => 'Admin',
        'city_leader' => 'City Leader',
        'surveyor'    => 'Surveyor',
        default       => ucfirst(str_replace('_', ' ', $role)),
    };
}
