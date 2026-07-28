<?php

use Baspa\ZipCodeLocationLookup\ZipCodeLocationLookup;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.postcode_tech.api_key', 'postcode-tech-key');
    config()->set('services.google.api_key', 'google-key');
});

/**
 * @return array<string, mixed>
 */
function postcodeTechAddress(): array
{
    return [
        'postcode' => '4921JN',
        'number' => 20,
        'street' => 'Zilverschoon',
        'city' => 'Made',
        'municipality' => 'Drimmelen',
        'province' => 'Noord-Brabant',
        'geo' => ['lat' => 51.6775, 'lon' => 4.7817],
    ];
}

/**
 * A Geocoding response for a bare postcode: a centroid with no `route`
 * component, which is what pushes the lookup into its recovery strategies.
 *
 * @return array<string, mixed>
 */
function postcodeCentroid(): array
{
    return ['status' => 'OK', 'results' => [[
        'formatted_address' => '4921 JN Made, Netherlands',
        'geometry' => ['location' => ['lat' => 51.6780824, 'lng' => 4.7815545]],
        'address_components' => [
            ['long_name' => '4921 JN', 'short_name' => '4921 JN', 'types' => ['postal_code']],
            ['long_name' => 'Made', 'short_name' => 'Made', 'types' => ['locality', 'political']],
            ['long_name' => 'Netherlands', 'short_name' => 'NL', 'types' => ['country', 'political']],
        ],
    ]]];
}

/**
 * @return array<string, mixed>
 */
function streetAddress(string $street, string $city, string $number, string $postalCode, float $latitude, float $longitude): array
{
    return ['status' => 'OK', 'results' => [[
        'formatted_address' => "$street $number, $postalCode $city, Netherlands",
        'geometry' => ['location' => ['lat' => $latitude, 'lng' => $longitude]],
        'address_components' => [
            ['long_name' => $number, 'short_name' => $number, 'types' => ['street_number']],
            ['long_name' => $street, 'short_name' => $street, 'types' => ['route']],
            // Deliberately not first in the list: the parser must scan every
            // type, not only `types[0]`.
            ['long_name' => $city, 'short_name' => $city, 'types' => ['political', 'locality']],
            ['long_name' => 'Drimmelen', 'short_name' => 'Drimmelen', 'types' => ['administrative_area_level_2', 'political']],
            ['long_name' => 'Noord-Brabant', 'short_name' => 'NB', 'types' => ['administrative_area_level_1', 'political']],
            ['long_name' => 'Netherlands', 'short_name' => 'NL', 'types' => ['country', 'political']],
            ['long_name' => $postalCode, 'short_name' => $postalCode, 'types' => ['postal_code']],
        ],
    ]]];
}

it('resolves a postcode and house number through postcode.tech', function () {
    Http::fake(['postcode.tech/*' => Http::response(postcodeTechAddress())]);

    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20))->toBe([
        'street' => 'Zilverschoon',
        'houseNumber' => 20,
        'postcode' => '4921JN',
        'city' => 'Made',
        'municipality' => 'Drimmelen',
        'province' => 'Noord-Brabant',
        'country' => 'NLD',
        'lat' => 51.6775,
        'lng' => 4.7817,
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'postcode.tech/api/v1/postcode/full')
        && str_contains($request->url(), 'postcode=4921JN')
        && str_contains($request->url(), 'number=20'));

    // Postcode.tech answers completely, so Google is not needed at all.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
});

it('normalises the postal code before querying', function (string $input) {
    Http::fake(['postcode.tech/*' => Http::response(postcodeTechAddress())]);

    (new ZipCodeLocationLookup)->lookup($input, 20);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'postcode=4921JN'));
})->with(['4921JN', '4921 JN', '4921jn', ' 4921 jn ']);

it('keeps the postcode in the geocoding query when postcode.tech has no coordinates', function () {
    Http::fake([
        'postcode.tech/*' => Http::response(['street' => 'Zilverschoon', 'city' => 'Made']),
        'maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817)),
    ]);

    expect((new ZipCodeLocationLookup)->lookup('4921 JN', 20))->toMatchArray([
        'city' => 'Made',
        'lat' => 51.6775,
        'lng' => 4.7817,
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'geocode/json')
        && str_contains(urldecode($request->url()), 'Zilverschoon 20, 4921JN Made')
        && str_contains(urldecode($request->url()), 'country:NL'));
});

it('does not double URL-encode the geocoding query', function () {
    Http::fake([
        'postcode.tech/*' => Http::response(['street' => 'Zilverschoon', 'city' => 'Made']),
        'maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817)),
    ]);

    (new ZipCodeLocationLookup)->lookup('4921JN', 20);

    // urlencode() on top of the client's own encoding turned the separators
    // into literal "+" and "%2C" text inside the address.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'geocode/json')
        && ! str_contains($request->url(), '%2B')
        && ! str_contains($request->url(), '%252C'));
});

it('skips postcode.tech for a postal code that is not Dutch', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817))]);

    (new ZipCodeLocationLookup)->lookup('W1A 1AA', 20);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'postcode.tech'));
});

it('falls back to geocoding when postcode.tech does not know the combination', function () {
    Http::fake([
        'postcode.tech/*' => Http::response(['message' => 'No result for this combination.'], 404),
        'maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817)),
    ]);

    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20))->toMatchArray([
        'street' => 'Zilverschoon',
        'city' => 'Made',
        'municipality' => 'Drimmelen',
        'province' => 'Noord-Brabant',
        'country' => 'NLD',
    ]);
});

it('falls back to geocoding when postcode.tech is unreachable', function () {
    Http::fake([
        'postcode.tech/*' => Http::response('gateway timeout', 504),
        'maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817)),
    ]);

    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20)['city'])->toBe('Made');
});

it('keeps the postcode when recovering a street name through reverse geocoding', function () {
    // Regression: this recovery path rebuilt the query as "street + number +
    // city" without the postcode. Dutch street names repeat, so
    // "Zilverschoon 20, Made" resolved to Zilverschoon in Apeldoorn.
    Http::fake(function ($request) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, 'postcode.tech') => Http::response(['message' => 'No result for this combination.'], 404),
            str_contains($url, 'findplacefromtext') => Http::response(['status' => 'ZERO_RESULTS', 'candidates' => []]),
            str_contains($url, 'latlng=') => Http::response(streetAddress('Zilverschoon', 'Made', '10', '4921 JN', 51.6780824, 4.7815545)),
            str_contains($url, 'Zilverschoon 20, 4921JN Made') => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6774583, 4.7817044)),
            default => Http::response(postcodeCentroid()),
        };
    });

    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20))->toMatchArray([
        'street' => 'Zilverschoon',
        'city' => 'Made',
        'lat' => 51.6774583,
        'lng' => 4.7817044,
    ]);
});

it('rejects a Places candidate that carries no postal code', function () {
    // Regression: the guard used stripos($input, ''), which returns 0 rather
    // than false, so any candidate without a postal code was accepted.
    $placeWithoutPostalCode = ['status' => 'OK', 'result' => [
        'formatted_address' => 'Zilverschoon, Apeldoorn, Netherlands',
        'geometry' => ['location' => ['lat' => 52.2238632, 'lng' => 5.9864305]],
        'address_components' => [
            ['long_name' => 'Zilverschoon', 'short_name' => 'Zilverschoon', 'types' => ['route']],
            ['long_name' => 'Apeldoorn', 'short_name' => 'Apeldoorn', 'types' => ['locality', 'political']],
            ['long_name' => 'Netherlands', 'short_name' => 'NL', 'types' => ['country', 'political']],
        ],
    ]];

    Http::fake(function ($request) use ($placeWithoutPostalCode) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, 'postcode.tech') => Http::response(['message' => 'No result for this combination.'], 404),
            str_contains($url, 'findplacefromtext') => Http::response(['status' => 'OK', 'candidates' => [['place_id' => 'apeldoorn-place']]]),
            str_contains($url, 'place/details') => Http::response($placeWithoutPostalCode),
            str_contains($url, 'latlng=') => Http::response(streetAddress('Zilverschoon', 'Made', '10', '4921 JN', 51.6780824, 4.7815545)),
            str_contains($url, 'Zilverschoon 20, 4921JN Made') => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6774583, 4.7817044)),
            default => Http::response(postcodeCentroid()),
        };
    });

    // The Apeldoorn candidate is discarded and the reverse-geocoding path wins.
    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20))->toMatchArray([
        'city' => 'Made',
        'lat' => 51.6774583,
        'lng' => 4.7817044,
    ]);
});

it('reads address components whose type is not listed first', function () {
    Http::fake([
        'postcode.tech/*' => Http::response(['message' => 'No result for this combination.'], 404),
        'maps.googleapis.com/*' => Http::response(streetAddress('Zilverschoon', 'Made', '20', '4921 JN', 51.6775, 4.7817)),
    ]);

    // `locality` arrives as ["political", "locality"] in streetAddress().
    expect((new ZipCodeLocationLookup)->lookup('4921JN', 20)['city'])->toBe('Made');
});

it('rejects an empty postal code', function () {
    expect(fn () => (new ZipCodeLocationLookup)->lookup('', 20))
        ->toThrow(InvalidArgumentException::class, 'Zip code cannot be empty');
});

it('requires Google Maps to be enabled', function () {
    expect(fn () => (new ZipCodeLocationLookup(false))->lookup('4921JN', 20))
        ->toThrow(InvalidArgumentException::class, 'Google Maps is required for address lookup');
});
