<?php

namespace Peat;
class Admin extends BaseLogic
{
    protected int $id;
    protected Client $client;

    public function __construct(int $admin_id)
    {
        parent::__construct();
        if ($this->row = $this->getDB()->fetchAdmin($admin_id)) {
            $this->id = $admin_id;
        } else {
            $this->handleErrorAndStop(
                sprintf(__('Admin not found with id %s in instance %s','peatcms'),
                    var_export($admin_id, true), Setup::$instance_id),
                __('Security warning, after multiple warnings your account may be blocked', 'peatcms')
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
        $this->addMessage(__('Security warning, after multiple warnings your account may be blocked', 'peatcms'), 'warn');

        return false;
    }

    public function isRelatedElement(BaseElement $element): bool
    {
        return ($this->isRelatedInstanceId($element->getInstanceId()));
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
            $this->row->__instances__ = array($this->getDB()->fetchInstanceById($this->row->instance_id));
            $this->row->__clients__ = array();
        }
        $this->row->__sessions__ = $this->getDB()->fetchAdminSessions($this->getId());
        Help::prepareAdminRowForOutput($this->row, 'admin');
    }
}

