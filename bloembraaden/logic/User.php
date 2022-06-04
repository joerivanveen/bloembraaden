<?php

namespace Peat;
class User extends BaseLogic
{
    private $addresses, $orders;

    public function __construct($user_id)
    {
        parent::__construct(Help::getDB()->fetchUser($user_id));
        $this->id = $user_id;
        $this->type_name = 'user';

    }

    /**
     * Get the addresses for this user
     * @return array indexed holding address objects (stdClass)
     * @since 0.7.9
     */
    public function getAddresses(): array
    {
        return $this->addresses ?? $this->addresses = Help::getDB()->fetchAddressesByUserId($this->getId());
    }

    public function getOrders(): array
    {
        return $this->orders ?? $this->orders = Help::getDB()->fetchOrdersByUserId($this->getId());
    }

    /**
     * Overridden to include addresses in the output
     * @return \stdClass
     */
    public function completeRowForOutput(): void
    {
        $this->row->__addresses__ = $this->getAddresses();
        $this->row->__orders__ = $this->getOrders();
        $this->row->slug = '__user__'; //the default slug...
    }

}

