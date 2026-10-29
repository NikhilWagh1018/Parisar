<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Cities.php
//  Small helpers around the `cities` table, used when a new account
//  needs a city or state (registration form, profile).
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
 * The CycleAudit city (a row in `cities`) that matches the city a person typed
 * at sign-up, or null when no such city has been set up yet. Spelling and case
 * do not matter ("bangalore" finds "Bengaluru"). Null means the Admin places the
 * person later, once the city exists.
 *
 * @param list<array{id:int, name:string}> $cities  from listCities()
 */
function signupCityId(array $cities, string $typedCity): ?int
{
    $want = mb_strtolower(cityCanonicalName($typedCity));
    if ($want === '') {
        return null;
    }
    foreach ($cities as $c) {
        if (mb_strtolower(cityCanonicalName($c['name'])) === $want) {
            return $c['id'];
        }
    }
    return null;
}

/**
 * Validate an admin moving a user to another city.
 *
 *   - Admins have no city, so they cannot be moved;
 *   - the target must be one of the real cities (an int id, not a
 *     bool/array/string like "1 OR 1=1");
 *   - a user cannot be left without a city this way.
 *
 * @param list<array{id:int, name:string}> $cities      from listCities()
 * @param string                           $targetRole  the user's current role
 * @param mixed                            $posted      raw value from the request
 * @return array{0: ?int, 1: ?string}  [city id, error message]
 */
function validateUserCityChange(array $cities, string $targetRole, mixed $posted): array
{
    if ($targetRole === 'admin') {
        return [null, 'Admins are not tied to a city.'];
    }
    if (!is_bool($posted) && !is_array($posted)) {
        $id = filter_var($posted, FILTER_VALIDATE_INT);
        if ($id !== false) {
            foreach ($cities as $c) {
                if ($c['id'] === $id) {
                    return [$id, null];
                }
            }
        }
    }
    return [null, 'Please choose a valid city.'];
}

/**
 * Cities and towns by state, from helpers/data/indian_cities.php (plain names).
 *
 * @return array<string, list<string>>  state => city names
 */
function indianCitiesByState(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/data/indian_cities.php';
    }
    return $data;
}

/**
 * Other spellings of cities in the list, including common misspellings. Lookup only
 * (they are not offered in the picker): "Banglore" finds "Bengaluru". A name that is
 * itself in the list always wins over an alias. Every value must be a listed label.
 */
const CITY_ALIASES = [
    'Bangalore' => 'Bengaluru', 'Bengalooru' => 'Bengaluru', 'Banglore' => 'Bengaluru', 'Bangalor' => 'Bengaluru',
    'Bengalore' => 'Bengaluru', 'Banaglore' => 'Bengaluru', 'Mysore' => 'Mysuru', 'Gurgaon' => 'Gurugram',
    'Aurangabad' => 'Chhatrapati Sambhajinagar', 'Ahmednagar' => 'Ahilyanagar', 'Ahmadnagar' => 'Ahilyanagar',
    'Osmanabad' => 'Dharashiv', 'Bombay' => 'Mumbai', 'Mumbay' => 'Mumbai', 'Poona' => 'Pune', 'Puna' => 'Pune',
    'Calcutta' => 'Kolkata', 'Madras' => 'Chennai', 'Trivandrum' => 'Thiruvananthapuram', 'Cochin' => 'Kochi',
    'Quilon' => 'Kollam', 'Vizag' => 'Visakhapatnam', 'Baroda' => 'Vadodara', 'Allahabad' => 'Prayagraj',
    'Pondicherry' => 'Puducherry', 'Mangalore' => 'Mangaluru', 'Belgaum' => 'Belagavi', 'Hubli' => 'Hubballi',
    'Gulbarga' => 'Kalaburagi', 'Bellary' => 'Ballari', 'Bijapur' => 'Vijayapura', 'Shimoga' => 'Shivamogga',
    'Tumkur' => 'Tumakuru', 'Tuticorin' => 'Thoothukudi', 'Trichy' => 'Tiruchirappalli', 'Pimpri Chinchwad' => 'Pimpri-Chinchwad',
];

/** Lower-case, single-spaced form of a name, used to compare spellings. */
function cityKey(string $s): string
{
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
}

/**
 * For a name used by towns in several states, the one state whose town keeps the plain
 * name (the well-known city). The others are labelled "Name (State)". Names not listed
 * here, such as Bilaspur, have no plain form: everyone must pick a state.
 */
const CITY_PRIMARY_STATE = [
    'hyderabad' => 'Telangana', 'kanpur' => 'Uttar Pradesh', 'aligarh' => 'Uttar Pradesh', 'udaipur' => 'Rajasthan',
    'kota' => 'Rajasthan', 'raipur' => 'Chhattisgarh', 'srinagar' => 'Jammu and Kashmir', 'durgapur' => 'West Bengal',
    'kharagpur' => 'West Bengal', 'nizamabad' => 'Telangana', 'vijayapura' => 'Karnataka', 'kolar' => 'Karnataka',
    'chandrapur' => 'Maharashtra', 'chapra' => 'Bihar', 'hajipur' => 'Bihar', 'shahjahanpur' => 'Uttar Pradesh',
    'sitapur' => 'Uttar Pradesh', 'rameswaram' => 'Tamil Nadu', 'sirsa' => 'Haryana',
];

/**
 * Every city the picker offers, one entry per city per state.
 *
 * @return list<array{label:string, plain:string, state:string, shared:bool}>
 */
function cityEntries(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $count = [];
    foreach (indianCitiesByState() as $names) {
        foreach ($names as $n) {
            $count[cityKey($n)] = ($count[cityKey($n)] ?? 0) + 1;
        }
    }
    $out = [];
    foreach (indianCitiesByState() as $state => $names) {
        foreach ($names as $n) {
            $shared  = $count[cityKey($n)] > 1;
            $primary = $shared && (CITY_PRIMARY_STATE[cityKey($n)] ?? null) === $state;
            $out[] = [
                'label'  => ($shared && !$primary) ? $n . ' (' . $state . ')' : $n,
                'plain'  => $n,
                'state'  => $state,
                'shared' => $shared,
            ];
        }
    }
    return $out;
}

/**
 * Every city the picker offers, as [label => state], sorted by label. The label is
 * what is stored as the city's name: plain for most ("Pune"), with the state for
 * towns that share a name ("Bilaspur (Chhattisgarh)").
 *
 * @return array<string, string>
 */
function indianCityList(): array
{
    static $out = null;
    if ($out === null) {
        $out = [];
        foreach (cityEntries() as $e) {
            $out[$e['label']] = $e['state'];
        }
        uksort($out, 'strnatcasecmp');
    }
    return $out;
}

/**
 * Lookup tables over the list.
 *
 * @return array{byLabel: array<string,string>, shared: array<string,list<string>>}
 *         byLabel: cityKey(label) => label;  shared: cityKey(plain name) => every label with that name
 */
function cityIndex(): array
{
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $idx = ['byLabel' => [], 'shared' => []];
    foreach (cityEntries() as $e) {
        $idx['byLabel'][cityKey($e['label'])] = $e['label'];
        if ($e['shared']) {
            $idx['shared'][cityKey($e['plain'])][] = $e['label'];
        }
    }
    foreach ($idx['shared'] as &$labels) {
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    }
    return $idx;
}

/**
 * Match what a person typed against the list.
 *
 * Finds an exact label (case and spacing ignored), a name that is unique to one state,
 * or an alias ("Bangalore"). A shared name ("Bilaspur") is resolved only when the state
 * hint singles one out; otherwise the choices are returned so the form can ask.
 *
 * @return array{0: ?string, 1: ?string, 2: list<string>}  [label, state, choices when ambiguous]
 */
function cityResolve(string $typed, string $stateHint = ''): array
{
    $key = cityKey($typed);
    if ($key === '') {
        return [null, null, []];
    }
    $list = indianCityList();
    $idx  = cityIndex();
    if (isset($idx['byLabel'][$key])) {
        $label = $idx['byLabel'][$key];
        return [$label, $list[$label], []];
    }
    $alias = null;
    foreach (CITY_ALIASES as $a => $canonical) {
        if (cityKey($a) === $key) {
            $alias = $idx['byLabel'][cityKey($canonical)] ?? null;
            break;
        }
    }
    if (isset($idx['shared'][$key])) {
        $choices = $idx['shared'][$key];
        $st = stateCanonical($stateHint);
        if ($st !== null) {
            foreach ($choices as $label) {
                if ($list[$label] === $st) {
                    return [$label, $st, []];
                }
            }
        }
        if ($alias !== null) {
            $choices[] = $alias;
        }
        return [null, null, $choices];
    }
    if ($alias !== null) {
        return [$alias, $list[$alias], []];
    }
    return [null, null, []];
}

/**
 * The state a city is in, so nobody types it. Null for a name not in the list or a
 * shared name that does not say which state ("Bilaspur").
 */
function cityStateFor(string $cityName): ?string
{
    return cityResolve($cityName)[1];
}

/** Tidy a typed city name: trim, collapse spaces. */
function cityNameClean(string $name): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $name));
}

/** A usable city name: 2-100 characters, letters, spaces and . - ' ( ) only. */
function cityNameIsValid(string $name): bool
{
    $n = cityNameClean($name);
    $len = mb_strlen($n);
    return $len >= 2 && $len <= 100 && preg_match("/^[\\p{L}][\\p{L}\\s.'()\\-]*$/u", $n) === 1;
}

/**
 * The listed label of a city: tidied, alternative spellings mapped (Bangalore ->
 * Bengaluru) and known cities given their listed capitalisation. A name that is not
 * in the list (or is ambiguous) is only tidied.
 */
function cityCanonicalName(string $name): string
{
    $name = cityNameClean($name);
    return cityResolve($name)[0] ?? $name;
}

/** All states and union territories in the city list, alphabetical. */
function indianStates(): array
{
    $states = array_keys(indianCitiesByState());
    sort($states, SORT_NATURAL | SORT_FLAG_CASE);
    return $states;
}

/** The listed spelling of a state ("maharashtra " -> "Maharashtra"), or null when it is not a state we know. */
function stateCanonical(string $typed): ?string
{
    $t = mb_strtolower(cityNameClean($typed));
    if ($t === '') {
        return null;
    }
    foreach (indianStates() as $s) {
        if (mb_strtolower($s) === $t) {
            return $s;
        }
    }
    return null;
}

/**
 * Check the City a person chose (sign-up and profile). The city must be in the list;
 * its state comes from the list, never from what was typed. The posted state is used
 * only to pick between towns that share a name ("Bilaspur" + "Haryana").
 *
 * @return array{0: ?string, 1: ?string, 2: array<string,string>}  [city label, state, errors keyed 'city'];
 *         city and state are null when there are errors
 */
function validateHomeLocation(string $cityRaw, string $stateRaw): array
{
    $city = cityNameClean($cityRaw);
    if ($city === '') {
        return [null, null, ['city' => 'City is required.']];
    }
    [$label, $state, $choices] = cityResolve($city, $stateRaw);
    if ($label === null || $state === null) {
        $msg = $choices !== []
            ? 'More than one place has that name. Pick one from the list: ' . implode(', ', array_slice($choices, 0, 6)) . '.'
            : 'Pick your city from the list. If it is not there, ask a Parisar admin to add it.';
        return [null, null, ['city' => $msg]];
    }
    return [$label, $state, []];
}

/** Organisation is required: an error message, or null when it is fine. */
function organisationError(string $org): ?string
{
    $org = cityNameClean($org);
    if ($org === '') {
        return 'Organisation is required.';
    }
    $len = mb_strlen($org);
    if ($len < 2 || $len > 200) {
        return 'Organisation name must be 2–200 characters.';
    }
    if (preg_match('/\p{L}/u', $org) !== 1) {
        return 'Organisation name must contain letters.';
    }
    if (strpbrk($org, '<>') !== false) {
        return 'Organisation name cannot contain < or >.';
    }
    return null;
}

/**
 * The search list for the City input (<datalist id="cityList">). Each option's value is
 * the city label and carries its state in data-state, which js/location_fields.js uses
 * to fill the State box. data-aliases holds the alternative spellings (Bangalore ->
 * Bengaluru) so the form can correct them on the spot.
 */
function locationDatalistsHtml(): string
{
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $aliases = [];
    foreach (CITY_ALIASES as $a => $canonical) {
        $aliases[cityKey($a)] = $canonical;
    }
    $out = '<datalist id="cityList" data-aliases="' . $h((string)json_encode($aliases, JSON_UNESCAPED_UNICODE)) . '">';
    foreach (indianCityList() as $label => $state) {
        $out .= '<option value="' . $h($label) . '" data-state="' . $h($state) . '"></option>';
    }
    return $out . '</datalist>';
}

/**
 * The id of the city with this name (case-insensitive), creating it when it does
 * not exist yet. Known alternative spellings (Bangalore) map to the usual name.
 *
 * @return array{0:int, 1:bool}  [city id, true when it was just created]
 */
function cityFindOrCreate(PDO $pdo, string $name): array
{
    $name = cityCanonicalName($name);
    $find = $pdo->prepare('SELECT id FROM cities WHERE LOWER(name) = LOWER(?) ORDER BY id LIMIT 1');
    $find->execute([$name]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return [(int)$id, false];
    }
    // The live table also needs a unique URL-style slug ("pune") and a creation time (UTC).
    $pdo->prepare('INSERT INTO cities (name, slug, created_at) VALUES (?, ?, ?)')
        ->execute([$name, citySlugUnique($pdo, $name), gmdate('Y-m-d H:i:s')]);
    return [(int)$pdo->lastInsertId(), true];
}

/** "Navi Mumbai" -> "navi-mumbai": lower case, letters and digits, single dashes. */
function citySlug(string $name): string
{
    $s = mb_strtolower(cityNameClean($name));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') {
            $s = $t;
        }
    }
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 140) : 'city';
}

/** A slug no other city uses yet: "pune", then "pune-2", "pune-3"... */
function citySlugUnique(PDO $pdo, string $name): string
{
    $base = citySlug($name);
    $slug = $base;
    $check = $pdo->prepare('SELECT 1 FROM cities WHERE slug = ? LIMIT 1');
    for ($i = 2; $i < 100; $i++) {
        $check->execute([$slug]);
        if ($check->fetchColumn() === false) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}
