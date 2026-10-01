<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Cities.php
//  Small helpers around the `cities` table, used when a new account
//  needs a city (registration form, Google sign-up, "choose your
//  city" page).
//
//  While exactly one city exists (Parisar today: Pune), new accounts
//  are put in it automatically and nobody is asked. Once there are
//  two or more, a new account has to pick one.
// ═══════════════════════════════════════════════════════════════

/**
 * All cities, alphabetical.
 *
 * @return list<array{id:int, name:string}>
 */
function listCities(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name FROM cities ORDER BY name ASC, id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);
    return array_map(
        static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
        $rows
    );
}

/**
 * The id of the only city, or null if there are none or several.
 */
function soleCityId(PDO $pdo): ?int
{
    $cities = listCities($pdo);
    return count($cities) === 1 ? $cities[0]['id'] : null;
}

/**
 * Work out which city a self-registering account belongs to.
 *
 *   0 cities  -> [null, null]            (nothing to assign)
 *   1 city    -> [that id, null]         (automatic, nobody is asked)
 *   2+ cities -> the posted id if it is one of them,
 *                otherwise [null, 'Please select your city.']
 *
 * @param list<array{id:int, name:string}> $cities  from listCities()
 * @param mixed                            $posted  raw form value
 * @return array{0: ?int, 1: ?string}  [city id, error message]
 */
function resolveSignupCity(array $cities, mixed $posted): array
{
    if (count($cities) === 0) {
        return [null, null];
    }
    if (count($cities) === 1) {
        return [$cities[0]['id'], null];
    }
    $id = filter_var($posted, FILTER_VALIDATE_INT);
    if ($id !== false) {
        foreach ($cities as $c) {
            if ($c['id'] === $id) {
                return [$id, null];
            }
        }
    }
    return [null, 'Please select your city.'];
}
