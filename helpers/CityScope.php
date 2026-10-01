<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityScope.php
//  Resolves which city a logged-in user's read access is scoped to.
//
//  Returns:
//    null  — unscoped (national_admin sees every city)
//    int>0 — scoped to that city_id
//    0     — no city can be determined; matches no rows (fail closed)
//
//  A user with no city_id falls back to the single city if exactly
//  one exists (same rule as RoadRepository::resolveCityIdForNewRoadGroup),
//  so new signups keep working while Parisar is single-city.
// ═══════════════════════════════════════════════════════════════

function resolveViewerCityScope(PDO $pdo, string $role, ?int $userCityId): ?int
{
    if ($role === 'national_admin') {
        return null;
    }
    if ($userCityId !== null && $userCityId > 0) {
        return $userCityId;
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn();
    if ($count === 1) {
        return (int)$pdo->query('SELECT id FROM cities LIMIT 1')->fetchColumn();
    }
    return 0;
}
