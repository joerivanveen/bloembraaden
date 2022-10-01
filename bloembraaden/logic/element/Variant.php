<?php

declare(strict_types = 1);

namespace Peat;


class Variant extends BaseElement
{
    public function __construct(\stdClass $properties = null) {
        parent::__construct($properties);
        $this->type_name = 'variant';
    }

    /**
     * @return float
     * @since 0.5.1
     */
    public function getPrice():float {
        return Help::getAsFloat($this->row->price, 0);
    }
    /**
     * @return float
     * @since 0.5.1
     */
    public function getPriceFrom():float {
        return Help::getAsFloat($this->row->price_from, 0);
    }

    public function create(?bool $online = true): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New variant', 'peatcms'),
            'content' => __('Default content', 'peatcms'),
            'online' => $online,
        ));
    }
}