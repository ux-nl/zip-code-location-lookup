# Zip Code Location Lookup

[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ux-nl/zip-code-location-lookup/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ux-nl/zip-code-location-lookup/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ux-nl/zip-code-location-lookup/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ux-nl/zip-code-location-lookup/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

This package allows you to lookup the geographic location of a zip code by combining the [Postcode.tech](https://postcode.tech/) API with the [Google Geocoding API](https://developers.google.com/maps/documentation/geocoding/overview).

> Maintained by [UX Nederland](https://ux.nl). Originally written by [Baspa](https://github.com/baspa/zip-code-location-lookup) and continued here under the same MIT license. The PHP namespace is still `Baspa\ZipCodeLocationLookup` so upstream changes stay easy to merge.

## Installation

You can install the package via composer:

```bash
composer require ux-nl/zip-code-location-lookup
```

Then add the Google Maps API and Postcode.tech API keys to your `.env` file:

```
GOOGLE_MAPS_API_KEY=your_api_key
POSTCODE_TECH_API_KEY=your_api_key
```

Then add those keys to the `services.php` config file:

```php
return [

    // ...

    'google' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'postcode_tech' => [
        'api_key' => env('POSTCODE_TECH_API_KEY'),
    ],
];
```

## Usage

```php
use Baspa\ZipCodeLocationLookup\ZipCodeLocationLookup;

// With Google Maps integration (default)
$lookup = new ZipCodeLocationLookup();

// Without Google Maps integration
$lookup = new ZipCodeLocationLookup(false);

// Lookup a zip code
$result = $lookup->lookup(zipCode: '1011AB', number: 1);

// Returns an array containing:
// - postcode: The normalized postcode
// - street: Street name
// - city: City name
// - municipality: Municipality name
// - province: Province name
// - lat: Latitude coordinate
// - lng: Longitude coordinate
$result = [
    'postcode' => '1011 AB',
    'street' => 'Dam',
    'city' => 'Amsterdam',
    'municipality' => 'Amsterdam',
    'province' => 'Noord-Holland',
    'lat' => 52.373169,
    'lng' => 4.893379
];
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [Baspa](https://github.com/Baspa)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
