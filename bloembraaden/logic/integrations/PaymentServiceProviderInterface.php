<?php

namespace Peat;
interface PaymentServiceProviderInterface
{
    public const STATUS_UNPAID = -1;
    public const STATUS_PENDING = 0;
    public const STATUS_PAID = 1;

    public function __construct(?\stdClass $row);

    public function getFieldNames(): array;

    public function getFieldValue(string $field_name);

    public function getFields(): \stdClass;

    /**
     * @return bool whether the ‘live’ flag has been set for this psp
     * @since 0.6.16
     */
    public function isLive(): bool;

    /**
     * @param string $payment_id
     * @return int -1 means unpaid / canceled, 0 means pending / waiting, 1 means paid
     * @since 0.7.9
     */
    public function checkPaymentStatusByPaymentId(string $payment_id): int;

    /**
     * startup a payment transaction, return unique id to track this payment, or null on failure
     * @param Order $order
     * @param Instance $instance
     * @return string|null
     */
    public function beginTransaction(Order $order, Instance $instance): ?string;

    /**
     * for a delayed payment, execute it (entirely) for this order
     * @param int $order_id
     * @return bool success
     */
    public function capturePayment(int $order_id): bool;

    /**
     * updates the status based on raw return value of psp, returns success
     * @param \stdClass $payload
     * @return bool
     */
    public function updatePaymentStatus(\stdClass $payload): bool;

    /**
     * Called when the payment is updated to return as body of the request
     * to allow for PSP-specific responses (the status is always 200 naturally)
     * @return object
     */
    public function successBody(): \stdClass;

    public function getOutput(): \stdClass;

}