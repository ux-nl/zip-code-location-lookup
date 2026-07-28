<?php

namespace Baspa\ZipCodeLocationLookup;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ZipCodeLocationLookup
{
    protected string $googleMapsApiKey;

    protected string $postcodeTechApiKey;

    protected bool $useGoogleMaps = true;

    public function __construct(bool $useGoogleMaps = true)
    {
        $this->useGoogleMaps = $useGoogleMaps;
        $this->postcodeTechApiKey = config('services.postcode_tech.api_key');

        if (empty($this->postcodeTechApiKey)) {
            throw new InvalidArgumentException('Postcode.tech API key must be configured in services config');
        }

        if ($useGoogleMaps) {
            $this->googleMapsApiKey = config('services.google.api_key');
            if (empty($this->googleMapsApiKey)) {
                throw new InvalidArgumentException('Google Maps API key must be configured in services config');
            }
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function lookup(string $zipCode, int $number): array
    {
        if (empty($zipCode)) {
            throw new InvalidArgumentException('Zip code cannot be empty');
        }

        if (! $this->useGoogleMaps) {
            throw new InvalidArgumentException('Google Maps is required for address lookup');
        }

        // Postcode.tech is authoritative for Dutch postcodes and answers with
        // street, city and coordinates in a single request. Geocoding a bare
        // postcode only yields a centroid, which then has to be recovered from
        // through reverse geocoding — and Dutch street names repeat across the
        // country, so that recovery can land in the wrong city entirely.
        $address = $this->lookupViaPostcodeTech($zipCode, $number);

        if ($address !== null) {
            return $address;
        }

        return $this->lookupViaGoogleMaps($zipCode, $number);
    }

    /**
     * Resolve a Dutch postcode and house number through Postcode.tech. Returns
     * null when the postcode is not Dutch, the combination is unknown, or the
     * API is unreachable, so the caller can fall back to geocoding.
     *
     * @return array<string, mixed>|null
     */
    protected function lookupViaPostcodeTech(string $zipCode, int $number): ?array
    {
        $postcode = $this->normalizePostalCode($zipCode);

        if (! preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $postcode)) {
            return null;
        }

        try {
            $data = $this->getPostcodeTechResponse($postcode, $number);
        } catch (\Throwable) {
            // Een storing bij Postcode.tech mag de lookup niet blokkeren.
            return null;
        }

        if ($data === null || empty($data['street']) || empty($data['city'])) {
            return null;
        }

        $latitude = $data['geo']['lat'] ?? null;
        $longitude = $data['geo']['lon'] ?? null;

        if ($latitude === null || $longitude === null) {
            $geocoded = $this->getGoogleMapsResponse([
                'street' => $data['street'],
                'city' => $data['city'],
            ], $postcode, $number);

            $latitude = $geocoded['lat'] ?? null;
            $longitude = $geocoded['lng'] ?? null;
        }

        return [
            'street' => $data['street'],
            'houseNumber' => $number,
            'postcode' => $data['postcode'] ?? $postcode,
            'city' => $data['city'],
            'municipality' => $data['municipality'] ?? '',
            'province' => $data['province'] ?? '',
            'country' => 'NLD',
            'lat' => $latitude,
            'lng' => $longitude,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function lookupViaGoogleMaps(string $zipCode, int $number): array
    {
        $googleMapsResponse = $this->getGoogleMapsResponse([], $zipCode, $number);

        if ($googleMapsResponse === null) {
            throw new InvalidArgumentException('Unable to geocode the provided zip code');
        }

        $country = $googleMapsResponse['address_components']['country'];

        if ($country === 'Netherlands' || $country === 'NL') {
            $country = 'NLD';
        }
        if ($country === 'Belgium' || $country === 'BE') {
            $country = 'BEL';
        }
        if ($country === 'Germany' || $country === 'DE') {
            $country = 'DEU';
        }
        if ($country === 'Luxembourg' || $country === 'LU') {
            $country = 'LUX';
        }

        return [
            'street' => $googleMapsResponse['address_components']['street_name'],
            'houseNumber' => $number,
            'postcode' => $zipCode,
            'city' => $googleMapsResponse['address_components']['city'],
            'municipality' => $googleMapsResponse['address_components']['municipality'],
            'province' => $googleMapsResponse['address_components']['province'],
            'country' => $country,
            'lat' => $googleMapsResponse['lat'],
            'lng' => $googleMapsResponse['lng'],
        ];
    }

    /**
     * Fetch street, city, municipality, province and coordinates for a postcode
     * and house number. Returns null when Postcode.tech does not know the
     * combination (404), which is an expected outcome rather than an error.
     *
     * @return array<string, mixed>|null
     */
    protected function getPostcodeTechResponse(string $zipCode, int $number): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->postcodeTechApiKey,
        ])->get('https://postcode.tech/api/v1/postcode/full', [
            'postcode' => $this->normalizePostalCode($zipCode),
            'number' => $number,
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->parsePostcodeTechResponse($response);
    }

    /**
     * Strip spaces and upper-case a postal code so "1012 js" and "1012JS"
     * compare and query identically.
     */
    protected function normalizePostalCode(string $postalCode): string
    {
        return str_replace(' ', '', strtoupper(trim($postalCode)));
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, mixed>|null
     */
    protected function getGoogleMapsResponse(array $address, string $zipCode, int $number): ?array
    {
        if (empty($address)) {
            $query = $zipCode.' '.$number.', Netherlands';
        } else {
            $query = $this->buildAddressQuery($address['street'], $number, $zipCode, $address['city']);
        }

        $result = $this->geocode($query);

        if ($result !== null && empty($result['address_components']['street_name'])) {
            $foundViaPlaces = false;

            // Strategy 1: Google Places API (Find Place From Text)
            // This is more accurate for text-based queries where Geocoding API returns a postcode centroid
            $placesResponse = Http::get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'key' => $this->googleMapsApiKey,
                'input' => $query,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
            ]);

            $placesData = $placesResponse->json();

            if ($placesData['status'] === 'OK' && ! empty($placesData['candidates'][0]['place_id'])) {
                $placeId = $placesData['candidates'][0]['place_id'];

                // Fetch Place Details to get address components
                $detailsResponse = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                    'key' => $this->googleMapsApiKey,
                    'place_id' => $placeId,
                    'fields' => 'address_component,formatted_address,geometry',
                ]);

                $detailsResult = $this->parseGoogleMapsResponse($detailsResponse);

                if ($detailsResult !== null && ! empty($detailsResult['address_components']['street_name'])) {
                    // Only accept the candidate when its postcode matches the
                    // one that was asked for.
                    if ($this->postalCodesMatch($detailsResult['address_components']['postal_code'] ?? '', $zipCode)) {
                        $result = $detailsResult;
                        $foundViaPlaces = true;

                        // Check if the house number matches
                        $returnedNumber = $detailsResult['address_components']['street_number'] ?? '';
                        if ($returnedNumber != $number) {
                            // If house number mismatches, try to geocode specifically with the found street name
                            // This ensures we get the location of the requested number, not the one Places API snapped to.
                            $streetGeocodeResult = $this->geocode($this->buildAddressQuery(
                                $detailsResult['address_components']['street_name'],
                                $number,
                                $zipCode,
                                $detailsResult['address_components']['city'],
                            ));

                            if ($streetGeocodeResult !== null && ! empty($streetGeocodeResult['lat'])) {
                                $result = $streetGeocodeResult;
                            }
                        }
                    }
                }
            }

            // Strategy 2: Reverse Geocoding (Fallback if Places failed or mismatched)
            // This snaps to the nearest street from the centroid coordinates.
            if (! $foundViaPlaces && ! empty($result['lat']) && ! empty($result['lng'])) {
                $reverseResponse = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'key' => $this->googleMapsApiKey,
                    'latlng' => $result['lat'].','.$result['lng'],
                ]);

                $reverseResult = $this->parseGoogleMapsResponse($reverseResponse);

                if ($reverseResult !== null && ! empty($reverseResult['address_components']['street_name'])) {
                    // We found a street name via reverse geocoding. Geocode it
                    // together with the house number to get the exact location.
                    //
                    // The postcode MUST stay in this query. Dutch street names
                    // repeat across the country, so "Zilverschoon 20, Made"
                    // resolves to the better-known Zilverschoon in Apeldoorn
                    // (52.2239, 5.9864) instead of Made (51.6775, 4.7817).
                    $streetGeocodeResult = $this->geocode($this->buildAddressQuery(
                        $reverseResult['address_components']['street_name'],
                        $number,
                        $zipCode,
                        $reverseResult['address_components']['city'] ?? '',
                    ));

                    if ($streetGeocodeResult !== null && ! empty($streetGeocodeResult['lat'])) {
                        // Use the precise coordinates from the street-based geocoding
                        $result = $streetGeocodeResult;
                    } else {
                        // Fallback: at least update the street name from reverse geocoding
                        $result['address_components']['street_name'] = $reverseResult['address_components']['street_name'];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Geocode a free-form address query.
     *
     * The query is passed as a plain string: `Http::get()` already URL-encodes
     * its query parameters, so calling `urlencode()` first made Google receive
     * literal "+" and "%2C" characters instead of separators.
     *
     * @return array<string, mixed>|null
     */
    protected function geocode(string $query): ?array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'key' => $this->googleMapsApiKey,
            'address' => $query,
            'components' => 'country:NL',
        ]);

        return $this->parseGoogleMapsResponse($response);
    }

    /**
     * Build a fully qualified address query. Keeping the postcode in it is what
     * pins a duplicate street name to the right town.
     */
    protected function buildAddressQuery(string $street, int $number, string $zipCode, string $city): string
    {
        return trim(sprintf(
            '%s %d, %s %s',
            $street,
            $number,
            $this->normalizePostalCode($zipCode),
            $city,
        ));
    }

    /**
     * Whether a geocoded postal code refers to the one that was asked for.
     * Either may be a prefix of the other ("4921" vs "4921JN"), but an empty
     * value never matches — `stripos($haystack, '')` returns 0, which made an
     * earlier version accept any result that carried no postal code at all.
     */
    protected function postalCodesMatch(string $returned, string $expected): bool
    {
        $returned = $this->normalizePostalCode($returned);
        $expected = $this->normalizePostalCode($expected);

        if ($returned === '' || $expected === '') {
            return false;
        }

        return str_starts_with($returned, $expected) || str_starts_with($expected, $returned);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function parsePostcodeTechResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new InvalidArgumentException(
                'Failed to fetch data from Postcode.tech API: '.$response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseGoogleMapsResponse(Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        // Handle both Geocoding API (results) and Place Details API (result) formats
        $result = null;
        if (isset($data['result'])) {
            $result = $data['result'];
        } elseif (isset($data['results'][0])) {
            $result = $data['results'][0];
        }

        if ($result && $data['status'] === 'OK') {
            $location = $result['geometry']['location'];
            $addressComponents = $this->parseAddressComponents($result['address_components']);

            return [
                'lat' => (float) $location['lat'],
                'lng' => (float) $location['lng'],
                'formatted_address' => $result['formatted_address'],
                'address_components' => $addressComponents,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, string>
     */
    protected function parseAddressComponents(array $components): array
    {
        $address = [
            'street_number' => '',
            'street_name' => '',
            'city' => '',
            'municipality' => '',
            'province' => '',
            'country' => '',
            'postal_code' => '',
        ];

        // Every component carries a list of types and their order is not
        // guaranteed, so scan all of them rather than only `types[0]`: a
        // `locality` can arrive as ["political", "locality"] and would
        // otherwise be dropped.
        $typeMap = [
            'street_number' => 'street_number',
            'route' => 'street_name',
            'locality' => 'city',
            'postal_town' => 'city',
            'administrative_area_level_2' => 'municipality',
            'administrative_area_level_1' => 'province',
            'country' => 'country',
            'postal_code' => 'postal_code',
        ];

        foreach ($components as $component) {
            foreach ((array) ($component['types'] ?? []) as $type) {
                $key = $typeMap[$type] ?? null;

                if ($key !== null && $address[$key] === '') {
                    $address[$key] = (string) ($component['long_name'] ?? '');
                }
            }
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $postcodeTechResponse
     * @param  array<string, float>  $googleMapsResponse
     * @return array<string, mixed>
     */
    protected function mergeResponses(array $postcodeTechResponse, array $googleMapsResponse): array
    {
        return array_merge($postcodeTechResponse, $googleMapsResponse);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function formatResponse(array $response): string
    {
        $json = json_encode($response);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode response to JSON');
        }

        return $json;
    }
}
