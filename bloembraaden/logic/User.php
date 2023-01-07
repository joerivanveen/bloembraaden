<?php

declare(strict_types = 1);

namespace Peat;

class User extends BaseLogic
{
    private array $addresses, $orders;

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
     * Overridden to include addresses and orders in the output
     * @return void
     */
    public function completeRowForOutput(): void
    {
        $this->row->__addresses__ = $this->getAddresses();
        $this->row->__orders__ = $this->getOrders();
        $this->row->__has_multiple_sessions__ = 1 < Help::getDB()->fetchUserSessionCount($this->getId());
        $this->row->slug = '__user__'; //the default slug...
    }

}

