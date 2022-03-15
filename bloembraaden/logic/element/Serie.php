<?php

namespace Peat;

class Serie extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'serie';
    }

    public function create(): ?int
    {
        return $this->getDB()->insertElement($this->getType(), array(
            'title' => __('New series', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
        ));
    }
}