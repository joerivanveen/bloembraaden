<?php

declare(strict_types = 1);

namespace Peat;


class Product extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'product';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New product', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
       ));
    }
}