<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/Cities.php';

class CitiesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT NOT NULL DEFAULT \'\', created_at TEXT)');
    }

    private function addCities(string ...$names): void
    {
        foreach ($names as $n) {
            $this->pdo->prepare('INSERT INTO cities (name, slug) VALUES (?, ?)')->execute([$n, citySlug($n)]);
        }
    }

    public function test_listCities_is_alphabetical_with_int_ids(): void
    {
        $this->addCities('Pune', 'Mumbai');
        $this->assertSame(
            [['id' => 2, 'name' => 'Mumbai'], ['id' => 1, 'name' => 'Pune']],
            listCities($this->pdo)
        );
    }

    public function test_soleCityId(): void
    {
        $this->assertNull(soleCityId($this->pdo));
        $this->addCities('Pune');
        $this->assertSame(1, soleCityId($this->pdo));
        $this->addCities('Mumbai');
        $this->assertNull(soleCityId($this->pdo));
    }

    public function test_signup_city_is_null_when_no_cities_exist_or_none_typed(): void
    {
        $this->assertNull(signupCityId([], 'Pune'));
        $this->addCities('Pune');
        $this->assertNull(signupCityId(listCities($this->pdo), ''));
        $this->assertNull(signupCityId(listCities($this->pdo), '   '));
    }

    public function test_signup_city_matches_the_typed_name_ignoring_case_spacing_and_spelling(): void
    {
        $this->addCities('Pune', 'Bengaluru');
        $cities = listCities($this->pdo);
        $this->assertSame(1, signupCityId($cities, 'pune'));
        $this->assertSame(1, signupCityId($cities, '  PUNE  '));
        $this->assertSame(1, signupCityId($cities, 'Poona'));
        $this->assertSame(2, signupCityId($cities, 'bangalore'));
    }

    public function test_signup_city_is_null_for_a_city_that_is_not_set_up_yet(): void
    {
        $this->addCities('Pune');
        $this->assertNull(signupCityId(listCities($this->pdo), 'Mumbai'));
    }

    public function test_cityCanonicalName_uses_the_listed_spelling(): void
    {
        $this->assertSame('Bengaluru', cityCanonicalName('bangalore'));
        $this->assertSame('Navi Mumbai', cityCanonicalName(' navi   mumbai '));
        $this->assertSame('Smallville', cityCanonicalName(' Smallville '));
    }

    public function test_stateCanonical_accepts_listed_states_only(): void
    {
        $this->assertSame('Maharashtra', stateCanonical(' maharashtra '));
        $this->assertSame('Ladakh', stateCanonical('LADAKH'));
        $this->assertNull(stateCanonical('Atlantis'));
        $this->assertNull(stateCanonical(''));
    }

    public function test_home_location_for_a_known_city_brings_its_own_state(): void
    {
        $this->assertSame(['Pune', 'Maharashtra', []], validateHomeLocation('pune', ''));
        $this->assertSame(['Bengaluru', 'Karnataka', []], validateHomeLocation('Bangalore', 'Goa'));
    }

    public function test_home_location_for_an_unknown_city_needs_a_listed_state(): void
    {
        $this->assertSame(['Smallville', 'Goa', []], validateHomeLocation('Smallville', ' goa '));
        $this->assertSame([null, null, ['state' => 'State is required.']], validateHomeLocation('Smallville', ''));
        $this->assertSame([null, null, ['state' => 'Choose a state from the list.']], validateHomeLocation('Smallville', 'Atlantis'));
    }

    public function test_home_location_requires_a_valid_city(): void
    {
        $this->assertSame([null, null, ['city' => 'City is required.']], validateHomeLocation('  ', 'Goa'));
        [, , $e] = validateHomeLocation('<b>x</b>', 'Goa');
        $this->assertArrayHasKey('city', $e);
        [, , $e] = validateHomeLocation('12345', 'Goa');
        $this->assertArrayHasKey('city', $e);
    }

    public function test_organisation_is_required_and_checked(): void
    {
        $this->assertSame('Organisation is required.', organisationError(''));
        $this->assertSame('Organisation is required.', organisationError('   '));
        $this->assertNotNull(organisationError('A'));
        $this->assertNotNull(organisationError(str_repeat('a', 201)));
        $this->assertNotNull(organisationError('12345'));
        $this->assertNotNull(organisationError('<script>x</script>'));
        $this->assertNull(organisationError('Parisar'));
        $this->assertNull(organisationError("St. Xavier's College & Co. 2"));
    }

    public function test_location_datalists_list_every_city_with_its_state_and_every_state(): void
    {
        $html = locationDatalistsHtml();
        $this->assertStringContainsString('<datalist id="cityList">', $html);
        $this->assertStringContainsString('<datalist id="stateList">', $html);
        $this->assertStringContainsString('value="Pune" label="Maharashtra" data-state="Maharashtra"', $html);
        $this->assertStringContainsString('<option value="Ladakh">', $html);
    }

    public function test_user_city_change_accepts_only_real_cities(): void
    {
        $this->addCities('Pune', 'Mumbai');
        $cities = listCities($this->pdo);
        $this->assertSame([2, null], validateUserCityChange($cities, 'surveyor', 2));
        $this->assertSame([1, null], validateUserCityChange($cities, 'city_leader', '1'));
        foreach (['', '0', '3', 'abc', '1 OR 1=1', null, [1], true, 1.5] as $bad) {
            $this->assertSame([null, 'Please choose a valid city.'], validateUserCityChange($cities, 'surveyor', $bad));
        }
    }

    public function test_user_city_change_refuses_admins(): void
    {
        $this->addCities('Pune', 'Mumbai');
        $this->assertSame(
            [null, 'Admins are not tied to a city.'],
            validateUserCityChange(listCities($this->pdo), 'admin', 1)
        );
    }

    public function test_cityStateFor_maps_known_cities_and_ignores_case_and_spacing(): void
    {
        $this->assertSame('Maharashtra', cityStateFor('Pune'));
        $this->assertSame('Maharashtra', cityStateFor('  pune '));
        $this->assertSame('Karnataka', cityStateFor('BENGALURU'));
        $this->assertSame('Maharashtra', cityStateFor('Navi  Mumbai'));
    }

    public function test_cityStateFor_returns_null_for_an_unknown_city(): void
    {
        $this->assertNull(cityStateFor('Atlantis'));
        $this->assertNull(cityStateFor(''));
    }

    public function test_city_picker_list_is_sorted_and_has_a_state_for_every_city(): void
    {
        $list = indianCityList();
        $this->assertGreaterThan(150, count($list));
        $this->assertSame('Maharashtra', $list['Pune']);
        $this->assertSame('Karnataka', $list['Bengaluru']);
        $this->assertArrayNotHasKey('Bangalore', $list);
        $names = array_keys($list);
        $sorted = $names;
        natcasesort($sorted);
        $this->assertSame(array_values($sorted), $names);
        foreach ($list as $city => $state) {
            $this->assertSame($state, cityStateFor($city), $city);
        }
    }

    public function test_alternative_spellings_find_the_same_state(): void
    {
        $this->assertSame('Karnataka', cityStateFor('Bangalore'));
        $this->assertSame('Maharashtra', cityStateFor('aurangabad'));
        $this->assertNull(cityStateFor('Nowhereville'));
    }

    public function test_city_names_are_checked_and_tidied(): void
    {
        $this->assertSame('Navi Mumbai', cityNameClean("  Navi \t Mumbai "));
        $this->assertTrue(cityNameIsValid('Pimpri-Chinchwad'));
        $this->assertTrue(cityNameIsValid("St. John's (East)"));
        $this->assertFalse(cityNameIsValid('A'));
        $this->assertFalse(cityNameIsValid('Pune<script>'));
        $this->assertFalse(cityNameIsValid('12345'));
        $this->assertFalse(cityNameIsValid(str_repeat('a', 101)));
    }

    public function test_cityFindOrCreate_reuses_an_existing_city_ignoring_case(): void
    {
        $this->addCities('Pune');
        $this->assertSame([1, false], cityFindOrCreate($this->pdo, ' pune '));
        $this->assertCount(1, listCities($this->pdo));
    }

    public function test_cityFindOrCreate_adds_a_new_city_with_its_usual_spelling(): void
    {
        $this->addCities('Pune');
        [$id, $created] = cityFindOrCreate($this->pdo, 'bangalore');
        $this->assertTrue($created);
        $this->assertSame('Bengaluru', $this->pdo->query("SELECT name FROM cities WHERE id = $id")->fetchColumn());
        $this->assertSame([$id, false], cityFindOrCreate($this->pdo, 'BENGALURU'));
        [, $c2] = cityFindOrCreate($this->pdo, 'Some Small Town');
        $this->assertTrue($c2);
        $this->assertCount(3, listCities($this->pdo));
    }

    public function test_new_cities_get_a_unique_url_style_slug(): void
    {
        $this->assertSame('navi-mumbai', citySlug('Navi  Mumbai'));
        $this->assertSame('pimpri-chinchwad', citySlug('Pimpri-Chinchwad'));
        $this->assertSame('st-john-s-east', citySlug("St. John's (East)"));
        $this->addCities('Pune');
        [$id] = cityFindOrCreate($this->pdo, 'Amravati');
        $row = $this->pdo->query("SELECT slug, created_at FROM cities WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('amravati', $row['slug']);
        $this->assertNotEmpty($row['created_at']);
        // A different name with the same slug gets a numbered one.
        $this->pdo->exec("INSERT INTO cities (name, slug) VALUES ('Some Town', 'some-town')");
        $this->assertSame('some-town-2', citySlugUnique($this->pdo, 'Some-Town'));
    }
}
