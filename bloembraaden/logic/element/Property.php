<?php

namespace Peat;

class Property extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'property';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New property', 'peatcms'),
            'content' => __('SEO text here', 'peatcms'),
            'excerpt' => '',
        ));
    }
}