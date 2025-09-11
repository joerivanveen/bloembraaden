<?php

declare(strict_types = 1);

namespace Bloembraaden;

class User extends BaseLogic
{
    private array $addresses, $orders;

    public function __construct(int $user_id)
    {
        parent::__construct();
        if (!($this->row = Help::getDB()->fetchUser($user_id))) {
            $this->addError(
                sprintf('User not found with id %s in instance %s.',
                    var_export($user_id, true), Setup::$INSTANCE_DOMAIN)
            );
        }
        $this->type_name = 'user';
    }

    /**
     * Get the addresses for this user
     * @return array indexed holding address objects (stdClass)
     * @since 0.7.9
     */
    public function getAddresses(): array
    {
        if (false === isset($this->addresses)) {
            if (0 !== ($user_id = $this->getId())) {
                $this->addresses = Help::getDB()->fetchAddressesByUserId($user_id);
            } else { // just for security purposes a non-specific user id should not be able to ‘fetch’ anything
                $this->addresses = array();
            }
        }
        return $this->addresses;
    }

    public function getOrders(): array
    {
        if (false === isset($this->orders)) {
            if (0 !== ($user_id = $this->getId())) {
                $this->orders = Help::getDB()->fetchOrdersByUserId($user_id);
            } else { // just for security purposes a non-specific user id should not be able to ‘fetch’ anything
                $this->orders = array();
            }
        }
        return $this->orders;
    }

    /**
     * Overridden to include addresses and orders in the output
     * @return void
     */
    public function completeRowForOutput(): void
    {
        $this->row->__addresses__ = $this->getAddresses();
        $this->row->__orders__ = $this->getOrders();
        // TODO because this is not used currently and there is no index on user_id in _session, remove entirely
        //$this->row->__has_multiple_sessions__ = 1 < Help::getDB()->fetchUserSessionCount($this->getId());
        $this->row->slug = '__user__'; //the default slug...
    }

}

