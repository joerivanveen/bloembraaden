<?php

declare(strict_types = 1);

namespace Peat;


class Comment extends BaseElement
{
    public function __construct(\stdClass $properties = null)
    {
        parent::__construct($properties);
        $this->type_name = 'comment';
    }

    public function create(): ?int
    {
        //Help::addError(new \Exception('Cannot ‘create’ a comment element'));
        Help::addMessage(__('Cannot ‘create’ a comment element', 'peatcms'), 'warn');
        return null;
    }
}
