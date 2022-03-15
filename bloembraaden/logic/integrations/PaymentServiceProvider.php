<?php

namespace Peat;

class PaymentServiceProvider extends BaseLogic
{
    private \stdClass $cache_fields;

    public function __construct(?\stdClass $row)
    {
        if (null === $row) $this->addError('PaymentServiceProvider cannot be initialized with null row');
        parent::__construct($row);
        $this->type_name = 'payment_service_provider';
    }

    public function getFieldNames(): array
    {
        $this->addError('->getFieldNames must be overridden by actual PaymentServiceProvider class');

        return array();
    }

    public function updatePaymentStatus(\stdClass $data): bool
    {
        $this->addError('->updatePaymentStatus must be overridden by actual PaymentServiceProvider class');

        return false;
    }

    public function getFieldValue(string $field_name)
    {
        if (isset($this->getFields()->$field_name)) {
            return $this->getFields()->$field_name;
        }

        return null;
    }

    public function getFields(): \stdClass
    {
        if (isset($this->cache_fields)) return $this->cache_fields;

        $field_values = array();
        if (isset($this->row->field_values)) {
            $field_values = json_decode($this->row->field_values);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $field_values = array();
                $this->addError('Payment service provider values not correctly read');
            }
        }
        $field_names = $this->getFieldNames(); // detached now, the array will contain name=>value pairs after this
        foreach ($field_names as $field_name => $expl) {
            if (isset($field_values->$field_name)) {
                $field_names[$field_name] = $field_values->$field_name;
            } else {
                $field_names[$field_name] = null;
            }
        }
        $this->cache_fields = (object)$field_names;

        return $this->cache_fields;
    }

    /**
     * @return bool whether the ‘live’ flag has been set for this psp
     * @since 0.6.16
     */
    public function isLive(): bool
    {
        return $this->row->live_flag ?? false;
    }

    // you need default-src for live and stage
    // https://stagconnect.acehubpaymentservices.com https://connect.acehubpaymentservices.com
    // and api-location:
    // Gateway/v3/Checkouts
    public function checkPaymentStatusByPaymentId(string $payment_id): int
    {
        $this->handleErrorAndStop('->checkPaymentStatusByPaymentId must be overridden by actual PaymentServiceProvider class');
        return false;
    }

    protected function logPaymentStatus(\stdClass $payload): int
    {
        return $this->getDB()->insertRowAndReturnKey('_payment_status_update', array(
            'instance_id' => Setup::$instance_id,
            'raw' => json_encode($payload),
            'origin' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN IP'
        ));
    }

    /**
     * Called when the payment is updated to return as body of the request
     * to allow for PSP-specific responses (the status is always 200 naturally)
     * @return object
     */
    public function successBody(): \stdClass
    {
        return (object)array('success' => true);
    }

    /**
     * Get the output object for admin page so the psp can be edited
     */
    public function completeRowForOutput(): void
    {
        // get the names and values for this psp, so it can be edited
        $this->row->fields = json_encode($this->getFields());
        Help::prepareAdminRowForOutput($this->row, 'payment_service_provider', $this->getId());
    }
}