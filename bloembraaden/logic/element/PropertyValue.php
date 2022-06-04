<?php

namespace Peat;

class PropertyValue extends BaseElement
{
    public function __construct(\stdClass $row = null) {
        parent::__construct($row);
        $this->type_name = 'property_value';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New property value', 'peatcms'),
            'content' => __('SEO text here', 'peatcms'),
            'excerpt' => '',
        ));
    }

}