<?php

declare(strict_types = 1);

namespace Bloembraaden;

class AddressShop extends BaseElement
{
    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'address_shop';
    }
}