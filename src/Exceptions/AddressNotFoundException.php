<?php

namespace Baspa\ZipCodeLocationLookup\Exceptions;

use InvalidArgumentException;

/**
 * Postcode.tech kent de combinatie van postcode en huisnummer niet (404).
 *
 * Dat is een definitief antwoord, geen storing: er wordt dan niet op Google
 * teruggevallen, want geocoding "vindt" voor een niet-bestaand huisnummer
 * altijd wel iets in de buurt, soms in een andere plaats.
 *
 * Breidt InvalidArgumentException uit, zodat bestaande catch-blokken rond
 * lookup() deze uitkomst blijven afvangen.
 */
class AddressNotFoundException extends InvalidArgumentException
{
    public static function forCombination(string $postcode, int $number): self
    {
        return new self(sprintf('No address found for postcode %s and house number %d', $postcode, $number));
    }
}
