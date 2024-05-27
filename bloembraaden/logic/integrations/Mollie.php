<?php

declare(strict_types = 1);

namespace Bloembraaden;

class Mollie extends PaymentServiceProvider implements PaymentServiceProviderInterface
{
    public function getFieldNames(): array
    {
        return array(
            'returnUrl' => 'Return url (after payment)',
            'api_key' => 'The api key used for authorization',
            'default_src' => 'Space separated list of domain names for CSP header',
            'gateway_url' => 'The full url of the api endpoint',
        );
    }

    public function beginTransaction(Order $order, Instance $instance): ?string
    {
        // https://docs.mollie.com/reference/v2/payments-api/create-payment
        $out = $order->getOutputFull();
        // TODO temp for pc...
        if ($out->shipping_address_country_iso2 === 'XX') $out->shipping_address_country_iso2 = 'NL';
        if ($out->billing_address_country_iso2 === 'XX') $out->billing_address_country_iso2 = 'NL';
        // set a valid billing country
        if (false === isset($out->billing_address_country_iso2) || '' === trim($out->billing_address_country_iso2)) {
            // default set to same as shipping
            $out->billing_address_country_iso2 = $out->shipping_address_country_iso2;
        }
        $shipping_street = str_replace(
            '  ', '',
            $out->shipping_address_street . ' ' .
            $out->shipping_address_street_addition . ' ' .
            $out->shipping_address_number . ' ' .
            $out->shipping_address_number_addition
        );
        $billing_street = str_replace(
            '  ', '',
            $out->billing_address_street . ' ' .
            $out->billing_address_street_addition . ' ' .
            $out->billing_address_number . ' ' .
            $out->billing_address_number_addition
        );
        $instance_domain = str_replace('.local', '.io', $instance->getDomain(true));
        $data = '{
            "amount": {
                "currency": "EUR",
                "value": "' . number_format($order->getGrandTotal(), 2, '.', '') . '"
            },
            "description": "' . htmlentities($out->order_number . ' ' . $instance->getName()) . '",
            "redirectUrl": "' . htmlentities((string)$this->getFieldValue('returnUrl')) . '",
            "webhookUrl": "' . str_replace('/', '\/', $instance_domain) . '\/__action__\/payment_status_update",
            "shippingAddress": {
                "country": "' . htmlentities($out->shipping_address_country_iso2) . '",
                "city": "' . htmlentities($out->shipping_address_city) . '",
                "postalCode": "' . htmlentities($out->shipping_address_postal_code) . '",
                "streetAndNumber": "' . htmlentities($shipping_street) . '"
            },
            "billingAddress": {
                "country": "' . htmlentities($out->billing_address_country_iso2) . '",
                "city": "' . htmlentities($out->billing_address_city) . '",
                "postalCode": "' . htmlentities($out->billing_address_postal_code) . '",
                "streetAndNumber": "' . htmlentities($billing_street) . '"
            }
        }';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->getFieldValue('gateway_url') . 'payments',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Authorization: Bearer ' . $this->getFieldValue('api_key'),
                'Content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        // receives a _links.checkout in the response by Mollie to redirect the client to, but we build that ourselves in javascript
        $err = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
        curl_close($curl);
        if ($err) {
            $this->addError($err);
            $this->addMessage(__('Payment request generated an error on the server', 'peatcms'), 'error');
        } else {
            $return_object = json_decode($result);
            if ($status_code >= 400) {
                if (json_last_error() === 0) {
                    $this->addError($return_object->detail ?? $return_object->title ?? 'ERROR');
                } else {
                    $this->addError($result);
                }
                $this->addMessage(sprintf(__('Payment request was bad (status %s)', 'peatcms'), $status_code), 'error');
            } else { // status code must be 200...
                if (json_last_error() === 0) {
                    return $return_object->id;
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
        // TODO: Implement capturePayment() method.
        return false;
    }

    public function getPaymentByPaymentId(string $payment_id): ?\stdClass
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->getFieldValue('gateway_url') . 'payments/' . $payment_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Authorization: Bearer ' . $this->getFieldValue('api_key'),
                'Content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        // receives a resource (payment object) with status and amount etc.
        $err = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
        curl_close($curl);
        if ($err) {
            $this->addError($err);
            $this->addMessage(__('Payment request generated an error on the server', 'peatcms'), 'error');
        } else {
            $return_object = json_decode($result);
            if ($status_code >= 400) {
                if (json_last_error() === 0) {
                    $this->addError($return_object->detail ?? $return_object->title ?? var_export($return_object, true));
                } else {
                    $this->addError($result);
                }
                $this->addMessage(sprintf(__('Payment request was bad (status %s)', 'peatcms'), $status_code), 'error');
            } else { // status code must be 200...
                if (json_last_error() === 0) {
                    return $return_object;
                } else {
                    $this->addError($result);
                    $this->addMessage(__('Payment response was not recognized', 'peatcms'), 'error');
                }
            }
        }

        return null;
    }

    public function checkPaymentStatusByPaymentId(string $payment_id): int
    {
        if (($result = $this->getPaymentByPaymentId($payment_id))) {
            if ('paid' === $result->status) return PaymentServiceProviderInterface::STATUS_PAID;
            if (in_array($result->status, array('open', 'pending', 'authorized'))) return PaymentServiceProviderInterface::STATUS_PENDING;
        }

        return PaymentServiceProviderInterface::STATUS_UNPAID;
    }

    public function updatePaymentStatus(\stdClass $payload): bool
    {
        if (isset($payload->id)) {
            $payment_id = $payload->id;
            // get the status of this payment id from mollie https://docs.mollie.com/reference/v2/payments-api/get-payment
            if (($result = $this->getPaymentByPaymentId($payment_id))) {
                $log_id = $this->logPaymentStatus($result);
                // get the order having this payment_id
                $amount = (float)$result->amount->value ?? 0.0;
                $status = $result->status;
                if (($order_row = Help::getDB()->getOrderByPaymentTrackingId($payment_id))) {
                    $order_update_array = array(
                        'payment_status' => $status,
                        'payment_tracking_text' => '',
                        'payment_transaction_id' => $payment_id,
                    );
                    $order_id = $order_row->order_id;
                    if ('paid' === $status) {
                        // check if the amount is more or less ok
                        if ((($amount + 1.0) * 100) < $order_row->amount_grand_total) {
                            // if not the status must not be ‘paid’...
                            $order_update_array = array_merge($order_update_array, array(
                                'payment_confirmed_text' => 'Wrong amount',
                            ));
                        } else {
                            $order_update_array = array_merge($order_update_array, array(
                                'payment_confirmed_bool' => true,
                                'payment_confirmed_date' => 'NOW()',
                                'payment_confirmed_text' => 'Auto',
                            ));
                        }
                    }
                    // update the log entry
                    Help::getDB()->updateColumns('_payment_status_update', array(
                        'bool_processed' => true,
                        'date_processed' => 'NOW()',
                        'order_id' => $order_id,
                        'amount' => intval($amount * 100),
                    ), $log_id);
                    // update the status in the order
                    return Help::getDB()->updateElement(new Type('order'), $order_update_array, $order_id);
                } elseif ('expired' === $status) {
                    $this->addError(sprintf('Payment %s with status expired discarded', $payment_id));
                    return true; // don’t bother any further with expired statuses, already logged with processed false
                }
            }
        } else {
            $this->addError(sprintf('%s->updatePaymentStatus missing id in payload: ' . var_export($payload, true), 'Mollie'));
        }

        return false;
    }
}