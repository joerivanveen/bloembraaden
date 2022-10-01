<?php

declare(strict_types = 1);

namespace Peat;


class Brand extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'brand';
    }

    public function create(?bool $online = false): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New brand', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
            'excerpt' => '',
            'online' => $online,
        ));
    }
}