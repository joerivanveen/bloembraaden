<?php

declare(strict_types = 1);

namespace Peat;

class Menu extends BaseElement
{
    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'menu';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('Menu', 'peatcms'),
            'slug' => __('menu', 'peatcms'),
        ));
    }

    public function putItem(int $item_id, int $to_item_id, string $relative): bool
    {
        // every item can only be in a menu once, this simplifies the programming a lot
        // check if item_id and to_item_id belong to the same instance as the menu
        $items_belong_to_this_instance = true;
        if (null === Help::getDB()->fetchElementRow(new Type('menu_item'), $item_id)) {
            $items_belong_to_this_instance = false;
        }
        if ($to_item_id > 0 and
            null === Help::getDB()->fetchElementRow(new Type('menu_item'), $to_item_id)) {
            $items_belong_to_this_instance = false;
        }
        if (false === $items_belong_to_this_instance) {
            $this->addMessage(__('Security warning, after multiple warnings your account may be blocked', 'peatcms'), 'warn');
            return false;
        }
        // $relative can be 'child': put $item_id directly under the $to_item_id, or 'order': put it above it on the same level
        // find the right place and upsert the cross table
        if ($relative === 'child') {
            return Help::getDB()->underMenuItem($this->getId(), $item_id, $to_item_id);
        } else { // order $item_id right before $to_item_id
            return Help::getDB()->orderMenuItem($this->getId(), $item_id, $to_item_id);
        }
    }

    public function completeRowForOutput(): void
    {
        // menu uses an admin template
        $this->row->template_pointer = (object)Array('name' => 'menu', 'admin' => true);
        // get the children as well TODO caching
        $this->row->__menu__ = array(
            '__item__' => ($menu_items = Help::getDB()->fetchMenuItems($this->getId())),
        );
        $this->row->item_count = count($menu_items);
    }
}