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
