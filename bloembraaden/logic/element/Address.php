<?php

declare(strict_types = 1);

namespace Bloembraaden;

class Address extends BaseElement
{
    public function __construct(\stdClass $properties = null)
    {
        parent::__construct($properties);
        $this->type_name = 'address';
    }

    public function create(?bool $online = true): ?int
    {
        $this->handleErrorAndStop('For element Address use `new` in stead of `create`');

        return null;
    }

    public function new(int $user_id): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'user_id' => $user_id,
        ));
    }
}