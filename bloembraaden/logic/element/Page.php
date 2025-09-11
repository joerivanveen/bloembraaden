<?php

declare(strict_types = 1);

namespace Bloembraaden;


class Page extends BaseElement
{
    public function __construct(?\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'page';
    }

    public function create(?bool $online = true): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New document', 'peatcms'),
            'content' => __('Default content.', 'peatcms'),
            'date_published' => 'NOW()',
            'online' => $online,
        ));
    }
}