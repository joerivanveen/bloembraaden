<?php

namespace Peat;
class Payvision extends PaymentServiceProvider implements PaymentServiceProviderInterface
{
    /**
     * @return string[] the available fieldnames / settings for this provider
     */
    public function getFieldNames(): array
    {
        return array(
            'businessId' => 'Your businessId',
            'storeId' => 'storeId',
            'brandIds' => 'brandIds between square brackets',
            'brandIds_with_delayed_payment' => 'as brandIds, but the brands that support delayed payment',
            'returnUrl' => 'Return url (after payment)',
            'failedUrl' => 'Shown to client upon fail',
            'successUrl' => 'Shown to client upon success',
            'pendingUrl' => 'Shown to client when payment is not yet final',
            'authorization' => 'Authorisation header, including ‘Basic’, as specified by payvision',
            'default_src' => 'Space separated list of domain names for CSP header',
            'gateway_url' => 'The full url of the api endpoint',
        );
    }

    public function getPaymentByPaymentId(string $payment_id): ?\stdClass
    {
        // $post_data must contain a checkout_id, which we will check at payvision
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->getFieldValue('gateway_url') . '/payments/' . $payment_id . '?businessId=' . $this->getFieldValue('businessId'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'accept: application/json',
                'authorization: ' . $this->getFieldValue('authorization'),
                'content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        $err = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
        curl_close($curl);
        if ($err) {
            $this->addError($err);
            $this->addMessage(__('Payment request generated an error on the server', 'peatcms'), 'error');
        } else {
            $return_object = json_decode($result);
            if ($status_code >= 400) {
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->addError($status_code . ':  ' . $return_object->body->error->message);
                } else {
                    $this->addError($status_code . ':  ' . $result);
                }
                $this->addMessage(sprintf(__('Payment request was bad (status %s)', 'peatcms'), $status_code), 'error');
            } else { // status code must be 200...
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $return_object;
                } else {
                    $this->addError($result);
                    $this->addMessage(__('Payment response was not recognized', 'peatcms'), 'error');
                }
            }
        }

        return null; // retry
    }

    /**
     * @param string $payment_id
     * @return int -1 = failed / retry, 0 = pending, 1 = success
     */
    public function checkPaymentStatusByPaymentId(string $payment_id): int
    {
        $return_object = $this->getPaymentByPaymentId($payment_id);
        if (isset($return_object->result)) {
            $result = $return_object->result;
            // result codes
            // https://developers.acehubpaymentservices.com/reference#result-codes-2
            if ($result === 0) return PaymentServiceProviderInterface::STATUS_PAID;
            if (in_array($result, array(1, 2, 3))) return PaymentServiceProviderInterface::STATUS_PENDING;
        }

        return PaymentServiceProviderInterface::STATUS_UNPAID;
    }

    public function beginTransaction(Order $order, Instance $instance): ?string
    { // TODO this should not know about the order and its methods, how to implement better?
        $instance_name = $instance->getName();
        // payvision needs 8 characters minimum to track payment
        // brands: https://developers.acehubpaymentservices.com/docs/brands-reference
        // PC: 3010, 1030, 1210, 1020, 1010 (1020 = mastercard)
        // stuff you need to send to initiate the checkout:
        // https://developers.acehubpaymentservices.com/reference#checkouts
        $out = $order->getOutputFull();
        // TODO temp for pc...
        if ($out->shipping_address_country_iso2 === 'XX') $out->shipping_address_country_iso2 = 'NL';
        if ($out->billing_address_country_iso2 === 'XX') $out->billing_address_country_iso2 = 'NL';
        // payvision demands a valid shipping country address
        if (false === isset($out->billing_address_country_iso2) or trim($out->billing_address_country_iso2) === '') {
            // default set to same as shipping
            $out->billing_address_country_iso2 = $out->shipping_address_country_iso2;
        }
        // prepare tracking code minimum 8 characters
        $trackingCode = sprintf('%08d', $order->getId()) . '-' . Help::randomString(6);
        if (true === $out->payment_afterwards) {
            $brand_ids = $this->getFieldValue('brandIds_with_delayed_payment');
            $authorization_string = 'authorize';
        } else {
            $brand_ids = $this->getFieldValue('brandIds');
            $authorization_string = 'payment';
        }
        if (is_array($brand_ids) && count($brand_ids) > 0) {
            $brand_ids = '[' . implode(',', $brand_ids) . ']';
        } else {
            $this->addError(sprintf('No brand_ids found (payvision) %s', var_export($brand_ids, true)));
            $this->addMessage(__('Payment request configuration error', 'peatcms'), 'error');

            return null;
        }
        $data = '{
                "header": {
                    "businessId": "' . $this->getFieldValue('businessId') . '"
                },
                "body": {
                    "transaction": {
                        "storeId":"' . $this->getFieldValue('storeId') . '",
                        "authorizationMode":"' . $authorization_string . '",
                        "amount": "' . ($order->getGrandTotal()) . '",
                        "currencyCode": "EUR",
                        "trackingCode": "' . $trackingCode . '",
                        "purchaseId": "' . $trackingCode . '",
                        "invoiceId": "' . $trackingCode . '",
                        "countryCode": "NL",
                        "languageCode": "NL",
                        "descriptor": "' . substr(str_replace(' ', '-', $instance_name) . '-' . substr($out->order_number, -4), 0, 127) . '"
                    }, 
                    "checkout":{
                        "brandIds": ' . $brand_ids . ',
                        "returnUrl": "' . $this->getFieldValue('returnUrl') . '"
                    },
                    "billingAddress":{
                        "street": "' . htmlentities(substr($out->billing_address_street, 0, 255)) . '",
                        "houseNumber": "' . htmlentities(substr($out->billing_address_number, 0, 255)) . '",
                        "houseNumberSuffix": "' . htmlentities(substr($out->billing_address_number_addition, 0, 255)) . '",
                        "streetInfo": "' . htmlentities(substr($out->billing_address_street_addition, 0, 255)) . '",
                        "city": "' . htmlentities(substr($out->billing_address_city, 0, 255)) . '",
                        "stateCode": "",
                        "zip": "' . htmlentities(substr($out->billing_address_postal_code, 0, 20)) . '",
                        "countryCode": "' . htmlentities(substr($out->billing_address_country_iso2, 0, 2)) . '"
                    },
                    "shippingAddress":{
                        "street": "' . htmlentities(substr($out->shipping_address_street, 0, 255)) . '",
                        "houseNumber": "' . htmlentities(substr($out->shipping_address_number, 0, 255)) . '",
                        "houseNumberSuffix": "' . htmlentities(substr($out->shipping_address_number_addition, 0, 255)) . '",
                        "streetInfo": "' . htmlentities(substr($out->shipping_address_street_addition, 0, 255)) . '",
                        "city": "' . htmlentities(substr($out->shipping_address_city, 0, 255)) . '",
                        "stateCode": "",
                        "zip": "' . htmlentities(substr($out->shipping_address_postal_code, 0, 20)) . '",
                        "countryCode": "' . htmlentities(substr($out->shipping_address_country_iso2, 0, 2)) . '"
                    },
                    "customer":{
                        "email": "' . htmlentities($out->user_email) . '"
                    }
                }
            }';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->getFieldValue('gateway_url') . '/checkouts',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                'accept: application/json',
                'authorization: ' . $this->getFieldValue('authorization'),
                'content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        $err = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
        curl_close($curl);
        if ($err) {
            $this->addError($err);
            $this->addMessage(__('Payment request generated an error on the server', 'peatcms'), 'error');
        } else {
            $return_object = json_decode($result);
            if ($status_code >= 400) {
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->addError($return_object->body->error->message);
                } else {
                    $this->addError($result);
                }
                $this->addMessage(sprintf(__('Payment request was bad (status %s)', 'peatcms'), $status_code), 'error');
            } else { // status code must be 200...
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $return_object->body->checkout->checkoutId;
                } else {
                    $this->addError($result);
                    $this->addMessage(__('Payment response was not recognized', 'peatcms'), 'error');
                }
            }
        }

        return null;
    }

    public function capturePayment(int $order_id): bool
    {
        // use curl to put the request to payvision, return the body to updatepaymentstatus...
        // https://developers.acehubpaymentservices.com/v3.3/reference#capture-3
        $type = new Type('order');
        // prepare tracking code minimum 8 characters
        $trackingCode = sprintf('%08d', $order_id);
        if (($order_row = Help::getDB()->fetchElementRow($type, $order_id)) && isset($order_row->payment_transaction_id)) {
            $url = $this->getFieldValue('gateway_url') . '/payments/' . $order_row->payment_transaction_id . '/capture';
            $data = '{
                    "header": {
                        "businessId": "' . $this->getFieldValue('businessId') . '"
                    },
                    "body": {
                        "transaction": {
                            "amount": "' . \strval($order_row->amount_grand_total / 100.0) . '",
                            "currencyCode": "EUR",
                            "trackingCode": "' . $trackingCode . '_capture"
                        }
                    }
                }';
            // you can only do this once, because each trackingcode can only be used once...
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => array(
                    'accept: application/json',
                    'authorization: ' . $this->getFieldValue('authorization'),
                    'content-type: application/json',
                ),
            ));
            $result = curl_exec($curl);
            $err = \curl_error($curl);
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
            curl_close($curl);
            if ($err) {
                $this->addError($err);
                $this->addMessage(__('Payment request generated an error on the server', 'peatcms'), 'error');
            } else {
                $return_object = json_decode($result);
                if ($status_code >= 400) {
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $this->addError($return_object->body->error->message);
                    } else {
                        $this->addError($result);
                    }
                    $this->addMessage(sprintf(__('Payment request was bad (status %s)', 'peatcms'), $status_code), 'error');
                } else { // status code must be 200...
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $this->updatePaymentStatus($return_object->body);
                    } else {
                        $this->addError($result);
                        $this->addMessage(__('Payment response was not recognized', 'peatcms'), 'error');

                        return false;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param \stdClass $payload
     * @return bool
     */
    public function updatePaymentStatus(\stdClass $payload): bool
    {
        // register this update in the log table
        if (($log_id = $this->logPaymentStatus($payload))) {
            if (isset($payload->Payload)) { // from webhook the actual payload is not directly the payload
                $payload = $payload->Payload;
            }
            $payment_confirmed = false;
            if (isset($payload->result)) $payment_confirmed = (0 === $payload->result);
            $payment_status = 'unknown';
            if (isset($payload->description)) $payment_status = $payload->description;
            $payment_tracking_text = 'unknown';
            $order_id = 0;
            $amount = 0;
            $transaction_id = null;
            if (isset($payload->body) && isset($payload->body->transaction)) {
                $transaction = $payload->body->transaction;
            } elseif (isset($payload->transaction)) {
                $transaction = $payload->transaction;
            } else {
                $this->addError('No transaction found');
                $transaction = new \stdClass;
            }
            if (isset($transaction->trackingCode)) $order_id = (int)$transaction->trackingCode;
            if (isset($transaction->amount)) $amount = (float)$transaction->amount;
            if (isset($transaction->id)) $transaction_id = $transaction->id;
            if (isset($transaction->action)) $payment_tracking_text = $transaction->action;
            if ($order_id > 0) { // do the processing
                $type = new Type('order');
                if (($order = Help::getDB()->fetchElementRow($type, $order_id))) {
                    $order_update_array = array();
                    if (true === $payment_confirmed) { // double check the amount (can be 1.0 off...)
                        if ((($amount + 1.0) * 100) < $order->amount_grand_total) {
                            $payment_confirmed = false;
                            $order_update_array = array_merge($order_update_array, array(
                                'payment_confirmed_text' => 'Wrong amount',
                            ));
                        } else {
                            // @since 0.7.9 we’re double checking the ‘paid’ status by re-requesting the payload ourselves
                            if (PaymentServiceProviderInterface::STATUS_PAID ===  $this->checkPaymentStatusByPaymentId($transaction_id)) {
                                $order_update_array = array_merge($order_update_array, array(
                                    'payment_confirmed_bool' => true,
                                    'payment_confirmed_date' => 'NOW()',
                                    'payment_confirmed_text' => 'Auto',
                                ));
                            } else {
                                $payment_confirmed = false;
                                $order_update_array = array_merge($order_update_array, array(
                                    'payment_confirmed_text' => 'Failed confirmation double check',
                                ));
                            }
                        }
                        /*} elseif (true === $order->payment_confirmed_bool) {
                            // if it was already paid, remember that status
                            $payment_confirmed = true;*/
                    }
                    // if it’s a capture update that as well
                    if ($payment_tracking_text === 'capture' && $payment_confirmed) {
                        $order_update_array = array_merge($order_update_array, array(
                            'payment_afterwards_captured' => true,
                        ));
                    }
                    $order_update_array = array_merge($order_update_array, array(
                        'payment_status' => $payment_status,
                        'payment_tracking_text' => $payment_tracking_text,
                        'payment_transaction_id' => $transaction_id,
                    ));
                    if (Help::getDB()->updateElement($type, $order_update_array, $order_id)) { // the order status is updated
                        Help::getDB()->updateColumns('_payment_status_update', array(
                            'bool_processed' => true,
                            'date_processed' => 'NOW()',
                            'order_id' => $order_id,
                            'amount' => intval($amount * 100),
                        ), $log_id);

                        return true;
                    }
                }
            }
            $this->addError('payment status update failed for order, check the payment status log');

            return true;
        }

        return false;
    }
}