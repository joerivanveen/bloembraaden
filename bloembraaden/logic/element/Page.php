<?php

namespace Peat;

class Page extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'page';
    }

    public function create(): ?int
    {
        return $this->getDB()->insertElement($this->getType(), array(
            'title' => __('New document', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
            'date_published' => 'NOW()',
        ));
    }
}