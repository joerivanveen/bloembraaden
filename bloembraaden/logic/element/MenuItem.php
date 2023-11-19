<?php

declare(strict_types = 1);

namespace Bloembraaden;

class MenuItem extends BaseElement
{
    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'menu_item';
        if (isset($this->row->menu_item_id)) {
            // TODO you need to fake the slug...
            $this->row->slug = '/__admin__/menu_item/' . $this->row->menu_item_id;
        }
    }

    public function create(?bool $online = true): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('Menu item', 'peatcms'),
            'act' => '{}',
            'content' => __('Type content here, if your template uses it', 'peatcms'),
            'online' => $online,
        ));
    }

    public function fetchById(int $id = 0): ?BaseElement
    {
        parent::fetchById($id);
        if (isset($this->row->menu_item_id)) {
            // TODO you need to fake the slug...
            $this->row->slug = '/__admin__/menu_item/' . $this->row->menu_item_id;

            return $this;
        }

        return null;
    }

    public function completeRowForOutput(): void
    {
        // TODO what to do with the template?
        $this->row->template_pointer = (object)array('name' => 'menu_item', 'admin' => true);
        $this->row->type_name = $this->type_name;
    }
}