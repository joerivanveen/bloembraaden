<?php

namespace Peat;

class Product extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'product';
    }

    public function create(): ?int
    {
        return $this->getDB()->insertElement($this->getType(), array(
            'title' => __('New product', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
       ));
    }
}