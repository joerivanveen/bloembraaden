<?php

declare(strict_types=1);

namespace Bloembraaden;

class Admin extends BaseLogic
{
    protected int $id;
    protected Client $client;

    public function __construct(int $admin_id)
    {
        parent::__construct();
        if (!($this->row = db()->fetchAdmin($admin_id))) {
            $this->handleErrorAndStop(
                sprintf(__('Admin not found with id %s in instance %s', 'peatcms'),
                    var_export($admin_id, true), Setup::$instance_id),
                __('Security warning, after multiple warnings your account may be blocked.', 'peatcms')
            );
        }
        $this->type_name = 'admin';
    }

    public function isRelatedInstanceId(int $instance_id): bool
    {
        // TODO expand this with the client functionality
        // if this admin has an instance_id, check if the element belongs to it
        if (($my_instance_id = $this->row->instance_id) === $instance_id) return true;
        // if the admins instance_id = 0 it's related to any instance belonging to its client
        if ($my_instance_id === 0 && $this->row->client_id > 0) {
            if ($arr = $this->getClient()->getInstances()) {
                foreach ($arr as $index => $obj) {
                    if ($obj->getId() === $instance_id) return true;
                }
            }
        }
        $this->addMessage(__('Security warning, after multiple warnings your account may be blocked.', 'peatcms'), 'warn');

        return false;
    }

    public function isRelatedElement(BaseElement $element): bool
    {
        return ($this->isRelatedInstanceId($element->getInstanceId()));
    }

    public function canDo(string $action, string $table_name, $id): bool
    {
        $allowed = false;
        if ($row = db()->selectRow($table_name, $id)) {
            if (isset($row->instance_id)) {
                $allowed = $this->isRelatedInstanceId($row->instance_id);
            } elseif (isset($row->property_id)) {
                $allowed = $this->isRelatedElement((new Property())->fetchById($row->property_id));
            }
        }
        return $allowed;
    }

    /**
     * Tables (besides all cms_ tables) that admins may update column by column
     */
    private const UPDATABLE_TABLES = array(
        '_instance',
        '_instance_domain',
        '_address_shop',
        '_template',
        '_country',
        '_payment_service_provider',
        '_redirect',
        '_admin',
        '_vat_category',
    );

    /**
     * Columns that determine ownership or security, these can never be updated by an admin directly
     */
    private const PROTECTED_COLUMNS = array(
        'instance_id',
        'client_id',
        'admin_id',
        'user_id',
        'session_id',
        'password_hash',
        'token_hash',
        'filename_saved',
    );

    /**
     * Checks whether a column may be updated by an admin through the generic update actions,
     * this does not check whether the row belongs to the admin, use canDo() or isRelatedElement() for that
     *
     * @param string $table_name
     * @param string $column_name
     * @return bool
     */
    public function mayUpdateColumn(string $table_name, string $column_name): bool
    {
        $allowed = (true === str_starts_with($table_name, 'cms_') || true === in_array($table_name, self::UPDATABLE_TABLES, true))
            && false === in_array($column_name, self::PROTECTED_COLUMNS, true)
            && $column_name !== db()->getTableInfo($table_name)->getPrimaryKeyColumn()->getName();
        if (false === $allowed) {
            $this->addError(sprintf('Admin %1$s may not update %2$s.%3$s.', $this->getId(), $table_name, $column_name));
            $this->addMessage(__('Security warning, after multiple warnings your account may be blocked.', 'peatcms'), 'warn');
        }

        return $allowed;
    }

    public function checkPassword(string $password): bool
    {
        $hashed = hash_hmac('sha256', $password, Setup::$HASHKEY, false);
        return password_verify($hashed, $this->row->password_hash);
    }

    public function getClient(): Client
    {
        if (false === isset($this->client)) {
            $this->client = new Client($this->row->client_id);
        }

        return $this->client;
    }

    public function completeRowForOutput(): void
    {
        // TODO if $this->row->client_id === 0 show a row of available clients to choose from
        if (!isset($this->row->instance_id)) $this->handleErrorAndStop('instance_id not set on admin');
        // for admins that don't belong to an instance you may fetch the client (and hence all instances with their own admins)
        if ($this->row->instance_id === 0) {
            $this->row->__clients__ = array($this->getClient()->getOutput()); // Templator needs index based array for complex tags
            $this->row->__instances__ = array();
        } else {
            $this->row->__instances__ = array(db()->fetchInstanceById($this->row->instance_id));
            $this->row->__clients__ = array();
        }
        $this->row->__sessions__ = db()->fetchAdminSessions($this->getId());
        Help::prepareAdminRowForOutput($this->row, 'admin');
        // some fields can never be output
        unset($this->row->password_hash);
    }
}

