<?php

declare(strict_types = 1);

namespace Peat;


class Embed extends BaseElement
{
    public function __construct(\stdClass $properties = null)
    {
        parent::__construct($properties);
        $this->type_name = 'embed';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New embed', 'peatcms'),
            'slug' => 'embed',
        ));
    }
}
