# Changelog

All notable changes to `zip-code-location-lookup` will be documented in this file.

## v0.3.1 - 2026-08-18

De lookup kon op drie manieren falen zonder dat er iets zichtbaar werd.

### Stille terugval naar geocoding

De `catch (\Throwable)` rond de Postcode.tech-call logde niets: 401, 429, 5xx, timeout en connection reset zagen er identiek uit aan een adres dat niet bestaat. Daarna gaat de lookup verder op het Google-pad, dat vaker geen straat of een straat uit de verkeerde plaats oplevert.

Relevant omdat de rate limit scherper is dan hij lijkt — gemeten: `x-ratelimit-limit: 60` per minuut, gedeeld over de hele app, terwijl een adresformulier twee lookups per adres doet.

Een 404 blijft ongelogd: een onbekende combinatie is een verwachte uitkomst.

### Geen timeouts

Nergens stond een `Http::timeout()`, dus gold de default van 30s per call. Postcode.tech doet er zelf ~1,0s over en het herstelpad kan tot vijf calls achter elkaar doen. Alles loopt nu via één `request()`: 5s response, 3s connect.

### TypeError bij een ontbrekende sleutel

`config()` werd toegewezen aan een `string`-property, dus een ontbrekende sleutel gaf een TypeError in plaats van de `InvalidArgumentException` die uitlegt wat er mist.

**Tests:** 22 passed (was 17). PHPStan schoon.

**Compatibiliteit:** geen API-wijzigingen.

**Bekend:** de L10-legs van de testmatrix falen op `Class "GuzzleHttp\Promise\Create" not found`. Dat is niet nieuw — `main` faalde daar al op v0.3.0.

## v0.2.0 - 2026-04-07

### What's Changed

* Bump dependabot/fetch-metadata from 2.2.0 to 2.3.0 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/1
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/2
* Bump dependabot/fetch-metadata from 2.3.0 to 2.4.0 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/3
* Bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/5
* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/6
* Bump stefanzweifel/git-auto-commit-action from 5 to 7 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/7
* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/8
* Bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/9
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/10
* Bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/Baspa/zip-code-location-lookup/pull/11

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/Baspa/zip-code-location-lookup/pull/1

**Full Changelog**: https://github.com/Baspa/zip-code-location-lookup/compare/v0.1.0...v0.2.0

## v0.1.0 - 2025-01-28

**Full Changelog**: https://github.com/Baspa/zip-code-location-lookup/commits/v0.1.0
