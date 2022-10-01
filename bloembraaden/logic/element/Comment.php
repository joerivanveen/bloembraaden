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

    public function create(?bool $online = true): ?int
    {
        //Help::addError(new \Exception('Cannot ‘create’ a comment element'));
        Help::addMessage(__('Cannot ‘create’ a comment element', 'peatcms'), 'warn');
        return null;
    }

    public function completeRowForOutput(): void
    {
        unset($this->row->ip_address);
        unset($this->row->reverse_dns);
        unset($this->row->email);
        unset($this->row->user_agent);
    }
}
