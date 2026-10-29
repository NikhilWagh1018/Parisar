<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/Cities.php
//  Small helpers around the `cities` table, used when a new account
//  needs a city (the registration form).
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
 * Cities an Admin can pick when creating a project, grouped by state. Names the
 * app already knew by another spelling live in CITY_ALIASES (below) and are not
 * offered twice.
 *
 * @return array<string, list<string>>  state => city names
 */
function indianCitiesByState(): array
{
    return [
        'Maharashtra'       => ['Pune', 'Mumbai', 'Nagpur', 'Nashik', 'Chhatrapati Sambhajinagar', 'Thane', 'Kolhapur', 'Solapur', 'Navi Mumbai', 'Pimpri-Chinchwad', 'Amravati', 'Satara', 'Sangli', 'Akola', 'Latur', 'Jalgaon', 'Dhule', 'Nanded', 'Ahilyanagar', 'Panvel', 'Kalyan-Dombivli', 'Vasai-Virar', 'Mira-Bhayandar', 'Ichalkaranji', 'Baramati'],
        'Karnataka'         => ['Bengaluru', 'Mysuru', 'Hubballi', 'Dharwad', 'Mangaluru', 'Belagavi', 'Kalaburagi', 'Davanagere', 'Ballari', 'Shivamogga', 'Tumakuru', 'Udupi', 'Vijayapura'],
        'Tamil Nadu'        => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem', 'Tirunelveli', 'Erode', 'Vellore', 'Thoothukudi', 'Thanjavur', 'Tiruppur', 'Hosur', 'Kanchipuram'],
        'Telangana'         => ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar', 'Khammam', 'Secunderabad'],
        'Andhra Pradesh'    => ['Visakhapatnam', 'Vijayawada', 'Tirupati', 'Guntur', 'Nellore', 'Kurnool', 'Rajahmundry', 'Kakinada', 'Amaravati'],
        'Kerala'            => ['Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur', 'Kollam', 'Kannur', 'Alappuzha', 'Palakkad'],
        'Gujarat'           => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Anand'],
        'Rajasthan'         => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota', 'Ajmer', 'Bikaner', 'Alwar', 'Bhilwara'],
        'Delhi'             => ['Delhi', 'New Delhi'],
        'Uttar Pradesh'     => ['Lucknow', 'Kanpur', 'Varanasi', 'Agra', 'Noida', 'Ghaziabad', 'Prayagraj', 'Meerut', 'Bareilly', 'Aligarh', 'Moradabad', 'Gorakhpur', 'Jhansi', 'Mathura'],
        'West Bengal'       => ['Kolkata', 'Howrah', 'Siliguri', 'Durgapur', 'Asansol', 'Kharagpur'],
        'Madhya Pradesh'    => ['Bhopal', 'Indore', 'Jabalpur', 'Gwalior', 'Ujjain', 'Sagar', 'Rewa'],
        'Punjab'            => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda', 'Mohali'],
        'Haryana'           => ['Gurugram', 'Faridabad', 'Panipat', 'Ambala', 'Rohtak', 'Hisar', 'Karnal', 'Sonipat'],
        'Bihar'             => ['Patna', 'Gaya', 'Bhagalpur', 'Muzaffarpur', 'Darbhanga', 'Purnia'],
        'Odisha'            => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Puri', 'Sambalpur', 'Berhampur'],
        'Jharkhand'         => ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro Steel City', 'Hazaribagh'],
        'Chhattisgarh'      => ['Raipur', 'Bilaspur', 'Bhilai', 'Durg', 'Korba'],
        'Assam'             => ['Guwahati', 'Dibrugarh', 'Silchar', 'Jorhat'],
        'Uttarakhand'       => ['Dehradun', 'Haridwar', 'Rishikesh', 'Haldwani'],
        'Himachal Pradesh'  => ['Shimla', 'Dharamshala', 'Manali', 'Solan'],
        'Goa'               => ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa'],
        'Chandigarh'        => ['Chandigarh'],
        'Jammu and Kashmir' => ['Srinagar', 'Jammu'],
        'Puducherry'        => ['Puducherry'],
        'Tripura'           => ['Agartala'],
        'Meghalaya'         => ['Shillong'],
        'Manipur'           => ['Imphal'],
        'Sikkim'            => ['Gangtok'],
        'Arunachal Pradesh' => ['Itanagar'],
        'Nagaland'          => ['Kohima'],
        'Mizoram'           => ['Aizawl'],
    ];
}

/** Other spellings of cities above (lookup only; not offered in the picker). */
const CITY_ALIASES = [
    'Bangalore' => 'Bengaluru', 'Mysore' => 'Mysuru', 'Gurgaon' => 'Gurugram', 'Aurangabad' => 'Chhatrapati Sambhajinagar',
    'Ahmednagar' => 'Ahilyanagar', 'Bombay' => 'Mumbai', 'Poona' => 'Pune', 'Calcutta' => 'Kolkata', 'Madras' => 'Chennai',
    'Trivandrum' => 'Thiruvananthapuram', 'Cochin' => 'Kochi', 'Vizag' => 'Visakhapatnam', 'Baroda' => 'Vadodara',
    'Allahabad' => 'Prayagraj', 'Pondicherry' => 'Puducherry', 'Mangalore' => 'Mangaluru', 'Belgaum' => 'Belagavi',
];

/** Every city the picker offers, as [name => state], sorted by name. */
function indianCityList(): array
{
    $out = [];
    foreach (indianCitiesByState() as $state => $names) {
        foreach ($names as $n) {
            $out[$n] = $state;
        }
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * The state a city is in, for the New Project form (so nobody types it).
 * Lookup is case-insensitive and ignores extra spaces. Returns null for a city that
 * is not in the list, in which case the form falls back to asking for the state.
 */
function cityStateFor(string $cityName): ?string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (indianCityList() as $n => $state) {
            $map[mb_strtolower($n)] = $state;
        }
        foreach (CITY_ALIASES as $alias => $canonical) {
            $map[mb_strtolower($alias)] = $map[mb_strtolower($canonical)] ?? null;
        }
    }
    $key = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $cityName)));
    return $map[$key] ?? null;
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
 * The id of the city with this name (case-insensitive), creating it when it does
 * not exist yet. Known alternative spellings (Bangalore) map to the usual name.
 *
 * @return array{0:int, 1:bool}  [city id, true when it was just created]
 */
function cityFindOrCreate(PDO $pdo, string $name): array
{
    $name = cityNameClean($name);
    foreach (CITY_ALIASES as $alias => $canonical) {
        if (mb_strtolower($alias) === mb_strtolower($name)) {
            $name = $canonical;
            break;
        }
    }
    foreach (indianCityList() as $known => $_) {
        if (mb_strtolower($known) === mb_strtolower($name)) {
            $name = $known;
            break;
        }
    }
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
