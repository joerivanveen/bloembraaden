<?php

namespace Peat;
class Client extends BaseLogic
{
    protected int $id;
    protected array $instances;

    public function __construct(int $client_id)
    {
        parent::__construct();
        if ($this->row = Help::getDB()->fetchClient($client_id)) {
            $this->id = $client_id;
        } else {
            $this->handleErrorAndStop(sprintf('Could not create client with id %d', $client_id));
        }
        $this->type_name = 'client';
    }

    public function completeRowForOutput(): void
    {
        // add the instances...
        if (false === isset($this->row->__instances__)) {
            $arr = $this->getInstances();
            foreach ($arr as $index => $obj) {
                $arr[$index] = $obj->getOutput();
            }
            $this->row->__instances__ = $arr;
        }
        Help::prepareAdminRowForOutput($this->row, 'client');
    }

    public function getInstances(): array
    {
        if (false === isset($this->instances)) {
            $arr = Help::getDB()->fetchInstancesForClient($this->id);
            foreach ($arr as $index => $obj) {
                $arr[$index] = new Instance($obj);
            }
            $this->instances = $arr;
        }

        return $this->instances;
    }
}