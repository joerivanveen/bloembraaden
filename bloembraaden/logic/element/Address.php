<?php

declare(strict_types=1);

namespace Bloembraaden;

class Address extends BaseElement
{
    public function __construct(?\stdClass $properties = null)
    {
        parent::__construct($properties);
        $this->type_name = 'address';
    }

    public function create(?bool $online = true): ?int
    {
        $this->handleErrorAndStop('For element Address use `new` in stead of `create`.');

        return null;
    }

    public function new(int $user_id): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'user_id' => $user_id,
        ));
    }

    public static function makeKey(\stdClass $address): string
    {
        // todo remove this when local pickup is implemented everywhere
        if ('XX' === $address->address_country_iso2) {
            $address->address_country_iso2 = 'NL';
        }

        return strtolower(str_replace(' ', '', "$address->address_postal_code$address->address_street$address->address_number$address->address_number_addition$address->address_country_iso2"));
    }
}